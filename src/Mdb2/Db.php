<?php
/**
* MDB2 Wrapper Made To Handle Like Our Other Classes Related Functionality
* @author Joe Huss <detain@interserver.net>
* @copyright 2018
* @package MyAdmin
* @category SQL
*/

namespace MyDb\Mdb2;

use \MyDb\Generic;
use \MyDb\Db_Interface;

/**
 * Db
 *
 * @access public
 */
class Db extends Generic implements Db_Interface {
	public $host = 'localhost';
	public $user = 'pdns';
	public $password = '';
	public $database = 'pdns';
	public $type = 'mdb2';
	public $error = false;
	public $message = '';

	/**
	 * Db::quote()
	 * @param string $text
	 * @param string $type
	 * @return string
	 */
	public function quote($text = '', $type = 'text') {
		switch ($type) {
			case 'text':
				return "'".mysqli_real_escape_string($this->linkId, $text)."'";
				break;
			case 'integer':
				return (int) $text;
				break;
			default:
				return $text;
				break;
		}
	}

	/**
	 * Db::queryOne()
	 *
	 * @param mixed $query
	 * @param string $line
	 * @param string $file
	 * @return bool
	 */
	public function queryOne($query, $line = '', $file = '') {
		$this->query($query, $line, $file);
		if ($this->num_rows() > 0) {
			$this->next_record();
			return $this->f(0);
		} else
			return 0;
	}

	/**
	 * Db::queryRow()
	 *
	 * @param mixed $query
	 * @param string $line
	 * @param string $file
	 * @return array|bool
	 */
	public function queryRow($query, $line = '', $file = '') {
		$this->query($query, $line, $file);
		if ($this->num_rows() > 0) {
			$this->next_record();
			return $this->Record;
		} else
			return 0;
	}

	/**
	 * Db::lastInsertId()
	 * @param mixed $table
	 * @param mixed $field
	 * @return int
	 */
	public function lastInsertId($table, $field) {
		return $this->getLastInsertId($table, $field);
	}

	/**
	 * alias function of select_db, changes the database we are working with.
	 *
	 * @param string $database the name of the database to use
	 * @return void
	 */
	public function useDb($database) {
		$this->selectDb($database);
	}

	/**
	 * changes the database we are working with.
	 *
	 * @param string $database the name of the database to use
	 * @return void
	 */
	public function selectDb($database) {
		$this->connect();
		mysqli_select_db($this->linkId, $database);
	}

	/* public: connection management */

	/**
	 * Db::connect()
	 * @param string $database
	 * @param string $host
	 * @param string $user
	 * @param string $password
	 * @return int|\mysqli
	 */
	public function connect($database = '', $host = '', $user = '', $password = '') {
		/* Handle defaults */
		if ('' == $database)
			$database = $this->database;
		if ('' == $host)
			$host = $this->host;
		if ('' == $user)
			$user = $this->user;
		if ('' == $password)
			$password = $this->password;
		/* establish connection, select database */
		if (!is_object($this->linkId)) {
			$this->connectionAttempt++;
			if ($this->connectionAttempt > 1)
				error_log("MySQLi Connection Attempt #{$this->connectionAttempt}/{$this->maxConnectErrors}");
			if ($this->connectionAttempt >= $this->maxConnectErrors) {
				$this->halt("connect($host, $user, \$password) failed. ".$mysqli->connect_error);
				return 0;
			}
			$this->linkId = mysqli_init();
			$this->linkId->options(MYSQLI_INIT_COMMAND, "SET NAMES {$this->characterSet} COLLATE {$this->collation}, COLLATION_CONNECTION = {$this->collation}, COLLATION_DATABASE = {$this->collation}");
			$this->linkId->real_connect($host, $user, $password, $database);
			$this->linkId->set_charset($this->characterSet);
			if ($this->linkId->connect_errno) {
				$this->halt("connect($host, $user, \$password) failed. ".$mysqli->connect_error);
				return 0;
			}
		}
		return $this->linkId;
	}

