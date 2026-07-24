<?php
namespace Reactor\Contracts;

/**
 * Contract for all middleware classes.
 *
 * Middleware can halt processing by returning `true`.
 */
interface MiddlewareInterface
{
    /**
     * Process the update.
     *
     * @param array $update The incoming Telegram update.
     * @return bool True to stop further processing, false to continue.
     */
    public function handle(array $update): bool;
}
