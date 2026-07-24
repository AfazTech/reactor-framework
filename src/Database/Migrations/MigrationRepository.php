<?php
namespace Reactor\Database\Migrations;

use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Container;

class MigrationRepository
{
    private DatabaseManagerInterface $db;
    private LoggerInterface $logger;
    private string $table = 'migrations';
    private string $migrationPath;
    private Container $container;

    public function __construct(DatabaseManagerInterface $db, LoggerInterface $logger, string $migrationPath, Container $container)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->migrationPath = $migrationPath;
        $this->container = $container;
        $this->ensureMigrationsTable();
    }

    private function ensureMigrationsTable(): void
    {
        if (!$this->db->schema()->hasTable($this->table)) {
            $this->db->schema()->create($this->table, function ($table) {
                $table->id();
                $table->string('migration')->unique();
                $table->integer('batch');
                $table->timestamps();
            });
            $this->logger->info('Migrations table created');
        }
    }

    public function getMigrations(): array
    {
        $files = glob($this->migrationPath . '/*.php');
        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $migrations[$name] = $file;
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
        foreach ($all as $name => $file) {
            $row = $ranRows->get($name);
            $status[] = [
                'migration' => $name,
                'batch' => $row->batch ?? null,
                'ran' => $row !== null,
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
        $file = $this->migrationPath . '/' . $name . '.php';
        if (file_exists($file)) {
            require_once $file;
        }

        $className = 'Reactor\\Migrations\\' . $this->classNameFromMigration($name);
        if (!class_exists($className)) {
            throw new \RuntimeException("Migration class not found: $className (from file: $name)");
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
            'migration' => $name,
            'batch' => $batch,
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
