<?php

if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('config')) {
    function config($key, $default = null) {
        static $config = null;
        if ($config === null) {
            $config = new \Reactor\Core\Config();
        }
        return $config->get($key, $default);
    }
}

if (!function_exists('database_path')) {
    function database_path($path = '') {
        return __DIR__ . '/../database/' . $path;
    }
}

if (!function_exists('storage_path')) {
    function storage_path($path = '') {
        return __DIR__ . '/../storage/' . $path;
    }
}
