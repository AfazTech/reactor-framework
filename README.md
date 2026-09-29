# Reactor

A modern, asynchronous PHP framework for building powerful Telegram bots.

Reactor provides a structured and developer-friendly foundation for building Telegram bots with support for attribute-based routing, middleware, dependency injection, caching, queues, database migrations, scheduled tasks, and more.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Latest Version](https://img.shields.io/badge/version-1.0.0-orange.svg)](https://github.com/afaztech/reactor-framework/releases)

---

## Overview

Reactor is an asynchronous PHP framework built on top of [Amp](https://amphp.org/) and [Neili](https://github.com/AfazTech/neili), designed specifically for creating robust Telegram bots. It follows modern PHP practices, including PSR-4 autoloading, dependency injection, and attribute-driven configuration.

Whether you are building a simple command bot or a complex multi-step conversational application, Reactor provides the tools you need: a flexible router, middleware pipeline, database layer with migrations, queue system, scheduler, caching, and comprehensive logging – all with an emphasis on clean architecture and testability.

---

## Features

- **Attribute-based handlers & routing** – Define commands, text handlers, callbacks, and conversation steps using PHP 8 attributes.
- **Asynchronous by design** – Built on Amp, enabling non-blocking I/O for high-performance bots.
- **Middleware pipeline** – Global, group, and local middleware with priority ordering.
- **Dependency Injection Container** – Powerful container with auto-resolution, contextual bindings, tagging, and scoped instances.
- **Service Providers** – Register and boot services in a modular way.
- **Event Dispatcher** – Decoupled event-driven architecture.
- **Multi-source Configuration** – Merge package defaults, application config, and runtime overrides.
- **Database Layer** – Eloquent-based database manager with migrations and seeders.
- **Queue System** – Database-backed queue for background jobs.
- **Scheduler** – Cron-based task scheduling with overlap prevention.
- **Caching** – Unified cache interface with File, Redis, Memcached, and Array drivers.
- **Localization** – JSON-based language files with fallback support.
- **Package Ecosystem** – Discover packages, register their providers, and publish assets.
- **Logging** – PSR-3 compatible logging with Monolog and rotating file handler.
- **Error Handling** – Centralized error handler with user-friendly exception support.
- **Testing** – Comprehensive PHPUnit test suite.

---

## Requirements

- PHP >= 8.1
- Composer
- Extensions: `pdo`, `json`, `mbstring` (usually enabled by default)
- Optional: `redis` or `memcached` extensions if using those cache drivers

---

## Installation

### As a standalone framework

```bash
composer require afaztech/reactor-framework
```

### Creating a new bot project

The recommended way to start a new Reactor bot is using the official application skeleton:

```bash
composer create-project afaztech/reactor-skeleton my-bot
cd my-bot
cp .env.example .env
```

Edit `.env` and set your Telegram bot token:

```
TOKEN=your-telegram-bot-token
BOT_MODE=polling
DB_CONNECTION=sqlite
DB_DATABASE=database/test.sqlite
```

Run migrations and start the bot:

```bash
php reactor.php migrate
php bot.php
```

---

## Getting Started

### Basic Handler

Handlers are plain PHP classes decorated with attributes. The router automatically discovers them.

```php
<?php
namespace App\Handlers;

use Reactor\Attributes\Text;
use Reactor\Attributes\OnUpdate;
use Reactor\Attributes\Group;

#[Group('private')]
#[Text(name: '/start', isCommand: true, priority: 100)]
#[OnUpdate('message')]
class StartHandler extends BaseHandler
{
    protected function handle(array $params = []): void
    {
        $user = $this->extractUser($this->update);
        if (!$user) {
            return;
        }

        $lang = $this->getUserLanguage();
        $text = $this->language->get('start_message', $lang);
        $keyboard = (new \App\Keyboard($this->language))->mainMenu($lang);

        $this->reply($text, $keyboard);
    }
}
```

### Middleware

Middleware can run globally, for specific groups, or only for certain handlers.

```php
<?php
namespace App\Middleware;

use Reactor\Attributes\Middleware;
use Reactor\Attributes\OnUpdate;
use Reactor\Enums\MiddlewareMode;
use Reactor\Contracts\MiddlewareInterface;

#[Middleware(priority: 10, mode: MiddlewareMode::GLOBAL)]
#[OnUpdate('any')]
class LogMiddleware implements MiddlewareInterface
{
    public function handle(array $update): bool
    {
        // Log the update...
        return false; // Continue processing
    }
}
```

### Routing

The router matches incoming updates in the following order:

1. **Callback data** – Exact or regex match on `callback_query.data`
2. **Commands** – Messages starting with `/`
3. **Text/Button** – Exact match on translated text or regex
4. **Steps** – Multi-step conversation flow based on user's current step
5. **Fallback** – A handler marked with `#[Fallback]` or the built-in default

---

## Core Concepts

### Dependency Injection

Reactor includes a powerful DI container. You can bind interfaces to implementations, register singletons, use contextual binding, and more.

```php
$container->singleton(UserRepositoryInterface::class, EloquentUserRepository::class);
$container->bind(LoggerInterface::class, FileLogger::class);
```

Auto-resolution is supported via reflection.

### Configuration

Configuration is loaded from multiple sources with a clear precedence:

1. Package defaults (lowest priority)
2. Application config (`config/` directory)
3. Runtime overrides

Values are accessed with dot notation: `config('bot.token')`.

Environment variables are loaded from `.env` using `vlucas/phpdotenv`.

### Database

Reactor uses Laravel's Eloquent as the database layer. Migrations are defined as classes extending `Reactor\Database\Migrations\Migration`.

```php
class CreateUsersTable extends Migration
{
    public function up(): void
    {
        if (!$this->db->schema()->hasTable('users')) {
            $this->db->schema()->create('users', function ($table) {
                $table->increments('id');
                $table->bigInteger('user_id')->unique();
                // ...
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

Migrations are run via the CLI: `php reactor.php migrate`.

### Queue

A database-backed queue is included. Jobs implement `Reactor\Contracts\JobInterface`.

```php
class SendMessageJob extends BaseJob implements JobInterface
{
    public function handle(array $data): void
    {
        $this->client->sendMessage($data['chatId'], $data['text']);
    }
}
```

Dispatch jobs with `QueueManager` and process them via `php reactor.php queue:work`.

### Scheduler

Schedule tasks using a fluent interface. The scheduler is ideal for cron jobs.

```php
$schedule->call(function () {
    // Do something
})->everyFiveMinutes();
```

Run due tasks with `php reactor.php schedule:run` (typically every minute via cron).

### Caching

The cache system supports multiple drivers. Configure the default driver in `config/cache.php`.

```php
$cache->set('key', 'value', 3600);
$value = $cache->get('key');
$cache->remember('key', 3600, fn() => 'computed');
```

### Events

A simple event dispatcher allows you to listen and dispatch events.

```php
$dispatcher->on('user.registered', function ($userId) {
    // Send welcome message
});
```

### Localization

Translations are stored in JSON files under `lang/`. Use the `Language` service to retrieve translated strings.

```php
$text = $language->get('welcome', 'en');
```

### Packages

Reactor supports a package ecosystem. Packages can register service providers, config, migrations, and publishable assets.

A package's `composer.json` should declare `"type": "reactor-package"` and optionally an `extra.reactor` block.

---

## Directory Structure

A typical Reactor application has the following structure:

```
.
├── app/
│   ├── Contracts/          # Interfaces
│   ├── Database/           # Database manager implementation
│   ├── Handlers/           # Bot handlers
│   ├── Jobs/               # Queue jobs
│   ├── Middleware/         # Middleware classes
│   ├── Models/             # Eloquent models
│   ├── Providers/          # Service providers
│   ├── Repositories/       # Repositories
│   └── Keyboard.php        # Keyboard builder
├── config/                 # Configuration files
├── database/
│   ├── migrations/         # Migrations
│   └── seeders/            # Seeders
├── lang/                   # Translation files
├── public_html/            # Webhook entry point
├── bot.php                 # Polling entry point
├── reactor.php             # CLI entry point
└── .env
```

---

## Testing

Reactor comes with a comprehensive test suite. Run it using PHPUnit:

```bash
vendor/bin/phpunit
```

---

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

Please ensure your code follows the existing style and includes tests where appropriate.

---

## License

Reactor is open-sourced software licensed under the [MIT license](LICENSE).

---