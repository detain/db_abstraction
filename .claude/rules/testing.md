---
paths:
  - "tests/**"
  - "phpunit.xml.dist"
---
# Tests (PHPUnit 10.5)

- `composer.json` requires `phpunit/phpunit` `^10.5`; `phpunit.xml.dist` uses the 10.5 schema (`<source>` + `<coverage><report><clover outputFile="coverage.xml"/>`) and sets `date.timezone` to `America/New_York`.
- Never override `__construct()` in a `TestCase`. Build the driver at the top of `setUp()`: `$this->db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));`, then `transactionBegin()`; `tearDown()` calls `transactionAbort()`. PostgreSQL uses `PGDBNAME`/`PGDBUSER`/`PGDBPASS`/`PGDBHOST`.
- `tests/Mysqli/DbTest.php` is a live-server integration test (no mocks). Retry/replay/shared-link semantics belong in `tests/Mysqli/TransactionRetryTest.php`, `tests/Mysqli/TransactionReplayTest.php`, `tests/Mysqli/TransactionSafetyTest.php`.
- Those tests use `#[RunTestsInSeparateProcesses]` and `require_once` `tests/Mysqli/fake_mysqli.php` from `setUp()`, never at file scope: it defines `MyDb\Mysqli\mysqli_*` and would shadow the real functions for the whole run.
- Anything that can reach `emailError()`/`haltmsg()` loads `tests/MailDouble.php` first (stand-in for `\MyAdmin\Mail`) and skips when the real class is loaded (`mailIsDouble()` in `tests/GenericTest.php`).
- Code that `die()`s (`halt()` with `haltOnError = 'yes'`) is tested in a child `PHP_BINARY -r` process, checking its output and exit status (`testHaltUnderYesEndsTheSession`).
- Pinned `fromTimestamp()`/`toTimestamp()` values are epoch seconds for `America/New_York`.
- Register new test files in every matching `<testsuite>` of `phpunit.xml.dist`; server-free tests also go in `generic tests`.
- CI (`.github/workflows/ci.yml`): PHP 8.3/8.4, MySQL 8.0, Postgres 16. Loads `tests/mysql.sql` as root (creates the `tests` user for `'%'` and `'localhost'`) and `tests/psql.sql` into `tests` (owner `postgres`), then runs `vendor/bin/phpunit --testsuite "all tests"`.
