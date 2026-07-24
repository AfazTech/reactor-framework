<?php
namespace Reactor\Contracts;

/**
 * Contract for a logger that supports different severity levels.
 */
interface LoggerInterface
{
    /**
     * Log an informational message.
     *
     * @param string $message
     * @param array  $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * Log an error message.
     *
     * @param string $message
     * @param array  $context
     */
    public function error(string $message, array $context = []): void;

    /**
     * Log a warning message.
     *
     * @param string $message
     * @param array  $context
     */
    public function warning(string $message, array $context = []): void;

    /**
     * Log a debug message (only if debug mode is enabled).
     *
     * @param string $message
     * @param array  $context
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Log a critical message.
     *
     * @param string $message
     * @param array  $context
     */
    public function critical(string $message, array $context = []): void;
}
