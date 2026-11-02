<?php

namespace Reactor\Diagnostics;

use Amp\File;
use Neili\Client;
use Reactor\Contracts\AsyncDatabaseManagerInterface;
use Reactor\Contracts\CacheInterface;
use Reactor\Core\Config;
use Reactor\Core\Container;
use Reactor\Core\Paths;

final class Doctor
{
    public function __construct(private Container $container, private Config $config, private Paths $paths) {}
    public function checks(bool $telegram = false): array
    {
        $checks = [];
        $check = function (string $name, callable $test) use (&$checks) {
            try {
                $detail = $test();
                $checks[] = ['name' => $name, 'status' => 'pass', 'detail' => $detail];
            } catch (\Throwable $e) {
                // Transport exceptions can contain token-bearing URLs or DSNs.
                $checks[] = ['name' => $name, 'status' => 'fail', 'detail' => 'Check failed: ' . get_class($e)];
            }
        };
        $check('php', function () {
            if (PHP_VERSION_ID < 80400) { throw new \RuntimeException('PHP 8.4 is required.'); }
            return PHP_VERSION;
        });
        $driver = null;
        $check('database_configuration', function () use (&$driver) {
            $driver = $this->config->getDatabaseConfig()['driver'] ?? 'sqlite';
            return 'configuration found';
        });
        foreach (array_unique(['openssl', 'pdo', ...($driver === 'sqlite' ? ['sqlite3', 'pdo_sqlite'] : [])]) as $extension) {
            $check('extension:' . $extension, function () use ($extension) {
                if (!extension_loaded($extension)) { throw new \RuntimeException('Missing extension.'); }
                return 'loaded';
            });
        }
        $check('storage', function () {
            $directory = $this->paths->storage() . '/framework';
            if (!File\isDirectory($directory)) { File\createDirectoryRecursively($directory); }
            $file = $directory . '/doctor-' . bin2hex(random_bytes(8));
            try { File\write($file, 'ready'); if (File\read($file) !== 'ready') { throw new \RuntimeException(); } }
            finally { if (File\exists($file)) { File\deleteFile($file); } }
            return 'read/write OK';
        });
        $check('async_database', function () {
            $this->container->get(AsyncDatabaseManagerInterface::class)->fetchOne('SELECT 1 AS ready')->await();
            return 'query OK';
        });
        $check('queue_table', function () {
            $db = $this->container->get(AsyncDatabaseManagerInterface::class);
            $table = (string) $this->config->get('queue.table', 'jobs');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) { throw new \InvalidArgumentException(); }
            $quote = in_array($db->getDriver(), ['mysql', 'mariadb'], true) ? '`' : '"';
            $db->fetchAll('SELECT 1 FROM ' . $quote . $table . $quote . ' LIMIT 0')->await();
            return 'table accessible';
        });
        $check('cache', function () {
            $cache = $this->container->get(CacheInterface::class);
            $key = 'reactor:doctor:' . bin2hex(random_bytes(8));
            try {
                if (!$cache->set($key, 'ready', 10)->await() || $cache->get($key)->await() !== 'ready') { throw new \RuntimeException(); }
            } finally { $cache->delete($key)->await(); }
            return 'read/write OK';
        });
        if ($telegram) {
            $check('telegram', function () {
                $response = $this->container->get(Client::class)->getMe()->await();
                if (!($response['ok'] ?? false)) { throw new \RuntimeException(); }
                return 'getMe OK';
            });
        }
        return $checks;
    }
}
