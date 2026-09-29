<?php

if (!function_exists('base_path')) {
    /**
     * Resolve the application base path.
     *
     * When REACTOR_BASE_PATH is defined (by the App constructor), it takes
     * precedence so helpers resolve against the host application rather
     * than the framework's own directory inside the vendor tree.
     *
     * When the constant is not defined (for example, when the framework
     * is used without the application skeleton) the current working
     * directory is used. This matches the common case of running a
     * project from its root without an explicit bootstrap.
     */
    function base_path(string $path = ''): string
    {
        if (defined('REACTOR_BASE_PATH')) {
            $base = REACTOR_BASE_PATH;
        } else {
            $cwd = getcwd();
            $base = $cwd !== false ? $cwd : dirname(__DIR__, 2);
        }

        return $path === ''
            ? $base
            : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Convenience helper that reads a configuration value using the
     * host application's config directory.
     *
     * The helper creates a lightweight Config instance on first use and
     * caches it for the rest of the request. It resolves the config
     * directory through base_path('config') so it works both with and
     * without the application skeleton. When the directory does not
     * exist the Config instance is created empty, and only runtime
     * overrides registered elsewhere will be visible.
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $config = null;
        if ($config === null) {
            $configPath = base_path('config');
            $config = new \Reactor\Core\Config(
                is_dir($configPath) ? $configPath : null
            );
        }
        return $config->get($key, $default);
    }
}

if (!function_exists('database_path')) {
    function database_path(string $path = ''): string
    {
        return base_path('database' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }
}
