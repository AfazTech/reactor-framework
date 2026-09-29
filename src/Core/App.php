<?php

namespace Reactor\Core;

use Neili\Client;
use Neili\Poller;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use Reactor\Core\Bootstrappers\AppBootstrapper;
use Reactor\Core\Bootstrappers\DatabaseBootstrapper;
use Reactor\Core\Bootstrappers\MiddlewareBootstrapper;
use Reactor\Core\Bootstrappers\RouterBootstrapper;
use Reactor\Core\Bootstrappers\TelegramBootstrapper;
use Reactor\Database\Migrations\MigrationRegistrar;
use Reactor\Database\Migrations\Migrator;
use Reactor\Core\Config;
use Reactor\Core\Container;
use Reactor\Core\Router;
use Reactor\Core\Paths;
use Reactor\Core\LockManager;
use Reactor\Core\MiddlewareManager;
use Reactor\Core\UpdateProcessor;
use Dotenv\Dotenv;

/**
 * Convenience orchestrator for a Reactor-based application.
 *
 * App is NOT a mandatory entry point. The framework's individual
 * services (Container, Config, Router, Cache, Migrations, Schedule)
 * are bound by CoreServiceProvider and can be resolved from a fresh
 * container without ever instantiating App.
 *
 * Custom skeletons may keep App but replace any part of its bootstrap
 * chain via the $bootstrappers argument. The default chain is
 * DatabaseBootstrapper → TelegramBootstrapper → MiddlewareBootstrapper
 * → RouterBootstrapper. Omit TelegramBootstrapper (and bind
 * Neili\Client yourself through a service provider) to provide the
 * client from a custom source.
 */
class App
{
    protected Container $container;
    protected Config $config;
    protected Paths $paths;
    protected LoggerInterface $logger;
    protected LanguageInterface $language;
    protected ?Client $client = null;
    protected ?Poller $poller = null;
    protected Router $router;
    protected Migrator $migrator;
    protected ?LockManager $pollingLockManager = null;
    protected ?UpdateProcessor $updateProcessor = null;
    protected array $handlerSources;
    protected array $middlewareSources;
    protected array $middlewares = [];

    /**
     * @param string     $basePath          Root directory of the application.
     * @param array      $handlerSources    Handler discovery sources.
     * @param array      $middlewareSources Middleware discovery sources.
     * @param array      $providers         Additional service provider
     *                                      classes or instances.
     * @param array|null $bootstrappers     Optional list of
     *                                      BootstrapperInterface instances.
     *                                      When null the default chain
     *                                      is used. Pass an explicit list
     *                                      to skip or replace any phase.
     */
    public function __construct(
        string $basePath,
        array $handlerSources = [],
        array $middlewareSources = [],
        array $providers = [],
        ?array $bootstrappers = null
    ) {
        $this->paths = new Paths(rtrim($basePath, '/'));

        if (!defined('REACTOR_BASE_PATH')) {
            define('REACTOR_BASE_PATH', $this->paths->base());
        }

        $this->handlerSources = $handlerSources ?: [
            [
                'directory' => $this->paths->app() . '/Handlers',
                'namespace' => 'App\\Handlers',
            ],
        ];
        $this->middlewareSources = $middlewareSources ?: [
            [
                'directory' => $this->paths->app() . '/Middleware',
                'namespace' => 'App\\Middleware',
            ],
        ];

        if (file_exists($this->paths->path('.env'))) {
            $dotenv = Dotenv::createImmutable($this->paths->base());
            $dotenv->load();
        }

        $this->container = new Container();
        $this->config = new Config($this->paths->config());

        $this->container->singleton(Paths::class, fn() => $this->paths);
        $this->container->singleton(Config::class, fn() => $this->config);
        $this->container->singleton(Container::class, fn() => $this->container);
        $this->container->singleton('basePath', fn() => $this->paths->base());
        $this->container->singleton(App::class, fn() => $this);

        // Register the application's migration source first, so that any
        // package or extension sources added later (during provider
        // registration) come after. On conflicting migration names the
        // first registered source wins.
        $migrationRegistrar = new MigrationRegistrar();
        $migrationRegistrar->add(
            $this->paths->database() . '/migrations',
            (string) $this->config->get('database.migrations.namespace', 'App\\Migrations')
        );
        $this->container->singleton(MigrationRegistrar::class, fn() => $migrationRegistrar);

        $bootstrapper = new AppBootstrapper(
            $providers,
            $bootstrappers ?? $this->defaultBootstrappers()
        );
        $bootstrapper->bootstrap($this->container);

        $this->logger = $this->container->get(LoggerInterface::class);
        $this->language = $this->container->get(LanguageInterface::class);
        $this->router = $this->container->get(Router::class);
        $this->migrator = $this->container->get(Migrator::class);
        $this->middlewares = $this->container->get('middlewares') ?? [];

        $middlewareManager = $this->container->get(MiddlewareManager::class);
        $middlewareManager->setMiddlewares($this->middlewares);

        // Telegram wiring is optional. When a Neili\Client binding is
        // present (either registered by TelegramBootstrapper or by a
        // custom service provider) the client and update processor are
        // resolved eagerly; otherwise App remains usable for non-
        // Telegram consumers of the framework.
        if ($this->container->has(Client::class)) {
            $this->client = $this->container->get(Client::class);
            $this->updateProcessor = $this->container->get(UpdateProcessor::class);
        }
        if ($this->container->has(Poller::class)) {
            $this->poller = $this->container->get(Poller::class);
        }

        $appName = $this->config->get('app.name', 'reactor');
        $lockFile = $this->paths->storage() . '/locks/' . $appName . '_polling.lock';
        $this->pollingLockManager = new LockManager($this->logger, $lockFile);

        $this->logger->info('=== APP INITIALIZATION COMPLETED SUCCESSFULLY ===');
    }

