<?php
/**
 * A scripted stand-in for ext-mysqli, so \MyDb\Mysqli\Db::query() can be driven with no server.
 *
 * Inside namespace MyDb\Mysqli the driver calls mysqli_init(), mysqli_query(), mysqli_errno() and
 * friends unqualified, so PHP looks for MyDb\Mysqli\mysqli_query() before the global function, and
 * defining them here intercepts them. PHP caches the function a call site resolved to, so this file
 * must be loaded before the driver first runs in the process: require it from setUp() of a test that
 * runs in a separate process, never at file scope of a test class, or the stubs would replace the
 * real functions for every other test in the run.
 *
 * FakeServer keeps one server-side transaction per connection, so a test can see what would really
 * have been committed: a deadlock (1213), a certification failure (3101) or a lost connection (2006, 2013, 2055, 4031)
 * throws the connection's whole transaction away, as MySQL does; other errors fail the statement only.
 * With $implicitBegin (the default, as MySQL's trans_rollback_implicit() does) a 1213/3101 on a statement
 * inside START TRANSACTION leaves the connection in a new, empty transaction, so later statements are not
 * autocommitted until a ROLLBACK/COMMIT/START; a COMMIT that fails, with any code, ends the transaction, and so
 * does a START/BEGIN (its implicit commit) unless it did not parse. A 2014 (commands out of sync) never reached
 * the server and changes nothing there.
 *
 * Results: FakeServer::rows() scripts the rows a matching statement returns (a FakeResult the driver
 * reads through mysqli_fetch_*()/mysqli_data_seek()), FakeServer::affected() its affected rows; every
 * INSERT/REPLACE takes the next insert id, and, as with InnoDB, an id is never handed out twice.
 */

namespace MyDb\Mysqli {

	use MyDb\Tests\Mysqli\FakeLink;
	use MyDb\Tests\Mysqli\FakeServer;
	use MyDb\Tests\Mysqli\FakeStmt;

	function mysqli_init()
	{
		return new FakeLink();
	}

	function mysqli_real_escape_string($link, $string)
	{
		FakeServer::live($link, 'mysqli_real_escape_string');
		return addslashes((string) $string);
	}

	function mysqli_begin_transaction($link, $flags = 0, $name = null)
	{
		FakeServer::$begins++;
		return FakeServer::query($link, 'START TRANSACTION') === true;
	}

	function mysqli_query($link, $query, $resultMode = 0)
	{
		return FakeServer::query($link, $query);
	}

	function mysqli_errno($link)
	{
		return FakeServer::live($link, 'mysqli_errno')->errno;
	}

	function mysqli_error($link)
	{
		return FakeServer::live($link, 'mysqli_error')->error;
	}

	function mysqli_commit($link)
	{
		return FakeServer::query($link, 'COMMIT') === true;
	}

	function mysqli_rollback($link)
	{
		return FakeServer::query($link, 'ROLLBACK') === true;
	}

	function mysqli_close($link)
	{
		FakeServer::live($link, 'mysqli_close')->close();
		return true;
	}

	function mysqli_select_db($link, $database)
	{
		FakeServer::live($link, 'mysqli_select_db');
		return true;
	}

	function mysqli_prepare($link, $query)
	{
		$link = FakeServer::live($link, 'mysqli_prepare');
		FakeServer::$wire[] = ['prepare', $link->id(), $query];
		if (FakeServer::$prepareFails > 0) {
			// the server refuses the statement (1064): thrown under MYSQLI_REPORT_STRICT, false with reporting off
			FakeServer::$prepareFails--;
			$link->errno = 1064;
			$link->error = 'You have an error in your SQL syntax';
			if (FakeServer::$strict) {
				throw new \mysqli_sql_exception($link->error, 1064);
			}
			return false;
		}
		return new FakeStmt($link, $query);
	}

	function mysqli_stmt_execute($statement)
	{
		return FakeServer::query($statement->link, $statement->sql) !== false;
	}

	function mysqli_stmt_errno($statement)
	{
		return $statement->link->errno;
	}

