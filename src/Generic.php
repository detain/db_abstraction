<?php
/**
	* Generic SQL Driver Related Functionality
	* by detani@interserver.net
	* @copyright 2018
	* @package MyAdmin
	* @category SQL
	*/

namespace MyDb;

/**
 * Class Generic
 */
abstract class Generic
{
	/* public: connection parameters */
	public $host = 'localhost';
	public $database = '';
	public $user = '';
	public $password = '';

	/* public: configuration parameters */
	public $autoStripslashes = FALSE;
	public $Debug = 0; // Set to 1 for debugging messages.
	public $haltOnError = 'yes'; // "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)
	public $seqTable = 'db_sequence';

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
	public function link_id() {
		return $this->linkId;
	}

	/**
	 * @return int
	 */
	public function query_id() {
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
	public function db_addslashes($str) {
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
			return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
		elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
			return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
		elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
			return mktime(1, 1, 1, $parts[2], $parts[3], $parts[1]);
		elseif (is_numeric($timestamp) && $timestamp >= 943938000)
			return $timestamp;
		else {
			$this->log('Cannot Match Timestamp from '.$timestamp, __LINE__, __FILE__);
			return FALSE;
		}
	}

	/**
	 * @param int $start
	 * @return string
	 */
	public function limit($start = 0) {
		echo '<b>Warning: limit() is no longer used, use limit_query()</b>';
		if ($start == 0) {
			$s = 'limit '.(int)$this->maxMatches;
		} else {
			$s = 'limit '.(int)$start.','.(int)$this->maxMatches;
		}
		return $s;
	}

	/**
	 * perform a query with limited result set
	 * 
	 * @param string $queryString
	 * @param int $offset
	 * @param string|int $line
	 * @param string $file
	 * @param string|int $numRows
	 * @return mixed
	 */
	public function limit_query($queryString, $offset = 0, $line = '', $file = '', $numRows = '') {
		if (!$numRows)
			$numRows = $this->maxMatches;
		if ($offset == 0) {
			$queryString .= ' LIMIT '.(int)$numRows;
		} else {
			$queryString .= ' LIMIT '.(int)$offset.','.(int)$numRows;
		}

		if ($this->Debug)
			printf("Debug: limit_query = %s<br>offset=%d, num_rows=%d<br>\n", $queryString, $offset, $numRows);

		return $this->query($queryString, $line, $file);
	}

	/**
	 * db:qr()
	 *
	 *  alias of query_return()
	 *
	 * @param mixed $query SQL Query to be used
	 * @param string $line optionally pass __LINE__ calling the query for logging
	 * @param string $file optionally pass __FILE__ calling the query for logging
	 * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
	 */
	public function qr($query, $line = '', $file = '') {
		return $this->query_return($query, $line, $file);
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

		if ($this->haltOnError == 'no')
			return;
		$this->haltmsg($msg);

		if ($file)
			error_log("File: $file");
		if ($line)
			error_log("Line: $line");
		if ($this->haltOnError != 'report') {
			echo '<p><b>Session halted.</b>';
			// FIXME! Add check for error levels
			if (isset($GLOBALS['tf']))
				$GLOBALS['tf']->terminate();
		}
	}

	/**
	 * @param $msg
	 */
	public function haltmsg($msg) {
		$this->log("Database error: $msg", __LINE__, __FILE__);
		if ($this->Errno != '0' || $this->Error != '()')
			$this->log('SQL Error: '.$this->Errno.' ('.$this->Error.')', __LINE__, __FILE__);
	}

	/**
	 * @return array
	 */
	public function index_names() {
		return [];
	}

}
