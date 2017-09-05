<?php
/**
* MDB2 Wrapper Made To Handle Like Our Other Classes Related Functionality
* @author Joe Huss <detain@interserver.net>
* @copyright 2017
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
class Db extends \MyDb\Generic implements \MyDb\Db_Interface
{
	public $host = 'localhost';
	public $user = 'pdns';
	public $password = '';
	public $database = 'pdns';
	public $type = 'mdb2';

	public $characterSet = 'utf8mb4';
	public $collation = 'utf8mb4_unicode_ci';

	/**
	 * @var bool
	 */
	public $autoStripslashes = FALSE;
	/**
	 * @var int
	 */
	public $autoFree = 1; // Set to 1 for automatic mysql_free_result()
	/**
	 * @var int
	 */
	public $Debug = 0; // Set to 1 for debugging messages.
	/**
	 * @var string
	 */
	public $haltOnError = 'yes'; // "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)
	/**
	 * @var string
	 */
	public $seqTable = 'db_sequence';
	/**
	 * @var array result array and current row number
	 */
	public $Record = [];
	/**
	 * @var array
	 */
	public $Row;

	/* public: current error number and error text */
	public $Errno = 0;
	public $Error = '';
	public $error = FALSE;
	public $message = '';

	/* private: link and query handles */
	/** @var mysqli **/
	public $linkId = 0;
	public $queryId = 0;

	public $maxConnectErrors = 30;
	public $connectionAttempt = 0;

	public $maxMatches = 10000000;

	/**
	 * Db::quote()
	 * @param string $text
	 * @param string $type
	 * @return string
	 */
	public function quote($text = '', $type = 'text') {
		switch ($type) {
			case 'text':
				return "'" . mysqli_real_escape_string($this->linkId, $text) . "'";
				break;
			case 'integer':
				return (int)$text;
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
	 * @param        $message
	 * @param string $line
	 * @param string $file
	 * @return mixed|void
	 */
	public function log($message, $line = '', $file = '') {
		if (function_exists('billingd_log')) {
			if (isset($GLOBALS['tf']) && isset($GLOBALS['tf']->session) && $GLOBALS['tf']->session->sessionid != '')
				myadmin_log('db', 'info', $message, $line, $file);
			else
				myadmin_log('db', 'info', $message, $line, $file, FALSE);
		} else
			error_log($message);
	}

	/**
	 * alias function of select_db, changes the database we are working with.
	 *
	 * @param string $database the name of the database to use
	 * @return void
	 */
	public function use_db($database) {
		$this->select_db($database);
	}

	/**
	 * changes the database we are working with.
	 *
	 * @param string $database the name of the database to use
	 * @return void
	 */
	public function select_db($database) {
		$this->connect();
		mysqli_select_db($this->linkId, $database);
	}

	/* public: some trivial reporting */

	/**
	 * Db::link_id()
	 *
	 * @return int|\MyDb\Mdb2\mysqli
	 */
	public function link_id() {
		return $this->linkId;
	}

	/**
	 * Db::query_id()
	 * @return int
	 */
	public function query_id() {
		return $this->queryId;
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
				myadmin_log('db', 'info', "MySQLi Connection Attempt #{$this->connectionAttempt}/{$this->maxConnectErrors}", __LINE__, __FILE__);
			if ($this->connectionAttempt >= $this->maxConnectErrors) {
				$this->halt("connect($host, $user, \$password) failed. " . $mysqli->connect_error);
				return 0;
			}
			$this->linkId = mysqli_init();
			$this->linkId->options(MYSQLI_INIT_COMMAND, "SET NAMES {$this->characterSet} COLLATE {$this->collation}, COLLATION_CONNECTION = {$this->collation}, COLLATION_DATABASE = {$this->collation}");
			$this->linkId->real_connect($host, $user, $password, $database);
			$this->linkId->set_charset($this->characterSet);
			if ($this->linkId->connect_errno) {
				$this->halt("connect($host, $user, \$password) failed. " . $mysqli->connect_error);
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
	 * Db::db_addslashes()
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
	 * @param int $epoch a timestamp
	 * @return string a formatted date string
	 */
	public function toTimestamp($epoch) {
		return date('Y-m-d H:i:s', $epoch);
	}

	/**
	 * Db::fromTimestamp()
	 * @param string $timestamp a formatted date string
	 * @return int a timestamp
	 */
	public function fromTimestamp($timestamp) {
		if (preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $timestamp, $parts))
			return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
		elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
			return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
		elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
			return mktime(1, 1, 1, $parts[2], $parts[3], $parts[1]);
		elseif (is_numeric($timestamp) && $timestamp >= 943938000)
			return (int)$timestamp;
		else {
			$this->log('Cannot Match Timestamp from '.$timestamp, __LINE__, __FILE__);
			return time();
		}
	}

	/**
	 * Db::limit()
	 * @param mixed $start
	 * @return string
	 */
	public function limit($start) {
		echo '<b>Warning: limit() is no longer used, use limit_query()</b>';

		if ($start == 0) {
			$s = 'limit '.$this->maxMatches;
		} else {
			$s = "limit $start," . $this->maxMatches;
		}
		return $s;
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
	 * Db::query_return()
	 *
	 * Sends an SQL query to the server like the normal query() command but iterates through
	 * any rows and returns the row or rows immediately or FALSE on error
	 *
	 * @param mixed $query SQL Query to be used
	 * @param string $line optionally pass __LINE__ calling the query for logging
	 * @param string $file optionally pass __FILE__ calling the query for logging
	 * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
	 */
	public function query_return($query, $line = '', $file = '') {
		$this->query($query, $line, $file);
		if ($this->num_rows() == 0) {
			return FALSE;
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
		$halt_prev = $this->haltOnError;
		$this->haltOnError = 'no';
		// New query, discard previous result.
		if (is_resource($this->queryId))
			$this->free();
		if ($this->Debug)
			printf("Debug: query = %s<br>\n", $queryString);
		if (isset($GLOBALS['log_queries']) && $GLOBALS['log_queries'] !== FALSE)
			$this->log($queryString, $line, $file);
		$tries = 3;
		$try = 1;
		$this->queryId = @mysqli_query($this->linkId, $queryString, MYSQLI_STORE_RESULT);
		$this->Row = 0;
		$this->Errno = @mysqli_errno($this->linkId);
		$this->Error = @mysqli_error($this->linkId);
		while ($this->queryId === FALSE && $try <= $tries) {
			$this->message = 'MySQL error '.@mysqli_errno($this->linkId).': '.@mysqli_error($this->linkId).' Query: '.$this->query;
			$this->error = TRUE;
			@mysqli_close($this->linkId);
			$this->connect();
			$try++;
		}
		$this->haltOnError = $halt_prev;
		if ($this->queryId === FALSE) {
			$email = "MySQLi Error<br>\n".'Query: '.$queryString . "<br>\n".'Error #'.$this->Errno.': '.$this->Error . "<br>\n".'Line: '.$line . "<br>\n".'File: '.$file . "<br>\n" . (isset($GLOBALS['tf']) ?
					'User: '.$GLOBALS['tf']->session->account_id . "<br>\n" : '');

			$email .= '<br><br>Request Variables:<br>'.print_r($_REQUEST, TRUE);
			$email .= '<br><br>Server Variables:<br>'.print_r($_SERVER, TRUE);
			$subject = DOMAIN.' MySQLi Error On '.TITLE;
			$headers = '';
			$headers .= 'MIME-Version: 1.0'.EMAIL_NEWLINE;
			$headers .= 'Content-type: text/html; charset=UTF-8'.EMAIL_NEWLINE;
			$headers .= 'From: '.TITLE.' <'.EMAIL_FROM.'>'.EMAIL_NEWLINE;
			//				$headers .= "To: \"John Quaglieri\" <john@interserver.net>" . EMAIL_NEWLINE;

			$headers .= 'X-Mailer: Trouble-Free.Net Admin Center'.EMAIL_NEWLINE;
			admin_mail($subject, $email, $headers, FALSE, 'admin_email_sql_error.tpl');
			$this->halt('Invalid SQL: '.$queryString, $line, $file);
		}

		// Will return nada if it fails. That's fine.
		return $this->queryId;
	}

	// public: perform a query with limited result set

/**
	 * Db::limit_query()
	 * @param mixed  $queryString
	 * @param mixed  $offset
	 * @param string $line
	 * @param string $file
	 * @param string|int $numRows
	 * @return mixed
	 */
	public function limit_query($queryString, $offset, $line = '', $file = '', $numRows = '') {
		if (!$numRows)
			$numRows = $this->maxMatches;
		if ($offset == 0) {
			$queryString .= ' LIMIT '.$numRows;
		} else {
			$queryString .= ' LIMIT '.$offset.','.$numRows;
		}

		if ($this->Debug)
			printf("Debug: limit_query = %s<br>offset=%d, num_rows=%d<br>\n", $queryString, $offset, $numRows);

		return $this->query($queryString, $line, $file);
	}

	/* public: walk result set */

	/**
	 * Db::next_record()
	 *
	 * @param mixed $resultType
	 * @return bool
	 */
	public function next_record($resultType = MYSQLI_BOTH) {
		if ($this->queryId === FALSE) {
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
			$this->halt("seek($pos) failed: result has " . $this->num_rows().' rows');
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
	 * Db::transaction_begin()
	 * @return bool
	 */
	public function transaction_begin() {
		return TRUE;
	}

	/**
	 * Db::transaction_commit()
	 * @return bool
	 */
	public function transaction_commit() {
		return TRUE;
	}

	/**
	 * Db::transaction_abort()
	 * @return bool
	 */
	public function transaction_abort() {
		return TRUE;
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
			return - 1;

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
	 * Db::affected_rows()
	 * @return int
	 */
	public function affected_rows() {
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

	/* public: shorthand notation */

	/**
	 * Db::nf()
	 * @return int
	 */
	public function nf() {
		return $this->num_rows();
	}

	/**
	 * Db::np()
	 * @return void
	 */
	public function np() {
		print $this->num_rows();
	}

	/**
	 * Db::f()
	 *
	 * @param mixed  $Name
	 * @param string $stripSlashes
	 * @return string
	 */
	public function f($Name, $stripSlashes = '') {
		if ($stripSlashes || ($this->autoStripslashes && !$stripSlashes)) {
			return stripslashes($this->Record[$Name]);
		} else {
			return $this->Record[$Name];
		}
	}

	/**
	 * Db::p()
	 *
	 * @param mixed $Name
	 * @return void
	 */
	public function p($Name) {
		print $this->Record[$Name];
	}

	/* public: sequence numbers */

	/**
	 * Db::nextid()
	 *
	 * @param mixed $seqName
	 * @return int
	 */
	public function nextid($seqName) {
		$this->connect();

		if ($this->lock($this->seqTable)) {
			/* get sequence number (locked) and increment */
			$q = sprintf("select nextid from %s where seq_name = '%s'", $this->seqTable, $seqName);
			$id = @$this->linkId->query($q);
			$res = @$id->fetch_array();

			/* No current value, make one */
			if (!is_array($res)) {
				$currentid = 0;
				$q = sprintf("insert into %s values('%s', %s)", $this->seqTable, $seqName, $currentid);
				$id = @$this->linkId->query($q);
			} else {
				$currentid = $res['nextid'];
			}
			$nextid = $currentid + 1;
			$q = sprintf("update %s set nextid = '%s' where seq_name = '%s'", $this->seqTable, $nextid, $seqName);
			$id = @$this->linkId->query($q);
			$this->unlock();
		} else {
			$this->halt('cannot lock '.$this->seqTable.' - has it been created?');
			return 0;
		}
		return $nextid;
	}

	/* private: error handling */

	/**
	 * Db::halt()
	 *
	 * @param mixed  $msg
	 * @param string $line
	 * @param string $file
	 * @return void
	 */
	public function halt($msg, $line = '', $file = '') {
		$this->unlock();
		/* Just in case there is a table currently locked */

		//$this->Error = @$this->linkId->error;
		//$this->Errno = @$this->linkId->errno;
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
	 * Db::haltmsg()
	 *
	 * @param mixed $msg
	 * @param string $line
	 * @param string $file
	 * @return mixed|void
	 */
	public function haltmsg($msg, $line = '', $file = '') {
		$this->log("Database error: $msg", $line, $file);
		if ($this->Errno != '0' || !in_array($this->Error, '', '()')) {
			$sqlstate = mysqli_sqlstate($this->linkId);
			$this->log("MySQLi SQLState: {$sqlstate}. Error: " . $this->Errno.' ('.$this->Error.')', $line, $file);
		}
		$backtrace=(function_exists('debug_backtrace') ? debug_backtrace() : []);
		$this->log(
			('' !== getenv('REQUEST_URI') ? ' '.getenv('REQUEST_URI') : '').
			((isset($_POST) && count($_POST)) ? ' POST='.myadmin_stringify($_POST) : '').
			((isset($_GET) && count($_GET)) ? ' GET='.myadmin_stringify($_GET) : '').
			((isset($_FILES) && count($_FILES)) ? ' FILES='.myadmin_stringify($_FILES) : '').
			('' !== getenv('HTTP_USER_AGENT') ? ' AGENT="'.getenv('HTTP_USER_AGENT').'"' : '').
			(isset($_SERVER[ 'REQUEST_METHOD' ]) ?' METHOD="'. $_SERVER['REQUEST_METHOD']. '"'.
			($_SERVER['REQUEST_METHOD'] === 'POST' ? ' POST="'. myadmin_stringify($_POST). '"' : '') : ''));
		for($level=1, $levelMax = count($backtrace);$level < $levelMax;$level++) {
			$message=(isset($backtrace[$level]['file']) ? 'File: '. $backtrace[$level]['file'] : '').
				(isset($backtrace[$level]['line']) ? ' Line: '. $backtrace[$level]['line'] : '').
				' Function: '.(isset($backtrace[$level] ['class']) ? '(class '. $backtrace[$level] ['class'].') ' : '') .
				(isset($backtrace[$level] ['type']) ? $backtrace[$level] ['type'].' ' : '').
				$backtrace[$level] ['function'].'(';
			if(isset($backtrace[$level] ['args']))
				for($argument = 0, $argumentMax = count($backtrace[$level]['args']); $argument < $argumentMax; $argument++)
					$message .= ($argument > 0 ? ', ' : '').
						(is_object($backtrace[$level]['args'][$argument]) ? 'class '.get_class($backtrace[$level]['args'][$argument]) : myadmin_stringify($backtrace[$level]['args'][$argument]));
			$message.=')';
			$this->log($message);
		}

	}

	/**
	 * Db::table_names()
	 *
	 * @return array
	 */
	public function table_names() {
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
	 * Db::index_names()
	 *
	 * @return array
	 */
	public function index_names() {
		return [];
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
		$this->query("grant all on $currentDatabase.* to $currentUser@localhost identified by '$currentPassword'");
		$this->disconnect();

		$this->user = $currentUser;
		$this->password = $currentPassword;
		$this->database = $currentDatabase;
		$this->connect();
		/*return $return; */
	}

}
