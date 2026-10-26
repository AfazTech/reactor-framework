<?php

namespace Reactor\Core\Providers;

use Reactor\Console\Support\EntryPointResolver;
use Reactor\Core\Container;
use Reactor\Core\Config;
use Reactor\Core\Language;
use Reactor\Core\Logger;
use Reactor\Core\EventDispatcher;
use Reactor\Core\Paths;
use Reactor\Core\Router;
use Reactor\Core\MiddlewareManager;
use Reactor\Core\ErrorHandler;
use Reactor\Core\UpdateProcessor;
use Reactor\Core\UpdateTypeResolver;
use Reactor\Core\UnknownCommandHandler;
use Reactor\Core\NullUserProvider;
use Reactor\Core\NullDatabaseManager;
use Reactor\Core\Packages\PackageManifest;
use Reactor\Core\Packages\PackageRegistrar;
use Reactor\Core\Processing\MiddlewareProcessor;
use Reactor\Core\Processing\HandlerInvoker;
use Reactor\Core\Routing\CommandParser;
use Reactor\Database\Migrations\MigrationRegistrar;
use Reactor\Queue\DatabaseQueue;
use Reactor\Queue\FailedJobRepository;
use Reactor\Queue\QueueManager;
use Reactor\Queue\Signals;
use Reactor\Queue\Worker;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\AsyncDatabaseManagerInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Contracts\QueueManagerInterface;
use Reactor\Database\Migrations\Migrator;

/**
 * Registers every core service of the framework in the container.
 *
 * The provider is deliberately usable on its own (without the App
 * orchestrator or the application skeleton): it registers fallback
 * bindings for Paths, Config and Container so that any consumer can
 * resolve the framework's services as long as they call
 *
 *     (new CoreServiceProvider())->register($container);
 *
 * before resolving anything. When the App orchestrator is used, its
 * own bindings take precedence because every fallback is guarded by a
 * has() check.
 *
 * Neili\Client is intentionally *not* registered here: it needs a
 * valid token and other settings that only the host application can
 * supply. Standalone consumers must bind Neili\Client themselves (for
 * example, via the DatabaseBootstrapper or their own bootstrapper)
 * before resolving any service that depends on it (ErrorHandler,
 * HandlerInvoker, UpdateProcessor, UnknownCommandHandler).
 */
class CoreServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        if (!$container->has(Container::class)) {
            $container->instance(Container::class, $container);
        }

        if (!$container->has(Paths::class)) {
            $container->singleton(Paths::class, function () {
                $base = defined('REACTOR_BASE_PATH')
                    ? REACTOR_BASE_PATH
                    : (getcwd() ?: sys_get_temp_dir());
                return new Paths($base);
            });
        }

        if (!$container->has(Config::class)) {
            $container->singleton(Config::class, function ($c) {
                $paths = $c->get(Paths::class);
                $configPath = $paths->config();
                return new Config(is_dir($configPath) ? $configPath : null);
            });
        }

        $container->singleton(LoggerInterface::class, function ($c) {
            $config = $c->get(Config::class);
            $debugMode = $config->isDebugMode();
            $paths = $c->get(Paths::class);
            $logFile = $paths->logs() . '/app.log';
            return new Logger($logFile, $debugMode);
        });

        $container->singleton(LanguageInterface::class, function ($c) {
            return new Language($c->get(Paths::class), $c->get(Config::class));
        });

        $container->singleton(Language::class, function ($c) {
            return $c->get(LanguageInterface::class);
        });

        $container->singleton(EventDispatcher::class, function () {
            return new EventDispatcher();
        });

        $container->singleton(UpdateTypeResolver::class, function () {
            return new UpdateTypeResolver();
        });

        $container->singleton(CommandParser::class, function () {
            return new CommandParser();
        });

        if (!$container->has(UserProviderInterface::class)) {
            $container->singleton(UserProviderInterface::class, function () {
                return new NullUserProvider();
            });
        }

        if (!$container->has(DatabaseManagerInterface::class)) {
            $container->singleton(DatabaseManagerInterface::class, function ($c) {
                return new NullDatabaseManager($c->get(LoggerInterface::class));
            });
        }

        if (!$container->has(AsyncDatabaseManagerInterface::class)) {
            $container->singleton(AsyncDatabaseManagerInterface::class, function ($c) {
                throw new \RuntimeException(
                    'No async database driver configured. '
                    . 'Bind AsyncDatabaseManagerInterface in a service provider.'
                );
            });
        }

        if (!$container->has(MigrationRegistrar::class)) {
            $container->singleton(MigrationRegistrar::class, function ($c) {
                return new MigrationRegistrar($c->get(LoggerInterface::class));
            });
        }

        $container->singleton(PackageManifest::class, function ($c) {
            $paths = $c->get(Paths::class);
            $config = $c->get(Config::class);
            return new PackageManifest(
                $paths,
                $paths->path('vendor'),
                $c->get(LoggerInterface::class),
                $config->isDebugMode()
            );
        });

        $container->singleton(PackageRegistrar::class, function ($c) {
            return new PackageRegistrar(
                $c->get(PackageManifest::class),
                $c->get(Container::class)
            );
        });

        $container->singleton(EntryPointResolver::class, function ($c) {
            return new EntryPointResolver(
                $c->get(Paths::class),
                $c->get(Container::class)
            );
        });

        $container->singleton(Router::class, function ($c) {
            return new Router(
                $c->get(Container::class),
                $c->get(LoggerInterface::class),
                $c->get(LanguageInterface::class),
                $c->get(UserProviderInterface::class),
                $c->get(UpdateTypeResolver::class),
                $c->get(CommandParser::class)
            );
        });

        $container->singleton(Migrator::class, function ($c) {
            return new Migrator(
                $c->get(LoggerInterface::class),
                $c->get(Config::class),
                $c->get(DatabaseManagerInterface::class),
                $c->get(Paths::class),
                $c->get(Container::class),
                $c->get(MigrationRegistrar::class)
            );
        });

        $container->singleton(QueueManagerInterface::class, function ($c) {
            return new DatabaseQueue($c->get(DatabaseManagerInterface::class));
        });

        $container->singleton(QueueManager::class, function ($c) {
            return new QueueManager(
                $c->get(Container::class),
                $c->get(QueueManagerInterface::class),
                $c->get(AsyncDatabaseManagerInterface::class)
            );
        });

        $container->singleton(Signals::class, function ($c) {
            $paths = $c->get(Paths::class);
            return new Signals($paths->storage() . '/framework/queue-restart');
        });

        $container->singleton(FailedJobRepository::class, function ($c) {
            return new FailedJobRepository(
                $c->get(DatabaseManagerInterface::class),
                $c->get(LoggerInterface::class)
            );
        });

        $container->singleton(Worker::class, function ($c) {
            return new Worker(
                $c->get(QueueManager::class),
                $c->get(Container::class),
                $c->get(LoggerInterface::class),
                $c->get(FailedJobRepository::class),
                $c->get(Signals::class)
            );
        });

        $container->singleton(ErrorHandler::class, function ($c) {
            return new ErrorHandler(
                $c->get(LoggerInterface::class),
                $c->get(EventDispatcher::class),
                $c->get(\Neili\Client::class),
                $c->get(Config::class),
                $c->get(LanguageInterface::class),
                $c->get(UserProviderInterface::class)
            );
        });

        $container->singleton(MiddlewareManager::class, function ($c) {
            return new MiddlewareManager(
                $c->get(Container::class),
                $c->get(ErrorHandler::class),
                $c->get(LoggerInterface::class)
            );
        });

        $container->singleton(MiddlewareProcessor::class, function ($c) {
            return new MiddlewareProcessor(
                $c->get(MiddlewareManager::class),
                $c->get(LoggerInterface::class)
            );
        });

        $container->singleton(HandlerInvoker::class, function ($c) {
            return new HandlerInvoker(
                $c->get(Container::class),
                $c->get(ErrorHandler::class),
                $c->get(LoggerInterface::class),
                $c->get(EventDispatcher::class),
                $c->get(\Neili\Client::class),
                $c->get(LanguageInterface::class),
                $c->get(UserProviderInterface::class)
            );
        });

        $container->singleton(UpdateProcessor::class, function ($c) {
            return new UpdateProcessor(
                $c->get(Container::class),
                $c->get(Router::class),
                $c->get(MiddlewareProcessor::class),
                $c->get(HandlerInvoker::class),
                $c->get(ErrorHandler::class),
                $c->get(LoggerInterface::class),
                $c->get(EventDispatcher::class),
                $c->get(\Neili\Client::class),
                $c->get(LanguageInterface::class),
                $c->get(UserProviderInterface::class),
                $c->get(UpdateTypeResolver::class),
                $c->get(UnknownCommandHandler::class)
            );
        });

        $container->singleton(UnknownCommandHandler::class, function ($c) {
            return new UnknownCommandHandler(
                $c->get(\Neili\Client::class),
                $c->get(LanguageInterface::class),
                $c->get(UserProviderInterface::class),
                $c->get(LoggerInterface::class)
            );
        });
    }

    public function boot(Container $container): void
    {
        // Nothing to boot
    }
}
