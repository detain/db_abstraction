<?php
/**
 * Generic SQL Driver Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2018
 * @package MyAdmin
 * @category SQL
 */

namespace MyDb;

/**
 * Class Generic
 */
abstract class Generic {
	/* public: connection parameters */
	public $host = 'localhost';
	public $database = '';
	public $user = '';
	public $password = '';

	/* public: configuration parameters */
	public $autoStripslashes = false;
	public $Debug = 0; // Set to 1 for debugging messages.
	public $haltOnError = 'yes'; // "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)

	public $maxConnectErrors = 30;
	public $connectionAttempt = 0;
	public $maxMatches = 10000000;

	/**
	 * @var int
	 */
	public $autoFree = 0; // Set to 1 for automatic mysql_free_result()

	/* public: result array and current row number */
	public $Record = [];
	public $Row;

	/* public: current error number and error text */
	public $Errno = 0;
	public $Error = '';

	/* public: this is an api revision, not a CVS revision. */
	public $type = 'generic';

	/* private: link and query handles */
	public $linkId = 0;
	public $queryId = 0;

	public $characterSet = 'utf8mb4';
	public $collation = 'utf8mb4_unicode_ci';

	/**
	 * Constructs the db handler, can optionally specify connection parameters
	 *
	 * @param string $database Optional The database name
	 * @param string $user Optional The username to connect with
	 * @param string $password Optional The password to use
	 * @param string $host Optional The hostname where the server is, or default to localhost
	 * @param string $query Optional query to perform immediately
	 */
	public function __construct($database = '', $user = '', $password = '', $host = 'localhost', $query = '') {
		$this->database = $database;
		$this->user = $user;
		$this->password = $password;
		$this->host = $host;
		if ($query != '')
			$this->query($query);
		$this->connection_atttempt = 0;
	}

	/**
	 * @param string $message
	 * @param string $line
	 * @param string $file
	 * @return void
	 */
	public function log($message, $line = '', $file = '', $level = 'info') {
		//if (function_exists('myadmin_log'))
			//myadmin_log('db', $level, $message, $line, $file, isset($GLOBALS['tf']));
		//else
			error_log($message);
	}

	/**
	 * @return int
	 */
	public function linkId() {
		return $this->linkId;
	}