    /**
     * Default bootstrap chain used when the caller does not supply one.
     *
     * Subclasses may override to add, remove or reorder phases.
     *
     * @return array<int, \Reactor\Core\BootstrapperInterface>
     */
    protected function defaultBootstrappers(): array
    {
        return [
            new DatabaseBootstrapper(),
            new TelegramBootstrapper(),
            new MiddlewareBootstrapper($this->middlewareSources),
            new RouterBootstrapper($this->handlerSources),
        ];
    }

    /**
     * Start the bot in polling mode.
     *
     * Requires a Neili\Poller binding. When TelegramBootstrapper is not
     * part of the bootstrap chain, the host application must provide
     * its own Poller binding before calling this method.
     */
    public function start(): void
    {
        if ($this->poller === null) {
            throw new \RuntimeException(
                'Polling is not available: no Neili\\Poller was registered. '
                . 'Include TelegramBootstrapper in the bootstrap chain or bind '
                . 'Neili\\Poller yourself before calling start().'
            );
        }

        $mode = $this->config->getBotMode();
        if ($mode === 'webhook') {
            $this->logger->info('Bot mode is webhook. Polling not started.');
            return;
        }

        $this->pollingLockManager->acquire();

        $this->logger->info('=== STARTING BOT POLLING ===');

        $this->poller->onUpdate(function ($update) {
            // Final safety net: UpdateProcessor rethrows fatal \Error
            // subclasses so they do not disappear silently. We must not
            // let them kill the long-running polling loop.
            try {
                $this->processUpdate($update);
            } catch (\Throwable $e) {
                $this->logger->critical('Unhandled error during update processing', [
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);
            }
        });

        $this->logger->info('Bot polling started successfully');
        $this->poller->start(false);
    }

    /**
     * Process an incoming update.
     *
     * Requires a Neili\Client binding so that the framework's update
     * pipeline (error handler, handler invoker, fallback replies) can
     * send messages back to the user.
     */
    public function processUpdate(array $update): void
    {
        if ($this->updateProcessor === null) {
            throw new \RuntimeException(
                'Update processing requires a Neili\\Client binding. '
                . 'Bind Neili\\Client in a service provider or include '
                . 'TelegramBootstrapper in the bootstrap chain.'
            );
        }

        $this->updateProcessor->process($update);
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getMigrator(): Migrator
    {
        return $this->migrator;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getLanguage(): LanguageInterface
    {
        return $this->language;
    }

    public function getPaths(): Paths
    {
        return $this->paths;
    }

    public function path(string $path = ''): string
    {
        return $this->paths->path($path);
    }
}
