<?php
namespace Reactor\Queue;

use Reactor\Contracts\QueueManagerInterface;
use App\Models\Job;

class DatabaseQueue implements QueueManagerInterface
{
    public function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): void
    {
        Job::create([
            'queue' => $queue,
            'payload' => json_encode(['job' => $jobClass, 'data' => $data]),
            'attempts' => 0,
            'available_at' => time() + $delay,
            'created_at' => time(),
        ]);
    }

    public function pop(string $queue = 'default'): ?array
    {
        $job = Job::where('queue', $queue)
            ->where('reserved_at', null)
            ->where('available_at', '<=', time())
            ->orderBy('id')
            ->first();

        if (!$job) {
            return null;
        }

        $job->reserved_at = time();
        $job->attempts++;
        $job->save();

        return [
            'id' => $job->id,
            'payload' => json_decode($job->payload, true),
            'attempts' => $job->attempts,
        ];
    }

    public function release(array $job, int $delay = 0): void
    {
        Job::where('id', $job['id'])->update([
            'reserved_at' => null,
            'available_at' => time() + $delay,
        ]);
    }

    public function delete(array $job): void
    {
        Job::destroy($job['id']);
    }
}