	/* This only affects systems not using persistent connections */

	/**
	 * Db::disconnect()
	 * @return int
	 */
	public function disconnect() {
		if (is_object($this->linkId))
			return $this->linkId->close();
		else
			return 0;
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function real_escape($string) {
		if ((!is_resource($this->linkId) || $this->linkId == 0) && !$this->connect())
			return mysqli_escape_string($link, $string);
		return mysqli_real_escape_string($this->linkId, $string);
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function escape($string) {
		return mysqli_real_escape_string($this->linkId, $string);
	}

	/**
	 * Db::dbAddslashes()
	 * @param mixed $str
	 * @return string
	 */
	public function dbAddslashes($str) {
		if (!isset($str) || $str == '')
			return '';

		return addslashes($str);
	}

	/**
	 * discard the query result
	 * @return void
	 */
	public function free() {
		if (is_resource($this->queryId))
			@mysqli_free_result($this->queryId);
		$this->queryId = 0;
	}

	/**
	 * Db::queryReturn()
	 *
	 * Sends an SQL query to the server like the normal query() command but iterates through
	 * any rows and returns the row or rows immediately or FALSE on error
	 *
	 * @param mixed $query SQL Query to be used
	 * @param string $line optionally pass __LINE__ calling the query for logging
	 * @param string $file optionally pass __FILE__ calling the query for logging
	 * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
	 */
	public function queryReturn($query, $line = '', $file = '') {
		$this->query($query, $line, $file);
		if ($this->num_rows() == 0) {
			return false;
		} elseif ($this->num_rows() == 1) {
			$this->next_record(MYSQL_ASSOC);
			return $this->Record;
		} else {
			$out = [];
			while ($this->next_record(MYSQL_ASSOC))
				$out[] = $this->Record;
			return $out;
		}
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
	 * Db::query()
	 *
	 *  Sends an SQL query to the database
	 *
	 * @param mixed $queryString
	 * @param string $line
	 * @param string $file
	 * @return mixed 0 if no query or query id handler, safe to ignore this return
	 */
	public function query($queryString, $line = '', $file = '') {
		/* No empty queries, please, since PHP4 chokes on them. */
		/* The empty query string is passed on from the constructor,
		* when calling the class without a query, e.g. in situations
		* like these: '$db = new db_Subclass;'
		*/
		if ($queryString == '')
			return 0;
		if (!$this->connect()) {
			return 0;
			/* we already complained in connect() about that. */
		}
		$haltPrev = $this->haltOnError;
		$this->haltOnError = 'no';
		// New query, discard previous result.
		if (is_resource($this->queryId))
			$this->free();
		if ($this->Debug)
			printf("Debug: query = %s<br>\n", $queryString);
		if (isset($GLOBALS['log_queries']) && $GLOBALS['log_queries'] !== false)
			$this->log($queryString, $line, $file);
		$tries = 3;
		$try = 1;
		$this->queryId = @mysqli_query($this->linkId, $queryString, MYSQLI_STORE_RESULT);
		$this->Row = 0;
		$this->Errno = @mysqli_errno($this->linkId);
		$this->Error = @mysqli_error($this->linkId);
		while ($this->queryId === false && $try <= $tries) {
			$this->message = 'MySQL error '.@mysqli_errno($this->linkId).': '.@mysqli_error($this->linkId).' Query: '.$this->query;
			$this->error = true;
			@mysqli_close($this->linkId);
			$this->connect();
			$try++;
		}
		$this->haltOnError = $haltPrev;
		if ($this->queryId === false) {
			$this->emailError($queryString, 'Error #'.$this->Errno.': '.$this->Error, $line, $file);
		}

		// Will return nada if it fails. That's fine.
		return $this->queryId;
	}

	/* public: walk result set */

	/**
	 * Db::next_record()
	 *
	 * @param mixed $resultType
	 * @return bool
	 */
	public function next_record($resultType = MYSQLI_BOTH) {
		if ($this->queryId === false) {
			$this->halt('next_record called with no query pending.');
			return 0;
		}

		$this->Record = @mysqli_fetch_array($this->queryId, $resultType);
		++$this->Row;
		$this->Errno = mysqli_errno($this->linkId);
		$this->Error = mysqli_error($this->linkId);

		$stat = is_array($this->Record);
		if (!$stat && $this->autoFree && is_resource($this->queryId))
			$this->free();
		return $stat;
	}

	/* public: position in result set */

	/**
	 * Db::seek()
	 * @param integer $pos
	 * @return int
	 */
	public function seek($pos = 0) {
		$status = @mysqli_data_seek($this->queryId, $pos);
		if ($status) {
			$this->Row = $pos;
		} else {
			$this->halt("seek($pos) failed: result has ".$this->num_rows().' rows');
			/* half assed attempt to save the day,
			* but do not consider this documented or even
			* desirable behaviour.
			*/
			@mysqli_data_seek($this->queryId, $this->num_rows());
			$this->Row = $this->num_rows;
			return 0;
		}
		return 1;
	}

	/**
	 * Db::transactionBegin()
	 * @return bool
	 */
	public function transactionBegin() {
		return true;
	}

	/**
	 * Db::transactionCommit()
	 * @return bool
	 */
	public function transactionCommit() {
		return true;
	}

	/**
	 * Db::transactionAbort()
	 * @return bool
	 */
	public function transactionAbort() {
		return true;
	}

	/**
	 * Db::getLastInsertId()
	 * @param mixed $table
	 * @param mixed $field
	 * @return int|string
	 */
	public function getLastInsertId($table, $field) {
		/* This will get the last insert ID created on the current connection.  Should only be called
		* after an insert query is run on a table that has an auto incrementing field.  $table and
		* $field are required, but unused here since it's unnecessary for mysql.  For compatibility
		* with pgsql, the params must be supplied.
		*/

		if (!isset($table) || $table == '' || !isset($field) || $field == '')
			return -1;

		return @mysqli_insert_id($this->linkId);
	}

	/* public: table locking */

	/**
	 * Db::lock()
	 * @param mixed  $table
	 * @param string $mode
	 * @return bool|int|\mysqli_result
	 */
	public function lock($table, $mode = 'write') {
		$this->connect();

		$query = 'lock tables ';
		if (is_array($table)) {
			foreach ($table as $key => $value) {
				if ($key == 'read' && $key != 0) {
					$query .= "$value read, ";
				} else {
					$query .= "$value $mode, ";
				}
			}
			$query = mb_substr($query, 0, -2);
		} else {
			$query .= "$table $mode";
		}
		$res = @mysqli_query($this->linkId, $query);
		if (!$res) {
			$this->halt("lock($table, $mode) failed.");
			return 0;
		}
		return $res;
	}

	/**
	 * Db::unlock()
	 * @return bool|int|\mysqli_result
	 */
	public function unlock() {
		$this->connect();

		$res = @mysqli_query($this->linkId, 'unlock tables');
		if (!$res) {
			$this->halt('unlock() failed.');
			return 0;
		}
		return $res;
	}

	/* public: evaluate the result (size, width) */

	/**
	 * Db::affectedRows()
	 * @return int
	 */
	public function affectedRows() {
		return @mysqli_affected_rows($this->linkId);
	}

	/**
	 * Db::num_rows()
	 * @return int
	 */
	public function num_rows() {
		return @mysqli_num_rows($this->queryId);
	}

	/**
	 * Db::num_fields()
	 * @return int
	 */
	public function num_fields() {
		return @mysqli_num_fields($this->queryId);
	}

	/**
	 * Db::tableNames()
	 *
	 * @return array
	 */
	public function tableNames() {
		$return = [];
		$this->query('SHOW TABLES');
		$i = 0;
		while ($info = $this->queryId->fetch_row()) {
			$return[$i]['table_name'] = $info[0];
			$return[$i]['tablespace_name'] = $this->database;
			$return[$i]['database'] = $this->database;
			++$i;
		}
		return $return;
	}
}
