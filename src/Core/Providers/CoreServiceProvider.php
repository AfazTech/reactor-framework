<?php

namespace Reactor\Core\Providers;

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
use Reactor\Core\Processing\MiddlewareProcessor;
use Reactor\Core\Processing\HandlerInvoker;
use Reactor\Core\Routing\CommandParser;
use Reactor\Database\Migrations\MigrationRegistrar;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\UserProviderInterface;
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
        // Fallback bindings for standalone usage. Every one of them is
        // guarded by has() so that an explicit binding registered by
        // the host application (or by App) always wins.
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

        // UpdateTypeResolver has no dependencies and is needed by Router.
        $container->singleton(UpdateTypeResolver::class, function () {
            return new UpdateTypeResolver();
        });

        // CommandParser has no dependencies and is needed by Router.
        $container->singleton(CommandParser::class, function () {
            return new CommandParser();
        });

        // Standalone fallback: when the application skeleton is not
        // used, no UserProviderInterface binding is registered by the
        // host project. A no-op implementation keeps Router and the
        // error handling pipeline functional (default language "en",
        // no current step).
        if (!$container->has(UserProviderInterface::class)) {
            $container->singleton(UserProviderInterface::class, function () {
                return new NullUserProvider();
            });
        }

        // Standalone fallback: without a real database manager every
        // schema/query call throws a descriptive exception. This is
        // enough for handlers that do not need persistence, and it
        // fails clearly when database features are actually used.
        if (!$container->has(DatabaseManagerInterface::class)) {
            $container->singleton(DatabaseManagerInterface::class, function ($c) {
                return new NullDatabaseManager($c->get(LoggerInterface::class));
            });
        }

        // Fallback registrar for standalone framework usage. When the App
        // class is used (the common case), it binds MigrationRegistrar
        // with the application source pre-registered before bootstrap,
        // and this fallback becomes a no-op thanks to the has() check.
        if (!$container->has(MigrationRegistrar::class)) {
            $container->singleton(MigrationRegistrar::class, function ($c) {
                return new MigrationRegistrar($c->get(LoggerInterface::class));
            });
        }

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
