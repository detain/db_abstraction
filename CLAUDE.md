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

**Tests** (`tests/`, PHPUnit `^10.5`):
- `tests/GenericTest.php` — unit tests for `Generic` base class
- `tests/LoaderTest.php` — unit tests for `Loader`
- `tests/Mysqli/DbTest.php`, `tests/Pdo/DbTest.php`, `tests/Pgsql/DbTest.php`, `tests/Mdb2/DbTest.php` — integration tests per driver (live server, no mocks)
- `tests/Mysqli/TransactionRetryTest.php`, `tests/Mysqli/TransactionReplayTest.php`, `tests/Mysqli/TransactionSafetyTest.php` — server-free, drive `tests/Mysqli/fake_mysqli.php` in separate processes; `tests/DetachTest.php`, `tests/ErrorReportRedactionTest.php`, `tests/LogBackTraceRedactionTest.php` also run in `generic tests`; `tests/MailDouble.php` stands in for `\MyAdmin\Mail`
- Fixtures: `tests/mysql.sql`, `tests/psql.sql`, `tests/sqlite.sql` — create `service_types` table with seed data (`tests/mysql.sql` also creates the `tests` user for `'%'` and `'localhost'`)
- CI: `.github/workflows/ci.yml` — PHP 8.3/8.4, MySQL 8.0 + Postgres 16 services, loads fixtures, runs `all tests` (clover `coverage.xml`)

## Conventions

- Every driver class: `namespace MyDb\{Driver};` · extends `Generic` · implements `Db_Interface`
- Driver type string set via `public $type = 'mysqli';` (or `'pgsql'`, `'pdo'`, `'adodb'`, `'mdb2'`)
- Required methods: `connect()`, `disconnect()`, `query()`, `next_record()`, `num_rows()`, `num_fields()`, `affectedRows()`, `seek()`, `free()`, `real_escape()`, `getLastInsertId()`, `lock()`, `unlock()`, `transactionBegin()`, `transactionCommit()`, `transactionAbort()`, `haltmsg()`, `tableNames()`
- `$this->Record` holds current row after `next_record()`; `$this->Row` tracks position
- `$this->linkId` = connection resource; `$this->queryId` = result resource
- Error handling: call `$this->halt('message', $line, $file)` → logs via `haltmsg()`
- Integration tests: use `getenv('DBUSER')` / `getenv('DBPASS')` / `getenv('DBHOST')` / `getenv('DBNAME')` for MySQL; `getenv('PGDBUSER')` etc. for PostgreSQL
- Test isolation: `setUp()` builds the `Db` from env vars (never override `__construct()` in a test) and calls `transactionBegin()`, `tearDown()` calls `transactionAbort()`
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

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
