<?php

namespace Reactor\Console\Commands;

use Reactor\Diagnostics\Doctor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'doctor', description: 'Check runtime, storage, async database, queue and cache readiness')]
final class DoctorCommand extends Command
{
    public function __construct(private Doctor $doctor) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
        $this->addOption('telegram', null, InputOption::VALUE_NONE, 'Also perform a read-only Telegram getMe request');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checks = $this->doctor->checks((bool) $input->getOption('telegram'));
        if ($input->getOption('json')) { $output->writeln(json_encode($checks, JSON_THROW_ON_ERROR)); }
        else { foreach ($checks as $check) { $output->writeln($check['status'] . ': ' . $check['name'] . ' - ' . $check['detail']); } }
        return count(array_filter($checks, fn ($check) => $check['status'] === 'fail')) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
