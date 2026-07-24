<?php

namespace Reactor\Core;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Reactor\Contracts\LoggerInterface;

/**
 * PSR‑3 compatible logger using Monolog with a rotating file handler.
 */
class Logger implements LoggerInterface
{
    private MonologLogger $logger;
    private bool $debugMode;

    /**
     * Constructor.
     *
     * @param string|null $logFile   Path to the log file. Defaults to storage/logs/app.log.
     * @param bool        $debugMode Whether debug messages are enabled.
     */
    public function __construct(?string $logFile = null, bool $debugMode = false)
    {
        $this->debugMode = $debugMode;
        $logFile = $logFile ?? __DIR__ . '/../../storage/logs/app.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logger = new MonologLogger('telegram-bot');
        $level = $debugMode ? Level::Debug : Level::Info;
        $handler = new RotatingFileHandler($logFile, 30, $level);
        $logger->pushHandler($handler);

        $logger->info('Logger initialized', [
            'debug_mode' => $debugMode,
            'log_file'   => $logFile,
            'timestamp'  => date('Y-m-d H:i:s'),
        ]);

        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function debug(string $message, array $context = []): void
    {
        if ($this->debugMode) {
            $this->logger->debug($message, $context);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }
}
