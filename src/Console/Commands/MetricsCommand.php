<?php

namespace Reactor\Console\Commands;

use Amp\File;
use Reactor\Core\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'metrics', description: 'Read the latest metrics snapshot exported by the polling process')]
final class MetricsCommand extends Command
{
    public function __construct(private Paths $paths) { parent::__construct(); }
    protected function configure(): void { $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Snapshot file override'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getOption('file') ?? $this->paths->storage() . '/framework/metrics.json';
        try {
            $data = json_decode(File\read($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !isset($data['pid'], $data['observed_at'])) { throw new \RuntimeException(); }
            $data['snapshot_age_seconds'] = max(0, time() - $data['observed_at']);
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>No valid polling metrics snapshot is available.</error>');
            return self::FAILURE;
        }
    }
}