	function mysqli_stmt_error($statement)
	{
		return $statement->link->error;
	}

	function mysqli_affected_rows($link)
	{
		return FakeServer::live($link, 'mysqli_affected_rows')->affected_rows;
	}

	function mysqli_insert_id($link)
	{
		return FakeServer::live($link, 'mysqli_insert_id')->insert_id;
	}

	function mysqli_fetch_row($result)
	{
		return FakeServer::result($result, 'mysqli_fetch_row')->fetch(MYSQLI_NUM);
	}

	function mysqli_fetch_array($result, $mode = MYSQLI_BOTH)
	{
		return FakeServer::result($result, 'mysqli_fetch_array')->fetch($mode);
	}

	function mysqli_fetch_object($result)
	{
		$row = FakeServer::result($result, 'mysqli_fetch_object')->fetch(MYSQLI_ASSOC);
		return $row === null ? null : (object) $row;
	}

	function mysqli_data_seek($result, $offset)
	{
		return FakeServer::result($result, 'mysqli_data_seek')->seek($offset);
	}

	function mysqli_num_rows($result)
	{
		return count(FakeServer::result($result, 'mysqli_num_rows')->rows);
	}

	function mysqli_num_fields($result)
	{
		return FakeServer::result($result, 'mysqli_num_fields')->fields;
	}

	function mysqli_free_result($result)
	{
		FakeServer::result($result, 'mysqli_free_result');
	}

	function usleep($microseconds)
	{
		FakeServer::$sleeps[] = $microseconds;
	}

	function error_log($message, $messageType = 0, $destination = null, $headers = null)
	{
		FakeServer::$log[] = (string) $message;
		return true;
	}
}

namespace MyDb\Tests\Mysqli {

	/**
	* stands in for a \mysqli handle: closing it makes every later use throw, as PHP 8's mysqli does.
	* A link that lost its connection (2006) is dead: every later statement on it fails with 2006
	* again, without reaching the server, until the driver closes it and connects anew.
	*/
	class FakeLink
	{
		public $connect_errno = 0;
		public $connect_error = '';
		public $errno = 0;
		public $error = '';
		public $dead = false;
		/** real_connect() failed the way real ext-mysqli does: the object is half-open and every later call on it throws \Error */
		public $halfOpen = false;
		public $affected_rows = 0;
		public $insert_id = 0;
		private $id;
		private $closed = false;

		public function __construct()
		{
			$this->id = ++FakeServer::$connections;
			FakeServer::$wire[] = ['connect', $this->id, ''];
		}

		public function options($option, $value)
		{
			return true;
		}

		public function real_connect($host = null, $user = null, $password = null, $database = null, $port = null)
		{
			if (FakeServer::$connectFailsReal > 0) {
				// probed against a refused port: strict throws, off returns false; either way the object is left half-open
				FakeServer::$connectFailsReal--;
				$this->halfOpen = true;
				$this->connect_errno = 2002;
				$this->connect_error = 'Connection refused';
				if (FakeServer::$strict) {
					throw new \mysqli_sql_exception('Connection refused', 2002);
				}
				return false;
			}
			if (FakeServer::$connectFails > 0) {
				FakeServer::$connectFails--;
				if (FakeServer::$strict) {
					throw new \mysqli_sql_exception('Connection refused', 2002);
				}
				return false;
			}
			if (FakeServer::$connectRefused) {
				if (FakeServer::$strict) {
					// MYSQLI_REPORT_STRICT: the driver's connect() never gets to halt()
					throw new \mysqli_sql_exception(FakeServer::$connectRefusedError, FakeServer::$connectRefusedErrno);
				}
				// the half-open handle: real_connect() reports success but the handle carries the error,
				// which is the one refused-connect shape in which connect() returns 0 instead of exiting
				$this->connect_errno = FakeServer::$connectRefusedErrno;
				$this->connect_error = FakeServer::$connectRefusedError;
			}
			return true;
		}

		public function set_charset($charset)
		{
			return true;
		}

