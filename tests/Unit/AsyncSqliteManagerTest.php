<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Database\Async\AsyncSqliteManager;

class AsyncSqliteManagerTest extends TestCase
{
    public function testParameterizedSelectsWorkInsideAndOutsideTransactions(): void
    {
        $db = new AsyncSqliteManager(['database' => ':memory:']);
        $db->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)')->await();
        $db->execute('INSERT INTO items VALUES (:id, :name)', ['id' => 1, 'name' => 'first'])->await();
        self::assertSame(['id' => 1, 'name' => 'first'],
            $db->fetchOne('SELECT * FROM items WHERE id = :id', ['id' => 1])->await());
        self::assertSame([['id' => 1]], $db->fetchAll('SELECT id FROM items WHERE name = ?', ['first'])->await());
        self::assertNull($db->fetchOne('SELECT * FROM items WHERE id = ?', [2])->await());
        $db->transaction(function ($tx) {
            $tx->execute('INSERT INTO items VALUES (?, ?)', [2, 'second'])->await();
            self::assertSame(['name' => 'second'],
                $tx->fetchOne('SELECT name FROM items WHERE id = ?', [2])->await());
            self::assertCount(2, $tx->fetchAll('SELECT id FROM items WHERE id >= :min', ['min' => 1])->await());
        })->await();
        self::assertCount(2, $db->fetchAll('SELECT * FROM items')->await());
    }

    public function testFailedTransactionRollsBack(): void
    {
        $db = new AsyncSqliteManager(['database' => ':memory:']);
        $db->execute('CREATE TABLE items (id INTEGER)')->await();
        try {
            $db->transaction(function ($tx) {
                $tx->execute('INSERT INTO items VALUES (?)', [7])->await();
                throw new \RuntimeException('rollback');
            })->await();
            self::fail('Expected transaction failure');
        } catch (\RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        self::assertSame([], $db->fetchAll('SELECT * FROM items')->await());
    }
    public function testNestedTransactionsUseSavepointsAndCanRollBackIndependently(): void
    {
        $db = new AsyncSqliteManager(['database' => ':memory:']);
        $db->execute('CREATE TABLE items (id INTEGER)')->await();
        $result = $db->transaction(function ($outer) {
            $outer->execute('INSERT INTO items VALUES (1)')->await();
            $value = $outer->transaction(function ($inner) {
                $inner->execute('INSERT INTO items VALUES (2)')->await();
                return $inner->transaction(function ($deep) {
                    $deep->execute('INSERT INTO items VALUES (3)')->await();
                    return 'nested result';
                });
            })->await();
            self::assertSame('nested result', $value);
            try {
                $outer->transaction(function ($inner) {
                    $inner->execute('INSERT INTO items VALUES (4)')->await();
                    throw new \RuntimeException('inner rollback');
                })->await();
                self::fail('Expected inner rollback');
            } catch (\RuntimeException $e) {
                self::assertSame('inner rollback', $e->getMessage());
            }
            $outer->execute('INSERT INTO items VALUES (5)')->await();
            return $value;
        })->await(new \Amp\TimeoutCancellation(5));
        self::assertSame('nested result', $result);
        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 5]],
            $db->fetchAll('SELECT id FROM items ORDER BY id')->await());
    }

    public function testOuterRollbackAlsoUndoesCommittedSavepoints(): void
    {
        $db = new AsyncSqliteManager(['database' => ':memory:']);
        $db->execute('CREATE TABLE items (id INTEGER)')->await();
        try {
            $db->transaction(function ($outer) {
                $outer->transaction(fn ($inner) => $inner->execute('INSERT INTO items VALUES (1)'))->await();
                throw new \RuntimeException('outer rollback');
            })->await(new \Amp\TimeoutCancellation(5));
            self::fail('Expected outer rollback');
        } catch (\RuntimeException $e) {
            self::assertSame('outer rollback', $e->getMessage());
        }
        self::assertSame([], $db->fetchAll('SELECT * FROM items')->await());
    }

}
