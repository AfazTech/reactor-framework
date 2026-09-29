<?php
namespace Reactor\Queue;

use Reactor\Contracts\QueueManagerInterface;
use Reactor\Contracts\DatabaseManagerInterface;

/**
 * Database-backed queue driver.
 *
 * This implementation is intentionally decoupled from any application-level
 * Eloquent model and operates directly on the queue table through the
 * framework's DatabaseManagerInterface.
 *
 * Concurrency: pop() runs inside a transaction. On drivers that support
 * row-level locking (MySQL/PostgreSQL) a FOR UPDATE lock is applied so
 * that two workers cannot reserve the same job. On SQLite, transactions
 * are serialized by the engine itself, so no explicit row lock is needed.
 */
class DatabaseQueue implements QueueManagerInterface
{
    private DatabaseManagerInterface $db;
    private string $table;

    /**
     * @param DatabaseManagerInterface $db    Database manager instance.
     * @param string                   $table Queue table name.
     */
    public function __construct(DatabaseManagerInterface $db, string $table = 'jobs')
    {
        $this->db = $db;
        $this->table = $table;
    }

    /**
     * {@inheritdoc}
     */
    public function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): void
    {
        $this->db->table($this->table)->insert([
            'queue'        => $queue,
            'payload'      => json_encode(['job' => $jobClass, 'data' => $data]),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time() + $delay,
            'created_at'   => time(),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function pop(string $queue = 'default'): ?array
    {
        $this->db->beginTransaction();

        try {
            $query = $this->db->table($this->table)
                ->where('queue', $queue)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', time())
                ->orderBy('id');

            if ($this->supportsRowLocking()) {
                $query = $query->lockForUpdate();
            }

            $job = $query->first();

            if (!$job) {
                $this->db->commit();
                return null;
            }

            $attempts = (int) $job->attempts + 1;

            $this->db->table($this->table)
                ->where('id', $job->id)
                ->update([
                    'reserved_at' => time(),
                    'attempts'    => $attempts,
                ]);

            $this->db->commit();

            return [
                'id'       => $job->id,
                'payload'  => json_decode($job->payload, true),
                'attempts' => $attempts,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function release(array $job, int $delay = 0): void
    {
        $this->db->table($this->table)
            ->where('id', $job['id'])
            ->update([
                'reserved_at'  => null,
                'available_at' => time() + $delay,
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(array $job): void
    {
        $this->db->table($this->table)
            ->where('id', $job['id'])
            ->delete();
    }

    /**
     * Whether the active driver supports SELECT ... FOR UPDATE.
     */
    private function supportsRowLocking(): bool
    {
        $driver = $this->db->getConfig()['driver'] ?? 'sqlite';
        return !in_array($driver, ['sqlite'], true);
    }
}
