# Firebird Integration Assets

This directory contains local assets for real Firebird 5 integration tests.

Files:
- `test.fdb`: local test database file, created on demand and ignored by git.
- `migrations/`: migration files used by integration tests.
- `recreate-test-db.php`: helper script that recreates `test.fdb` using `isql.exe`.

Defaults:
- Firebird root: `C:\firebird5`
- User: `SYSDBA`
- Password: `1619092230`

Overrides:
- `FIREBIRD_TEST_ROOT`
- `FIREBIRD_TEST_USER`
- `FIREBIRD_TEST_PASSWORD`
- `FIREBIRD_TEST_DB`

Manual usage:
```powershell
php .\db\recreate-test-db.php
php .\vendor\bin\phpunit --testsuite Integration
```
