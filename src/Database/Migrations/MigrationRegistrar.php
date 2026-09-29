<?php
namespace Reactor\Database\Migrations;

use Reactor\Contracts\LoggerInterface;

/**
 * Central registry of migration sources.
 *
 * The application and any packages or extensions can register their own
 * migration directory together with the namespace their migration classes
 * declare. This decouples the migration system from any hard-coded path
 * and namespace, which is required for a first-class package ecosystem.
 *
 * Registration is idempotent: calling add() twice with the same path is
 * a no-op. This makes it safe to register the application source from
 * multiple entry points without producing duplicate scans.
 */
class MigrationRegistrar
{
    /**
     * @var array<int, MigrationSource>
     */
    private array $sources = [];

    /**
     * @var array<string, true>
     */
    private array $seenPaths = [];

    /**
     * @var array<string, string>
     */
    private array $pathToNamespace = [];

    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Register a migration source.
     *
     * @param string $path      Absolute or relative directory path.
     * @param string $namespace PHP namespace declared by migration classes.
     *
     * @return bool True when the source was added, false when it was
     *              ignored (empty path/namespace or duplicate path).
     */
    public function add(string $path, string $namespace): bool
    {
        $path = rtrim($path, '/');
        $namespace = trim($namespace, '\\');

        if ($path === '' || $namespace === '') {
            $this->logger?->warning('Migration source ignored: empty path or namespace', [
                'path'      => $path,
                'namespace' => $namespace,
            ]);
            return false;
        }

        if (isset($this->seenPaths[$path])) {
            $existing = $this->pathToNamespace[$path] ?? '';
            if ($existing !== $namespace) {
                $this->logger?->warning('Migration source re-registered with different namespace, ignoring', [
                    'path'                => $path,
                    'existing_namespace'  => $existing,
                    'requested_namespace' => $namespace,
                ]);
            } else {
                $this->logger?->debug('Migration source already registered, skipping', ['path' => $path]);
            }
            return false;
        }

        $this->seenPaths[$path] = true;
        $this->pathToNamespace[$path] = $namespace;
        $this->sources[] = new MigrationSource($path, $namespace);

        $this->logger?->debug('Migration source registered', [
            'path'      => $path,
            'namespace' => $namespace,
        ]);

        return true;
    }

    /**
     * @return array<int, MigrationSource>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function hasAny(): bool
    {
        return $this->sources !== [];
    }
}
