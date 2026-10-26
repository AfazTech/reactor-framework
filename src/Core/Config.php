<?php

namespace Reactor\Core;

use Amp\File;

/**
 * Multi-source configuration manager.
 *
 * Configuration values are collected from one or more sources (package
 * defaults, the application's config directory, and runtime overrides)
 * and merged deterministically. Deep associative arrays are merged key
 * by key; list arrays (sequential numeric keys) and scalars are replaced.
 *
 * Layering, from lowest to highest precedence:
 *
 *   1. Package defaults — registered via addSource() with a priority
 *      lower than self::PRIORITY_APPLICATION.
 *   2. Application config — the directory passed to the constructor or
 *      set via setConfigPath(); registered at self::PRIORITY_APPLICATION.
 *   3. Runtime overrides — set via override() or any of the set*()
 *      helpers. Always applied last, regardless of source priority.
 *
 * Values are addressed using dot notation (e.g. 'bot.token').
 *
 * Directory listing and file reading go through amphp/file so that a
 * persistent-worker deployment can overlap configuration loading with
 * other I/O. Config files are evaluated with eval() instead of require
 * because require would re-enter the PHP autoloader synchronously and
 * block the event loop when called inside a fiber.
 */
class Config
{
    public const PRIORITY_PACKAGE = 10;
    public const PRIORITY_APPLICATION = 100;

    /** @var array<string, mixed> */
    private array $baseConfig = [];

    /** @var array<string, mixed> */
    private array $runtime = [];

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var array<int, array{path: string, priority: int}> */
    private array $sources = [];

    private string $appConfigPath;

    public function __construct(?string $appConfigPath = null)
    {
        $this->appConfigPath = $appConfigPath !== null
            ? rtrim($appConfigPath, '/')
            : '';

        if ($this->appConfigPath !== '') {
            $this->sources[] = [
                'path'     => $this->appConfigPath,
                'priority' => self::PRIORITY_APPLICATION,
            ];
        }

        $this->loadConfigFiles();
    }

