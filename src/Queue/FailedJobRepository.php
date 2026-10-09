<?php
namespace Reactor\Queue;

use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;

/**
 * Persistence layer for jobs that exhausted their retries.
 *
 * The failed_jobs table is created lazily the first time it is needed,
 * so applications that never trigger a failure pay no schema cost. The
 * queue:failed-table command is provided for users who prefer a
 * versioned migration instead.
 */
class FailedJobRepository
{
    private const TABLE = 'failed_jobs';

    public function __construct(
        private DatabaseManagerInterface $db,
        private LoggerInterface $logger,
    ) {
    }

    public function ensureTable(): void
    {
        if ($this->db->schema()->hasTable(self::TABLE)) {
            return;
        }

        $this->db->schema()->create(self::TABLE, function ($table) {
            $table->id();
            $table->string('queue')->index();
            $table->text('payload');
            $table->text('exception');
            $table->integer('failed_at')->index();
        });

        $this->logger->info('Failed jobs table created');
    }

    public function log(string $queue, string $payload, \Throwable $exception): int
    {
        $this->ensureTable();

        return (int) $this->db->table(self::TABLE)->insertGetId([
            'queue'     => $queue,
            'payload'   => $payload,
            'exception' => $this->formatException($exception),
            'failed_at' => time(),
        ]);
    }

    /**
     * @return array<int, object>
     */
    public function all(): array
    {
        $this->ensureTable();

        return $this->db->table(self::TABLE)
            ->orderBy('id', 'desc')
            ->get()
            ->toArray();
    }

    public function find(int $id): ?object
    {
        $this->ensureTable();

        return $this->db->table(self::TABLE)->where('id', $id)->first();
    }

    public function forget(int $id): bool
    {
        $this->ensureTable();

        return $this->db->table(self::TABLE)->where('id', $id)->delete() > 0;
    }

    public function flush(): int
    {
        $this->ensureTable();

        return (int) $this->db->table(self::TABLE)->delete();
    }

    public function pruneOlderThan(int $hours): int
    {
        $this->ensureTable();

        $threshold = time() - ($hours * 3600);

        return (int) $this->db->table(self::TABLE)
            ->where('failed_at', '<', $threshold)
            ->delete();
    }

    public function count(): int
    {
        $this->ensureTable();

        return (int) $this->db->table(self::TABLE)->count();
    }

    private function formatException(\Throwable $e): string
    {
        return get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
    }
}
