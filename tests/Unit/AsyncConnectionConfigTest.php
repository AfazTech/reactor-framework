<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Cache\Drivers\MysqlCache;
use Reactor\Cache\Drivers\PostgresCache;
use Reactor\Database\Async\AsyncMysqlManager;
use Reactor\Database\Async\AsyncPostgresManager;

class AsyncConnectionConfigTest extends TestCase
{
    public function testCredentialsArePreservedWithoutParsingAConnectionString(): void
    {
        foreach ([AsyncMysqlManager::class, MysqlCache::class, AsyncPostgresManager::class, PostgresCache::class] as $class) {
            foreach (['', 'with spaces', 'semi;colon', 'equals=value', "quotes'\"and\\slashes", " leading and trailing \t"] as $password) {
                $instance = new $class([
                    'host' => '127.0.0.1',
                    'port' => '13306',
                    'username' => 'user name;=test',
                    'password' => $password,
                    'database' => 'database name;=test',
                ]);
                $pool = (new \ReflectionProperty($class, 'pool'))->getValue($instance);
                $config = $pool->getConfig();

                self::assertSame('127.0.0.1', $config->getHost());
                self::assertSame(13306, $config->getPort());
                self::assertSame('user name;=test', $config->getUser());
                self::assertSame($password, $config->getPassword());
                self::assertSame('database name;=test', $config->getDatabase());
            }
        }
    }

    public function testDefaultConnectionSettingsRemainSupported(): void
    {
        foreach ([AsyncMysqlManager::class, MysqlCache::class, AsyncPostgresManager::class, PostgresCache::class] as $class) {
            $instance = new $class([]);
            $config = (new \ReflectionProperty($class, 'pool'))->getValue($instance)->getConfig();
            $mysql = in_array($class, [AsyncMysqlManager::class, MysqlCache::class], true);

            self::assertSame('127.0.0.1', $config->getHost());
            self::assertSame($mysql ? 3306 : 5432, $config->getPort());
            self::assertSame($mysql ? 'root' : 'postgres', $config->getUser());
            self::assertSame('', $config->getPassword());
            self::assertSame('reactor', $config->getDatabase());
        }
    }
}
