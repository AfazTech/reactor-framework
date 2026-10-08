<?php
namespace Reactor\Core\Bootstrappers;

use Neili\Client;
use Neili\Poller;
use Neili\Settings;
use Reactor\Core\BootstrapperInterface;
use Reactor\Core\Config;
use Reactor\Core\Container;
use Reactor\Contracts\LoggerInterface;

/**
 * Wires the Telegram client and long-polling poller.
 *
 * This bootstrapper is optional. Applications that do not talk to the
 * Telegram Bot API, or that want to provide their own Neili\Client
 * binding (for example, from a custom configuration source), can omit
 * it entirely and register a Neili\Client binding through a service
 * provider instead.
 *
 * When no token is configured the bootstrapper logs a warning and
 * returns without registering the client or poller. This lets CLI
 * commands that do not need Telegram (migrations, code generators,
 * lifecycle commands such as `stop` and `restart`) run in a fresh
 * environment. Commands that do need Telegram (most importantly
 * `start`) throw a descriptive RuntimeException at the point of use,
 * instead of failing silently or requiring a token for unrelated
 * operations.
 */
class TelegramBootstrapper implements BootstrapperInterface
{
    public function bootstrap(Container $container): void
    {
        $config = $container->get(Config::class);
        $logger = $container->get(LoggerInterface::class);

        $token = $config->getToken();
        if (empty($token)) {
            $logger->warning(
                'Telegram token is not set. Telegram-dependent features '
                . '(polling, sending messages) will not be available until '
                . 'a token is configured via bot.token in config/bot.php '
                . 'or TOKEN in .env.'
            );
            return;
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
