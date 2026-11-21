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
 * have been committed: a deadlock (1213), a certification failure (3101) or a lost connection (2006)
 * throws the connection's whole transaction away, as MySQL does; other errors fail the statement only.
 */

namespace MyDb\Mysqli {

	use MyDb\Tests\Mysqli\FakeLink;
	use MyDb\Tests\Mysqli\FakeServer;

	function mysqli_init()
	{
		return new FakeLink();
	}

	function mysqli_begin_transaction($link, $flags = 0, $name = null)
	{
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
					throw new \mysqli_sql_exception('Connection refused', 2002);
				}
				// the half-open handle: real_connect() reports success but the handle carries the error,
				// which is the one refused-connect shape in which connect() returns 0 instead of exiting
				$this->connect_errno = 2002;
				$this->connect_error = 'Connection refused';
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
				return $this->id;
			}
			throw new \Error('Undefined property: mysqli::$'.$name);
		}
	}

	final class FakeServer
	{
		/** @var bool true: failures throw mysqli_sql_exception (MYSQLI_REPORT_STRICT, PHP 8.1+ default); false: mysqli_query() returns false */
		public static $strict = true;
		/** @var bool every real_connect() is refused (2002) */
		public static $connectRefused = false;
		/** @var int next N real_connect() calls fail (r6 harness addition) */
		public static $connectFails = 0;
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

		private static $messages = [
			1062 => "Duplicate entry '1' for key 'PRIMARY'",
			1064 => 'You have an error in your SQL syntax',
			1213 => 'Deadlock found when trying to get lock; try restarting transaction',
			2013 => 'Lost connection to MySQL server during query',
			1180 => 'Got error 35 - \'Transaction was rolled back\' during COMMIT',
			2006 => 'MySQL server has gone away',
			3101 => 'Plugin instructed the server to rollback the current transaction.',
		];

		public static function reset()
		{
			self::$strict = true;
			self::$connectRefused = false;
			self::$connectFails = 0;
			self::$connections = 0;
			self::$wire = [];
			self::$open = [];
			self::$committed = [];
			self::$failures = [];
			self::$sleeps = [];
			self::$log = [];
		}

		/**
		* the next $times statements matching $pattern fail with $code
		*/
		public static function failNext($pattern, $code, $times = 1)
		{
			self::$failures[] = [$pattern, $code, $times];
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
			foreach (self::$failures as $index => $failure) {
				if ($failure[2] > 0 && preg_match($failure[0], $sql) === 1) {
					self::$failures[$index][2]--;
					return self::fail($link, $failure[1]);
				}
			}
			// the server ignores leading comments
			$sql = preg_replace('/^(?:[\s(]|\/\*.*?\*\/|--[ \t\r\n][^\n]*(?:\n|$)|#[^\n]*(?:\n|$))+/s', '', $sql);
			$sql = preg_replace('/\/\*.*?\*\//s', ' ', $sql);
			$chain = preg_match('/^(?:commit|rollback)\b(?:\s+work)?\s+and\s+chain\b/i', $sql) === 1;
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
			return true;
		}

		private static function fail(FakeLink $link, $code)
		{
			if (in_array($code, [1213, 2006, 2013, 3101, 1180], true)) {
				// the server rolls the connection's whole transaction back
				unset(self::$open[$link->id()]);
			}
			if ($code === 2006 || $code === 2013) {
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

		public function haltmsg($msg, $line = '', $file = '')
		{
		}
	}
}
