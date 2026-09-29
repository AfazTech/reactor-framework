<?php
namespace Reactor\Console\Commands;

use Reactor\Core\Packages\PackageManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds the cached package manifest.
 *
 * The manifest is normally refreshed automatically when
 * vendor/composer/installed.json is newer than the cached file. Running
 * this command forces a rebuild regardless, which is useful after
 * manually editing a package's composer.json or when the cache was
 * deleted while debugging.
 */
#[AsCommand(name: 'package:discover', description: 'Rebuild the cached package manifest')]
class PackageDiscoverCommand extends Command
{
    public function __construct(private PackageManifest $manifest)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->manifest->rebuild();
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to rebuild package manifest: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $packages = $this->manifest->all();
        $output->writeln(sprintf(
            '<info>Package manifest rebuilt: %d package(s) discovered.</info>',
            count($packages)
        ));
        $output->writeln('<comment>Cache file: ' . $this->manifest->manifestPath() . '</comment>');

        foreach ($packages as $package) {
            $output->writeln('  - ' . ($package['name'] ?? 'unknown'));
        }

        return Command::SUCCESS;
    }
}
