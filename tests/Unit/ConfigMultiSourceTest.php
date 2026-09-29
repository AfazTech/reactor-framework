<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Reactor\Core\Config;

/**
 * Covers the multi-source config layering:
 *
 *   package (low priority)  <  application (100)  <  runtime overrides
 *
 * Sources are created on disk so the test exercises the actual file
 * loading path, not a mock.
 */
class ConfigMultiSourceTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    private function makeDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/reactor_config_' . $prefix . '_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function writeConfig(string $dir, string $name, array $values): void
    {
        $export = var_export($values, true);
        file_put_contents($dir . '/' . $name . '.php', "<?php\n\nreturn {$export};\n");
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*') ?: [];
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    /** @test */
    public function it_loads_values_from_a_single_source(): void
    {
        $app = $this->makeDir('app');
        $this->writeConfig($app, 'app', ['name' => 'Reactor', 'debug' => false]);

        $config = new Config($app);

        $this->assertSame('Reactor', $config->get('app.name'));
        $this->assertFalse($config->get('app.debug'));
    }

    /** @test */
    public function application_source_overrides_package_source(): void
    {
        $pkg = $this->makeDir('pkg');
        $app = $this->makeDir('app');

        $this->writeConfig($pkg, 'bot', ['token' => 'pkg-token', 'mode' => 'webhook']);
        $this->writeConfig($app, 'bot', ['token' => 'app-token']);

        $config = new Config($app);
        $config->addSource($pkg, Config::PRIORITY_PACKAGE);

        $this->assertSame('app-token', $config->get('bot.token'));
        // Package value not overridden by app is preserved.
        $this->assertSame('webhook', $config->get('bot.mode'));
    }

    /** @test */
    public function deep_merge_preserves_untouched_nested_keys(): void
    {
        $pkg = $this->makeDir('pkg');
        $app = $this->makeDir('app');

        $this->writeConfig($pkg, 'database', [
            'connections' => [
                'mysql' => [
                    'host'     => 'pkg-host',
                    'port'     => 3306,
                    'database' => 'pkg-db',
                ],
            ],
        ]);
        $this->writeConfig($app, 'database', [
            'connections' => [
                'mysql' => [
                    'host' => 'app-host',
                ],
            ],
        ]);

        $config = new Config($app);
        $config->addSource($pkg, Config::PRIORITY_PACKAGE);

        $mysql = $config->get('database.connections.mysql');
        $this->assertSame('app-host', $mysql['host']);
        $this->assertSame(3306, $mysql['port']);
        $this->assertSame('pkg-db', $mysql['database']);
    }

    /** @test */
    public function list_arrays_are_replaced_not_merged(): void
    {
        $pkg = $this->makeDir('pkg');
        $app = $this->makeDir('app');

        $this->writeConfig($pkg, 'cache', [
            'stores' => [
                'memcached' => [
                    'servers' => [
                        ['host' => 'pkg1', 'port' => 11211],
                        ['host' => 'pkg2', 'port' => 11211],
                    ],
                ],
            ],
        ]);
        $this->writeConfig($app, 'cache', [
            'stores' => [
                'memcached' => [
                    'servers' => [
                        ['host' => 'app1', 'port' => 11211],
                    ],
                ],
            ],
        ]);

        $config = new Config($app);
        $config->addSource($pkg, Config::PRIORITY_PACKAGE);

        $servers = $config->get('cache.stores.memcached.servers');
        $this->assertCount(1, $servers);
        $this->assertSame('app1', $servers[0]['host']);
    }

    /** @test */
    public function higher_priority_source_overrides_application(): void
    {
        $app = $this->makeDir('app');
        $preset = $this->makeDir('preset');

        $this->writeConfig($app, 'app', ['name' => 'Reactor']);
        $this->writeConfig($preset, 'app', ['name' => 'Override']);

        $config = new Config($app);
        $config->addSource($preset, Config::PRIORITY_APPLICATION + 1);

        $this->assertSame('Override', $config->get('app.name'));
    }

    /** @test */
    public function runtime_override_beats_every_source(): void
    {
        $pkg = $this->makeDir('pkg');
        $app = $this->makeDir('app');

        $this->writeConfig($pkg, 'bot', ['token' => 'pkg']);
        $this->writeConfig($app, 'bot', ['token' => 'app']);

        $config = new Config($app);
        $config->addSource($pkg, Config::PRIORITY_PACKAGE);
        $config->override('bot.token', 'runtime');

        $this->assertSame('runtime', $config->get('bot.token'));
    }

    /** @test */
    public function runtime_override_can_target_nested_keys_without_losing_siblings(): void
    {
        $app = $this->makeDir('app');
        $this->writeConfig($app, 'bot', [
            'token'   => 'original',
            'api_url' => 'https://api.telegram.org/bot',
        ]);

        $config = new Config($app);
        $config->override('bot.token', 'new-token');

        $this->assertSame('new-token', $config->get('bot.token'));
        $this->assertSame('https://api.telegram.org/bot', $config->get('bot.api_url'));
    }

    /** @test */
    public function add_source_is_idempotent_for_the_same_path_and_priority(): void
    {
        $pkg = $this->makeDir('pkg');
        $app = $this->makeDir('app');

        $this->writeConfig($pkg, 'app', ['name' => 'Pkg']);
        $this->writeConfig($app, 'app', ['name' => 'App']);

        $config = new Config($app);
        $config->addSource($pkg, Config::PRIORITY_PACKAGE);
        $config->addSource($pkg, Config::PRIORITY_PACKAGE);

        $this->assertSame('App', $config->get('app.name'));
    }

    /** @test */
    public function multiple_package_sources_merge_in_registration_order(): void
    {
        $pkgA = $this->makeDir('pkgA');
        $pkgB = $this->makeDir('pkgB');
        $app = $this->makeDir('app');

        $this->writeConfig($pkgA, 'app', ['name' => 'A', 'env' => 'prod']);
        $this->writeConfig($pkgB, 'app', ['name' => 'B']);
        $this->writeConfig($app, 'app', ['name' => 'App']);

        $config = new Config($app);
        $config->addSource($pkgA, Config::PRIORITY_PACKAGE);
        $config->addSource($pkgB, Config::PRIORITY_PACKAGE);

        // Application always wins on conflicting keys.
        $this->assertSame('App', $config->get('app.name'));
        $this->assertSame('prod', $config->get('app.env'));
    }

    /** @test */
    public function has_returns_true_for_existing_keys_and_false_otherwise(): void
    {
        $app = $this->makeDir('app');
        $this->writeConfig($app, 'app', ['name' => 'Reactor']);

        $config = new Config($app);

        $this->assertTrue($config->has('app.name'));
        $this->assertFalse($config->has('app.missing'));
        $this->assertFalse($config->has('nonexistent'));
    }

    /** @test */
    public function set_config_path_replaces_the_application_source_only(): void
    {
        $pkg = $this->makeDir('pkg');
        $app = $this->makeDir('app');
        $app2 = $this->makeDir('app2');

        $this->writeConfig($pkg, 'app', ['name' => 'Pkg', 'env' => 'prod']);
        $this->writeConfig($app, 'app', ['name' => 'App1']);
        $this->writeConfig($app2, 'app', ['name' => 'App2']);

        $config = new Config($app);
        $config->addSource($pkg, Config::PRIORITY_PACKAGE);

        $config->setConfigPath($app2);

        $this->assertSame('App2', $config->get('app.name'));
        // The package value survives the app path change.
        $this->assertSame('prod', $config->get('app.env'));
    }

    /** @test */
    public function set_token_helpers_are_stored_as_runtime_overrides(): void
    {
        $app = $this->makeDir('app');
        $this->writeConfig($app, 'bot', ['token' => 'from-file']);

        $config = new Config($app);
        $config->setToken('runtime-token');
        $config->setApiUrl('https://custom.example/bot');

        $this->assertSame('runtime-token', $config->getToken());
        $this->assertSame('https://custom.example/bot', $config->getApiUrl());
    }

    /** @test */
    public function forget_override_removes_a_runtime_override(): void
    {
        $app = $this->makeDir('app');
        $this->writeConfig($app, 'bot', ['token' => 'from-file']);

        $config = new Config($app);
        $config->override('bot.token', 'runtime');
        $this->assertSame('runtime', $config->get('bot.token'));

        $config->forgetOverride('bot.token');
        $this->assertSame('from-file', $config->get('bot.token'));
    }

    /** @test */
    public function missing_source_directory_is_ignored(): void
    {
        $app = $this->makeDir('app');
        $this->writeConfig($app, 'app', ['name' => 'Reactor']);

        $config = new Config($app);
        $config->addSource('/this/path/does/not/exist', Config::PRIORITY_PACKAGE);

        $this->assertSame('Reactor', $config->get('app.name'));
    }
}
