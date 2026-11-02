<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\UpdateScope;

class UpdateScopeTest extends TestCase
{
    public function testCachesNullAndClearsTheFrameOnExit(): void
    {
        $scope = new UpdateScope();
        $reads = 0;
        $read = function () use (&$reads) { $reads++; return null; };
        $scope->run(function () use ($scope, $read) {
            self::assertNull($scope->remember('key', $read));
            self::assertNull($scope->remember('key', $read));
        });
        self::assertSame(1, $reads);
        $scope->run(fn () => $scope->remember('key', $read));
        self::assertSame(2, $reads);
        $scope->remember('key', $read);
        $scope->remember('key', $read);
        self::assertSame(4, $reads, 'Outside an update, reads must not be cached.');
    }

    public function testNestedScopesAndExceptionsRestoreTheParent(): void
    {
        $scope = new UpdateScope();
        $scope->run(function () use ($scope) {
            self::assertSame('outer', $scope->remember('key', fn () => 'outer'));
            try {
                $scope->run(function () use ($scope) {
                    self::assertSame('inner', $scope->remember('key', fn () => 'inner'));
                    throw new \RuntimeException('test');
                });
            } catch (\RuntimeException $e) {
                self::assertSame('test', $e->getMessage());
            }
            self::assertSame('outer', $scope->remember('key', fn () => 'wrong'));
        });
        self::assertSame('fresh', $scope->run(fn () => $scope->remember('key', fn () => 'fresh')));
    }

    public function testConcurrentFibersNeverShareTheirCache(): void
    {
        $scope = new UpdateScope();
        $futures = [];
        foreach (['first', 'second'] as $value) {
            $futures[] = \Amp\async(fn () => $scope->run(function () use ($scope, $value) {
                $scope->remember('key', fn () => $value);
                \Amp\delay(0.01);
                return $scope->remember('key', fn () => 'wrong');
            }));
        }
        self::assertSame(['first', 'second'], \Amp\Future\await($futures));
    }
}