		public function close()
		{
			if ($this->closed) {
				throw new \Error('mysqli object is already closed');
			}
			$this->closed = true;
			// the server drops whatever transaction the connection had open
			unset(FakeServer::$open[$this->id]);
			FakeServer::$wire[] = ['close', $this->id, ''];
			return true;
		}

		public function id()
		{
			return $this->id;
		}

		public function isClosed()
		{
			return $this->closed;
		}

		public function __get($name)
		{
			if ($this->closed) {
				throw new \Error('mysqli object is already closed');
			}
			if ($name === 'thread_id') {
				if ($this->halfOpen) {
					throw new \Error('Property access is not allowed yet');
				}
				return $this->id;
			}
			throw new \Error('Undefined property: mysqli::$'.$name);
		}
	}

	/**
	* a buffered result set (MYSQLI_STORE_RESULT): it can be read to the end and rewound
	*/
	class FakeResult
	{
		/** @var array[] rows as column => value */
		public $rows;
		public $fields;
		private $position = 0;

		public function __construct(array $rows)
		{
			$this->rows = array_values($rows);
			$this->fields = $this->rows === [] ? 1 : count($this->rows[0]);
		}

		public function fetch($mode)
		{
			if ($this->position >= count($this->rows)) {
				return null;
			}
			FakeServer::$fetches++;
			$row = $this->rows[$this->position++];
			if ($mode === MYSQLI_ASSOC) {
				return $row;
			}
			if ($mode === MYSQLI_NUM) {
				return array_values($row);
			}
			return array_merge(array_values($row), $row);
		}

		public function seek($offset)
		{
			if ($offset < 0 || $offset >= count($this->rows)) {
				return false;
			}
			$this->position = $offset;
			return true;
		}
	}

	/**
	* a prepared statement: execute() runs its SQL on the connection that prepared it
	*/
	class FakeStmt
	{
		public $link;
		public $sql;

		public function __construct(FakeLink $link, $sql)
		{
			$this->link = $link;
			$this->sql = $sql;
		}
	}

	final class FakeServer
	{
		/** the server's version (MYSQL_VERSION_ID): executable comments up to it run (serverSql()) */
		const SERVER_VERSION = 80036;
		/** @var bool true: failures throw mysqli_sql_exception (MYSQLI_REPORT_STRICT, PHP 8.1+ default); false: mysqli_query() returns false */
		public static $strict = true;
		/** @var int next N real_connect() calls fail the way real mysqli does (half-open object left behind) */
		public static $connectFailsReal = 0;
		/** @var string[] the functions called on a half-open object (each threw \Error), in order */
		public static $halfOpenCalls = [];
		/** @var bool every real_connect() is refused (2002) */
		public static $connectRefused = false;
		/** @var int the errno a refused real_connect() ($connectRefused) fails with */
		public static $connectRefusedErrno = 2002;
		/** @var string the error a refused real_connect() ($connectRefused) fails with */
		public static $connectRefusedError = 'Connection refused';
		/** @var int next N real_connect() calls fail (r6 harness addition) */
		public static $connectFails = 0;
		/** @var int next N mysqli_prepare() calls fail on the server (1064), thrown under $strict, else false (r16) */
		public static $prepareFails = 0;
		/** @var bool model MySQL's OPTION_BEGIN after an implicit rollback, and a failed COMMIT ending the transaction */
		public static $implicitBegin = true;
		/**
		 * @var bool model CREATE TEMPORARY TABLE / ALTER TABLE ... START TRANSACTION (off by default): trans_begin()
		 * (sql_parse.cc) commits the open transaction and begins one, then sql_table.cc refuses the statement (3979,
		 * ER_NOT_ALLOWED_WITH_START_TRANSACTION) without rolling that one back, so the session is left inside an empty
		 * transaction
		 */
		public static $ddlStartTransaction = false;
		/** @var array[] scripted result sets: [pattern, row sets (each run takes the next; the last one repeats)] */
		public static $results = [];
		/** @var array[] scripted affected rows: [pattern, counts (each run takes the next; the last one repeats)] */
		public static $affected = [];
		/** @var int the last insert id handed out (never reused, as with InnoDB's auto-increment) */
		public static $autoIncrement = 0;
		/** @var int mysqli_begin_transaction() calls (each also sends a START TRANSACTION) */
		public static $begins = 0;
		/** @var int connections opened so far, which is also the last connection's id */
		public static $connections = 0;
		/** @var array[] [event ('connect'|'close'|'query'), connection id, statement] in order */
		public static $wire = [];
		/** @var array<int, string[]> per connection with an open transaction, the writes made in it */
		public static $open = [];
		/** @var string[] writes that are durable */
		public static $committed = [];
		/** @var array[] scripted failures: [pattern, error code, times left] */
		public static $failures = [];
		/** @var int[] usleep() calls */
		public static $sleeps = [];
		/** @var string[] error_log() lines from the driver */
		public static $log = [];
		/** @var int rows fetched from any result set */
		public static $fetches = 0;

