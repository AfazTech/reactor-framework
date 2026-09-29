<?php

namespace Reactor\Enums;

/**
 * Defines the execution scope of middleware.
 */
enum MiddlewareMode
{
    /** Runs on every update */
    case GLOBAL;

    /** Runs only for handlers in specific groups */
    case GROUP;

    /** Runs only for handlers that explicitly list it */
    case LOCAL;
}