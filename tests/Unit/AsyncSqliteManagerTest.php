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
}
