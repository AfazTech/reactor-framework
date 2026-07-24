<?php
namespace Reactor\Contracts;

interface JobInterface
{
    public function handle(): void;
}
