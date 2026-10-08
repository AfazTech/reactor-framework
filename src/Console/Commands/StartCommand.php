<?php

namespace Reactor\Console\Commands;

use Reactor\Contracts\LoggerInterface;
use Reactor\Core\App;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Start the bot in the configured mode.
 *
 * In polling mode this command blocks: the underlying Poller runs until
 * it is interrupted with SIGINT (Ctrl+C) or SIGTERM. In webhook mode it
 * only prints the setup instructions and exits, because updates are
 * delivered by Telegram over HTTP in that case.
 */
#[AsCommand(name: 'start', description: 'Start the bot (polling or webhook mode)')]
class StartCommand extends Command
{
    public function __construct(
        private App $app,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $this->app->getConfig()->getBotMode();

        $output->writeln('========================================');
        $output->writeln('⚡ REACTOR BOT');
        $output->writeln('========================================');
        $output->writeln('✓ Bot initialized successfully');
        $output->writeln('✓ Mode: ' . strtoupper($mode));

        if ($mode === 'webhook') {
            $output->writeln('ℹ️  Webhook mode is active. Polling not started.');
            $output->writeln('ℹ️  Ensure your webhook is set via /setwebhook endpoint.');
            $output->writeln('========================================');
            $output->writeln('✅ Bot is running in webhook mode.');
            $output->writeln('========================================');

            return Command::SUCCESS;
        }

        $output->writeln('✓ Starting long polling...');
        $output->writeln('========================================');
        $output->writeln('🚀 Reactor is running and listening for messages');
        $output->writeln('📝 Press Ctrl+C to stop');
        $output->writeln('========================================');
        $output->writeln('');

        try {
            $this->app->start();
        } catch (\Throwable $e) {
            $this->logger->critical('Fatal error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $output->writeln('<error>❌ Error: ' . $e->getMessage() . '</error>');
            $output->writeln('<error>File: ' . $e->getFile() . ':' . $e->getLine() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
