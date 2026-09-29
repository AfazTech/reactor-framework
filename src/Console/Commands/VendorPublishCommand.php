<?php
namespace Reactor\Console\Commands;

use Reactor\Contracts\PackageServiceProviderInterface;
use Reactor\Contracts\ServiceProviderInterface;
use Reactor\Core\Container;
use Reactor\Core\Packages\PackageManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Copies publishable assets from packages into the host application.
 *
 * A package service provider implements PackageServiceProviderInterface
 * and declares its assets in publishes():
 *
 *   public function publishes(): array
 *   {
 *       return [
 *           __DIR__ . '/../config/my-package.php' => config_path('my-package.php'),
 *           __DIR__ . '/../database/migrations'   => database_path('migrations'),
 *       ];
 *   }
 *
 * Usage:
 *   php reactor.php vendor:publish "Vendor\Pkg\Provider"   # one provider
 *   php reactor.php vendor:publish --all                   # every package
 *   php reactor.php vendor:publish --all --force           # overwrite
 */
#[AsCommand(name: 'vendor:publish', description: 'Publish assets from a package')]
class VendorPublishCommand extends Command
{
    public function __construct(
        private Container $container,
        private PackageManifest $manifest
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'provider',
                InputArgument::OPTIONAL,
                'Fully-qualified provider class whose assets to publish'
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Publish assets from every package provider'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing files at the destination'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $providerClass = $input->getArgument('provider');
        $publishAll = (bool) $input->getOption('all');
        $force = (bool) $input->getOption('force');

        if (!$publishAll && ($providerClass === null || $providerClass === '')) {
            $output->writeln('<error>Provide a provider class or pass --all.</error>');
            return Command::INVALID;
        }

        $providers = $publishAll
            ? $this->providersFromManifest()
            : $this->providerFromClass((string) $providerClass);

        if ($providers === []) {
            $output->writeln('<error>No matching service provider found.</error>');
            return Command::FAILURE;
        }

        $published = 0;
        foreach ($providers as $provider) {
            if (!$provider instanceof PackageServiceProviderInterface) {
                $output->writeln(
                    '<comment>Skipping ' . get_class($provider)
                    . ': does not implement PackageServiceProviderInterface.</comment>'
                );
                continue;
            }

            foreach ($provider->publishes() as $source => $destination) {
                if (!is_string($source) || !is_string($destination)) {
                    continue;
                }
                $published += $this->publishPath($source, $destination, $force, $output);
            }
        }

        $output->writeln("<info>Published {$published} asset(s).</info>");
        return Command::SUCCESS;
    }

    /**
     * @return array<int, ServiceProviderInterface>
     */
    private function providersFromManifest(): array
    {
        $providers = [];

        foreach ($this->manifest->all() as $package) {
            foreach (($package['providers'] ?? []) as $class) {
                if (!is_string($class) || !class_exists($class)) {
                    continue;
                }
                try {
                    $instance = $this->container->get($class);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($instance instanceof ServiceProviderInterface) {
                    $providers[] = $instance;
                }
            }
        }

        return $providers;
    }

    /**
     * @return array<int, ServiceProviderInterface>
     */
    private function providerFromClass(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }

        try {
            $instance = $this->container->get($class);
        } catch (\Throwable $e) {
            return [];
        }

        return $instance instanceof ServiceProviderInterface ? [$instance] : [];
    }

    private function publishPath(string $source, string $destination, bool $force, OutputInterface $output): int
    {
        if (!file_exists($source)) {
            $output->writeln("<comment>Source not found, skipping: {$source}</comment>");
            return 0;
        }

        if (is_dir($source)) {
            return $this->publishDirectory($source, $destination, $force, $output);
        }

        return $this->publishFile($source, $destination, $force, $output);
    }

    private function publishDirectory(string $source, string $destination, bool $force, OutputInterface $output): int
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen(rtrim($source, '/')) + 1);
            $target = rtrim($destination, '/') . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }

            $count += $this->publishFile($item->getPathname(), $target, $force, $output);
        }

        return $count;
    }

    private function publishFile(string $source, string $destination, bool $force, OutputInterface $output): int
    {
        if (file_exists($destination) && !$force) {
            $output->writeln("<comment>Skipped (exists): {$destination}</comment>");
            return 0;
        }

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!copy($source, $destination)) {
            $output->writeln("<error>Failed to publish: {$source} -> {$destination}</error>");
            return 0;
        }

        $output->writeln("<info>Published: {$destination}</info>");
        return 1;
    }
}
