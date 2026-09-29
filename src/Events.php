<?php
namespace Reactor;

/**
 * Central registry of event names used across the framework.
 *
 * This class provides constants for all dispatched events, ensuring consistency
 * and enabling autocomplete in IDEs.
 */
class Events
{
    /**
     * Dispatched when a new user is registered.
     *
     * @var string
     */
    const USER_REGISTERED = 'user.registered';

    /**
     * Dispatched when the user's current step changes.
     *
     * @var string
     */
    const USER_STEP_CHANGED = 'user.step.changed';

    /**
     * Dispatched before processing an incoming update.
     *
     * @var string
     */
    const UPDATE_PROCESSING_STARTED = 'update.processing.started';

    /**
     * Dispatched after processing an incoming update.
     *
     * @var string
     */
    const UPDATE_PROCESSING_FINISHED = 'update.processing.finished';

    /**
     * Dispatched when an error occurs.
     *
     * @var string
     */
    const ERROR_OCCURRED = 'error.occurred';

    /**
     * Dispatched when a middleware fails.
     *
     * @var string
     */
    const MIDDLEWARE_FAILED = 'middleware.failed';

    /**
     * Dispatched after a handler is executed.
     *
     * @var string
     */
    const HANDLER_EXECUTED = 'handler.executed';
}
