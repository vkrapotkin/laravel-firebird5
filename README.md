# vkrapotkin/laravel-firebird5

Independent Laravel package with a Firebird SQL 5 driver for Laravel 13.

## Features

- `firebird` connection driver for Laravel 13 with package auto-discovery
- PDO Firebird connector with DSN builder for local and host-based database paths
- Query support for pagination, `insertGetId()`, `insertUsing()`, `DELETE ... ROWS`, single-row `upsert`, `union all`, `lockForUpdate()`, and `sharedLock()`
- Schema support for create, alter, `change()`, `renameColumn()`, `dropColumn()`, indexes, unique constraints, foreign keys, views, and bulk dropping of tables/views
- Schema introspection for tables, views, columns, indexes, foreign keys, and domains via `getTypes()`
- Firebird-aware transaction handling for top-level Laravel transaction blocks

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
- The package namespace is `Vkrapotkin\LaravelFirebird5`.
- Query and schema behavior is covered by unit tests and real integration tests against a Firebird 5 database recreated on demand.

## Known Limitations

- `rename table` is not exposed because a reliable Firebird 5 + `PDO_FIREBIRD` path was not confirmed in this driver flow.
- Nested transactions are not supported through PDO; the driver supports a single top-level transaction boundary only.
- `insertOrIgnore*` paths are intentionally unsupported and follow Laravel's base runtime exception behavior.
- Savepoint behavior is not advertised until it is validated on the Firebird/PDO combination used by this package.

## Transactions

Firebird with `PDO_FIREBIRD` often keeps the connection inside an already active transaction. Because of that, this driver adapts Laravel transaction handling as follows:

- A top-level `beginTransaction()` / `commit()` / `rollBack()` block is supported.
- If PDO already reports an active transaction, the driver adopts that transaction instead of trying to open a second physical transaction.
- Nested transactions are not supported through PDO and will throw a `RuntimeException`.
- Schema operations are not wrapped in grammar-managed transactions.

Recommended usage:

```php
DB::beginTransaction();

try {
    // write queries...
    DB::commit();
} catch (\Throwable $e) {
    DB::rollBack();
    throw $e;
}
```

Avoid opening nested transaction blocks on the same Firebird connection until savepoint behavior is explicitly implemented and validated.

## Testing

```bash
composer test
vendor\bin\phpunit --testsuite Unit
vendor\bin\phpunit --testsuite Integration --display-warnings
```


