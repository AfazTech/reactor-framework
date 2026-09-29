<?php
namespace Reactor\Core\Bootstrappers;

use Reactor\Contracts\LoggerInterface;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\BootstrapperInterface;
use Reactor\Core\Container;
use Reactor\Core\Packages\PackageRegistrar;
use Reactor\Core\Providers\CoreServiceProvider;
use Reactor\Core\Providers\ScheduleServiceProvider;
use Reactor\Core\ServiceProviderManager;

/**
 * Orchestrates the application bootstrap process.
 *
 * Four phases run in order:
 *
 *   1. Core providers are registered so that foundational bindings
 *      (Config, Logger, PackageManifest, PackageRegistrar, ...) exist.
 *   2. Package providers discovered through Composer metadata
 *      (composer.json `type: "reactor-package"`) are registered. Package
 *      config sources, migration sources and class aliases are applied
 *      at this stage.
 *   3. User-supplied providers run last, so they can override package
 *      bindings on a case-by-case basis.
 *   4. The caller-supplied BootstrapperInterface chain runs (database,
 *      Telegram, middleware, router, or any custom concern), followed by
 *      the boot() phase of every registered provider.
 *
 * Because the bootstrapper list is supplied by the caller, a custom
 * skeleton can skip or replace any phase. Omitting TelegramBootstrapper
 * runs Reactor without a framework-managed Telegram client, and
 * inserting a custom bootstrapper at the front lets the host
 * application register its own Neili\Client binding before the rest of
 * the chain runs.
 */
class AppBootstrapper implements BootstrapperInterface
{
    /**
     * @var array<int, BootstrapperInterface>
     */
    private array $bootstrappers;

    /**
     * @var array<int, ServiceProviderInterface|class-string<ServiceProviderInterface>>
     */
    private array $providers;

    /**
     * @param array $providers     Additional service providers. Each entry
     *                             may be a ServiceProviderInterface instance
     *                             or a class-string of one.
     * @param array $bootstrappers Bootstrapper instances to run, in order.
     */
    public function __construct(array $providers = [], array $bootstrappers = [])
    {
        $this->providers = $providers;
        $this->bootstrappers = $bootstrappers;
    }

    public function bootstrap(Container $container): void
    {
        $manager = new ServiceProviderManager($container);
        $container->singleton(ServiceProviderManager::class, fn() => $manager);

        // Phase 1: core providers (Config, Logger, Container, PackageManifest,
        // PackageRegistrar, Router, Migrator, ...).
        $manager->add(new CoreServiceProvider());
        $manager->add(new ScheduleServiceProvider());
        $manager->registerAll();

        // Phase 2: package providers discovered via Composer metadata.
        $this->registerPackageProviders($container, $manager);

        // Phase 3: user-supplied providers (override package bindings).
        $this->registerUserProviders($container, $manager);

        // Phase 4: bootstrapper chain (database, Telegram, middleware, router).
        foreach ($this->bootstrappers as $bootstrapper) {
            if ($bootstrapper instanceof BootstrapperInterface) {
                $bootstrapper->bootstrap($container);
            }
        }

        // Final boot() phase for every provider registered in phases 1-3.
        $manager->bootAll();
    }

    private function registerPackageProviders(Container $container, ServiceProviderManager $manager): void
    {
        try {
            /** @var PackageRegistrar $registrar */
            $registrar = $container->get(PackageRegistrar::class);
        } catch (\Throwable $e) {
            // Package infrastructure is optional. A failure here (for
            // example, an unwritable bootstrap/cache directory) must
            // not prevent the application from booting.
            $container->get(LoggerInterface::class)->warning(
                'Package discovery skipped: PackageRegistrar unavailable',
                ['error' => $e->getMessage()]
            );
            return;
        }

        $logger = $container->get(LoggerInterface::class);

        // Applies config sources, migration sources and class aliases.
        $registrar->register();

        foreach ($registrar->providerClasses() as $class) {
            if (!class_exists($class)) {
                $logger->warning('Package provider class not found, skipping', [
                    'class' => $class,
                ]);
                continue;
            }

            try {
                $provider = $container->get($class);
            } catch (\Throwable $e) {
                $logger->error('Failed to resolve package provider', [
                    'class' => $class,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$provider instanceof ServiceProviderInterface) {
                $logger->warning('Package provider does not implement ServiceProviderInterface', [
                    'class' => $class,
                ]);
                continue;
            }

            $manager->add($provider);
            $provider->register($container);
        }
    }

    private function registerUserProviders(Container $container, ServiceProviderManager $manager): void
    {
        foreach ($this->providers as $provider) {
            if (is_string($provider)) {
                $provider = $container->get($provider);
            }
            if (!$provider instanceof ServiceProviderInterface) {
                continue;
            }
            $manager->add($provider);
            $provider->register($container);
        }
    }
}
