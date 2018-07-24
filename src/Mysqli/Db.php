<?php
/**
 * MySQL Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2018
 * @package MyAdmin
 * @category SQL
 */

namespace MyDb\Mysqli;

use \MyDb\Generic;
use \MyDb\Db_Interface;

/**
 * Db
 *
 * @access public
 */
class Db extends Generic implements Db_Interface {
	/**
	 * @var string
	 */
	public $type = 'mysqli';

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
			//$this->linkId = new mysqli($host, $user, $password, $database);
			$this->linkId = mysqli_init();
			$this->linkId->options(MYSQLI_INIT_COMMAND, "SET NAMES {$this->characterSet} COLLATE {$this->collation}, COLLATION_CONNECTION = {$this->collation}, COLLATION_DATABASE = {$this->collation}");
			$this->linkId->real_connect($host, $user, $password, $database);
			$this->linkId->set_charset($this->characterSet);
			/*
			* $this->linkId = $this->Link_Init->real_connect($host, $user, $password, $database);
			* if ($this->linkId)
			* {
			* $this->linkId = $this->Link_Init;
			* }
			*/
			if ($this->linkId->connect_errno) {
				$this->halt("connect($host, $user, \$password) failed. ".$mysqli->connect_error);
				return 0;
			}
			/*if ($this->characterSet != '') {
				if ($this->collation != '')
					@mysqli_query($this->linkId, "SET NAMES {$this->characterSet} COLLATE {$this->collation}, COLLATION_CONNECTION = {$this->collation}, COLLATION_DATABASE = {$this->collation}, {$this->collation} = {$this->collation};", MYSQLI_STORE_RESULT);
				else
					@mysqli_query($this->linkId, "SET NAMES {$this->characterSet};", MYSQLI_STORE_RESULT);
				mysqli_set_charset($this->linkId, $this->characterSet);
			}*/
		}
		return $this->linkId;
	}

	/* This only affects systems not using persistent connections */

	/**
	 * Db::disconnect()
	 * @return bool
	 */
	public function disconnect() {
		$return = is_object($this->linkId) ? $this->linkId->close() : false;
		$this->linkId = 0;
		return $return;
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function real_escape($string) {
		if ((!is_resource($this->linkId) || $this->linkId == 0) && !$this->connect())
			return $this->escape($string);
		return mysqli_real_escape_string($this->linkId, $string);
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function escape($string) {
		if (function_exists('mysql_escape_string'))
			return mysql_escape_string($string);
		return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $string);
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

	/* public: discard the query result */

	/**
	 * Db::free()
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
			$this->next_record(MYSQLI_ASSOC);
			return $this->Record;
		} else {
			$out = [];
			while ($this->next_record(MYSQLI_ASSOC))
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
	 * creates a prepaired statement from query
	 *
	 * @param string $query sql wuery like INSERT INTO table (col) VALUES (?)  or  SELECT * from table WHERE col1 = ? and col2 = ?  or  UPDATE table SET col1 = ?, col2 = ? WHERE col3 = ?
	 * @return int|\MyDb\Mysqli\mysqli_stmt
	 */
	public function prepare($query) {
		if (!$this->connect())
			return 0;
		$haltPrev = $this->haltOnError;
		$this->haltOnError = 'no';
		return mysqli_prepare($this->linkId, $query);
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
		$try = 0;
		$this->queryId = false;
		while ((null === $this->queryId || $this->queryId === false) && $try <= $tries) {
			$try++;
			if ($try > 1) {
				@mysqli_close($this->linkId);
				$this->connect();
			}
			$this->queryId = @mysqli_query($this->linkId, $queryString, MYSQLI_STORE_RESULT);
			$this->Row = 0;
			$this->Errno = @mysqli_errno($this->linkId);
			$this->Error = @mysqli_error($this->linkId);
			if ($try == 1 && (null === $this->queryId || $this->queryId === false)) {
				$email = "MySQLi Error<br>\n".'Query: '.$queryString."<br>\n".'Error #'.$this->Errno.': '.$this->Error."<br>\n".'Line: '.$line."<br>\n".'File: '.$file."<br>\n".(isset($GLOBALS['tf']) ? 'User: '.$GLOBALS['tf']->session->account_id."<br>\n" : '');
				$email .= '<br><br>Request Variables:<br>'.print_r($_REQUEST, true);
				$email .= '<br><br>Server Variables:<br>'.print_r($_SERVER, true);
				$subject = $_SERVER['HOSTNAME'].' MySQLi Error';
				$headers = '';
				$headers .= 'MIME-Version: 1.0'.PHP_EOL;
				$headers .= 'Content-type: text/html; charset=UTF-8'.PHP_EOL;
				$headers .= 'From: No-Reply <no-reply@interserver.net>'.PHP_EOL;
				$headers .= 'X-Mailer: Trouble-Free.Net Admin Center'.PHP_EOL;
				mail('john@interserver.net', $subject, $email, $headers);
				mail('detain@interserver.net', $subject, $email, $headers);
				$this->haltmsg('Invalid SQL: '.$queryString, $line, $file);
			}
		}
		$this->haltOnError = $haltPrev;
		if (null === $this->queryId || $this->queryId === false)
			$this->halt('', $line, $file);

		// Will return nada if it fails. That's fine.
		return $this->queryId;
	}

	/**
	 * @return array|null|object
	 */
	public function fetchObject() {
		$this->Record = @mysqli_fetch_object($this->queryId);
		return $this->Record;
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
	 * Initiates a transaction
	 *
	 * @return bool
	 */
	public function transactionBegin() {
		if (version_compare(PHP_VERSION, '5.5.0') < 0)
			return true;
		if (!$this->connect())
			return 0;
		return mysqli_begin_transaction($this->linkId);
	}

	/**
	 * Commits a transaction
	 *
	 * @return bool
	 */
	public function transactionCommit() {
		if (version_compare(PHP_VERSION, '5.5.0') < 0 || $this->linkId === 0)
			return true;
		return mysqli_commit($this->linkId);
	}

	/**
	 * Rolls back a transaction
	 *
	 * @return bool
	 */
	public function transactionAbort() {
		if (version_compare(PHP_VERSION, '5.5.0') < 0 || $this->linkId === 0)
			return true;
		return mysqli_rollback($this->linkId);
	}

	/**
	 * This will get the last insert ID created on the current connection.  Should only be called after an insert query is
	 * run on a table that has an auto incrementing field.  $table and $field are required, but unused here since it's
	 * unnecessary for mysql.  For compatibility with pgsql, the params must be supplied.
	 *
	 * @param string $table
	 * @param string $field
	 * @return int|string
	 */
	public function getLastInsertId($table, $field) {
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
			while (list($key, $value) = each($table)) {
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
	 * @param bool $haltOnError optional, defaults to TRUE, whether or not to halt on error
	 * @return bool|int|\mysqli_result
	 */
	public function unlock($haltOnError = true) {
		$this->connect();

		$res = @mysqli_query($this->linkId, 'unlock tables');
		if ($haltOnError === true && !$res) {
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
	 * gets an array of the table names in teh current datase
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

	/**
	 * Db::createDatabase()
	 *
	 * @param string $adminname
	 * @param string $adminpasswd
	 * @return void
	 */
	public function createDatabase($adminname = '', $adminpasswd = '') {
		$currentUser = $this->user;
		$currentPassword = $this->password;
		$currentDatabase = $this->database;

		if ($adminname != '') {
			$this->user = $adminname;
			$this->password = $adminpasswd;
			$this->database = 'mysql';
		}
		$this->disconnect();
		$this->query("CREATE DATABASE $currentDatabase");
		$this->query("grant all on $currentDatabase.* to $currentUser@localhost identified by '{$currentPassword}'");
		$this->disconnect();

		$this->user = $currentUser;
		$this->password = $currentPassword;
		$this->database = $currentDatabase;
		$this->connect();
		/*return $return; */
	}

}

/**
 * @param $result
 * @param $row
 * @param int|string $field
 * @return bool
 */
function mysqli_result($result, $row, $field = 0) {
	if ($result === false) return false;
	if ($row >= mysqli_num_rows($result)) return false;
	if (is_string($field) && !(mb_strpos($field, '.') === false)) {
		$tField = explode('.', $field);
		$field = -1;
		$tFields = mysqli_fetch_fields($result);
		for ($id = 0, $idMax = mysqli_num_fields($result); $id < $idMax; $id++) {
			if ($tFields[$id]->table == $tField[0] && $tFields[$id]->name == $tField[1]) {
				$field = $id;
				break;
			}
		}
		if ($field == -1) return false;
	}
	mysqli_data_seek($result, $row);
	$line = mysqli_fetch_array($result);
	return isset($line[$field]) ? $line[$field] : false;
}
