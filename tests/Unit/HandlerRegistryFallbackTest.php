<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\Container;
use Reactor\Core\Routing\HandlerRegistry;
use Reactor\Contracts\LoggerInterface;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Verifies that #[Fallback] priority semantics are honored by the registry.
 *
 * The #[Fallback] attribute documents "higher priority = earlier". This
 * test locks that behavior in so that future refactors cannot silently
 * regress to "last discovered wins".
 */
class HandlerRegistryFallbackTest extends TestCase
{
    private HandlerRegistry $registry;
    private Container&MockObject $container;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->container = $this->createMock(Container::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->registry = new HandlerRegistry($this->container, $this->logger);
    }

    /** @test */
    public function it_stores_the_first_fallback_when_none_registered_yet(): void
    {
        $this->registry->setFallbackHandler('App\\Handlers\\FirstFallback', 0);

        $fallback = $this->registry->getFallbackHandler();
        $this->assertNotNull($fallback);
        $this->assertSame('App\\Handlers\\FirstFallback', $fallback['class']);
        $this->assertSame(0, $fallback['priority']);
    }

    /** @test */
    public function it_replaces_fallback_when_new_priority_is_higher(): void
    {
        $this->registry->setFallbackHandler('App\\Handlers\\LowPriority', 5);
        $this->registry->setFallbackHandler('App\\Handlers\\HighPriority', 50);

        $fallback = $this->registry->getFallbackHandler();
        $this->assertNotNull($fallback);
        $this->assertSame('App\\Handlers\\HighPriority', $fallback['class']);
        $this->assertSame(50, $fallback['priority']);
    }

    /** @test */
    public function it_keeps_existing_fallback_when_new_priority_is_lower(): void
    {
        $this->registry->setFallbackHandler('App\\Handlers\\HighPriority', 50);
        $this->registry->setFallbackHandler('App\\Handlers\\LowPriority', 5);

        $fallback = $this->registry->getFallbackHandler();
        $this->assertNotNull($fallback);
        $this->assertSame('App\\Handlers\\HighPriority', $fallback['class']);
        $this->assertSame(50, $fallback['priority']);
    }

    /** @test */
    public function it_keeps_existing_fallback_when_priorities_are_equal(): void
    {
        $this->registry->setFallbackHandler('App\\Handlers\\FirstFallback', 10);
        $this->registry->setFallbackHandler('App\\Handlers\\SecondFallback', 10);

        $fallback = $this->registry->getFallbackHandler();
        $this->assertNotNull($fallback);
        $this->assertSame('App\\Handlers\\FirstFallback', $fallback['class']);
        $this->assertSame(10, $fallback['priority']);
    }

    /** @test */
    public function it_uses_first_registered_as_tie_breaker(): void
    {
        // Three candidates with the same priority; the first must win.
        $this->registry->setFallbackHandler('App\\Handlers\\A', 7);
        $this->registry->setFallbackHandler('App\\Handlers\\B', 7);
        $this->registry->setFallbackHandler('App\\Handlers\\C', 7);

        $fallback = $this->registry->getFallbackHandler();
        $this->assertNotNull($fallback);
        $this->assertSame('App\\Handlers\\A', $fallback['class']);
    }

    /** @test */
    public function it_selects_the_highest_priority_among_many_candidates(): void
    {
        // Simulate discovery order that is not aligned with priority.
        $this->registry->setFallbackHandler('App\\Handlers\\Middle', 30);
        $this->registry->setFallbackHandler('App\\Handlers\\Highest', 99);
        $this->registry->setFallbackHandler('App\\Handlers\\Lowest', 1);
        $this->registry->setFallbackHandler('App\\Handlers\\High', 60);

        $fallback = $this->registry->getFallbackHandler();
        $this->assertNotNull($fallback);
        $this->assertSame('App\\Handlers\\Highest', $fallback['class']);
        $this->assertSame(99, $fallback['priority']);
    }

    /** @test */
    public function it_supports_negative_priorities(): void
    {
        $this->registry->setFallbackHandler('App\\Handlers\\VeryLow', -10);
        $this->registry->setFallbackHandler('App\\Handlers\\Zero', 0);

        $fallback = $this->registry->getFallbackHandler();
        $this->assertNotNull($fallback);
        $this->assertSame('App\\Handlers\\Zero', $fallback['class']);
        $this->assertSame(0, $fallback['priority']);
    }
}