    public function addSource(string $path, int $priority = self::PRIORITY_PACKAGE): void
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return;
        }

        foreach ($this->sources as $source) {
            if ($source['path'] === $path && $source['priority'] === $priority) {
                return;
            }
        }

        $this->sources[] = ['path' => $path, 'priority' => $priority];
        $this->loadConfigFiles();
    }

    public function setConfigPath(string $path): void
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return;
        }

        if ($this->appConfigPath !== '') {
            $this->sources = array_values(array_filter(
                $this->sources,
                fn(array $source): bool => !(
                    $source['priority'] === self::PRIORITY_APPLICATION
                    && $source['path'] === $this->appConfigPath
                )
            ));
        }

        $this->appConfigPath = $path;
        $this->sources[] = [
            'path'     => $path,
            'priority' => self::PRIORITY_APPLICATION,
        ];
        $this->loadConfigFiles();
    }

    public function override(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->runtime;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }

        $target = $value;

        $this->rebuild();
    }

    public function forgetOverride(string $key): void
    {
        $segments = explode('.', $key);
        if ($segments === [] || $segments === ['']) {
            return;
        }

        $this->unsetOverridePath($this->runtime, $segments);
        $this->rebuild();
    }

    private function unsetOverridePath(array &$array, array $segments): void
    {
        $segment = array_shift($segments);
        if ($segment === null || !array_key_exists($segment, $array)) {
            return;
        }

        if ($segments === []) {
            unset($array[$segment]);
            return;
        }

        if (!is_array($array[$segment])) {
            return;
        }

        $this->unsetOverridePath($array[$segment], $segments);

        if ($array[$segment] === []) {
            unset($array[$segment]);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $value = $this->config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    private function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) $value;
    }

    public function getToken(): string
    {
        return (string) $this->get('bot.token', '');
    }

    public function getApiUrl(): string
    {
        return (string) $this->get('bot.api_url', 'https://api.telegram.org/bot');
    }

    public function isVerifySsl(): bool
    {
        return $this->getBool('bot.verify_ssl', true);
    }

    public function isDebugMode(): bool
    {
        return $this->getBool('app.debug', false);
    }

    public function getDatabaseConfig(): array
    {
        $connection = $this->get('database.default', 'sqlite');
        $connections = $this->get('database.connections', []);

        if (!isset($connections[$connection]) || !is_array($connections[$connection])) {
            $error = "Database connection '{$connection}' not found in config/database.php. ";
            $error .= 'Available connections: ' . implode(', ', array_keys($connections));
            throw new \RuntimeException($error);
        }

        $config = $connections[$connection];

        if (($config['driver'] ?? null) === 'sqlite' && isset($config['database'])) {
            $dbPath = $config['database'];
            $isAbsolute = str_starts_with($dbPath, '/')
                || (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[A-Za-z]:[\\\\\/]/', $dbPath) === 1);

            if (!$isAbsolute) {
                $base = defined('REACTOR_BASE_PATH')
                    ? REACTOR_BASE_PATH
                    : (getcwd() ?: dirname(__DIR__, 2));
                $config['database'] = rtrim($base, '/') . '/' . ltrim($dbPath, '/');
            }
        }

        return $config;
    }

    public function getBotMode(): string
    {
        return (string) $this->get('bot.mode', 'polling');
    }

    public function isMultiProcess(): bool
    {
        return $this->getBool('bot.multi_process', false);
    }

    public function getWebhookSecret(): string
    {
        return (string) $this->get('bot.webhook_secret', '');
    }

    public function getPhpBinary(): string
    {
        return (string) $this->get('bot.php_binary', '/usr/bin/php');
    }

    public function setToken(string $token): void
    {
        $this->override('bot.token', $token);
    }

    public function setApiUrl(string $apiUrl): void
    {
        $this->override('bot.api_url', $apiUrl);
    }

    public function setDebugMode(bool $debug): void
    {
        $this->override('app.debug', $debug);
    }

    private function loadConfigFiles(): void
    {
        $this->baseConfig = [];

        $sorted = $this->sources;
        usort($sorted, fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($sorted as $source) {
            $directory = $source['path'];
            if (!File\isDirectory($directory)) {
                continue;
            }

            $phpFiles = [];
            foreach (File\listFiles($directory) as $entry) {
                if (str_ends_with($entry, '.php')) {
                    $phpFiles[] = $directory . '/' . $entry;
                }
            }
            sort($phpFiles);

            foreach ($phpFiles as $file) {
                $group = pathinfo($file, PATHINFO_FILENAME);
                $values = $this->evaluateConfigFile($file);
                if (!is_array($values)) {
                    continue;
                }

                $existing = $this->baseConfig[$group] ?? [];
                $this->baseConfig[$group] = is_array($existing)
                    ? $this->deepMerge($existing, $values)
                    : $values;
            }
        }

        $this->rebuild();
    }

    /**
     * Evaluate a single config file without blocking the event loop.
     *
     * The file content is read via amphp/file (non-blocking) and then
     * eval'd in-process so that `return [...]` continues to work
     * exactly as a normal `require $path` would. PHP magic constants
     * (__DIR__, __FILE__) that would otherwise resolve against this
     * file are rewritten to point at the config file being loaded.
     */
    private function evaluateConfigFile(string $path): mixed
    {
        $content = File\read($path);

        $dir = var_export(dirname($path), true);
        $file = var_export($path, true);
        $content = str_replace(['__DIR__', '__FILE__'], [$dir, $file], $content);

        if (str_starts_with($content, '<?php')) {
            $content = substr($content, 5);
        }

        try {
            return eval($content);
        } catch (\ParseError $e) {
            throw new \RuntimeException(
                "Failed to evaluate config file {$path}: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function rebuild(): void
    {
        $this->config = $this->deepMerge($this->baseConfig, $this->runtime);
    }

    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                isset($base[$key])
                && is_array($base[$key])
                && is_array($value)
                && !array_is_list($base[$key])
                && !array_is_list($value)
            ) {
                $base[$key] = $this->deepMerge($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
