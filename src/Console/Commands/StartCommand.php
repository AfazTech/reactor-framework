<?php

namespace Reactor\Console\Commands;

use Neili\Exceptions\PermanentException;
use Neili\Exceptions\TransientException;
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
 *
 * Ordering matters. Before anything else the command performs a
 * read-only lock check so that a second invocation fails immediately
 * with a clear message and does not waste a getMe call, emit a
 * misleading "running" banner, or risk a partial bootstrap. Only after
 * the lock is known to be free does it identify the bot via getMe and
 * start polling (which acquires the same lock for real).
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

        // Lock check FIRST — before any output, network call, or
        // bootstrap side effect. Only meaningful in polling mode
        // because the lock file is named {app}_polling.lock and
        // webhook mode never acquires it.
        if ($mode === 'polling') {
            $lockManager = $this->app->getPollingLockManager();
            if ($lockManager !== null) {
                $runningPid = $lockManager->isHeldByAnotherProcess();
                if ($runningPid !== null) {
                    $output->writeln('<error>❌ Bot is already running (PID: ' . $runningPid . ').</error>');
                    $output->writeln('<comment>   Use ./reactor stop to stop it first.</comment>');
                    $this->logger->warning('Start aborted: bot is already running', ['pid' => $runningPid]);
                    return Command::FAILURE;
                }
            }
        }

        $output->writeln('========================================');
        $output->writeln('⚡ REACTOR BOT');
        $output->writeln('========================================');
        $output->writeln('✓ Bot initialized successfully');
        $output->writeln('✓ Mode: ' . strtoupper($mode));

        if (!$this->identifyBot($output)) {
            return Command::FAILURE;
        }

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
        } catch (\RuntimeException $e) {
            // A concurrent process may have acquired the lock between
            // the read-only pre-check above and App::start(). Handle
            // that race with the same clean message as the pre-check
            // so the operator never sees a raw vendor path.
            if (str_starts_with($e->getMessage(), 'Lock file exists and process is running')) {
                $output->writeln('<error>❌ Bot is already running.</error>');
                $output->writeln('<comment>   Another process acquired the lock while this command was starting.</comment>');
                $this->logger->warning('Start aborted: concurrent lock acquisition', ['error' => $e->getMessage()]);
                return Command::FAILURE;
            }

            $this->logger->critical('Fatal error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            $output->writeln('<error>❌ Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->logger->critical('Fatal error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            $output->writeln('<error>❌ Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Fetch the bot's identity via getMe() and render it to the console.
     *
     * Returns false on any failure so the caller can abort the start
     * sequence with a non-zero exit code. This method never throws: it
     * classifies the failure into a clear operator-facing message.
     *
     * Error classification:
     *   - No client bound       → token not configured, or wiring missing.
     *   - PermanentException    → Telegram rejected the token (401/403/400).
     *   - TransientException    → Telegram is unreachable (network / 5xx).
     *   - Any other Throwable   → unexpected failure; logged with trace.
     */
    private function identifyBot(OutputInterface $output): bool
    {
        $client = $this->app->getClient();
        if ($client === null) {
            $token = $this->app->getConfig()->getToken();
            if ($token === '') {
                $output->writeln('<error>❌ Bot token is not configured.</error>');
                $output->writeln('<error>   Set bot.token in config/bot.php or TOKEN in .env.</error>');
                $this->logger->critical('Bot token is not configured');
            } else {
                $output->writeln('<error>❌ Neili\\Client is not bound in the container.</error>');
                $output->writeln('<error>   Include TelegramBootstrapper in the bootstrap chain,');
                $output->writeln('<error>   or bind Neili\\Client yourself in a service provider.</error>');
                $this->logger->critical('Neili\\Client is not bound in the container');
            }
            return false;
        }

        try {
            $response = $client->getMe()->await();
        } catch (PermanentException $e) {
            $output->writeln('<error>❌ Invalid bot token.</error>');
            $output->writeln('<error>   ' . $e->getMessage() . '</error>');
            $this->logger->critical('Invalid bot token', ['error' => $e->getMessage()]);
            return false;
        } catch (TransientException $e) {
            $output->writeln('<error>❌ Cannot reach the Telegram API.</error>');
            $output->writeln('<error>   ' . $e->getMessage() . '</error>');
            $output->writeln('<comment>   Verify network connectivity and retry.</comment>');
            $this->logger->error('Cannot reach the Telegram API', ['error' => $e->getMessage()]);
            return false;
        } catch (\Throwable $e) {
            $output->writeln('<error>❌ Failed to identify the bot.</error>');
            $output->writeln('<error>   ' . $e->getMessage() . '</error>');
            $this->logger->error('Failed to identify the bot', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }

        $bot = $response['result'] ?? null;
        if (!is_array($bot) || !isset($bot['id'])) {
            $output->writeln('<error>❌ Telegram returned an unexpected getMe response.</error>');
            $this->logger->critical('Unexpected getMe response', ['response' => $response]);
            return false;
        }

        $firstName = (string) ($bot['first_name'] ?? '');
        $lastName  = (string) ($bot['last_name'] ?? '');
        $username  = (string) ($bot['username'] ?? '');
        $id        = $bot['id'];

        $displayName = trim($firstName . ' ' . $lastName);
        if ($displayName === '') {
            $displayName = 'Unknown';
        }

        $output->writeln('✓ Bot identity');
        $output->writeln('    Name:      ' . $displayName);
        $output->writeln('    ID:        ' . $id);

        if ($username !== '') {
            $output->writeln('    Username:  @' . $username);
            $output->writeln('    App link:  tg://resolve?domain=' . $username);
            $output->writeln('    Web link:  https://' . $username . '.t.me');
        } else {
            $output->writeln('    Username:  <comment>(not set)</comment>');
        }

        return true;
    }
}
