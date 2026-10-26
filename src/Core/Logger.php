<?php

namespace Reactor\Core;

use Monolog\Logger as MonologLogger;
use Monolog\Level;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Logging\AsyncRotatingFileHandler;

/**
 * PSR-3 compatible logger using Monolog with a non-blocking rotating
 * file handler backed by amphp/file.
 *
 * The public API stays synchronous (void return types), matching the
 * framework's LoggerInterface contract. Internally each write runs
 * inside a fiber, so the Amp event loop is not blocked while the file
 * system operation is pending.
 */
class Logger implements LoggerInterface
{
    private MonologLogger $logger;
    private bool $debugMode;

    public function __construct(?string $logFile = null, bool $debugMode = false)
    {
        $this->debugMode = $debugMode;

        if ($logFile === null) {
            $logFile = rtrim(sys_get_temp_dir(), '/') . '/reactor-logs/app.log';
        }

        $directory = dirname($logFile);
        $prefix = pathinfo($logFile, PATHINFO_FILENAME);

        $logger = new MonologLogger('telegram-bot');
        $level = $debugMode ? Level::Debug : Level::Info;

        $handler = new AsyncRotatingFileHandler(
            directory: $directory,
            filenamePrefix: $prefix,
            maxFiles: 30,
            level: $level,
        );

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
