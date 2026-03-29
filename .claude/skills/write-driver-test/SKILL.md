---
name: write-driver-test
description: Creates a PHPUnit integration test class in tests/{Driver}/DbTest.php following the transaction-rollback isolation pattern used by Mdb2 and Pgsql tests. Use when user says 'add tests', 'write test for driver', or adds a new driver class. Reads env vars for DB credentials. Do NOT use for Generic or Loader unit tests — those live in tests/GenericTest.php and tests/LoaderTest.php.
---
# Write Driver Integration Test

## Critical

- **Never** skip `transactionBegin()`/`transactionAbort()` in `setUp`/`tearDown` — this is the only isolation mechanism; without it, tests corrupt the shared fixtures.
- **Never** use hardcoded credentials. Always read from env vars via `getenv()`.
- MySQL-based drivers (`mysqli`, `pdo`, `mdb2`, `adodb`) use `DBNAME`/`DBUSER`/`DBPASS`/`DBHOST`. PostgreSQL uses `PGDBNAME`/`PGDBUSER`/`PGDBPASS`/`PGDBHOST`.
- Fixture table for query tests is `service_types` — created by `tests/mysql.sql` (MySQL) or `tests/psql.sql` (PostgreSQL).
- New driver test files must be registered in `phpunit.xml.dist` under the appropriate `<testsuite>` blocks.

## Instructions

1. **Identify the driver.** Confirm the driver file exists at the expected path (e.g. `src/Mysqli/Db.php`) and verify its namespace and `$type` property value (e.g. `'mysqli'`, `'pgsql'`, `'pdo'`).

2. **Create the test directory and file** following the pattern `tests/<Driver>/DbTest.php` (e.g. `tests/Mysqli/DbTest.php`). Use tabs for indentation (per `.scrutinizer.yml`).

3. **Write the class scaffold** — namespace `MyDb\Tests\{Driver}`, extend `\PHPUnit\Framework\TestCase`, import `MyDb\{Driver}\Db`:

```php
<?php

namespace MyDb\Tests\{Driver};

use MyDb\{Driver}\Db;

class DbTest extends \PHPUnit\Framework\TestCase
{
	/** @var Db */
	protected $db;

	public function __construct($name = null, array $data = [], $dataName = '')
	{
		parent::__construct($name, $data, $dataName);
		$this->db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));
	}

	protected function setUp(): void
	{
		$this->db->transactionBegin();
	}

	protected function tearDown(): void
	{
		$this->db->transactionAbort();
	}
}
```
For PostgreSQL replace env var calls with `getenv('PGDBNAME')`, `getenv('PGDBUSER')`, `getenv('PGDBPASS')`, `getenv('PGDBHOST')`.

4. **Add test methods.** Call `$this->db->connect()` at the top of any test that hits the database. Use `$this->markTestIncomplete('...')` for methods not yet implemented rather than leaving them empty. Core methods to cover: `real_escape`, `query`/`next_record`/`num_rows`, `getLastInsertId`, `transactionBegin`/`transactionCommit`/`transactionAbort`.

5. **Register in `phpunit.xml.dist`.** Add the driver's test file (e.g. `<file>tests/Mysqli/DbTest.php</file>`) to `mysql tests` (or `postgresql tests`) and `all tests` testsuite blocks.

6. **Verify** by running:
```bash
DBUSER=tests DBPASS=tests DBHOST=localhost DBNAME=tests vendor/bin/phpunit -c phpunit.xml.dist --testsuite "mysql tests"
```
For PostgreSQL add `PGDBUSER`/`PGDBHOST`/`PGDBNAME` env vars and use `--testsuite "postgresql tests"`.

## Examples

**User says:** "Add tests for the Mdb2 driver"

**Actions taken:**
1. Confirmed `src/Mdb2/Db.php` exists, namespace `MyDb\Mdb2`, `$type = 'mdb2'`
2. Created `tests/Mdb2/DbTest.php` with MySQL env vars, `transactionBegin`/`transactionAbort` scaffold
3. Added `testQueryOne`, `testQueryRow`, `testLastInsertId` hitting real `service_types` table
4. Registered file in `phpunit.xml.dist` under `mysql tests` and `all tests`

**Result (`tests/Mdb2/DbTest.php` excerpt):**
```php
public function testQueryOne()
{
	$this->db->connect();
	$this->assertEquals(1, $this->db->queryOne("select * from service_types limit 1", __LINE__, __FILE__));
	$this->assertEquals(0, $this->db->queryOne("select * from service_types where st_id=-1", __LINE__, __FILE__));
}

public function testLastInsertId()
{
	$this->db->query("insert into service_types values (NULL, 'Test', 2, 'vps')", __LINE__, __FILE__);
	$id = $this->db->lastInsertId('service_types', 'st_id');
	$this->assertTrue(is_int($id));
	$this->assertFalse($id === false);
}
```

## Common Issues

- **`Connection refused` / `DBNAME env var empty`**: Run with full env prefix — `DBUSER=tests DBPASS=tests DBHOST=localhost DBNAME=tests vendor/bin/phpunit ...`. Missing env vars default to empty string and cause silent connection failure.
- **`Table 'tests.service_types' doesn't exist`**: Load the fixture first: `mysql --default-character-set=utf8mb4 < tests/mysql.sql` (MySQL) or `psql tests < tests/psql.sql` (PostgreSQL).
- **Tests pass individually but dirty each other**: `setUp`/`tearDown` are missing or calling `transactionCommit` instead of `transactionAbort`. Ensure `tearDown` always calls `$this->db->transactionAbort()`.
- **`Class MyDb\Tests\{Driver}\DbTest not found`**: New file not registered in `phpunit.xml.dist`. Add the driver's test file (e.g. `<file>tests/Mysqli/DbTest.php</file>`) to the relevant testsuites.
- **`Call to undefined method` on driver-specific methods** (e.g. `quote`, `queryOne`): These exist only on `Mdb2\Db`. Do not test them against base `Mysqli\Db` or `Pdo\Db`.