		private static $messages = [
			1062 => "Duplicate entry '1' for key 'PRIMARY'",
			2014 => "Commands out of sync; you can't run this command now",
			1064 => 'You have an error in your SQL syntax',
			1213 => 'Deadlock found when trying to get lock; try restarting transaction',
			2013 => 'Lost connection to MySQL server during query',
			1180 => 'Got error 35 - \'Transaction was rolled back\' during COMMIT',
			2006 => 'MySQL server has gone away',
			2055 => "Lost connection to MySQL server at 'reading initial communication packet'",
			4031 => 'The client was disconnected by the server because of inactivity.',
			3101 => 'Plugin instructed the server to rollback the current transaction.',
			3979 => 'START TRANSACTION clause cannot be used with this statement',
		];

		public static function reset()
		{
			self::$strict = true;
			self::$connectRefused = false;
			self::$connectRefusedErrno = 2002;
			self::$connectRefusedError = 'Connection refused';
			self::$connectFails = 0;
			self::$connectFailsReal = 0;
			self::$prepareFails = 0;
			self::$halfOpenCalls = [];
			self::$implicitBegin = true;
			self::$ddlStartTransaction = false;
			self::$results = [];
			self::$affected = [];
			self::$autoIncrement = 0;
			self::$begins = 0;
			self::$connections = 0;
			self::$wire = [];
			self::$open = [];
			self::$committed = [];
			self::$failures = [];
			self::$sleeps = [];
			self::$log = [];
			self::$fetches = 0;
		}

		/**
		* the next $times statements matching $pattern fail with $code
		*/
		public static function failNext($pattern, $code, $times = 1)
		{
			self::$failures[] = [$pattern, $code, $times];
		}

		/**
		* a statement matching $pattern returns the first row set the first time it succeeds, the second the
		* second time, and so on; the last one repeats
		*
		* @param string $pattern
		* @param array[] ...$rowSets each a list of rows (column => value)
		*/
		public static function rows($pattern, ...$rowSets)
		{
			self::$results[] = [$pattern, $rowSets];
		}

		/**
		* a write matching $pattern reports these affected rows, run after run (the last one repeats)
		*
		* @param string $pattern
		* @param int ...$counts
		*/
		public static function affected($pattern, ...$counts)
		{
			self::$affected[] = [$pattern, $counts];
		}

		/**
		* the result, if it is one mysqli would accept; otherwise what mysqli would throw
		*/
		public static function result($result, $function)
		{
			if (!$result instanceof FakeResult) {
				throw new \TypeError($function.'(): Argument #1 ($result) must be of type mysqli_result, '.gettype($result).' given');
			}
			return $result;
		}

		/**
		* the statements sent on any connection, in order (attempts on a dead link left out)
		*
		* @return string[]
		*/
		public static function statements()
		{
			$out = [];
			foreach (self::$wire as $event) {
				if ($event[0] === 'query') {
					$out[] = $event[2];
				}
			}
			return $out;
		}

