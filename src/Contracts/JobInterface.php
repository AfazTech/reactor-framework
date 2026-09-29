<?php
namespace Reactor\Contracts;

/**
 * Contract for queue jobs.
 *
 * Jobs receive their input as an associative array so that they remain
 * compatible with any serialization format and can evolve their parameter
 * set without breaking the interface contract.
 */
interface JobInterface
{
    /**
     * Execute the job with the given data payload.
     *
     * @param array $data Associative array of job arguments.
     */
    public function handle(array $data): void;
}
