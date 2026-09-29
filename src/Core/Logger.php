<?php

namespace Reactor\Core;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Reactor\Contracts\LoggerInterface;

/**
 * PSR-3 compatible logger using Monolog with a rotating file handler.
 *
 * The constructor only wires the underlying Monolog instance. It performs
 * no logging itself so that merely instantiating the logger (for example
 * during container auto-resolution) has no visible side effects.
 *
 * When no explicit log file path is provided, the logger writes to a
 * per-installation file under the system temp directory. This keeps the
 * framework usable without the application skeleton and avoids writing
 * into the Composer vendor tree (which is read-only on most systems).
 */
class Logger implements LoggerInterface
{
    private MonologLogger $logger;
    private bool $debugMode;

    public function __construct(?string $logFile = null, bool $debugMode = false)
    {
        $this->debugMode = $debugMode;

        if ($logFile === null) {
            // Do not assume the application skeleton exists. Write to a
            // predictable location under the system temp directory so a
            // direct `new Logger()` never tries to create files inside
            // the framework package or vendor directory.
            $logFile = rtrim(sys_get_temp_dir(), '/') . '/reactor-logs/app.log';
        }

        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logger = new MonologLogger('telegram-bot');
        $level = $debugMode ? Level::Debug : Level::Info;
        $handler = new RotatingFileHandler($logFile, 30, $level);
        $logger->pushHandler($handler);

        $this->logger = $logger;
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        if ($this->debugMode) {
            $this->logger->debug($message, $context);
        }
    }

    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }
}
