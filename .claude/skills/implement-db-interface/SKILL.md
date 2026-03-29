---
name: implement-db-interface
description: Generates all required Db_Interface + Generic method stubs for a new MyDb driver class (connect, disconnect, query, next_record, num_rows, num_fields, affectedRows, seek, free, real_escape, getLastInsertId, lock, unlock, transactionBegin/Commit/Abort, tableNames, haltmsg, qr). Use when user says 'implement interface', 'add missing methods', 'create a new driver', or a driver class is missing required methods. Do NOT use for calling DB methods or writing queries — only for implementing driver classes in src/{Driver}/Db.php.
---
# implement-db-interface

## Critical

- Every driver **must** extend `MyDb\Generic` and implement `MyDb\Db_Interface` — no exceptions.
- Use **tabs** for indentation (enforced by `.scrutinizer.yml`).
- Never skip `connect()` at the top of `query()`, `lock()`, `prepare()`, and `transactionBegin()`.
- Always guard `getLastInsertId()`: return `-1` if `$table` or `$field` is empty.
- `$this->Record` must be set in `next_record()`; `$this->Row` must be incremented there.
- `$this->linkId = 0` is the "not connected" sentinel; `$this->queryId = 0` is the "no result" sentinel.
- Call `$this->halt($msg, $line, $file)` (from `Generic`) on errors — never `throw` or `die()` directly.

## Instructions

1. **Create the driver file** (e.g. `src/Mysqli/Db.php`). Verify no file exists there before creating.

2. **Write the class skeleton** with the exact header, namespace, imports, and `$type` property:
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

class Db extends Generic implements Db_Interface
{
	public $type = '{driverstring}'; // e.g. 'mysqli', 'pgsql', 'pdo'
}
```
Verify `namespace MyDb\{Driver}` matches the directory name exactly.

3. **Implement `connect()`** — guard with `if (0 == $this->linkId)`, call `$this->halt()` on failure, set `$this->linkId`.

4. **Implement `disconnect()`** — close the resource, set `$this->linkId = 0`, return bool.

5. **Implement `real_escape($string = '')`** — use the driver's native escape function; fall back to `$this->escape($string)` (defined in `Generic`) if not connected.

6. **Implement `free()`** — free the result resource with `@`, then set `$this->queryId = 0`.

7. **Implement `query($queryString, $line = '', $file = '', $log = false)`** following this pattern:
 - Return `0` on empty string.
 - Call `$this->connect()`, return `0` if it fails.
 - Save/restore `$this->haltOnError` around the execute.
 - Call `$this->free()` if a prior `queryId` resource exists.
 - Run the query, set `$this->Row = 0`, `$this->Errno`, `$this->Error`.
 - Call `$this->addLog($queryString, $elapsed, $line, $file)` unless `$GLOBALS['disable_db_queries']` is set.
 - Call `$this->emailError()` + `$this->halt()` on failure.
 - Return `$this->queryId`.

8. **Implement `next_record($resultType = ...)`** — fetch into `$this->Record`, increment `$this->Row`, set `$this->Errno`/`$this->Error`, call `$this->free()` on end-of-results if `$this->autoFree`, return `is_array($this->Record)`.

9. **Implement `seek($pos)`** — reposition the cursor, update `$this->Row`, call `$this->haltmsg()` on failure.

10. **Implement transactions:**
 - `transactionBegin()` — native begin or `$this->query('begin')`.
 - `transactionCommit()` — native commit; return `false` if `$this->Errno` is set (pgsql pattern).
 - `transactionAbort()` — native rollback; always succeeds.

11. **Implement `getLastInsertId($table, $field)`:**
```php
public function getLastInsertId($table, $field)
{
	if (!isset($table) || $table == '' || !isset($field) || $field == '') {
		return -1;
	}
	// driver-specific last-insert logic here
}
```

12. **Implement `lock($table, $mode = 'write')` and `unlock()`** — handle array and string `$table`; call `$this->halt()` on failure.

13. **Implement `affectedRows()`, `num_rows()`, `num_fields()`** — thin wrappers with `@` suppression around the driver function.

14. **Implement `tableNames()`** — query the driver's catalog (e.g. `SHOW TABLES` for MySQL, `pg_class` for PgSQL), return array of `['table_name', 'tablespace_name', 'database']`.

15. **Implement `haltmsg($msg, $line = '', $file = '')`** — call `$this->log("Database error: $msg", ...)`, log the driver-specific last error, then call `$this->logBackTrace()`.

16. **Implement `qr($query, $line = '', $file = '')`** — alias to `queryReturn()`:
```php
public function qr($query, $line = '', $file = '')
{
	return $this->queryReturn($query, $line, $file);
}
```

17. **Run tests** to verify: `vendor/bin/phpunit -c phpunit.xml.dist --testsuite "{driver} tests"`

## Examples

**User says:** "Add a new driver modeled on the Mysqli driver"

**Actions taken:**
1. Create the driver file at the appropriate path with matching namespace and `public $type` set to the driver string.
2. Implement `connect()` using the driver's connection function, halt on failure.
3. Implement `query()` with `$this->connect()` guard, execute the query, call `addLog()`.
4. Implement `next_record()` fetching the result row, update `$this->Record` and `++$this->Row`.
5. Implement `getLastInsertId($table, $field)` using the driver's last-insert mechanism.
6. Implement `transactionBegin/Commit/Abort` via native calls or `$this->query('BEGIN')` etc.
7. Run `vendor/bin/phpunit -c phpunit.xml.dist --testsuite "{driver} tests"`.

**Result:** A class identical in structure to `src/Mysqli/Db.php` but using the target driver's extension calls.

## Common Issues

- **"Class MyDb\\{Driver}\\Db contains abstract methods"**: You missed one or more interface methods. Run `grep -n 'public function' src/Db_Interface.php` and cross-check against your class.
- **`$this->Record` is always `null`**: `next_record()` is not assigning the fetch result to `$this->Record` before returning.
- **`getLastInsertId()` returns `-1` unexpectedly**: Check that `$table` and `$field` are non-empty strings at the call site — the guard returns `-1` for empty values by design.
- **Transactions silently not rolling back**: `transactionAbort()` was called before `transactionBegin()` — ensure `setUp()` calls `transactionBegin()` and `tearDown()` calls `transactionAbort()` in tests.
- **`addLog()` not defined**: It lives in `Generic` — confirm `use MyDb\Generic` is at the top and the class `extends Generic`.
- **Indentation errors from linter**: Use tabs, not spaces. Run `make php-cs-fixer` to auto-fix.
