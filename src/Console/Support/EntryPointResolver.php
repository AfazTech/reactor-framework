<?php

declare(strict_types=1);

namespace Reactor\Console\Support;

use Reactor\Core\Container;
use Reactor\Core\Paths;

/**
 * Resolves the absolute path to the application's CLI entry point.
 *
 * The resolver never assumes a fixed filename. It first looks for the
 * project-level default (base()/reactor), then falls back to an explicit
 * override registered in the container, then to discovery through
 * $_SERVER['SCRIPT_FILENAME'] and $GLOBALS['argv'][0].
 *
 * The result is cached for the lifetime of the resolver so repeated calls
 * (e.g. BotProcessManager and QueueListenCommand in the same command) cannot
 * drift if the working directory changes in between.
 */
final class EntryPointResolver
{
    private ?string $resolved = null;
    private bool $resolvedOnce = false;

    public function __construct(
        private Paths $paths,
        private Container $container,
    ) {
    }

    /**
     * Resolve the entry point path, or null when nothing can be found.
     */
    public function resolve(): ?string
    {
        if ($this->resolvedOnce) {
            return $this->resolved;
        }
        $this->resolvedOnce = true;

        // 1. Project-level default: base()/reactor
        $default = $this->paths->base() . '/reactor';
        if (is_file($default)) {
            $this->resolved = realpath($default) ?: $default;
            return $this->resolved;
        }

        // 2. Explicit override set through the container.
        if ($this->container->has('entry_point')) {
            $explicit = $this->container->get('entry_point');
            if (is_string($explicit) && is_file($explicit)) {
                $this->resolved = realpath($explicit) ?: $explicit;
                return $this->resolved;
            }
        }

        // 3. $_SERVER['SCRIPT_FILENAME'] — authoritative in CLI, absolute.
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (is_string($scriptFilename) && $scriptFilename !== '' && is_file($scriptFilename)) {
            $this->resolved = realpath($scriptFilename) ?: $scriptFilename;
            return $this->resolved;
        }

        // 4. $GLOBALS['argv'][0] — the path the user typed, possibly relative.
        $argv0 = $GLOBALS['argv'][0] ?? null;
        if (is_string($argv0) && $argv0 !== '') {
            $normalized = $this->normalize($argv0);
            if ($normalized !== null) {
                $this->resolved = $normalized;
                return $this->resolved;
            }
        }

        // 5. Nothing found.
        return null;
    }

    /**
     * Resolve the entry point path or throw a descriptive exception.
     */
    public function resolveOrFail(): string
    {
        $path = $this->resolve();
        if ($path === null) {
            throw new \RuntimeException(
                'Cannot resolve the application CLI entry point. '
                . 'Expected a "reactor" file at ' . $this->paths->base() . ', '
                . "or an explicit entry point via Container::instance('entry_point', __FILE__) "
                . 'or Kernel::setEntryPoint(__FILE__).'
            );
        }
        return $path;
    }

    /**
     * Normalize a possibly relative path against the current working directory.
     */
    private function normalize(string $path): ?string
    {
        if (is_file($path)) {
            return realpath($path) ?: $path;
        }

        $cwd = getcwd();
        if ($cwd !== false) {
            $candidate = rtrim($cwd, '/') . '/' . ltrim($path, '/');
            if (is_file($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }
}
