<?php

namespace Tests\Unit;

use Amp\Mysql\MysqlTransaction;
use Amp\Postgres\PostgresTransaction;
use PHPUnit\Framework\TestCase;
use Reactor\Database\Async\AsyncMysqlManager;
use Reactor\Database\Async\AsyncPostgresManager;

class NestedNetworkTransactionTest extends TestCase
{
    /** @dataProvider drivers */
    public function testNestedTransactionStartsOnTheActiveTransaction(string $managerClass, string $transactionClass): void
    {
        $outer = $this->createMock($transactionClass);
        $inner = $this->createMock($transactionClass);
        $outer->expects(self::once())->method('beginTransaction')->willReturn($inner);
        $outer->expects(self::never())->method('commit');
        $inner->expects(self::once())->method('commit');
        $inner->expects(self::never())->method('rollback');
        $manager = (new \ReflectionClass($managerClass))->newInstanceWithoutConstructor();
        $adapter = (new \ReflectionMethod($managerClass, 'wrap'))->invoke($manager, $outer);
        self::assertSame('nested', $adapter->transaction(fn () => 'nested')->await());
    }

    public static function drivers(): array
    {
        return [[AsyncMysqlManager::class, MysqlTransaction::class],
            [AsyncPostgresManager::class, PostgresTransaction::class]];
    }
}
