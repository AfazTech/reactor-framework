<?php

namespace Reactor\Core\Packages;

use Amp\File;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Paths;

/**
 * Reads the Composer installed packages and extracts Reactor package metadata.
 *
 * Performance in webhook mode:
 *   - When `app.debug` is false (production), the cache staleness check is
 *     skipped entirely. The manifest is assumed to be up to date because
 *     `composer install` / `composer update` regenerate it via the
 *     `package:discover` post-autoload hook.
 *   - When `app.debug` is true (development), the staleness result is
 *     cached in APCu (when available) for a short interval so that the
 *     two stat syscalls do not run on every request.
 *
 * All file access goes through amphp/file so that a persistent-worker
 * deployment can overlap manifest loading with other I/O.
 */
class PackageManifest
{
    private const STALENESS_CACHE_KEY = 'reactor:manifest_stale';
    private const STALENESS_TTL = 5;

    private array $packages = [];
    private string $manifestPath;
    private string $installedPath;
    private ?LoggerInterface $logger;
    private bool $debugMode;

    public function __construct(
        private Paths $paths,
        private string $vendorPath,
        ?LoggerInterface $logger = null,
        bool $debugMode = false,
    ) {
        $this->logger = $logger;
        $this->debugMode = $debugMode;
        $this->manifestPath = $paths->path('bootstrap/cache/packages.php');
        $this->installedPath = rtrim($vendorPath, '/') . '/composer/installed.json';

        $this->load();
    }

    private function load(): void
    {
        if ($this->canUseCache()) {
            $data = $this->readCache();
            if ($data !== null) {
                $this->packages = $data;
                return;
            }
        }

        $this->packages = $this->scan();
        $this->write();
    }

    private function canUseCache(): bool
    {
        if (!File\exists($this->manifestPath)) {
            return false;
        }

        if (!$this->debugMode) {
            return true;
        }

        return !$this->isStale();
    }

    private function readCache(): ?array
    {
        try {
            $content = File\read($this->manifestPath);
        } catch (\Throwable $e) {
            $this->logger?->warning('Package manifest cache is unreadable, rebuilding', [
                'path'  => $this->manifestPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (str_starts_with($content, '<?php')) {
            $content = substr($content, 5);
        }

        try {
            $data = eval($content);
        } catch (\Throwable $e) {
            $this->logger?->warning('Package manifest cache failed to evaluate, rebuilding', [
                'path'  => $this->manifestPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function isStale(): bool
    {
        $cached = $this->readStalenessFromCache();
        if ($cached !== null) {
            return $cached;
        }

        if (!File\exists($this->installedPath) || !File\exists($this->manifestPath)) {
            $stale = true;
        } else {
            try {
                $stale = File\getModificationTime($this->manifestPath)
                    < File\getModificationTime($this->installedPath);
            } catch (\Throwable $e) {
                $stale = true;
            }
        }

        $this->writeStalenessToCache($stale);

        return $stale;
    }

    private function readStalenessFromCache(): ?bool
    {
        if (!function_exists('apcu_fetch')) {
            return null;
        }

        $success = false;
        $value = apcu_fetch(self::STALENESS_CACHE_KEY, $success);

        return $success ? (bool) $value : null;
    }

    private function writeStalenessToCache(bool $stale): void
    {
        if (function_exists('apcu_store')) {
            apcu_store(self::STALENESS_CACHE_KEY, $stale, self::STALENESS_TTL);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scan(): array
    {
        if (!File\exists($this->installedPath)) {
            return [];
        }

        try {
            $raw = File\read($this->installedPath);
        } catch (\Throwable $e) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $list = $decoded['packages'] ?? $decoded;
        if (!is_array($list)) {
            return [];
        }

        $packages = [];

        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['type'] ?? '') !== 'reactor-package') {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $extra = $entry['extra']['reactor'] ?? [];
            if (!is_array($extra)) {
                $extra = [];
            }

            $installPath = rtrim($this->vendorPath, '/') . '/' . $name;
            $configPath = $installPath . '/config';
            $migrationsPath = $installPath . '/database/migrations';

            $packages[] = [
                'name'                 => $name,
                'path'                 => $installPath,
                'providers'            => $this->normalizeStringList($extra['providers'] ?? []),
                'aliases'              => $this->normalizeAssoc($extra['aliases'] ?? []),
                'migrations_namespace' => (string) ($extra['migrations_namespace'] ?? ''),
                'config_path'          => File\isDirectory($configPath) ? $configPath : null,
                'migrations_path'      => File\isDirectory($migrationsPath) ? $migrationsPath : null,
            ];
        }

        return $packages;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * @return array<string, string>
     */
    private function normalizeAssoc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item) && $key !== '' && $item !== '') {
                $result[$key] = $item;
            }
        }
        return $result;
    }

    private function write(): void
    {
        $dir = dirname($this->manifestPath);
        if (!File\isDirectory($dir)) {
            File\createDirectoryRecursively($dir, 0755);
        }

        $export = var_export($this->packages, true);
        $content = "<?php\n\n// Auto-generated by Reactor PackageManifest. Do not edit.\n\nreturn " . $export . ";\n";

        try {
            File\write($this->manifestPath, $content);
        } catch (\Throwable $e) {
            $this->logger?->warning('Could not write package manifest cache', [
                'path'  => $this->manifestPath,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $this->logger?->debug('Package manifest written', [
            'path'  => $this->manifestPath,
            'count' => count($this->packages),
        ]);
    }

    public function rebuild(): void
    {
        if (File\exists($this->manifestPath)) {
            try {
                File\deleteFile($this->manifestPath);
            } catch (\Throwable $e) {
                // Ignore — write() will overwrite it.
            }
        }

        $this->packages = $this->scan();
        $this->write();

        if (function_exists('apcu_delete')) {
            apcu_delete(self::STALENESS_CACHE_KEY);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->packages;
    }

    public function hasAny(): bool
    {
        return $this->packages !== [];
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }
}