		/**
		* the next scripted value for $sql from $script ([pattern, values] pairs), or null when none matches
		*/
		private static function scripted(array &$script, $sql)
		{
			foreach ($script as $index => $entry) {
				if (preg_match($entry[0], $sql) === 1) {
					$value = $entry[1][0];
					if (count($entry[1]) > 1) {
						array_shift($script[$index][1]);
					}
					return [$value];
				}
			}
			return null;
		}

		/**
		* how many times $statement reached a server, on any connection (not counting attempts on a dead link)
		*/
		public static function sent($statement)
		{
			$count = 0;
			foreach (self::$wire as $event) {
				if ($event[0] === 'query' && $event[2] === $statement) {
					$count++;
				}
			}
			return $count;
		}

		public static function closes()
		{
			return count(array_filter(self::$wire, static function ($event) {
				return $event[0] === 'close';
			}));
		}

		/**
		* the link, if it is one mysqli would still accept; otherwise what mysqli would throw
		*/
		public static function live($link, $function)
		{
			if (!$link instanceof FakeLink) {
				throw new \TypeError($function.'(): Argument #1 ($mysql) must be of type mysqli, '.gettype($link).' given');
			}
			if ($link->isClosed()) {
				throw new \Error('mysqli object is already closed');
			}
			if ($link->halfOpen) {
				self::$halfOpenCalls[] = $function;
				throw new \Error('mysqli object is not fully initialized');
			}
			return $link;
		}

		public static function query($link, $sql)
		{
			$link = self::live($link, 'mysqli_query');
			$connection = $link->id();
			if ($link->dead) {
				self::$wire[] = ['dead', $connection, $sql];
				$link->errno = 0;
				$link->error = '';
				return self::fail($link, 2006);
			}
			self::$wire[] = ['query', $connection, $sql];
			$link->errno = 0;
			$link->error = '';
			$link->affected_rows = 0;
			$link->insert_id = 0;
			$original = $sql;
			foreach (self::$failures as $index => $failure) {
				if ($failure[2] > 0 && preg_match($failure[0], $sql) === 1) {
					self::$failures[$index][2]--;
					return self::fail($link, $failure[1], $sql);
				}
			}
			// the server ignores comments, and runs the content of an executable one
			$sql = self::serverSql($sql);
			$chain = preg_match('/^(?:commit|rollback)\b(?:\s+work)?\s+and\s+chain\b/i', $sql) === 1;
			if (self::$ddlStartTransaction && preg_match('/^\s*(?:create\s+temporary\s+table|alter\s+table)\b.*\bstart\s+transaction\b/is', $sql) === 1) {
				foreach (isset(self::$open[$connection]) ? self::$open[$connection] : [] as $write) {
					self::$committed[] = $write;
				}
				self::$open[$connection] = [];
				return self::fail($link, 3979, $sql);
			}
			if (preg_match('/^\s*(start\s+transaction|begin)\b/i', $sql) === 1) {
				// START TRANSACTION/BEGIN implicitly commits a transaction that is already open
				foreach (isset(self::$open[$connection]) ? self::$open[$connection] : [] as $write) {
					self::$committed[] = $write;
				}
				self::$open[$connection] = [];
			} elseif (preg_match('/^\s*commit\b/i', $sql) === 1) {
				foreach (isset(self::$open[$connection]) ? self::$open[$connection] : [] as $write) {
					self::$committed[] = $write;
				}
				unset(self::$open[$connection]);
				if ($chain) {
					self::$open[$connection] = [];
				}
			} elseif (preg_match('/^\s*(savepoint|release\s+savepoint|rollback\s+(work\s+)?to)\b/i', $sql) === 1) {
				// savepoints leave the transaction as it is (what they undo is not modelled)
			} elseif (preg_match('/^\s*rollback\b/i', $sql) === 1) {
				unset(self::$open[$connection]);
				if ($chain) {
					self::$open[$connection] = [];
				}
			} elseif (preg_match('/^\s*unlock\s+tables\b/i', $sql) === 1) {
				// halt() sends this after every failed statement
			} elseif (isset(self::$open[$connection])) {
				self::$open[$connection][] = $sql;
			} else {
				self::$committed[] = $sql; // autocommit
			}
			$rows = self::scripted(self::$results, $original);
			if ($rows !== null) {
				$result = new FakeResult($rows[0]);
				$link->affected_rows = count($result->rows);
				return $result;
			}
			if (preg_match('/^\s*(insert|replace|update|delete)\b/i', $sql, $write) === 1) {
				$count = self::scripted(self::$affected, $original);
				$link->affected_rows = $count === null ? 1 : $count[0];
				if (in_array(strtolower($write[1]), ['insert', 'replace'], true)) {
					$link->insert_id = ++self::$autoIncrement;
				}
			}
			return true;
		}

