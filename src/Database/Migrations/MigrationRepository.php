<?php
namespace Reactor\Database\Migrations;

use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Container;

/**
 * Discovers, tracks, and loads migrations across every registered source.
 *
 * A single migration is uniquely identified by the basename of its file
 * (without the .php extension). If two sources contain a migration with
 * the same basename, the first registered source wins and a warning is
 * emitted, because the migrations table stores only that basename as the
 * record key and cannot distinguish between the two.
 *
 * The repository is intentionally side-effect-free on construction: the
 * `migrations` table is created lazily via ensureMigrationsTable(), which
 * Migrator invokes only when a migration operation actually needs it.
 * This keeps regular request lifecycles (webhook, polling bootstrap)
 * completely out of the migration infrastructure.
 */
class MigrationRepository
{
    private DatabaseManagerInterface $db;
    private LoggerInterface $logger;
    private string $table = 'migrations';
    private MigrationRegistrar $registrar;
    private Container $container;
    private bool $tableEnsured = false;

    public function __construct(
        DatabaseManagerInterface $db,
        LoggerInterface $logger,
        MigrationRegistrar $registrar,
        Container $container
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->registrar = $registrar;
        $this->container = $container;
    }

    /**
     * Ensure the migrations tracking table exists.
     *
     * Idempotent: after the first successful call within this instance,
     * subsequent calls are a no-op. Must be invoked explicitly by the
     * Migrator before any operation that reads or writes the tracking
     * table, so that mere construction of the repository performs no
     * database work.
     */
    public function ensureMigrationsTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        if (!$this->db->schema()->hasTable($this->table)) {
            $this->db->schema()->create($this->table, function ($table) {
                $table->id();
                $table->string('migration')->unique();
                $table->integer('batch');
                $table->timestamps();
            });
            $this->logger->info('Migrations table created');
        }

        $this->tableEnsured = true;
    }

    /**
     * Discover every migration across all registered sources.
     *
     * @return array<string, array{file: string, namespace: string, source: string}>
     */
    public function getMigrations(): array
    {
        $migrations = [];

        foreach ($this->registrar->getSources() as $source) {
            if (!is_dir($source->path)) {
                $this->logger->warning('Migration source directory not found', [
                    'path'      => $source->path,
                    'namespace' => $source->namespace,
                ]);
                continue;
            }

            $files = glob($source->path . '/*.php');
            if ($files === false) {
                continue;
            }
            sort($files);

            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);

                if (isset($migrations[$name])) {
                    $this->logger->warning('Duplicate migration name across sources, keeping first', [
                        'migration'      => $name,
                        'kept_source'    => $migrations[$name]['file'],
                        'skipped_source' => $file,
                    ]);
                    continue;
                }

                $migrations[$name] = [
                    'file'      => $file,
                    'namespace' => $source->namespace,
                    'source'    => $source->path,
                ];
            }
        }

        return $migrations;
    }

    public function getRanMigrations(): array
    {
        return $this->db->table($this->table)->pluck('migration')->toArray();
    }

    public function getPendingMigrations(): array
    {
        $all = $this->getMigrations();
        $ran = $this->getRanMigrations();
        return array_diff_key($all, array_flip($ran));
    }

    public function status(): array
    {
        $all = $this->getMigrations();
        $ranRows = $this->db->table($this->table)->get()->keyBy('migration');

        $status = [];
        foreach ($all as $name => $info) {
            $row = $ranRows->get($name);
            $status[] = [
                'migration' => $name,
                'batch'     => $row->batch ?? null,
                'ran'       => $row !== null,
                'source'    => $info['source'],
            ];
        }
        return $status;
    }

    public function getNextBatchNumber(): int
    {
        $last = $this->db->table($this->table)->max('batch');
        return $last ? (int) $last + 1 : 1;
    }

    public function classNameFromMigration(string $name): string
    {
        $withoutTimestamp = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);
        $parts = explode('_', $withoutTimestamp);
        $parts = array_map('ucfirst', $parts);
        return implode('', $parts);
    }

    public function loadMigration(string $name): Migration
    {
        $migrations = $this->getMigrations();
        if (!isset($migrations[$name])) {
            throw new \RuntimeException("Migration not found: $name");
        }

        $file = $migrations[$name]['file'];
        $namespace = $migrations[$name]['namespace'];

        $className = $namespace . '\\' . $this->classNameFromMigration($name);

        if (class_exists($className, false)) {
            // The class is already loaded. Ensure it came from the expected
            // file; otherwise two migrations resolve to the same class name
            // and loading would silently reuse the wrong implementation.
            $reflection = new \ReflectionClass($className);
            $loadedFrom = $reflection->getFileName();

            if ($loadedFrom !== false && realpath($loadedFrom) !== realpath($file)) {
                throw new \RuntimeException(
                    "Migration class {$className} is already loaded from a different file: "
                    . $loadedFrom . " (expected: {$file}). "
                    . "Two migrations cannot share the same class name."
                );
            }
        } else {
            require $file;
        }

        if (!class_exists($className, false)) {
            throw new \RuntimeException("Migration class not found after loading: $className (from file: $name)");
        }

        $instance = $this->container->get($className);
        if (!($instance instanceof Migration)) {
            throw new \RuntimeException("Migration must extend Reactor\\Database\\Migrations\\Migration: $className");
        }

        return $instance;
    }

    public function markRan(string $name, int $batch): void
    {
        $this->db->table($this->table)->insert([
            'migration'  => $name,
            'batch'      => $batch,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function remove(string $name): void
    {
        $this->db->table($this->table)->where('migration', $name)->delete();
    }

    public function getBatches(int $steps = 1): array
    {
        return $this->db->table($this->table)
            ->select('batch')
            ->distinct()
            ->orderBy('batch', 'desc')
            ->limit($steps)
            ->pluck('batch')
            ->toArray();
    }

    public function getMigrationsByBatch(int $batch): array
    {
        return $this->db->table($this->table)
            ->where('batch', $batch)
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();
    }
}
