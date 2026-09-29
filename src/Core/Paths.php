<?php
namespace Reactor\Core;

/**
 * Helper to retrieve common directory paths relative to the base path.
 */
class Paths
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    public function base(): string
    {
        return $this->basePath;
    }

    public function config(): string
    {
        return $this->basePath . '/config';
    }

    public function storage(): string
    {
        return $this->basePath . '/storage';
    }

    public function database(): string
    {
        return $this->basePath . '/database';
    }

    public function logs(): string
    {
        return $this->basePath . '/storage/logs';
    }

    public function lang(): string
    {
        return $this->basePath . '/lang';
    }

    public function app(): string
    {
        return $this->basePath . '/app';
    }

    public function public(): string
    {
        return $this->basePath . '/public_html';
    }

    public function path(string $path = ''): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }
}
