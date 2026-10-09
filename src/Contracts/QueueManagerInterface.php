<?php
namespace Reactor\Contracts;

interface QueueManagerInterface
{
    public function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): void;

    /**
     * Pop the next available job.
     *
     * When $queue is an array, queues are consulted in the given order
     * (priority). The first non-empty queue wins.
     *
     * @param string|array<int, string> $queue
     * @return array{id: int|string, queue: string, payload: array, attempts: int}|null
     */
    public function pop(string|array $queue = 'default'): ?array;

    public function release(array $job, int $delay = 0): void;

    public function delete(array $job): void;

    /**
     * Count the number of pending (unreserved, ready) jobs in a queue.
     */
    public function size(string $queue): int;
}
