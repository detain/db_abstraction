# db_abstraction

PHP database abstraction library. Provides a unified interface over `mysqli`, `pgsql`, `PDO`, `ADOdb`, and `MDB2`. Namespace: `MyDb\`.

## Commands

```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist --testsuite "all tests"
vendor/bin/phpunit -c phpunit.xml.dist --testsuite "mysql tests"
```

With env vars (required for integration tests):
```bash
DBUSER=tests DBPASS=tests DBHOST=localhost DBNAME=tests vendor/bin/phpunit -c phpunit.xml.dist
PGDBUSER=postgres PGDBHOST=localhost PGDBNAME=tests DBUSER=tests DBPASS=tests DBHOST=localhost DBNAME=tests vendor/bin/phpunit -c phpunit.xml.dist --testsuite "all tests"
```

DB fixture setup:
```bash
mysql --default-character-set=utf8mb4 < tests/mysql.sql
psql tests < tests/psql.sql
sqlite3 tests.db ".read tests/sqlite.sql"
```

## Architecture

**Core** (`src/`):
- `src/Db_Interface.php` — interface all drivers must implement
- `src/Generic.php` — abstract base class with shared logic (`escape`, `limitQuery`, `haltmsg`, `addLog`, `logBackTrace`)
- `src/Loader.php` — runtime driver loader by type string

**Drivers** — each at `src/{Driver}/Db.php`, extends `Generic`, implements `Db_Interface`:
- `src/Mysqli/Db.php` — native `ext-mysqli`
- `src/Pgsql/Db.php` — native `ext-pgsql`, uses `pg_connect` / `pg_exec`
- `src/Pdo/Db.php` — PDO with configurable `$driver` (mysql default)
- `src/Adodb/Db.php` — ADOdb (`adodb/adodb-php`)
- `src/Mdb2/Db.php` — extends `src/Mysqli/Db.php`, adds `quote()`, `queryOne()`, `queryRow()`

**Tests** (`tests/`):
- `tests/GenericTest.php` — unit tests for `Generic` base class
- `tests/LoaderTest.php` — unit tests for `Loader`
- `tests/Mysqli/DbTest.php`, `tests/Pdo/DbTest.php`, `tests/Pgsql/DbTest.php`, `tests/Mdb2/DbTest.php` — integration tests per driver
- Fixtures: `tests/mysql.sql`, `tests/psql.sql`, `tests/sqlite.sql` — create `service_types` table with seed data

## Conventions

- Every driver class: `namespace MyDb\{Driver};` · extends `Generic` · implements `Db_Interface`
- Driver type string set via `public $type = 'mysqli';` (or `'pgsql'`, `'pdo'`, `'adodb'`, `'mdb2'`)
- Required methods: `connect()`, `disconnect()`, `query()`, `next_record()`, `num_rows()`, `num_fields()`, `affectedRows()`, `seek()`, `free()`, `real_escape()`, `getLastInsertId()`, `lock()`, `unlock()`, `transactionBegin()`, `transactionCommit()`, `transactionAbort()`, `haltmsg()`, `tableNames()`
- `$this->Record` holds current row after `next_record()`; `$this->Row` tracks position
- `$this->linkId` = connection resource; `$this->queryId` = result resource
- Error handling: call `$this->halt('message', $line, $file)` → logs via `haltmsg()`
- Integration tests: use `getenv('DBUSER')` / `getenv('DBPASS')` / `getenv('DBHOST')` / `getenv('DBNAME')` for MySQL; `getenv('PGDBUSER')` etc. for PostgreSQL
- Test isolation: `setUp()` calls `transactionBegin()`, `tearDown()` calls `transactionAbort()`
- Indentation: tabs (per `.scrutinizer.yml`)
- Commit messages: lowercase, descriptive

## Adding a New Driver

1. Create `src/{Driver}/Db.php` with `namespace MyDb\{Driver};`
2. Extend `MyDb\Generic`, implement `MyDb\Db_Interface`
3. Implement all required methods listed above
4. Add test class at `tests/{Driver}/DbTest.php` using env-var credentials
5. Add testsuite entry to `phpunit.xml.dist`

<!-- caliber:managed:pre-commit -->
## Before Committing

Run `caliber refresh` before creating git commits to keep docs in sync with code changes.
After it completes, stage any modified doc files before committing:

```bash
caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null
```
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->
