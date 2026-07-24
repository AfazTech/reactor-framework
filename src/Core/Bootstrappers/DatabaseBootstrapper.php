<?php
namespace Reactor\Core\Bootstrappers;

use Reactor\Core\Container;
use Reactor\Core\BootstrapperInterface;
use Reactor\Core\Config;
use Reactor\Core\Paths;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Database\Migrations\Migrator;
use Reactor\Exceptions\ConfigNotFoundException;
use Neili\Settings;
use Neili\Client;
use Neili\Poller;

/**
 * Bootstrapper that initialises the database connection and Telegram client.
 */
class DatabaseBootstrapper implements BootstrapperInterface
{
    public function bootstrap(Container $container): void
    {
        $config = $container->get(Config::class);
        $logger = $container->get(LoggerInterface::class);
        $paths = $container->get(Paths::class);

        $db = $container->get(DatabaseManagerInterface::class);
        $migrator = $container->get(Migrator::class);
        $migrator->run();

        $token = $config->getToken();
        if (empty($token)) {
            $logger->critical("TOKEN is not set in database or .env file");
            throw new ConfigNotFoundException('TOKEN not set in database or .env file');
        }

        $logger->info("Token loaded successfully", [
            'token_preview' => substr($token, 0, 10) . '...',
            'token_length' => strlen($token)
        ]);

        $apiUrl = $config->getApiUrl();
        $logger->info("API URL configured", ['url' => $apiUrl]);

        $settings = (new Settings)
            ->setAccessToken($token)
            ->setApiUrl($apiUrl)
            ->setApiVerifySSL(false);

        if ($config->isMultiProcess()) {
            $settings->setMultiProcess(true);
            $settings->setPhpBinary($config->getPhpBinary());
            $logger->info("Multi-process enabled via config", ['php_binary' => $config->getPhpBinary()]);
        }

        $client = new Client($settings);
        $poller = new Poller($client);
        $container->singleton(Client::class, fn() => $client);
        $container->singleton(Poller::class, fn() => $poller);

        $logger->info("Telegram client initialized");
    }
}
