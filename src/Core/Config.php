<?php

namespace Reactor\Core;

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
 * When no application config path is provided the instance starts with
 * an empty configuration; callers can populate it via addSource() and
 * override(). This keeps the class usable as a standalone package with
 * no assumption about the host application's directory layout.
 */
class Config
{
    /**
     * Priority recommended for package config directories.
     *
     * Packages should register their config with this priority so that
     * the application's own config overrides it on conflicts.
     */
    public const PRIORITY_PACKAGE = 10;

    /**
     * Priority used for the application's own config directory.
     */
    public const PRIORITY_APPLICATION = 100;

    /**
     * Base configuration loaded from all registered sources.
     *
     * @var array<string, mixed>
     */
    private array $baseConfig = [];

    /**
     * Runtime overrides applied on top of the base configuration.
     *
     * @var array<string, mixed>
     */
    private array $runtime = [];

    /**
     * Effective configuration (base merged with runtime overrides).
     *
     * @var array<string, mixed>
     */
    private array $config = [];

    /**
     * Registered config sources, each carrying a path and a priority.
     *
     * @var array<int, array{path: string, priority: int}>
     */
    private array $sources = [];

    /**
     * Path of the application's own config directory, or an empty
     * string when no application source is registered.
     */
    private string $appConfigPath;

    /**
     * @param string|null $appConfigPath Optional application config
     *                                   directory. When null the
     *                                   instance starts empty so the
     *                                   framework does not assume any
     *                                   skeleton-specific path.
     */
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

    /**
     * Register an additional config source directory.
     *
     * Packages should use self::PRIORITY_PACKAGE so that the application
     * wins on conflicts. Use a priority greater than
     * self::PRIORITY_APPLICATION to intentionally override application
     * values (e.g. an environment-specific preset).
     *
     * Registration is idempotent for the same (path, priority) pair.
     */
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

    /**
     * Replace the application's own config directory.
     *
     * Other sources (packages, presets) are preserved.
     */
    public function setConfigPath(string $path): void
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return;
        }

        // Drop the previous application source, if any.
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

    /**
     * Register a runtime override for a single key (dot notation).
     *
     * Runtime overrides take precedence over every config source and are
     * applied last during the effective config rebuild. Calling this
     * method with a nested key creates intermediate arrays as needed.
     */
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

    /**
     * Remove a runtime override previously registered via override().
     *
     * The value falls back to whatever the underlying config sources
     * provide (or is removed entirely if no source defines it). Empty
     * parent arrays left behind by the removal are pruned so that they
     * cannot shadow the base config during the rebuild step.
     */
    public function forgetOverride(string $key): void
    {
        $segments = explode('.', $key);
        if ($segments === [] || $segments === ['']) {
            return;
        }

        $this->unsetOverridePath($this->runtime, $segments);
        $this->rebuild();
    }

    /**
     * Recursively unset a path inside the runtime override tree and
     * prune any parent arrays that become empty as a result.
     */
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

    /**
     * Retrieve a configuration value using dot notation.
     */
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

    /**
     * Determine whether the given key exists in the effective config.
     */
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

    /**
     * Read a config value as a boolean.
     *
     * env() returns raw strings, so "false", "0", "no" and "off" must be
     * interpreted as false instead of PHP's default truthiness where the
     * non-empty string "false" would otherwise evaluate to true.
     */
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

    /**
     * Whether TLS certificates should be verified when talking to Telegram.
     *
     * Disabling this exposes the bot to man-in-the-middle attacks and must
     * only be used for local development against a self-signed proxy. The
     * default is true so that production deployments are secure-by-default.
     */
    public function isVerifySsl(): bool
    {
        return $this->getBool('bot.verify_ssl', true);
    }

    public function isDebugMode(): bool
    {
        return $this->getBool('app.debug', false);
    }

    /**
     * Get the database configuration for the default connection.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException If the connection is not found.
     */
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

        // Resolve relative SQLite path against the host application's base
        // path (REACTOR_BASE_PATH), not against the framework directory.
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

    /**
     * Load every registered source into the base config, then rebuild
     * the effective config (base + runtime overrides).
     *
     * Sources are merged in ascending priority order so that a source
     * with a higher priority overrides lower-priority values. Within the
     * same priority, insertion order is preserved because PHP's usort()
     * is stable as of PHP 8.0.
     */
    private function loadConfigFiles(): void
    {
        $this->baseConfig = [];

        $sorted = $this->sources;
        usort($sorted, fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($sorted as $source) {
            $directory = $source['path'];
            if (!is_dir($directory)) {
                continue;
            }

            $files = glob($directory . '/*.php');
            if ($files === false) {
                continue;
            }

            sort($files);

            foreach ($files as $file) {
                $group = pathinfo($file, PATHINFO_FILENAME);
                $values = require $file;
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
     * Recompute the effective config as base + runtime overrides.
     */
    private function rebuild(): void
    {
        $this->config = $this->deepMerge($this->baseConfig, $this->runtime);
    }

    /**
     * Recursively merge two arrays.
     *
     * Associative arrays are merged key-by-key. List arrays (sequential
     * numeric keys) and scalars are treated as atomic values, so the
     * override completely replaces the base value. This mirrors the way
     * Laravel's Config repository merges config files.
     */
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
