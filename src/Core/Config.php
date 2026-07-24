<?php

namespace Reactor\Core;

/**
 * Configuration manager that loads and provides access to config files.
 *
 * Config files are PHP files located in the `config/` directory.
 * Values can be accessed using dot notation (e.g., 'bot.token').
 */
class Config
{
    private array $config = [];
    private string $configPath;

    /**
     * Constructor.
     *
     * @param string|null $configPath Path to the config directory. Defaults to '../../config'.
     */
    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? __DIR__ . '/../../config';
        $this->loadConfigFiles();
    }

    /**
     * Set the config path and reload all files.
     *
     * @param string $path New config directory path.
     */
    public function setConfigPath(string $path): void
    {
        $this->configPath = $path;
        $this->config = [];
        $this->loadConfigFiles();
    }

    /**
     * Load all PHP files from the config directory.
     */
    private function loadConfigFiles(): void
    {
        if (!is_dir($this->configPath)) {
            return;
        }

        $files = glob($this->configPath . '/*.php');
        foreach ($files as $file) {
            $key = pathinfo($file, PATHINFO_FILENAME);
            $this->config[$key] = require $file;
        }
    }

    /**
     * Get a configuration value using dot notation.
     *
     * @param string $key     Dot‑separated key (e.g., 'app.debug').
     * @param mixed  $default Default value if key not found.
     *
     * @return mixed
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

    // --- Shortcut methods for common config keys ---

    /**
     * Get the bot token.
     *
     * @return string
     */
    public function getToken(): string
    {
        return $this->get('bot.token', '');
    }

    /**
     * Get the Telegram API URL.
     *
     * @return string
     */
    public function getApiUrl(): string
    {
        return $this->get('bot.api_url', 'https://api.telegram.org/bot');
    }

    /**
     * Check if debug mode is enabled.
     *
     * @return bool
     */
    public function isDebugMode(): bool
    {
        return (bool) $this->get('app.debug', false);
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

        // Resolve relative SQLite path.
        if ($config['driver'] === 'sqlite' && isset($config['database'])) {
            $dbPath = $config['database'];
            if (!str_starts_with($dbPath, '/') && !str_starts_with($dbPath, __DIR__)) {
                $config['database'] = __DIR__ . '/../../' . $dbPath;
            }
        }

        return $config;
    }

    /**
     * Get the bot mode (polling or webhook).
     *
     * @return string
     */
    public function getBotMode(): string
    {
        return $this->get('bot.mode', 'polling');
    }

    /**
     * Check if multi‑process polling is enabled.
     *
     * @return bool
     */
    public function isMultiProcess(): bool
    {
        return (bool) $this->get('bot.multi_process', false);
    }

    /**
     * Get the webhook secret.
     *
     * @return string
     */
    public function getWebhookSecret(): string
    {
        return $this->get('bot.webhook_secret', '');
    }

    /**
     * Get the PHP binary path.
     *
     * @return string
     */
    public function getPhpBinary(): string
    {
        return $this->get('bot.php_binary', '/usr/bin/php');
    }

    // --- Setters for runtime modifications ---

    /**
     * Set the bot token at runtime.
     *
     * @param string $token
     */
    public function setToken(string $token): void
    {
        $this->config['bot']['token'] = $token;
    }

    /**
     * Set the API URL at runtime.
     *
     * @param string $apiUrl
     */
    public function setApiUrl(string $apiUrl): void
    {
        $this->config['bot']['api_url'] = $apiUrl;
    }

    /**
     * Enable or disable debug mode at runtime.
     *
     * @param bool $debug
     */
    public function setDebugMode(bool $debug): void
    {
        $this->config['app']['debug'] = $debug;
    }
}
