# Changelog

## 0.2.1

### Fixed

- Convert UUID bindings using the qualified table name in joined queries, such as `registrations.contest_id`.
- Preserve UUID conversion for aliases of the base table, such as `up.id` when querying `uuid_playground as up`.

## 0.1.0

Initial public release of the Laravel 13 Firebird 5 driver package.

### Added

- Firebird connection driver, service provider, and PDO connector
- Query grammar support for pagination, `insertGetId()`, `insertUsing()`, `DELETE ... ROWS`, `union all`, row locking, and single-row `upsert`
- Schema grammar support for table creation and alteration, indexes, unique constraints, foreign keys, views, and metadata introspection
- Firebird-aware transaction handling for top-level Laravel transaction blocks
- Real integration test suite against Firebird 5 with local database recreation helpers

### Limitations

- Nested transactions are not supported through `PDO_FIREBIRD`
- `rename table` is not exposed
- `insertOrIgnore*` paths remain unsupported
