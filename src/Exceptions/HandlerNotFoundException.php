<?php
namespace Reactor\Exceptions;

/**
 * Thrown when no handler can be found for an update (rare, as fallback exists).
 */
class HandlerNotFoundException extends ReactorException
{
}
