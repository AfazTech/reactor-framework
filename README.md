# Reactor

A modern, asynchronous PHP framework for building powerful Telegram bots.

Reactor provides a structured and developer-friendly foundation for building Telegram bots with support for handlers, middleware, caching, queues, database migrations, scheduled tasks, and more.

## Features

* Attribute-based handlers and routing
* Support for Telegram update types
* Middleware with global, group, and local scopes
* Callback query and text handlers
* Multi-step conversation flows
* Dependency injection container
* Service providers
* Event dispatcher
* Configurable caching with File, Redis, Memcached, and Array drivers
* Database migrations and seeders
* Queue system
* Scheduled tasks
* Built-in logging and error handling
* Asynchronous architecture

## Example

A simple handler can be defined using attributes:

```php
<?php

namespace App\Handlers;

use Reactor\Attributes\Text;

#[Text('/start', isCommand: true)]
class StartHandler
{
    public function handle(): void
    {
        // Handle /start command
    }
}
```

Reactor automatically discovers and matches handlers based on their attributes and the incoming Telegram update.

## Middleware

Middleware can be used globally, by group, or locally for specific handlers.

```php
<?php

use Reactor\Attributes\Middleware;
use Reactor\Enums\MiddlewareMode;

#[Middleware(
    mode: MiddlewareMode::GLOBAL,
    priority: 10
)]
class ExampleMiddleware
{
    public function handle(array $update): bool
    {
        return false;
    }
}
```

## Cache

Reactor provides a unified cache interface with multiple drivers:

* File
* Redis
* Memcached
* Array

The cache system supports common operations such as `get`, `set`, `delete`, `remember`, and atomic increments and decrements.

## Database

Reactor includes database tooling for:

* Migrations
* Rollbacks
* Migration status
* Migration refresh
* Seeders

```bash
php reactor migrate
php reactor migrate:rollback
php reactor migrate:status
php reactor migrate:refresh
```

## Queue & Scheduling

Reactor provides a queue system for background jobs and a scheduling system for recurring tasks.

This allows applications to move time-consuming work out of the main update processing flow and run scheduled tasks independently.

## Installation

```bash
composer require afaztech/reactor
```

For creating a new Reactor application, use the official application skeleton.

```bash
composer create-project afaztech/reactor-skeleton my-bot
```

## Documentation

Documentation is currently under development.

## License

Reactor is open-sourced software licensed under the [MIT license](LICENSE).
