<?php
/**
 * MySQL Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @version $Revision$
 * @copyright 2018
 * @package MyAdmin
 * @category SQL
 */

namespace MyDb\Adodb;

use \MyDb\Generic;
use \MyDb\Db_Interface;

/**
 * Db
 *
 * @access public
 */
class Db extends \MyDb\Generic implements \MyDb\Db_Interface
{
	/* public: connection parameters */
	public $driver = 'mysql';
	public $autoFree = 0; // Set to 1 for automatic mysql_free_result()
	public $maxMatches = 1000000;
	public $Rows = [];
	/* public: this is an api revision, not a CVS revision. */
	public $type = 'adodb';

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
		if (!defined('_ADODB_LAYER'))
			require_once realpath(__DIR__).'/../vendor/adodb/adodb-php/adodb.inc.php';
		$this->database = $database;
		$this->user = $user;
		$this->password = $password;
		$this->host = $host;
		if ($query != '')
			$this->query($query);
	}

	/**
	 * @param        $message
	 * @param string $line
	 * @param string $file
	 * @return mixed|void
	 */
	public function log($message, $line = '', $file = '') {
		if (function_exists('myadmin_log'))
			myadmin_log('db', 'info', $message, $line, $file, FALSE);
		else
			error_log($message);
	}

	/* public: some trivial reporting */

	/**
	 * Db::link_id()
	 * @return bool
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

	/**
	 * Db::connect()
	 * @param string $database
	 * @param string $host
	 * @param string $user
	 * @param string $password
	 * @param string $driver
	 * @return bool|\the
	 */
	public function connect($database = '', $host = '', $user = '', $password = '', $driver = 'mysql') {
		/* Handle defaults */
		if ('' == $database)
			$database = $this->database;
		if ('' == $host)
			$host = $this->host;
		if ('' == $user)
			$user = $this->user;
		if ('' == $password)
			$password = $this->password;
		if ('' == $driver)
			$driver = $this->driver;
		/* establish connection, select database */
		if ($this->linkId === FALSE) {
			$this->linkId = NewADOConnection($driver);
			$this->linkId->Connect($host, $user, $password, $database);
		}
		return $this->linkId;
	}

	/* This only affects systems not using persistent connections */

	/**
	 * Db::disconnect()
	 * @return void
	 */
	public function disconnect() {
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function real_escape($string) {
		return escapeshellarg($string);
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function escape($string) {
		return escapeshellarg($string);
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
		//			@mysql_free_result($this->queryId);
		//			$this->queryId = 0;
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

		// New query, discard previous result.
		if ($this->queryId !== FALSE)
			$this->free();

		if ($this->Debug)
			printf("Debug: query = %s<br>\n", $queryString);
		if ($GLOBALS['log_queries'] !== FALSE) {
			$this->log($queryString, $line, $file);

		}

		try
		{
			$this->queryId = $this->linkId->Execute($queryString);
		}
		catch (exception $e) {
			$email = "MySQL Error<br>\n".'Query: '.$queryString . "<br>\n".'Error #'.print_r($e, TRUE) . "<br>\n".'Line: '.$line . "<br>\n".'File: '.$file . "<br>\n" . (isset($GLOBALS['tf']) ?
					'User: '.$GLOBALS['tf']->session->account_id . "<br>\n" : '');

			$email .= '<br><br>Request Variables:<br>';
			foreach ($_REQUEST as $key => $value)
				$email .= $key.': '.$value . "<br>\n";

			$email .= '<br><br>Server Variables:<br>';
			foreach ($_SERVER as $key => $value)
				$email .= $key.': '.$value . "<br>\n";
			$subject = DOMAIN.' ADOdb MySQL Error On '.TITLE;
			$headers = '';
			$headers .= 'MIME-Version: 1.0'.EMAIL_NEWLINE;
			$headers .= 'Content-type: text/html; charset=UTF-8'.EMAIL_NEWLINE;
			$headers .= 'From: '.TITLE.' <'.EMAIL_FROM.'>'.EMAIL_NEWLINE;
			//				$headers .= "To: \"John Quaglieri\" <john@interserver.net>" . EMAIL_NEWLINE;

			$headers .= 'X-Mailer: Trouble-Free.Net Admin Center'.EMAIL_NEWLINE;
			admin_mail($subject, $email, $headers, FALSE, 'admin/sql_error.tpl');
			$this->halt('Invalid SQL: '.$queryString, $line, $file);
		}
		$this->log("ADOdb Query $queryString (S:$success) - " . count($this->Rows).' Rows', __LINE__, __FILE__);
		$this->Row = 0;

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
	 * @param mixed $resultType
	 * @return bool
	 */
	public function next_record($resultType = MYSQL_ASSOC) {
		if (!$this->queryId) {
			$this->halt('next_record called with no query pending.');
			return 0;
		}
		++$this->Row;
		$this->Record = $this->queryId->FetchRow();
		$stat = is_array($this->Record);
		if (!$stat && $this->autoFree)
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
		if (isset($this->Rows[$pos])) {
			$this->Row = $pos;
		} else {
			$this->halt("seek($pos) failed: result has " . count($this->Rows).' rows');
			/* half assed attempt to save the day,
			* but do not consider this documented or even
			* desirable behaviour.
			*/
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
	 *
	 * @param mixed $table
	 * @param mixed $field
	 * @return mixed
	 */
	public function getLastInsertId($table, $field) {
		return $this->linkId->Insert_ID($table, $field);
	}

	/* public: table locking */

	/**
	 * Db::lock()
	 * @param mixed  $table
	 * @param string $mode
	 * @return void
	 */
	public function lock($table, $mode = 'write') {
		/*			$this->connect();

		* $query = "lock tables ";
		* if (is_array($table))
		* {
		* while (list($key,$value)=each($table))
		* {
		* if ($key == "read" && $key!=0)
		* {
		* $query .= "$value read, ";
		* }
		* else
		* {
		* $query .= "$value $mode, ";
		* }
		* }
		* $query = mb_substr($query,0,-2);
		* }
		* else
		* {
		* $query .= "$table $mode";
		* }
		* $res = @mysql_query($query, $this->linkId);
		* if (!$res)
		* {
		* $this->halt("lock($table, $mode) failed.");
		* return 0;
		* }
		* return $res;
		*/
	}

	/**
	 * Db::unlock()
	 * @return void
	 */
	public function unlock() {
		/*			$this->connect();

		* $res = @mysql_query("unlock tables");
		* if (!$res)
		* {
		* $this->halt("unlock() failed.");
		* return 0;
		* }
		* return $res;
		*/
	}

	/* public: evaluate the result (size, width) */

	/**
	 * Db::affected_rows()
	 *
	 * @return mixed
	 */
	public function affected_rows() {
		return @$this->linkId->Affected_Rows();
		//			return @$this->queryId->rowCount();
	}

	/**
	 * Db::num_rows()
	 *
	 * @return mixed
	 */
	public function num_rows() {
		return $this->queryId->NumRows();
	}

	/**
	 * Db::num_fields()
	 *
	 * @return mixed
	 */
	public function num_fields() {
		return $this->queryId->NumCols();
	}

	/* public: shorthand notation */

	/**
	 * Db::nf()
	 *
	 * @return mixed
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
	 * @param mixed $Name
	 * @return void
	 */
	public function p($Name) {
		print $this->Record[$Name];
	}

	/* public: sequence numbers */

	/**
	 * Db::nextid()
	 * @param mixed $seqName
	 * @return int
	 */
	public function nextid($seqName) {
		$this->connect();

		if ($this->lock($this->seqTable)) {
			/* get sequence number (locked) and increment */
			$q = sprintf("select nextid from %s where seq_name = '%s'", $this->seqTable, $seqName);
			$id = @mysql_query($q, $this->linkId);
			$res = @mysql_fetch_array($id);

			/* No current value, make one */
			if (!is_array($res)) {
				$currentid = 0;
				$q = sprintf("insert into %s values('%s', %s)", $this->seqTable, $seqName, $currentid);
				$id = @mysql_query($q, $this->linkId);
			} else {
				$currentid = $res['nextid'];
			}
			$nextid = $currentid + 1;
			$q = sprintf("update %s set nextid = '%s' where seq_name = '%s'", $this->seqTable, $nextid, $seqName);
			$id = @mysql_query($q, $this->linkId);
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

		//$this->Error = @mysql_error($this->linkId);
		//$this->Errno = @mysql_errno($this->linkId);
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
	 * @param mixed $msg
	 * @return void
	 */
	public function haltmsg($msg) {
		$this->log("Database error: $msg", __LINE__, __FILE__);
		if ($this->linkId->ErrorNo() != '0' && $this->linkId->ErrorMsg() != '')
			$this->log('ADOdb MySQL Error: '.$this->linkId->ErrorMsg(), __LINE__, __FILE__);
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
		while ($info = $this->queryId->FetchRow()) {
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
		$this->query("grant all on $currentDatabase.* to $currentUser@localhost identified by '{$currentPassword}'");
		$this->disconnect();

		$this->user = $currentUser;
		$this->password = $currentPassword;
		$this->database = $currentDatabase;
		$this->connect();
		/*return $return; */
	}

}
