---
name: add-driver
description: Scaffolds a new database driver in src/{Driver}/Db.php extending Generic and implementing Db_Interface. Use when user says 'add driver', 'new database backend', 'support X database', or creates files in src/. Do NOT use for modifying existing drivers or adding methods to existing drivers.
---
# Add Database Driver

## Critical

- Every driver MUST extend `MyDb\Generic` AND implement `MyDb\Db_Interface` — omitting either causes a fatal error at load time.
- `public $type` MUST be set to the driver's lowercase type string (e.g. `'sqlite'`). `Loader` uses this to route.
- Required methods (must all be implemented): `connect()`, `disconnect()`, `query()`, `next_record()`, `num_rows()`, `num_fields()`, `affectedRows()`, `seek()`, `free()`, `real_escape()`, `getLastInsertId()`, `lock()`, `unlock()`, `transactionBegin()`, `transactionCommit()`, `transactionAbort()`, `haltmsg()`, `tableNames()`, `indexNames()`.
- Error handling: never throw exceptions — call `$this->halt('message', $line, $file)` which routes through `haltmsg()`.
- Use tabs for indentation (enforced by `.scrutinizer.yml`).

## Instructions

1. **Create the driver directory and class file.**
   Path follows `src/<Driver>/Db.php` where `<Driver>` is PascalCase (e.g. `src/Mysqli/Db.php` for the Mysqli driver).
   Verify no existing file at that path before proceeding.

   ```php
   <?php
   /**
    * {Driver} Related Functionality
    * @author Joe Huss <detain@interserver.net>
    * @copyright 2025
    * @package MyAdmin
    * @category SQL
    */

   namespace MyDb\{Driver};

   use MyDb\Generic;
   use MyDb\Db_Interface;

   /**
    * Db
    *
    * @access public
    */
   class Db extends Generic implements Db_Interface
   {
       public $type = '{driver}';
   ```

2. **Implement `connect()`.** Accept `$database=''`, `$host=''`, `$user=''`, `$password=''`, `$port=''`. Fall back to `$this->database` etc. when empty. Set `$this->linkId` to the connection resource on success. Call `$this->halt()` on failure. Return the link ID.

3. **Implement `disconnect()`.** Close `$this->linkId` using the driver's close function, set `$this->linkId = 0`, return `true`.

4. **Implement `query($queryString, $line='', $file='')`.** Call `$this->connect()` first. Execute the query and store the result in `$this->queryId`. On failure call `$this->halt($errorMsg, $line, $file)`. Return `$this->queryId`.

5. **Implement `next_record($resultType = MYSQLI_ASSOC)`.** Fetch the next row into `$this->Record`, increment `$this->Row`. Return `true` if a row was fetched, `false` otherwise.

6. **Implement remaining required methods** following the pattern from `src/Mysqli/Db.php`:
   - `num_rows()` — return row count of `$this->queryId`
   - `num_fields()` — return field count
   - `affectedRows()` — return affected row count from last write query
   - `seek($pos)` — move result pointer
   - `free()` — free `$this->queryId`, set it to `0`
   - `real_escape($string)` — escape a string safe for SQL; call `$this->connect()` first
   - `getLastInsertId($table, $field)` — return last auto-increment ID
   - `lock($table, $mode)` / `unlock()` — table-level locking
   - `transactionBegin()` / `transactionCommit()` / `transactionAbort()` — transaction control
   - `tableNames()` — return array of `['table_name'=>..., 'tablespace_name'=>..., 'database'=>...]`
   - `indexNames()` — return array of indexes
   - `haltmsg($msg)` — log the error via `$this->log()`

7. **Register the driver in `src/Loader.php`.** Add a `case '{driver}':` to the `switch` block (around line 63) mirroring the existing cases.
   Verify the case string matches `public $type` exactly.

8. **Create the test file at `tests/<Driver>/DbTest.php`.**
   ```php
   <?php
   namespace MyDb\Tests\{Driver};

   use MyDb\{Driver}\Db;
   use PHPUnit\Framework\TestCase;

   class DbTest extends TestCase
   {
       protected $db;

       protected function setUp(): void
       {
           $this->db = new Db();
           $this->db->host = getenv('DBHOST') ?: 'localhost';
           $this->db->user = getenv('DBUSER') ?: '';
           $this->db->password = getenv('DBPASS') ?: '';
           $this->db->database = getenv('DBNAME') ?: '';
       }

       protected function tearDown(): void
       {
           $this->db->transactionAbort();
       }
   }
   ```
   Add test methods for `connect`, `disconnect`, `query`, `next_record`, `transactionBegin/Commit/Abort`, `real_escape`, `getLastInsertId`, `tableNames`.

9. **Run tests to verify.**
   ```bash
   vendor/bin/phpunit -c phpunit.xml.dist --testsuite "all tests"
   ```

## Examples

**User says:** "Add an SQLite driver"

**Actions:**
1. Create `src/Sqlite/Db.php` with `namespace MyDb\Sqlite;`, `public $type = 'sqlite';`, `connect()` using `sqlite_open()` or PDO sqlite DSN.
2. Add `case 'sqlite':` in `src/Loader.php` switch.
3. Create `tests/Sqlite/DbTest.php` with `namespace MyDb\Tests\Sqlite;`.
4. Run `vendor/bin/phpunit -c phpunit.xml.dist`.

**Result:** `MyDb\Sqlite\Db` is instantiable via `new \MyDb\Sqlite\Db($dbname)` and loadable by `Loader`.

## Common Issues

- **`Class MyDb\{Driver}\Db not found`** — namespace in the file does not match the directory path. Verify `namespace MyDb\{Driver};` matches the driver directory exactly (PascalCase).
- **`Class MyDb\{Driver}\Db contains 1 abstract method`** — a required interface method is missing. Run `grep -n 'public function' src/Db_Interface.php` and compare against your implementation.
- **`Call to undefined method ... ::halt()`** — the class does not extend `Generic`. Verify `use MyDb\Generic;` and `class Db extends Generic implements Db_Interface`.
- **Tests fail with `getenv('DBUSER') returns false`** — integration tests need env vars: `DBUSER=tests DBPASS=tests DBHOST=localhost DBNAME=tests vendor/bin/phpunit ...`
- **Loader returns `null` for new type** — the `case` string in `Loader.php` does not match `$type` exactly (case-sensitive). Both must be `'sqlite'`, not `'Sqlite'`.