		/**
		 * The statement as the server runs it, from its first keyword: a block comment is a space, an executable one
		 * (slash-star-bang, with no version or one up to SERVER_VERSION) is its content, as MySQL's lexer does; a
		 * MariaDB one (slash-star-M-bang, slash-star-m-bang too) and a later version are comments. Leading whitespace,
		 * "(" and line comments are dropped. Nesting as MySQL's lexer has it (r13): a version comment it skips ends at
		 * the first star-slash that does not close a comment nested in it (consume_comment(), one level); in one it
		 * runs, a block comment is a space and the first star-slash outside one ends it; a plain comment ends at its
		 * first star-slash. Quotes in a comment are not modelled.
		 *
		 * @param string $sql
		 * @return string
		 */
		public static function serverSql($sql)
		{
			// each block comment found with strpos (a pattern walked a comment never closed again from every slash-star
			// in it: the length squared, the whole of query()'s time on a long one); one never closed is left as it is
			$sql = (string) $sql;
			$bare = '';
			$from = 0;
			while (($open = strpos($sql, '/*', $from)) !== false) {
				if (substr($sql, $open + 2, 1) !== '!') {
					$close = strpos($sql, '*/', $open + 2);
					if ($close === false) {
						break;
					}
					$bare .= substr($sql, $from, $open - $from).' ';
					$from = $close + 2;
					continue;
				}
				$version = strspn($sql, '0123456789', $open + 3) >= 5 ? substr($sql, $open + 3, 5) : '';
				$runs = $version === '' || (int) $version <= self::SERVER_VERSION;
				$at = $open + 3 + strlen($version);
				$content = '';
				$end = false;
				while (($close = strpos($sql, '*/', $at)) !== false) {
					$nested = strpos($sql, '/*', $at);
					if ($nested === false || $nested > $close) {
						$content .= substr($sql, $at, $close - $at);
						$end = $close + 2;
						break;
					}
					// a comment nested in it (a space where it runs), to its own first star-slash
					$content .= substr($sql, $at, $nested - $at).' ';
					$inner = strpos($sql, '*/', $nested + 2);
					if ($inner === false) {
						break;
					}
					$at = $inner + 2;
				}
				if ($end === false) {
					break;
				}
				$bare .= substr($sql, $from, $open - $from).($runs ? ' '.$content.' ' : ' ');
				$from = $end;
			}
			$sql = $bare.substr($sql, $from);
			$sql = preg_replace('/^(?:[\s(]|--[ \t\r\n][^\n]*(?:\n|$)|#[^\n]*(?:\n|$))+/s', '', $sql);
			// a line comment among the leading words (START -- c, newline, TRANSACTION) is a space too
			do {
				$sql = preg_replace('/^(\w++(?:\s++\w++)*+)\s*+(?:--[ \t\r\n][^\n]*+|#[^\n]*+)(?:\n|$)/', '$1 ', $sql, 1, $count);
			} while ($count > 0);
			return $sql;
		}

