<?php
namespace Reactor\Core\Bootstrappers;

use Neili\Client;
use Neili\Poller;
use Neili\Settings;
use Reactor\Core\BootstrapperInterface;
use Reactor\Core\Config;
use Reactor\Core\Container;
use Reactor\Contracts\LoggerInterface;
use Reactor\Exceptions\ConfigNotFoundException;

/**
 * Wires the Telegram client and long-polling poller.
 *
 * This bootstrapper is optional. Applications that do not talk to the
 * Telegram Bot API, or that want to provide their own Neili\Client
 * binding (for example, from a custom configuration source), can omit
 * it entirely and register a Neili\Client binding through a service
 * provider instead.
 *
 * A valid token is required and must come from the host application's
 * configuration (typically config/bot.php with a bot.token key, or the
 * BOT_TOKEN environment variable). When the token is missing a
 * descriptive ConfigNotFoundException is thrown so the failure is
 * obvious rather than silently falling through.
 */
class TelegramBootstrapper implements BootstrapperInterface
{
    public function bootstrap(Container $container): void
    {
        $config = $container->get(Config::class);
        $logger = $container->get(LoggerInterface::class);

        $token = $config->getToken();
        if (empty($token)) {
            $logger->critical('Telegram token is not set in configuration');
            throw new ConfigNotFoundException(
                'Telegram token is not configured. Set bot.token in config/bot.php '
                . 'or BOT_TOKEN in your .env, or omit TelegramBootstrapper to run '
                . 'without a framework-managed Telegram client.'
            );
        }

        $logger->info('Telegram token loaded', [
            'token_preview' => substr($token, 0, 10) . '...',
            'token_length'  => strlen($token),
        ]);

        $apiUrl = $config->getApiUrl();
        $verifySsl = $config->isVerifySsl();

        if (!$verifySsl) {
            $logger->warning(
                'TLS certificate verification is DISABLED for Telegram API calls. '
                . 'This is insecure and should only be used for local development.'
            );
        }

        $settings = (new Settings())
            ->setAccessToken($token)
            ->setApiUrl($apiUrl)
            ->setApiVerifySSL($verifySsl);

        if ($config->isMultiProcess()) {
            $settings->setMultiProcess(true);
            $settings->setPhpBinary($config->getPhpBinary());
            $logger->info('Multi-process enabled via config', [
                'php_binary' => $config->getPhpBinary(),
            ]);
        }

        $client = new Client($settings);
        $poller = new Poller($client);

        $container->singleton(Client::class, fn() => $client);
        $container->singleton(Poller::class, fn() => $poller);

        $logger->info('Telegram client initialized');
    }
}
