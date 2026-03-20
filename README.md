# vkrapotkin/laravel-firebird5

Independent Laravel package with a Firebird SQL 5 driver for Laravel 13.

## Features

- `firebird` connection driver for Laravel 13
- PDO Firebird connector with DSN builder
- Query grammar for Firebird 5 pagination, `insertGetId()`, `DELETE ... ROWS`, and single-row `upsert`
- Expanded schema grammar for common DDL and schema introspection
- Package auto-discovery

## Requirements

- PHP 8.3+
- Laravel 13
- `ext-pdo`
- `pdo_firebird` enabled in PHP
- Firebird SQL 5

## Installation

```bash
composer require vkrapotkin/laravel-firebird5
```

## Configuration

Add a Firebird connection to `config/database.php`:

```php
'connections' => [
    'firebird' => [
        'driver' => 'firebird',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', 3050),
        'database' => env('DB_DATABASE', database_path('database.fdb')),
        'username' => env('DB_USERNAME', 'sysdba'),
        'password' => env('DB_PASSWORD', 'masterkey'),
        'charset' => env('DB_CHARSET', 'UTF8'),
        'role' => env('DB_ROLE'),
        'dialect' => env('DB_DIALECT', 3),
        'prefix' => '',
        'isolation_level' => env('DB_ISOLATION_LEVEL'),
    ],
],
```

Example `.env`:

```dotenv
DB_CONNECTION=firebird
DB_HOST=127.0.0.1
DB_PORT=3050
DB_DATABASE=C:\data\app.fdb
DB_USERNAME=sysdba
DB_PASSWORD=masterkey
DB_CHARSET=UTF8
DB_DIALECT=3
```

## Publishing

1. Create a new GitHub repository named `laravel-firebird5` under `vkrapotkin`.
2. Push this package as the initial codebase of the new repository.
3. Sign in to Packagist and submit `https://github.com/vkrapotkin/laravel-firebird5`.
4. Ensure GitHub Services / webhooks are enabled in Packagist for automatic updates.

## Notes

- The package targets Firebird SQL 5 syntax and PDO Firebird.
- Schema coverage is significantly broader than a minimal connection-only driver, but real integration testing still depends on a working `pdo_firebird` build in your environment.
- The package namespace is `Vkrapotkin\LaravelFirebird5`.

## Testing

```bash
composer test
```
