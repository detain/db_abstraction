<?php
/**
 * ADOdb SQL Related Functionality
 * @author Joe Huss <detain@interserver.net>
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
class Db extends Generic implements Db_Interface
{
	public $driver = 'mysql';
	public $Rows = [];
	public $type = 'adodb';

	/**
	 * Db::connect()
	 * @param string $database
	 * @param string $host
	 * @param string $user
	 * @param string $password
	 * @param string $driver
	 * @return bool|\the
	 */
	public function connect($database = '', $host = '', $user = '', $password = '', $driver = 'mysql')
	{
		/* Handle defaults */
		if ('' == $database) {
			$database = $this->database;
		}
		if ('' == $host) {
			$host = $this->host;
		}
		if ('' == $user) {
			$user = $this->user;
		}
		if ('' == $password) {
			$password = $this->password;
		}
		if ('' == $driver) {
			$driver = $this->driver;
		}
		/* establish connection, select database */
		if ($this->linkId === false) {
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
	public function disconnect()
	{
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function real_escape($string = '')
	{
		return escapeshellarg($string);
	}

	/**
	 * discard the query result
	 * @return void
	 */
	public function free()
	{
		//			@mysql_free_result($this->queryId);
		//			$this->queryId = 0;
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
	public function queryReturn($query, $line = '', $file = '')
	{
		$this->query($query, $line, $file);
		if ($this->num_rows() == 0) {
			return false;
		} elseif ($this->num_rows() == 1) {
			$this->next_record(MYSQL_ASSOC);
			return $this->Record;
		} else {
			$out = [];
			while ($this->next_record(MYSQL_ASSOC)) {
				$out[] = $this->Record;
			}
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
	public function qr($query, $line = '', $file = '')
	{
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
	public function query($queryString, $line = '', $file = '')
	{
		/* No empty queries, please, since PHP4 chokes on them. */
		/* The empty query string is passed on from the constructor,
		* when calling the class without a query, e.g. in situations
		* like these: '$db = new db_Subclass;'
		*/
		if ($queryString == '') {
			return 0;
		}
		if (!$this->connect()) {
			return 0;
			/* we already complained in connect() about that. */
		}

		// New query, discard previous result.
		if ($this->queryId !== false) {
			$this->free();
		}

		if ($this->Debug) {
			printf("Debug: query = %s<br>\n", $queryString);
		}
		if ($GLOBALS['log_queries'] !== false) {
			$this->log($queryString, $line, $file);
		}

		try {
			$this->queryId = $this->linkId->Execute($queryString);
		} catch (exception $e) {
			$this->emailError($queryString, $e, $line, $file);
		}
		$this->log("ADOdb Query $queryString (S:$success) - ".count($this->Rows).' Rows', __LINE__, __FILE__);
		$this->Row = 0;

		// Will return nada if it fails. That's fine.
		return $this->queryId;
	}

	/**
	 * Db::next_record()
	 * @param mixed $resultType
	 * @return bool
	 */
	public function next_record($resultType = MYSQL_ASSOC)
	{
		if (!$this->queryId) {
			$this->halt('next_record called with no query pending.');
			return 0;
		}
		++$this->Row;
		$this->Record = $this->queryId->FetchRow();
		$stat = is_array($this->Record);
		if (!$stat && $this->autoFree) {
			$this->free();
		}
		return $stat;
	}

	/* public: position in result set */

	/**
	 * Db::seek()
	 * @param integer $pos
	 * @return int
	 */
	public function seek($pos = 0)
	{
		if (isset($this->Rows[$pos])) {
			$this->Row = $pos;
		} else {
			$this->halt("seek($pos) failed: result has ".count($this->Rows).' rows');
			/* half assed attempt to save the day,
			* but do not consider this documented or even
			* desirable behaviour.
			*/
			return 0;
		}
		return 1;
	}

	/**
	 * Db::transactionBegin()
	 * @return bool
	 */
	public function transactionBegin()
	{
		return true;
	}

	/**
	 * Db::transactionCommit()
	 * @return bool
	 */
	public function transactionCommit()
	{
		return true;
	}

	/**
	 * Db::transactionAbort()
	 * @return bool
	 */
	public function transactionAbort()
	{
		return true;
	}

	/**
	 * Db::getLastInsertId()
	 *
	 * @param mixed $table
	 * @param mixed $field
	 * @return mixed
	 */
	public function getLastInsertId($table, $field)
	{
		return $this->linkId->Insert_ID($table, $field);
	}

	/* public: table locking */

	/**
	 * Db::lock()
	 * @param mixed  $table
	 * @param string $mode
	 * @return void
	 */
	public function lock($table, $mode = 'write')
	{
		/*			$this->connect();

		* $query = "lock tables ";
		* if (is_array($table))
		* {
		* foreach ($table as $key => $value)
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
	public function unlock()
	{
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
	 * Db::affectedRows()
	 *
	 * @return mixed
	 */
	public function affectedRows()
	{
		return @$this->linkId->Affected_Rows();
		//			return @$this->queryId->rowCount();
	}

	/**
	 * Db::num_rows()
	 *
	 * @return mixed
	 */
	public function num_rows()
	{
		return $this->queryId->NumRows();
	}

	/**
	 * Db::num_fields()
	 *
	 * @return mixed
	 */
	public function num_fields()
	{
		return $this->queryId->NumCols();
	}

	/**
	 * @param mixed $msg
	 * @param string $line
	 * @param string $file
	 * @return mixed|void
	 */
	public function haltmsg($msg, $line = '', $file = '')
	{
		$this->log("Database error: $msg", $line, $file, 'error');
		if ($this->linkId->ErrorNo() != '0' && $this->linkId->ErrorMsg() != '') {
			$this->log('ADOdb SQL Error: '.$this->linkId->ErrorMsg(), $line, $file, 'error');
		}
		$this->logBackTrace($msg, $line, $file);
	}

	/**
	 * Db::tableNames()
	 *
	 * @return array
	 */
	public function tableNames()
	{
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
}
