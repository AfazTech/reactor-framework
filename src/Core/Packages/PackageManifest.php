<?php
namespace Reactor\Core\Packages;

use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Paths;

/**
 * Reads the Composer installed packages and extracts Reactor package metadata.
 *
 * A package is considered a "Reactor package" when its composer.json declares
 * `"type": "reactor-package"` and optionally provides an `extra.reactor` block:
 *
 *   "extra": {
 *       "reactor": {
 *           "providers": ["Vendor\\Pkg\\Provider"],
 *           "aliases":   {"MyFacade": "Vendor\\Pkg\\Facade"},
 *           "migrations_namespace": "Vendor\\Pkg\\Database\\Migrations"
 *       }
 *   }
 *
 * The extracted metadata is cached in bootstrap/cache/packages.php so that
 * scanning installed.json only happens once per install (or when the cache
 * becomes stale relative to installed.json, e.g. after composer install).
 */
class PackageManifest
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $packages = [];

    private string $manifestPath;
    private string $installedPath;
    private ?LoggerInterface $logger;

    public function __construct(
        private Paths $paths,
        private string $vendorPath,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger;
        $this->manifestPath = $paths->path('bootstrap/cache/packages.php');
        $this->installedPath = rtrim($vendorPath, '/') . '/composer/installed.json';

        $this->load();
    }

    private function load(): void
    {
        if (file_exists($this->manifestPath) && !$this->isCacheStale()) {
            $data = $this->readCache();
            if ($data !== null) {
                $this->packages = $data;
                return;
            }
        }

        $this->packages = $this->scan();
        $this->write();
    }

    private function readCache(): ?array
    {
        try {
            $data = require $this->manifestPath;
        } catch (\Throwable $e) {
            $this->logger?->warning('Package manifest cache is unreadable, rebuilding', [
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

    private function isCacheStale(): bool
    {
        if (!file_exists($this->installedPath)) {
            return true;
        }
        if (!file_exists($this->manifestPath)) {
            return true;
        }
        return filemtime($this->manifestPath) < filemtime($this->installedPath);
    }

    /**
     * Scan vendor/composer/installed.json for Reactor packages.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scan(): array
    {
        if (!file_exists($this->installedPath)) {
            return [];
        }

        $raw = file_get_contents($this->installedPath);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Composer 2 wraps packages inside a "packages" key; Composer 1
        // exposed a flat list. Support both shapes.
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
                'config_path'          => is_dir($configPath) ? $configPath : null,
                'migrations_path'      => is_dir($migrationsPath) ? $migrationsPath : null,
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
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $export = var_export($this->packages, true);
        $content = "<?php\n\n// Auto-generated by Reactor PackageManifest. Do not edit.\n\nreturn " . $export . ";\n";

        if (@file_put_contents($this->manifestPath, $content) === false) {
            $this->logger?->warning('Could not write package manifest cache', [
                'path' => $this->manifestPath,
            ]);
            return;
        }

        $this->logger?->debug('Package manifest written', [
            'path'  => $this->manifestPath,
            'count' => count($this->packages),
        ]);
    }

    /**
     * Force a rebuild of the cached package manifest.
     */
    public function rebuild(): void
    {
        if (file_exists($this->manifestPath)) {
            @unlink($this->manifestPath);
        }
        $this->packages = $this->scan();
        $this->write();
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
