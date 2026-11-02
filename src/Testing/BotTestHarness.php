<?php

namespace Reactor\Testing;

use PHPUnit\Framework\Assert;
use Reactor\Core\App;
use Reactor\Core\EventDispatcher;
use Reactor\Contracts\UserProviderInterface;
use Reactor\Events;

final class BotTestHarness
{
    private array $handled = [];
    public function __construct(private App $app, public readonly FakeTelegram $telegram = new FakeTelegram())
    {
        $client = $app->getClient() ?? throw new \LogicException('Configure a test token before constructing the application.');
        $client->setHttpClient($telegram);
        $app->getContainer()->get(EventDispatcher::class)->on(Events::HANDLER_EXECUTED,
            function (string $class) { $this->handled[] = $class; });
    }
    public function receive(array $update): self
    {
        $this->app->processUpdate($update); $this->app->getClient()->flush(); return $this;
    }
    public function assertHandledBy(string $class): void { Assert::assertContains($class, $this->handled); }
    public function assertStep(int $userId, ?string $step): void
    {
        Assert::assertSame($step, $this->app->getContainer()->get(UserProviderInterface::class)->getStep($userId));
    }
}
