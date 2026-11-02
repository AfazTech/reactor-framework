<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Contracts\LoggerInterface;
use Reactor\Core\Container;
use Reactor\Core\Schedule\Event;

class ScheduleOverlapTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/reactor-schedule-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testAnIndependentContenderCannotRunUntilTheHolderFinishes(): void
    {
        $container = new Container();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $runs = 0;
        $contender = (new Event(function () use (&$runs) { $runs++; }, null, $this->directory))
            ->name('shared')->withoutOverlapping();
        $holder = (new Event(function () use ($contender, $container, $logger) {
            $contender->run($container, $logger);
        }, null, $this->directory))->name('shared')->withoutOverlapping();

        $holder->run($container, $logger);
        self::assertSame(0, $runs);
        $contender->run($container, $logger);
        self::assertSame(1, $runs);
        self::assertFileExists($this->directory . '/shared.lock');
    }

    public function testTheLockIsHeldAgainstAnotherProcess(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $event = (new Event(function () {
            $code = '$h=fopen($argv[1], "c+"); exit(flock($h, LOCK_EX | LOCK_NB) ? 0 : 7);';
            $process = proc_open([PHP_BINARY, '-r', $code, $this->directory . '/shared.lock'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            self::assertSame(7, proc_close($process), 'A second process must not acquire the live lock.');
        }, null, $this->directory))->name('shared')->withoutOverlapping();
        $event->run(new Container(), $logger);
    }

    public function testAnExceptionReleasesTheLockAndAStalePidDoesNotBlock(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $container = new Container();
        file_put_contents($this->directory . '/shared.lock', (string) getmypid());
        $broken = (new Event(function () { throw new \RuntimeException('failed task'); }, null, $this->directory))
            ->name('shared')->withoutOverlapping();
        $broken->run($container, $logger);
        $ran = false;
        $next = (new Event(function () use (&$ran) { $ran = true; }, null, $this->directory))
            ->name('shared')->withoutOverlapping();
        $next->run($container, $logger);
        self::assertTrue($ran);
    }
}