		private static function fail(FakeLink $link, $code, $sql = '')
		{
			$bare = self::serverSql((string) $sql);
			$isCommit = preg_match('/^\s*commit\b/i', $bare) === 1;
			if (preg_match('/^\s*(start\s+transaction|begin)\b/i', $bare) === 1 && !in_array($code, [1064, 1065, 1149], true)) {
				// a START/BEGIN with a transaction open commits it first: when that commit fails the transaction is
				// over (rolled back, the connection back in autocommit), like any failed COMMIT. One that does not parse
				// never runs, so the transaction stays open
				$isCommit = true;
			}
			$link->affected_rows = -1;
			if ($code === 2014) {
				// commands out of sync: the client never sent the statement, so the server's state is untouched
				$isCommit = false;
			} elseif (in_array($code, [1213, 2006, 2013, 2055, 4031, 3101, 1180], true)) {
				// the server rolls the connection's whole transaction back (a lost connection takes it with it)
				$wasOpen = isset(self::$open[$link->id()]);
				unset(self::$open[$link->id()]);
				// trans_rollback_implicit() keeps OPTION_BEGIN after a deadlock or a rollback request on a statement
				// inside START TRANSACTION: the statements that follow join a new transaction, not autocommit
				if (self::$implicitBegin && $wasOpen && !$isCommit && in_array($code, [1213, 3101], true)) {
					self::$open[$link->id()] = [];
				}
			} elseif (self::$implicitBegin && $isCommit) {
				// trans_commit() ends the transaction whatever its outcome
				unset(self::$open[$link->id()]);
			}
			if (in_array($code, [2006, 2013, 2055, 4031], true)) {
				$link->dead = true;
			}
			$link->errno = $code;
			$link->error = isset(self::$messages[$code]) ? self::$messages[$code] : 'error '.$code;
			if (self::$strict) {
				throw new \mysqli_sql_exception($link->error, $code);
			}
			return false;
		}
	}

	/**
	* the real driver, except that a failed statement is recorded instead of mailed
	*/
	class ScriptedDb extends \MyDb\Mysqli\Db
	{
		public $reported = [];

		/** @var int calls of isPlainRollback(), the whole-statement scan query() runs only on a ROLLBACK */
		public static $plainChecks = 0;

		protected static function isPlainRollback($queryString)
		{
			self::$plainChecks++;
			return parent::isPlainRollback($queryString);
		}

		/** the recorded statements of the open transaction */
		public function steps()
		{
			return $this->txSteps;
		}

		/** whether the open transaction can be replayed, and if not why */
		public function replayable()
		{
			return $this->txReplayable ? true : $this->txNotReplayableWhy;
		}

		/** the lost-transaction flag, as the driver holds it */
		public function lost($value = null)
		{
			if ($value !== null) {
				$this->transactionLost = $value;
			}
			return $this->transactionLost;
		}

		/** whether the driver takes the statement for a plain ROLLBACK */
		public function plain($sql)
		{
			return static::isPlainRollback($sql);
		}

		/** the statement as the driver's lexer helper leaves it, for a test of its cost */
		public function lead($sql)
		{
			return static::leadingKeywordSql($sql);
		}

		public function emailError($queryString, $error, $line, $file)
		{
			$this->reported[] = [$queryString, $error];
		}

		/** @var int|null the random part of every replay wait, in microseconds; null: all of it (so a wait is its full base) */
		public $jitterUs = null;

		/** @var float virtual seconds: replay waits advance it, so the replay budget is deterministic */
		public $virtualClock = 0.0;

		protected function replayJitterUs($max)
		{
			return $this->jitterUs === null ? $max : min($max, $this->jitterUs);
		}

		protected function replayClock()
		{
			return $this->virtualClock;
		}

		protected function replaySleep($microseconds)
		{
			$this->virtualClock += $microseconds / 1000000;
			parent::replaySleep($microseconds);
		}

		/** the state a new connection would not have: whether a statement marked the link */
		public function sessionDirtyFlag()
		{
			return $this->sessionStateChanged();
		}

		public function haltmsg($msg, $line = '', $file = '')
		{
		}

		/** @var int halt() calls, counted before it acts (with haltOnError 'yes' it ends the process) */
		public $halts = 0;

		public function halt($msg, $line = '', $file = '')
		{
			$this->halts++;
			return parent::halt($msg, $line, $file);
		}
	}
}
