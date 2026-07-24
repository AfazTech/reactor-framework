<?php

namespace Reactor\Core;

use Neili\Client;
use Neili\Poller;
use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\LanguageInterface;
use Reactor\Contracts\DatabaseManagerInterface;
use Reactor\Database\Migrations\Migrator;
use Reactor\Core\Bootstrappers\AppBootstrapper;
use Reactor\Core\Config;
use Reactor\Core\Container;
use Reactor\Core\Router;
use Reactor\Core\Paths;
use Reactor\Core\LockManager;
use Reactor\Core\MiddlewareManager;
use Reactor\Core\ErrorHandler;
use Reactor\Core\UpdateProcessor;
use Reactor\Core\EventDispatcher;
use Dotenv\Dotenv;

/**
 * Main application class that orchestrates the entire framework.
 *
 * This class initialises the container, configuration, database, router,
 * middleware, and starts the update processing loop (polling or webhook).
 */
class App
{
    protected Container $container;
    protected Config $config;
    protected Paths $paths;
    protected LoggerInterface $logger;
    protected LanguageInterface $language;
    protected Client $client;
    protected Poller $poller;
    protected Router $router;
    protected Migrator $migrator;
    protected LockManager $pollingLockManager;
    protected UpdateProcessor $updateProcessor;
    protected array $handlerSources;
    protected array $middlewareSources;
    protected array $middlewares = [];

    /**
     * Application constructor.
     *
     * @param string $basePath          Root directory of the application.
     * @param array  $handlerSources    Directories and namespaces for handler discovery.
     * @param array  $middlewareSources Directories and namespaces for middleware discovery.
     * @param array  $providers         List of service provider classes to register.
     */
    public function __construct(
        string $basePath,
        array $handlerSources = [],
        array $middlewareSources = [],
        array $providers = []
    ) {
        $this->paths = new Paths(rtrim($basePath, '/'));
        $this->handlerSources = $handlerSources ?: [
            ['directory' => $this->paths->app() . '/Handlers', 'namespace' => 'App\\Handlers'],
            ['directory' => $this->paths->app() . '/Handlers/Callbacks', 'namespace' => 'App\\Handlers\\Callbacks'],
            ['directory' => $this->paths->app() . '/Handlers/Steps', 'namespace' => 'App\\Handlers\\Steps'],
        ];
        $this->middlewareSources = $middlewareSources ?: [
            ['directory' => $this->paths->app() . '/Middleware', 'namespace' => 'App\\Middleware'],
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

        $bootstrapper = new AppBootstrapper($providers, $this->handlerSources, $this->middlewareSources);
        $bootstrapper->bootstrap($this->container);

        $this->logger = $this->container->get(LoggerInterface::class);
        $this->language = $this->container->get(LanguageInterface::class);
        $this->client = $this->container->get(Client::class);
        $this->poller = $this->container->get(Poller::class);
        $this->router = $this->container->get(Router::class);
        $this->migrator = $this->container->get(Migrator::class);
        $this->middlewares = $this->container->get('middlewares') ?? [];

        $errorHandler = $this->container->get(ErrorHandler::class);
        $middlewareManager = $this->container->get(MiddlewareManager::class);
        $middlewareManager->setMiddlewares($this->middlewares);

        $this->updateProcessor = $this->container->get(UpdateProcessor::class);

        $appName = $this->config->get('app.name', 'reactor');
        $lockFile = $this->paths->storage() . '/locks/' . $appName . '_polling.lock';
        $this->pollingLockManager = new LockManager($this->logger, $lockFile);

        $this->logger->info('=== APP INITIALIZATION COMPLETED SUCCESSFULLY ===');
    }

    /**
     * Start the bot in polling mode.
     *
     * This method acquires a lock to prevent multiple polling instances,
     * then starts the long‑polling loop.
     */
    public function start(): void
    {
        $mode = $this->config->getBotMode();
        if ($mode === 'webhook') {
            $this->logger->info('Bot mode is webhook. Polling not started.');
            return;
        }

        $this->pollingLockManager->acquire();

        $this->logger->info('=== STARTING BOT POLLING ===');

        $this->poller->onUpdate(function ($update) {
            $this->processUpdate($update);
        });

        $this->logger->info('Bot polling started successfully');
        $this->poller->start(false);
    }

    /**
     * Process an incoming update.
     *
     * @param array $update The Telegram update.
     */
    public function processUpdate(array $update): void
    {
        $this->updateProcessor->process($update);
    }

    /**
     * Get the Telegram client.
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Get the configuration manager.
     *
     * @return Config
     */
    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Get the DI container.
     *
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Get the router.
     *
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * Get the migrator.
     *
     * @return Migrator
     */
    public function getMigrator(): Migrator
    {
        return $this->migrator;
    }

    /**
     * Get the logger.
     *
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Get the language manager.
     *
     * @return LanguageInterface
     */
    public function getLanguage(): LanguageInterface
    {
        return $this->language;
    }

    /**
     * Get the paths helper.
     *
     * @return Paths
     */
    public function getPaths(): Paths
    {
        return $this->paths;
    }

    /**
     * Get a path relative to the base directory.
     *
     * @param string $path Sub‑path.
     *
     * @return string
     */
    public function path(string $path = ''): string
    {
        return $this->paths->path($path);
    }
}
