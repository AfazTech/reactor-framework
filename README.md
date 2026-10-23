# Reactor

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Reactor](https://img.shields.io/badge/Reactor-Framework-orange.svg)](https://github.com/afaztech/reactor)

A modern, asynchronous PHP framework for building powerful, production-ready Telegram bots.

Reactor combines attribute-based routing, a dependency injection container, a middleware pipeline, Eloquent, migrations, a database-backed queue, a scheduler, multi-driver caching, and JSON localisation into a cohesive, developer-friendly stack.

## Features

- Attribute-based routing
- Asynchronous execution
- Middleware pipeline
- Dependency injection container
- Service providers
- Multi-source configuration
- Eloquent ORM
- Database migrations
- Database-backed queue
- Cron scheduler
- Multiple cache drivers
- JSON localisation
- Package ecosystem
- PSR-3 logging
- Polling and webhook modes
- Multi-process support

## Requirements

- PHP >= 8.1
- Composer
- PDO extension for the chosen database (SQLite is used by default)

## Installation

```bash
composer require afaztech/reactor-framework
```

## Usage

```php
use Reactor\Core\App;

$app = new App(__DIR__);
$app->start();
```

The recommended way to start a new project is the [reactor](https://github.com/afaztech/reactor) application skeleton, which ships with a complete layout, example handlers, and ready-to-use configuration.

## Documentation

For detailed documentation, see [DOCUMENT.md](DOCUMENT.md).

## Contributing

Contributions are welcome. Please read the contribution guidelines.

## License

This project is licensed under the [MIT License](LICENSE).