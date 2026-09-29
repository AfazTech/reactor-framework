<?php
namespace Reactor\Core\Bootstrappers;

use Reactor\Core\BootstrapperInterface;
use Reactor\Core\Container;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\LoggerInterface;

/**
 * Initialises the database connection.
 *
 * The bootstrapper is deliberately limited to database concerns: it
 * forces the DatabaseManagerInterface to be resolved so that its
 * underlying connection and schema builders are available to the rest
 * of the application. All Telegram-related wiring (token, Neili
 * client, poller) lives in TelegramBootstrapper instead.
 *
 * This separation is what allows a custom skeleton to use Reactor
 * without Telegram, or with a non-Neili client, by simply choosing
 * which bootstrappers to run.
 *
 * Migrations are intentionally NOT run here. Running migrations on
 * every request is unsafe; use the dedicated `migrate` command.
 */
class DatabaseBootstrapper implements BootstrapperInterface
{
    public function bootstrap(Container $container): void
    {
        $logger = $container->get(LoggerInterface::class);

        // Force the database manager to be resolved so its connection
        // and schema builders are available to later consumers such as
        // repositories and queue drivers. When no real binding is
        // registered the NullDatabaseManager throws on use, which is
        // the documented standalone behavior.
        $container->get(DatabaseManagerInterface::class);

        $logger->debug('Database bootstrapper completed');
    }
}
