<?php
namespace Reactor\Contracts;

interface QueueManagerInterface
{
    public function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): void;
    public function pop(string $queue = 'default'): ?array;
    public function release(array $job, int $delay = 0): void;
    public function delete(array $job): void;
}
