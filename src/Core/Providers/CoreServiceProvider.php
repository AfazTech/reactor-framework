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
use Reactor\Core\Processing\MiddlewareProcessor;
use Reactor\Core\Processing\HandlerInvoker;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Database\Migrations\Migrator;

class CoreServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->singleton(LoggerInterface::class, function($c) {
            $config = $c->get(Config::class);
            $debugMode = $config->isDebugMode();
            $paths = $c->get(Paths::class);
            $logFile = $paths->logs() . '/app.log';
            return new Logger($logFile, $debugMode);
        });

        $container->singleton(LanguageInterface::class, function($c) {
            return new Language($c->get(Paths::class), $c->get(Config::class));
        });

        $container->singleton(Language::class, function($c) {
            return $c->get(LanguageInterface::class);
        });

        $container->singleton(EventDispatcher::class, function() {
            return new EventDispatcher();
        });

        $container->singleton(Router::class, function($c) {
            return new Router($c->get(Container::class), $c->get(LoggerInterface::class));
        });

        $container->singleton(Migrator::class, function($c) {
            return new Migrator(
                $c->get(LoggerInterface::class),
                $c->get(Config::class),
                $c->get(DatabaseManagerInterface::class),
                $c->get(Paths::class),
                $c->get(Container::class)
            );
        });

        $container->singleton(ErrorHandler::class, function($c) {
            return new ErrorHandler(
                $c->get(LoggerInterface::class),
                $c->get(EventDispatcher::class),
                $c->get(\Neili\Client::class),
                $c->get(Config::class),
                $c->get(LanguageInterface::class)
            );
        });

        $container->singleton(MiddlewareManager::class, function($c) {
            return new MiddlewareManager(
                $c->get(Container::class),
                $c->get(ErrorHandler::class),
                $c->get(LoggerInterface::class)
            );
        });

        $container->singleton(MiddlewareProcessor::class, function($c) {
            return new MiddlewareProcessor(
                $c->get(MiddlewareManager::class),
                $c->get(LoggerInterface::class)
            );
        });

        $container->singleton(HandlerInvoker::class, function($c) {
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

        $container->singleton(UpdateProcessor::class, function($c) {
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

        $container->singleton(UnknownCommandHandler::class, function($c) {
            return new UnknownCommandHandler(
                $c->get(\Neili\Client::class),
                $c->get(LanguageInterface::class),
                $c->get(UserProviderInterface::class),
                $c->get(LoggerInterface::class)
            );
        });

        // Register UpdateTypeResolver (no dependencies)
        $container->singleton(UpdateTypeResolver::class, function() {
            return new UpdateTypeResolver();
        });
    }

    public function boot(Container $container): void
    {
        // Nothing to boot
    }
}
