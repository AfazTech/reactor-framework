<?php

namespace Reactor\Contracts;

use Amp\Cancellation;

/**
 * A job that supports cooperative timeout cancellation.
 *
 * Pass $cancellation to every cancellable I/O operation and check it before
 * starting additional side effects. Cancellation does not undo effects that
 * have already completed: jobs should still be idempotent across retries.
 */
interface CancellableJobInterface extends JobInterface
{
    public function handle(array $data, ?Cancellation $cancellation = null): void;
}