	/**
	 * @return int
	 */
	public function queryId() {
		return $this->queryId;
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function real_escape($string) {
		if ((!is_resource($this->linkId) || $this->linkId == 0) && !$this->connect())
			return mysqli_escape_string($string);
		return mysqli_real_escape_string($this->linkId, $string);
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function escape($string) {
		return mysql_escape_string($string);
	}

	/**
	 * @param mixed $str
	 * @return string
	 */
	public function dbAddslashes($str) {
		if (!isset($str) || $str == '')
			return '';

		return addslashes($str);
	}

	/**
	 * Db::toTimestamp()
	 * @param mixed $epoch
	 * @return bool|string
	 */
	public function toTimestamp($epoch) {
		return date('Y-m-d H:i:s', $epoch);
	}

	/**
	 * Db::fromTimestamp()
	 * @param mixed $timestamp
	 * @return bool|int|mixed
	 */
	public function fromTimestamp($timestamp) {
		if (preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $timestamp, $parts))
			$time = mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
		elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
			$time = mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
		elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
			$time = mktime(1, 1, 1, $parts[2], $parts[3], $parts[1]);
		elseif (is_numeric($timestamp) && $timestamp >= 943938000)
			$time = $timestamp;
		else {
			$this->log('Cannot Match Timestamp from '.$timestamp, __LINE__, __FILE__);
			$time = false;
		}
		return $time;
	}

	/**
	 * @param int $start
	 * @return string
	 */
	public function limit($start = 0) {
		echo '<b>Warning: limit() is no longer used, use limitQuery()</b>';
		if ($start == 0) {
			$s = 'limit '.(int) $this->maxMatches;
		} else {
			$s = 'limit '.(int) $start.','.(int) $this->maxMatches;
		}
		return $s;
	}

	/**
	 * perform a query with limited result set
	 *
	 * @param string $queryString
	 * @param string|int $numRows
	 * @param int $offset
	 * @param string|int $line
	 * @param string $file
	 * @return mixed
	 */
	public function limitQuery($queryString, $numRows = '', $offset = 0, $line = '', $file = '') {
		if (!$numRows)
			$numRows = $this->maxMatches;
		if ($offset == 0) {
			$queryString .= ' LIMIT '.(int) $numRows;
		} else {
			$queryString .= ' LIMIT '.(int) $offset.','.(int) $numRows;
		}

		if ($this->Debug)
			printf("Debug: limitQuery = %s<br>offset=%d, num_rows=%d<br>\n", $queryString, $offset, $numRows);

		return $this->query($queryString, $line, $file);
	}

	/**
	 * db:qr()
	 *
	 *  alias of queryReturn()
	 *
	 * @param mixed $query SQL Query to be used
	 * @param string $line optionally pass __LINE__ calling the query for logging
	 * @param string $file optionally pass __FILE__ calling the query for logging
	 * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
	 */
	public function qr($query, $line = '', $file = '') {
		return $this->queryReturn($query, $line, $file);
	}

	/**
	 * gets a field
	 *
	 * @param mixed  $name
	 * @param string $stripSlashes
	 * @return string
	 */
	public function f($name, $stripSlashes = '') {
		if ($stripSlashes || ($this->autoStripslashes && !$stripSlashes)) {
			return stripslashes($this->Record[$name]);
		} else {
			return $this->Record[$name];
		}
	}

	/**
	 * error handling
	 *
	 * @param mixed $msg
	 * @param string $line
	 * @param string $file
	 * @return void
	 */
	public function halt($msg, $line = '', $file = '') {
		$this->unlock(false);
		/* Just in case there is a table currently locked */

		//$this->Error = @$this->linkId->error;
		//$this->Errno = @$this->linkId->errno;
		if ($this->haltOnError == 'no')
			return;
		if ($msg != '')
			$this->haltmsg($msg, $line, $file);
		if ($this->haltOnError != 'report') {
			echo '<p><b>Session halted.</b>';
			// FIXME! Add check for error levels
			if (isset($GLOBALS['tf']))
				$GLOBALS['tf']->terminate();
		}
	}

	/**
	 * @param mixed $msg
	 * @param string $line
	 * @param string $file
	 * @return mixed|void
	 */
	public function logBackTrace($msg, $line = '', $file = '') {
		$backtrace = (function_exists('debug_backtrace') ? debug_backtrace() : []);
		$this->log(
			('' !== getenv('REQUEST_URI') ? ' '.getenv('REQUEST_URI') : '').
			((isset($_POST) && count($_POST)) ? ' POST='.json_encode($_POST) : '').
			((isset($_GET) && count($_GET)) ? ' GET='.json_encode($_GET) : '').
			((isset($_FILES) && count($_FILES)) ? ' FILES='.json_encode($_FILES) : '').
			('' !== getenv('HTTP_USER_AGENT') ? ' AGENT="'.getenv('HTTP_USER_AGENT').'"' : '').
			(isset($_SERVER['REQUEST_METHOD']) ? ' METHOD="'.$_SERVER['REQUEST_METHOD'].'"'.
				($_SERVER['REQUEST_METHOD'] === 'POST' ? ' POST="'.json_encode($_POST).'"' : '') : ''), $line, $file, 'error');
		for ($level = 1, $levelMax = count($backtrace); $level < $levelMax; $level++) {
			$message = (isset($backtrace[$level]['file']) ? 'File: '.$backtrace[$level]['file'] : '').
				(isset($backtrace[$level]['line']) ? ' Line: '.$backtrace[$level]['line'] : '').
				' Function: '.(isset($backtrace[$level] ['class']) ? '(class '.$backtrace[$level] ['class'].') ' : '').
				(isset($backtrace[$level] ['type']) ? $backtrace[$level] ['type'].' ' : '').
				$backtrace[$level] ['function'].'(';
			if (isset($backtrace[$level] ['args']))
				for ($argument = 0, $argumentMax = count($backtrace[$level]['args']); $argument < $argumentMax; $argument++)
					$message .= ($argument > 0 ? ', ' : '').
						(is_object($backtrace[$level]['args'][$argument]) ? 'class '.get_class($backtrace[$level]['args'][$argument]) : json_encode($backtrace[$level]['args'][$argument]));
			$message .= ')';
			$this->log($message, $line, $file, 'error');
		}
	}

	/**
	 * @param mixed $msg
	 * @param string $line
	 * @param string $file
	 * @return mixed|void
	 */
	public function haltmsg($msg, $line = '', $file = '') {
		$this->log("Database error: $msg", $line, $file, 'error');
		if ($this->Errno != '0' || !in_array($this->Error, '', '()')) {
			$sqlstate = mysqli_sqlstate($this->linkId);
			$this->log("MySQLi SQLState: {$sqlstate}. Error: ".$this->Errno.' ('.$this->Error.')', $line, $file, 'error');
		}
		$this->logBackTrace($msg, $line, $file);
	}

	/**
	 * @return array
	 */
	public function indexNames() {
		return [];
	}

}
