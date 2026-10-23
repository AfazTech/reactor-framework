# Reactor — Documentation

Complete reference for the `afaztech/reactor-framework` package.

---

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Architecture](#architecture)
- [Application Lifecycle](#application-lifecycle)
- [The Container](#the-container)
- [Configuration](#configuration)
- [Routing](#routing)
- [Handlers](#handlers)
- [Middleware](#middleware)
- [Events](#events)
- [Error Handling](#error-handling)
- [Language](#language)
- [Database](#database)
- [Migrations](#migrations)
- [Seeders](#seeders)
- [Queue](#queue)
- [Scheduler](#scheduler)
- [Cache](#cache)
- [Logging](#logging)
- [Service Providers](#service-providers)
- [Packages](#packages)
- [Bootstrappers](#bootstrappers)
- [Console Commands](#console-commands)
- [Helpers](#helpers)
- [Exceptions](#exceptions)
- [License](#license)

---

## Overview

Reactor is an asynchronous PHP framework for building Telegram bots. It provides the foundational services that any bot needs — dependency injection, routing, middleware, configuration, persistence, background jobs, scheduling, caching, and localisation — while staying small enough that the entire flow can be understood from the source.

The framework itself does not ship an application layout. Consumers install it as a package and either use it directly through the `Reactor\Core\App` orchestrator, or wire its services through their own entry point.

---

## Requirements

- PHP >= 8.1
- Composer
- `ext-pdo`, `ext-json`, `ext-mbstring`
- Optional: `ext-redis` or `ext-memcached` for those cache drivers

Runtime dependencies:

- `afaztech/neili` — Telegram client and long-polling poller
- `illuminate/database` — Eloquent
- `illuminate/events` — event helpers
- `monolog/monolog` — logging
- `vlucas/phpdotenv` — environment loading
- `symfony/console` — CLI

---

## Installation

```bash
composer require afaztech/reactor-framework
```

Nothing is auto-registered. The framework's core services are bound by `Reactor\Core\Providers\CoreServiceProvider`, which the `App` orchestrator resolves during its bootstrap. Applications that do not use `App` must register the provider themselves:

```php
use Reactor\Core\Container;
use Reactor\Core\Providers\CoreServiceProvider;

$container = new Container();
(new CoreServiceProvider())->register($container);
```

---

## Architecture

```
┌────────────────────────────────────────────────────┐
│                   Your application                  │
│  Handlers · Middleware · Service providers          │
└───────────────────────┬────────────────────────────┘
                        │  uses
                        ▼
┌────────────────────────────────────────────────────┐
│                     Reactor                         │
│  Container · Config · Router · Middleware           │
│  Events · Language · Database · Queue · Schedule    │
│  Cache · Logger · Packages · Console                │
└───────────────────────┬────────────────────────────┘
                        │  delegates I/O to
                        ▼
┌────────────────────────────────────────────────────┐
│              Neili  +  Amp  +  Eloquent             │
└────────────────────────────────────────────────────┘
```

The framework does not depend on Telegram at its core. `TelegramBootstrapper` (an optional bootstrapper) is what wires `Neili\Client` and `Neili\Poller` into the container. Omitting it produces a Telegram-free Reactor instance that still resolves config, database, queue, cache, and the CLI.

---

## Application Lifecycle

`Reactor\Core\App` is a convenience orchestrator. Its constructor:

1. Resolves the base path and defines `REACTOR_BASE_PATH`.
2. Loads `.env` via Dotenv when present.
3. Creates the container and config, then binds core singletons.
4. Registers the application migration source on `MigrationRegistrar`.
5. Runs `AppBootstrapper`, which:
   - registers the core providers,
   - applies package providers,
   - applies user-supplied providers,
   - runs the bootstrapper chain (`DatabaseBootstrapper` → `TelegramBootstrapper` → `MiddlewareBootstrapper` → `RouterBootstrapper` by default).
6. Resolves the logger, language, router, migrator, and `middlewares` list.
7. Hands the middleware list to `MiddlewareManager`.
8. Resolves `Neili\Client` (if bound) and the `UpdateProcessor`.
9. Acquires a polling lock manager handle.

`App::start()`:

- Throws when no token is configured or no `Neili\Poller` is bound.
- Returns early in webhook mode.
- Acquires a file lock (`LockManager`) to prevent two pollers.
- Registers an update handler that forwards to `processUpdate()`.
- Delegates to `Poller::start(false)`.

Custom bootstrapper chains are supported via the fifth constructor argument:

```php
$app = new App(
    basePath: __DIR__,
    providers: [App\Providers\AppServiceProvider::class],
    bootstrappers: [
        new Reactor\Core\Bootstrappers\DatabaseBootstrapper(),
        new Reactor\Core\Bootstrappers\MiddlewareBootstrapper($sources),
        new Reactor\Core\Bootstrappers\RouterBootstrapper($sources),
    ],
);
```

---

## The Container

`Reactor\Core\Container` is a PSR-11-compatible dependency injection container.

### Bindings

```php
$c->bind('key', fn($c) => new Service());
$c->factory('key', fn($c) => new Service());  // alias of bind()
$c->singleton('key', fn($c) => new Service());
$c->scoped('key', fn($c) => new Service());
$c->instance('key', new Service());
```

- **Transient** (`bind` / `factory`) — new instance on every resolve.
- **Singleton** (`singleton`) — one shared instance.
- **Scoped** (`scoped`) — one instance per scope; reset via `forgetScopedInstances()`.
- **Instance** (`instance`) — a pre-built object; wins over any binding.

Concrete arguments may be a closure, a class-string, or `null` (the abstract name itself becomes the class to auto-resolve).

### Aliases

```php
$c->alias('LoggerInterface', Logger::class);
```

Alias chains are resolved recursively and cycle-checked. Self-aliases and cycles throw `\InvalidArgumentException`.

### Contextual bindings

```php
$c->when(UserController::class)
  ->needs(LoggerInterface::class)
  ->give(DatabaseLogger::class);
```

`give()` accepts a class-string, a callable, or a pre-built value.

### Tagging

```php
$c->tag([ServiceA::class, ServiceB::class], 'readers');
$readers = $c->tagged('readers');
```

### Auto-resolution

`get()` falls back to reflection-based construction. Constructor parameters are resolved in the following order:

1. Contextual binding for the concrete class.
2. Recursive `get()` call.
3. Default value when the parameter has one.

Circular dependencies are detected and reported with the full chain.

Unions, intersections, and untyped or built-in parameters without a default value throw `ContainerResolutionException`.

### Forget methods

```php
$c->forgetInstance('key');           // drop a resolved singleton or instance
$c->forgetScopedInstances();         // start a fresh scope
```

---

## Configuration

`Reactor\Core\Config` aggregates values from multiple source directories, each with a priority.

### Layers

| Layer | Priority | Typical source |
|-------|----------|----------------|
| Package defaults | `Config::PRIORITY_PACKAGE` (10) | `vendor/*/config/` |
| Application config | `Config::PRIORITY_APPLICATION` (100) | `config/` |
| Runtime overrides | always last | `override()` |

### Usage

```php
$config->get('bot.token');
$config->get('bot.token', $default);
$config->has('bot.token');
$config->override('bot.token', 'xxx');
$config->forgetOverride('bot.token');
$config->addSource('/path/to/package/config', Config::PRIORITY_PACKAGE);
$config->setConfigPath('/path/to/app/config');
```

Deep associative arrays are merged recursively; list arrays and scalars are replaced as a whole.

### Typed helpers

| Method | Reads |
|--------|-------|
| `getToken()` | `bot.token` |
| `getApiUrl()` | `bot.api_url` |
| `getBotMode()` | `bot.mode` |
| `isDebugMode()` | `app.debug` |
| `isVerifySsl()` | `bot.verify_ssl` |
| `isMultiProcess()` | `bot.multi_process` |
| `getWebhookSecret()` | `bot.webhook_secret` |
| `getPhpBinary()` | `bot.php_binary` |
| `getDatabaseConfig()` | `database.connections.{default}` |

Boolean helpers normalize string values (`"false"`, `"0"`, `"no"`, `"off"`) to `false`.

`getDatabaseConfig()` resolves relative SQLite paths against `REACTOR_BASE_PATH`.

---

## Routing

`Reactor\Core\Router` resolves an incoming update to a `HandlerExecution`.

### Discovery

```php
$router->discover('/path/to/Handlers', 'App\\Handlers');
$router->discoverMany([
    ['directory' => __DIR__ . '/Handlers', 'namespace' => 'App\\Handlers'],
]);
```

Discovery is recursive. Classes already registered through a previous source are skipped, so overlapping sources do not duplicate handlers.

### Handler attributes

| Attribute | Purpose |
|-----------|---------|
| `#[Text]` | Command or translated-text match (repeatable) |
| `#[Callback]` | Exact or regex match on callback data |
| `#[Step]` | Step flow handler, bound to a user's current step |
| `#[Fallback]` | Fallback handler when nothing matches |
| `#[OnUpdate]` | Restrict to specific update types |
| `#[Group]` | Assign the handler to one or more middleware groups |
| `#[UseMiddleware]` | Attach local middleware |

### Priority

- Commands and texts: highest `priority` wins. Ties drop the later-discovered handler with a warning.
- Callbacks: first-match wins, checked in registration order.
- Patterns (regex): sorted by descending priority.
- Fallbacks: highest priority wins; ties keep the first discovered.

### Matching order

1. Callback data (exact, then regex).
2. Commands (parsed by `CommandParser`).
3. Text (translated key match, then regex).
4. Step (based on `UserProviderInterface::getStep()`).
5. Fallback (via `#[Fallback]`); `UpdateProcessor` uses `UnknownCommandHandler` only when no fallback is registered.

### Dynamic registration

```php
$router->registerStepHandler(
    stepName: 'flow_payment',
    handlerClass: PaymentStep::class,
    nextStep: null,
    autoClear: false,
);
```

### Command parsing

`Reactor\Core\Routing\CommandParser` handles:

- `/command`
- `/command arg1 arg2`
- `/command@BotName`
- `/command@BotName arg1 arg2`

Whitespace may be spaces, tabs, or newlines. The `@BotName` suffix is stripped before lookup.

---

## Handlers

Handlers are plain classes. The framework imposes no base class; the only requirement is an `execute(array $update, array $params = []): void` method.

`HandlerInvoker` resolves the handler from the container, calls `execute()`, and awaits the returned `Amp\Future` when the handler returned one.

### Example

```php
use Reactor\Attributes\Text;
use Reactor\Attributes\OnUpdate;

#[Text(name: '/start', isCommand: true, priority: 100)]
#[OnUpdate('message')]
class StartHandler
{
    public function __construct(private Neili\Client $client) {}

    public function execute(array $update, array $params = []): void
    {
        $chatId = $update['message']['chat']['id'];
        $this->client->sendMessage($chatId, 'Hello!');
    }
}
```

Any dependency in the handler's constructor is resolved through the container.

### Errors

A handler that throws is routed through `ErrorHandler`. `UserFriendlyException` also sends a translated message to the user in their own language. Any other exception is logged and dispatched as `ERROR_OCCURRED`.

---

## Middleware

Middleware runs before the handler. `MiddlewareManager` executes middleware in three phases:

1. **Global** — always runs.
2. **Group** — runs when the handler's `#[Group]` intersects the middleware's groups.
3. **Local** — runs when the handler lists the middleware via `#[UseMiddleware]`.

Within each phase, middleware is sorted by descending priority.

### Interface

```php
interface MiddlewareInterface
{
    public function handle(array $update): bool;
}
```

Return `true` to **stop** processing. Return `false` to **continue**.

### Attribute

```php
use Reactor\Attributes\Middleware;
use Reactor\Attributes\OnUpdate;
use Reactor\Enums\MiddlewareMode;

#[Middleware(priority: 50, mode: MiddlewareMode::GLOBAL)]
#[OnUpdate('message,callback_query')]
class AuthMiddleware implements MiddlewareInterface
{
    public function handle(array $update): bool
    {
        return false;
    }
}
```

### Modes

- `MiddlewareMode::GLOBAL`
- `MiddlewareMode::GROUP` (requires `groups: [...]`)
- `MiddlewareMode::LOCAL`

### Middleware execution order

`MiddlewareProcessor` runs the phases in order and stops at the first `true`. Any exception raised by a middleware is routed through `ErrorHandler::handleMiddlewareError()` and treated as a stop signal.

A middleware class is only executed once per update, even if it is registered under multiple phases.

---

## Events

`Reactor\Core\EventDispatcher` is a synchronous dispatcher.

```php
$dispatcher->on('user.registered', function (int $userId, array $update) {});
$dispatcher->on('*', function (string $event, ...$args) {});
$dispatcher->off('user.registered', $listener);
$dispatcher->dispatch('user.registered', $userId, $update);
```

Dispatch returns `true` when any listener returned `false` (propagation stopped).

### Core events

Defined on `Reactor\Events`:

| Constant | Value |
|----------|-------|
| `USER_REGISTERED` | `user.registered` |
| `USER_STEP_CHANGED` | `user.step.changed` |
| `UPDATE_PROCESSING_STARTED` | `update.processing.started` |
| `UPDATE_PROCESSING_FINISHED` | `update.processing.finished` |
| `ERROR_OCCURRED` | `error.occurred` |
| `MIDDLEWARE_FAILED` | `middleware.failed` |
| `HANDLER_EXECUTED` | `handler.executed` |

---

## Error Handling

`Reactor\Core\ErrorHandler` centralises exception handling.

```php
$errorHandler->handleException($e, $context);
$errorHandler->handleMiddlewareError($e, $update, $middlewareClass);
```

Behavior:

- Every exception is logged at `error` level with its trace.
- `ERROR_OCCURRED` is dispatched with the exception and context.
- If the exception is a `UserFriendlyException`, a message is sent to the user in their own language (falling back to the default language when the provider fails or the user has no preference).

`UserFriendlyException` carries a translation key (`getUserMessageKey()`) and an optional technical message that is only logged.

---

## Language

`Reactor\Core\Language` loads flat JSON translation files from the application's `lang/` directory.

### Usage

```php
$language->get('start_message', 'en');
$language->get('blocked', 'en', ['duration' => 30]);
$language->getAvailableLanguages();
$language->getLanguageName('fa');
$language->getDefaultLanguage();
$language->setDefaultLanguage('fa');
$language->reload();
```

Missing keys fall back to the default language; if still missing, the key itself is returned. Placeholders use `{name}` syntax.

### Adding translations at runtime

```php
$language->addTranslation('en', 'my_key', 'My value');
$language->saveLanguageFile('en');
```

`saveLanguageFile()` writes the current translations back to `lang/<code>.json`.

---

## Database

`Reactor\Contracts\DatabaseManagerInterface` is the framework's persistence contract. The framework binds `NullDatabaseManager` by default; real applications bind an Eloquent-backed or custom implementation.

### Contract

```php
public function connection(): object;
public function schema(): object;
public function table(string $table): object;
public function beginTransaction(): void;
public function commit(): void;
public function rollBack(): void;
public function transaction(callable $callback);
public function getConfig(): array;
```

`NullDatabaseManager` throws a descriptive `\RuntimeException` on every schema/query call so that misuse fails loudly.

### Binding an implementation

```php
$container->singleton(
    DatabaseManagerInterface::class,
    fn($c) => new App\Database\EloquentManager(
        $c->get(Config::class),
        $c->get(LoggerInterface::class),
    )
);
```

---

## Migrations

Migrations live in `database/migrations/` and extend `Reactor\Database\Migrations\Migration`.

```php
use Reactor\Database\Migrations\Migration;
use Reactor\Contracts\DatabaseManagerInterface;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        if (!$this->db->schema()->hasTable('users')) {
            $this->db->schema()->create('users', function ($table) {
                $table->increments('id');
                $table->bigInteger('user_id')->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $this->db->schema()->dropIfExists('users');
    }
}
```

### Sources

The application's `database/migrations/` directory is registered with `MigrationRegistrar`. Packages register their own directories, and each source carries a namespace so classes can be autoloaded.

On conflicting migration basenames, the first registered source wins with a warning.

### Tracking table

The `migrations` tracking table is created lazily on the first operation that needs it (`ensureMigrationsTable()`), so the migration infrastructure is side-effect free at bootstrap.

### Transactions

`MigrationRunner` wraps `up()` / `down()` in a schema transaction only when the driver supports transactional DDL (PostgreSQL, SQLite). MySQL, MariaDB, SQL Server, and Oracle auto-commit DDL, so the runner runs the statement directly and records the migration afterwards.

### Concurrency

`MigrationLock` (file-based, PID-tagged) prevents two processes from running migrations at the same time. Stale locks are detected and removed.

### Commands

| Command | Purpose |
|---------|---------|
| `migrate` | Run all pending migrations |
| `migrate:rollback [--step=N]` | Roll back the last N batches |
| `migrate:reset` | Roll back everything |
| `migrate:refresh` | Reset then run |
| `migrate:status` | Tabular status |
| `migrate:make <name> [--create=table] [--table=table]` | Generate a migration file |

---

## Seeders

Seeders extend `Reactor\Database\Seeders\Seeder` and implement `run()`.

```php
use Reactor\Database\Seeders\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Insert seed data.
    }
}
```

`Seeder::call()` invokes another seeder class.

### Command

```bash
./reactor db:seed
./reactor db:seed --class="App\\Seeders\\DatabaseSeeder"
```

`SeederRunner` accepts any class that extends `Seeder`.

---

## Queue

The framework ships a database-backed queue driver (`Reactor\Queue\DatabaseQueue`) and a long-running worker (`Reactor\Queue\Worker`).

### Contract

`QueueManagerInterface`:

```php
public function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): void;
public function pop(string|array $queue = 'default'): ?array;
public function release(array $job, int $delay = 0): void;
public function delete(array $job): void;
public function size(string $queue): int;
```

`QueueManager` wraps the driver and adds container-based job resolution.

### DatabaseQueue

- `pop()` runs inside a transaction. On MySQL/PostgreSQL the row is locked (`FOR UPDATE`); on SQLite the engine serializes the transaction itself.
- Priority queues are supported by passing an array of queue names; the first non-empty queue wins.
- `size()` counts ready (unreserved, available) jobs.

### Worker

`Worker::work(WorkerOptions)` owns the loop, retry policy, timeout enforcement, and graceful shutdown via the restart signal.

`WorkerOptions`:

| Option | Default | Meaning |
|--------|---------|---------|
| `queue` | `default` | Name or ordered list |
| `tries` | `3` | Max attempts per job |
| `timeout` | `60` | Seconds per job (0 = no timeout) |
| `sleep` | `3` | Sleep between empty-queue polls |
| `maxJobs` | `0` | Jobs before exit (0 = unlimited) |
| `maxTime` | `0` | Seconds before exit (0 = unlimited) |
| `memory` | `128` | MB limit (0 = unlimited) |
| `once` | `false` | Process one job then exit |
| `stopWhenEmpty` | `false` | Process until the queue is empty |

Timeout enforcement uses `Amp\TimeoutCancellation`: async jobs are cancelled cleanly; synchronous jobs continue running but the worker moves on.

Failed jobs are stored via `FailedJobRepository` in the `failed_jobs` table (created lazily).

Exponential backoff on retry: `min(60, 2^attempts)` seconds.

### Signals

`Signals` stores a restart timestamp in a file. Running workers compare the file mtime against their own start time and exit after the current job.

### Job base class

`Reactor\Queue\BaseJob` provides `$this->client`, `$this->logger`, `$this->language`, `$this->userProvider`.

### Commands

| Command | Purpose |
|---------|---------|
| `queue:work` | Start the worker |
| `queue:listen` | Run in a reload-on-every-iteration loop (development) |
| `queue:restart` | Broadcast restart signal |
| `queue:failed` | List failed jobs |
| `queue:retry <id\|all>` | Requeue failed jobs |
| `queue:forget <id>` | Delete a failed job |
| `queue:flush` | Delete every failed job |
| `queue:prune-failed [--hours=N]` | Delete failed jobs older than N hours |
| `queue:monitor [--queue=name]` | Pending jobs per queue |
| `queue:failed-table` | Generate a `failed_jobs` migration |

---

## Scheduler

`Reactor\Core\Schedule\Schedule` holds scheduled events. `Event` supports cron expressions and overlap prevention.

```php
$schedule->call(fn(Container $c) => ...)
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

$schedule->job(MyJob::class)->dailyAt('03:15');
```

### Cron expressions

`CronExpression` supports the standard five-field syntax with `*`, `a`, `a-b`, `a,b`, `*/n`, and `a-b/n` values.

### Overlap prevention

`withoutOverlapping($minutes)` writes a lock file under `storage/schedule/`. Events skipped because a lock is held log a warning.

### Command

```bash
./reactor schedule:run
```

Invoke every minute via cron.

---

## Cache

`Reactor\Contracts\CacheInterface` — a PSR-16-like contract.

```php
$cache->get($key, $default);
$cache->set($key, $value, $ttl);
$cache->delete($key);
$cache->has($key);
$cache->clear();
$cache->remember($key, $ttl, $callback);
$cache->rememberForever($key, $callback);
$cache->increment($key, $amount);
$cache->decrement($key, $amount);
```

`CacheManager` selects a driver from config (`driver` key):

| Driver | Class |
|--------|-------|
| `array` | `ArrayCache` |
| `file` | `FileCache` |
| `redis` | `RedisCache` |
| `memcached` | `MemcachedCache` |

Unknown drivers throw `\InvalidArgumentException`.

`FileCache`, `RedisCache`, and `MemcachedCache` support the `allowed_classes` config key to constrain unserialization.

---

## Logging

`Reactor\Contracts\LoggerInterface` — PSR-3-like contract with `info`, `error`, `warning`, `debug`, `critical`.

`Reactor\Core\Logger`:

- Backed by Monolog (`RotatingFileHandler`, 30-day retention).
- `debug()` is a no-op unless the constructor was passed `debugMode: true`.
- When no file path is provided, writes to `<sys_temp_dir>/reactor-logs/app.log` to avoid touching the vendor tree.

Applications that want a different destination bind their own `LoggerInterface` implementation.

---

## Service Providers

```php
interface ServiceProviderInterface
{
    public function register(Container $container): void;
    public function boot(Container $container): void;
}
```

All providers are registered, then booted. Provider order determines override precedence for bindings registered under the same abstract.

`ServiceProviderManager` collects providers and runs `registerAll()` / `bootAll()`.

`PackageServiceProviderInterface` extends the base contract with `publishes(): array` for publishable assets.

---

## Packages

Package discovery is driven by Composer's `installed.json`. A package is a Reactor package when its `composer.json` declares `"type": "reactor-package"`.

### `extra.reactor` block

```json
{
    "extra": {
        "reactor": {
            "providers": ["Vendor\\Package\\Provider"],
            "aliases": {"Facade": "Vendor\\Package\\Facade"},
            "migrations_namespace": "Vendor\\Package\\Migrations"
        }
    }
}
```

`PackageManifest` reads the metadata and caches it in `bootstrap/cache/packages.php`. The cache is invalidated when `installed.json` is newer.

`PackageRegistrar::register()`:

- Adds `config/` directories as low-priority config sources.
- Registers `database/migrations/` with `MigrationRegistrar`.
- Applies class aliases.

`providerClasses()` returns every declared provider. `AppBootstrapper` resolves each one, calls its `register()`, and registers it with the manager.

### Publishable assets

```php
class Provider implements PackageServiceProviderInterface
{
    public function publishes(): array
    {
        return [
            __DIR__ . '/../config/pkg.php' => config_path('pkg.php'),
            __DIR__ . '/../lang'          => lang_path('vendor/pkg'),
        ];
    }
}
```

`vendor:publish` copies files and directories to their destinations.

---

## Bootstrappers

`BootstrapperInterface` — a single `bootstrap(Container $container): void` method.

### Default chain

| Bootstrapper | Purpose |
|--------------|---------|
| `DatabaseBootstrapper` | Forces `DatabaseManagerInterface` to resolve |
| `TelegramBootstrapper` | Wires `Neili\Client` and `Neili\Poller` |
| `MiddlewareBootstrapper` | Recursively discovers middleware sources |
| `RouterBootstrapper` | Recursively discovers handler sources |

`TelegramBootstrapper` skips wiring when no token is configured, so CLI commands and non-Telegram workflows keep working without a token.

`MiddlewareBootstrapper` and `RouterBootstrapper` deduplicate classes across overlapping sources.

Custom chains are supported via `App`'s `bootstrappers` argument.

---

## Console Commands

The framework ships a `Reactor\Console\Kernel` that registers every core command with a Symfony Console application.

| Group | Commands |
|-------|----------|
| Migrations | `migrate`, `migrate:rollback`, `migrate:reset`, `migrate:refresh`, `migrate:make`, `migrate:status` |
| Seeders | `db:seed` |
| Code generation | `make:handler`, `make:middleware`, `make:step` |
| Queue | `queue:work`, `queue:listen`, `queue:restart`, `queue:failed`, `queue:retry`, `queue:forget`, `queue:flush`, `queue:monitor`, `queue:prune-failed`, `queue:failed-table` |
| Scheduler | `schedule:run` |
| Packages | `package:discover`, `vendor:publish` |
| Lifecycle | `start`, `stop`, `restart` |

`BotProcessManager` (used by `stop` / `restart`) relies on the PID stored in the polling lock file; it sends `SIGTERM` first and escalates to `SIGKILL` when `--force` is passed or when the process does not exit within the configured timeout.

---

## Helpers

Available through `src/Helpers/functions.php`:

| Function | Returns |
|----------|---------|
| `base_path($path = '')` | `REACTOR_BASE_PATH` or cwd, joined with `$path` |
| `env($key, $default = null)` | Environment value |
| `config($key, $default = null)` | `Config::get()` on a lazily-constructed instance |
| `config_path($path = '')` | `base_path('config/' . $path)` |
| `app_path($path = '')` | `base_path('app/' . $path)` |
| `database_path($path = '')` | `base_path('database/' . $path)` |
| `storage_path($path = '')` | `base_path('storage/' . $path)` |
| `lang_path($path = '')` | `base_path('lang/' . $path)` |
| `public_path($path = '')` | `base_path('public_html/' . $path)` |

The framework helpers are designed to work in and out of the application skeleton: `base_path()` uses `REACTOR_BASE_PATH` when defined and falls back to the current working directory otherwise.

---

## Exceptions

| Exception | When |
|-----------|------|
| `ReactorException` | Base class |
| `ConfigNotFoundException` | Config key missing |
| `ContainerResolutionException` | Container resolution failed |
| `HandlerNotFoundException` | No handler matched (rare; fallback normally triggers) |
| `UserFriendlyException` | User-facing error; carries a translation key |

`UserFriendlyException` is caught by `ErrorHandler` and replied to the user in their language. Everything else is logged and dispatched as `ERROR_OCCURRED`.

---

## License

This project is licensed under the [MIT License](LICENSE).