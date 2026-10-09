<?php
namespace Reactor\Queue;

/**
 * Value object holding the runtime options of a queue worker.
 *
 * The options are populated from the CLI flags of queue:work and passed
 * unchanged to Worker::work().
 */
final class WorkerOptions
{
    /**
     * @param string|array<int, string> $queue          Single queue name or ordered list (priority).
     * @param int                       $tries          Maximum attempts per job before it is marked as failed.
     * @param int                       $timeout        Seconds a single job may run (0 = no timeout).
     * @param int                       $sleep          Seconds to sleep between empty-queue polls.
     * @param int                       $maxJobs        Stop after this many jobs (0 = unlimited).
     * @param int                       $maxTime        Stop after this many seconds (0 = unlimited).
     * @param int                       $memory         Stop after this many MB of resident memory (0 = unlimited).
     * @param bool                      $once           Process a single job and exit.
     * @param bool                      $stopWhenEmpty  Process until the queue is empty, then exit.
     */
    public function __construct(
        public string|array $queue = 'default',
        public int $tries = 3,
        public int $timeout = 60,
        public int $sleep = 3,
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public int $memory = 128,
        public bool $once = false,
        public bool $stopWhenEmpty = false,
    ) {
    }
}
