<?php
namespace Reactor\Core\Packages;

use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Config;
use Reactor\Core\Container;
use Reactor\Database\Migrations\MigrationRegistrar;

/**
 * Applies package metadata to the running application.
 *
 * For every discovered Reactor package this registrar:
 *   1. Adds its config/ directory as a low-priority config source so
 *      that the application's own config files win on conflicts.
 *   2. Registers its database/migrations directory with the
 *      MigrationRegistrar so `migrate` picks up package migrations.
 *   3. Applies class aliases declared in extra.reactor.aliases so that
 *      consumers can reference the short name directly.
 *
 * Service provider classes themselves are not instantiated here; the
 * caller (AppBootstrapper) is responsible for resolving them from the
 * container so that their constructor dependencies are honored.
 */
class PackageRegistrar
{
    public function __construct(
        private PackageManifest $manifest,
        private Container $container
    ) {
    }

    public function register(): void
    {
        $config = $this->container->get(Config::class);
        $migrations = $this->container->get(MigrationRegistrar::class);
        $logger = $this->container->get(LoggerInterface::class);

        foreach ($this->manifest->all() as $package) {
            $name = (string) ($package['name'] ?? 'unknown');

            // 1. Config source (low priority so the app can override).
            if (!empty($package['config_path'])) {
                $config->addSource($package['config_path'], Config::PRIORITY_PACKAGE);
                $logger->debug('Registered package config source', [
                    'package' => $name,
                    'path'    => $package['config_path'],
                ]);
            }

            // 2. Migration source.
            if (!empty($package['migrations_path'])) {
                $namespace = (string) ($package['migrations_namespace'] ?? '');
                if ($namespace === '') {
                    $logger->warning(
                        'Package migration source skipped: missing migrations_namespace in extra.reactor',
                        ['package' => $name, 'path' => $package['migrations_path']]
                    );
                } else {
                    $migrations->add($package['migrations_path'], $namespace);
                    $logger->debug('Registered package migration source', [
                        'package'   => $name,
                        'namespace' => $namespace,
                    ]);
                }
            }

            // 3. Class aliases.
            foreach (($package['aliases'] ?? []) as $alias => $target) {
                if (!class_exists($target) && !interface_exists($target)) {
                    $logger->warning('Package alias target not found, skipping', [
                        'package' => $name,
                        'alias'   => $alias,
                        'target'  => $target,
                    ]);
                    continue;
                }
                if (class_exists($alias, false) || interface_exists($alias, false)) {
                    $logger->debug('Package alias already defined, skipping', [
                        'package' => $name,
                        'alias'   => $alias,
                    ]);
                    continue;
                }
                class_alias($target, $alias);
                $logger->debug('Registered package class alias', [
                    'package' => $name,
                    'alias'   => $alias,
                    'target'  => $target,
                ]);
            }
        }
    }

    /**
     * Fully-qualified service provider class names from every package.
     *
     * @return array<int, string>
     */
    public function providerClasses(): array
    {
        $providers = [];

        foreach ($this->manifest->all() as $package) {
            foreach (($package['providers'] ?? []) as $class) {
                if (is_string($class) && $class !== '') {
                    $providers[] = $class;
                }
            }
        }

        return array_values(array_unique($providers));
    }
}
