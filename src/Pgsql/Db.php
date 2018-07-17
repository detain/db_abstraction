<?php
/**
 * PostgreSQL Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2018
 * @package MyAdmin
 * @category SQL
 */

namespace MyDb\Pgsql;

use \MyDb\Generic;
use \MyDb\Db_Interface;

/**
 * Db
 *
 * @access public
 */
class Db extends \MyDb\Generic implements \MyDb\Db_Interface
{
	/* public: this is an api revision, not a CVS revision. */
	public $type = 'pgsql';

	/* Set this to 1 for automatic pg_freeresult on last record. */
	public $autoFree = 0;

	// PostgreSQL changed somethings from 6.x -> 7.x
	public $db_version;

	public $port = '5432';

	/**
	 * Db::ifadd()
	 *
	 * @param mixed $add
	 * @param mixed $me
	 * @return string
	 */
	public function ifadd($add, $me) {
		if ('' != $add)
			return ' '.$me . $add;
		return '';
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
		$this->query("\\c {$database}", __LINE__, __FILE__);
	}

	/* public: some trivial reporting */

	/**
	 * Db::link_id()
	 * @return int
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

	/**
	 * Db::connect()
	 * @return void
	 */
	public function connect() {
		if (0 == $this->linkId) {
			$connect_string = 'dbname='.$this->database . $this->ifadd($this->host, 'host=') . $this->ifadd($this->port, 'port=') . $this->ifadd($this->user, 'user=') . $this->ifadd("'" . $this->password . "'", 'password=');
			$this->linkId = pg_pconnect($connect_string);

			if (!$this->linkId) {
				$this->halt('Link-ID == FALSE, '.($GLOBALS['phpgw_info']['server']['db_persistent'] ? 'p' : '').'connect failed');
			} else {
				$this->query('select version()', __LINE__, __FILE__);
				$this->next_record();

				$version = $this->f('version');
				$parts = explode(' ', $version);
				$this->db_version = $parts[1];
			}
		}
	}

	// For PostgreSQL 6.x

/**
	 * Db::toTimestamp6()
	 * @param mixed $epoch
	 * @return void
	 */
	public function toTimestamp6($epoch) {

	}

	// For PostgreSQL 6.x

/**
	 * Db::fromTimestamp6()
	 * @param mixed $timestamp
	 * @return void
	 */
	public function fromTimestamp6($timestamp) {

	}

	// For PostgreSQL 7.x

/**
	 * Db::toTimestamp7()
	 * @param mixed $epoch
	 * @return bool|string
	 */
	public function toTimestamp7($epoch) {
		// This needs the GMT offset!
		return date('Y-m-d H:i:s-00', $epoch);
	}

	// For PostgreSQL 7.x

/**
	 * Db::fromTimestamp7()
	 * @param mixed $timestamp
	 * @return int
	 */
	public function fromTimestamp7($timestamp) {
		preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $timestamp, $parts);

		return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
	}

	/* This only affects systems not using persistent connections */

	/**
	 * Db::disconnect()
	 * @return bool
	 */
	public function disconnect() {
		return @pg_close($this->linkId);
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
		if (!$line && !$file) {
			if (isset($GLOBALS['tf']))
				$GLOBALS['tf']->warning(__LINE__, __FILE__, "Lazy developer didn't pass __LINE__ and __FILE__ to db->query() - Actually query: $queryString");
		}

		/* No empty queries, please, since PHP4 chokes on them. */
		/* The empty query string is passed on from the constructor,
		* when calling the class without a query, e.g. in situations
		* like these: '$db = new db_Subclass;'
		*/
		if ($queryString == '')
			return 0;

		$this->connect();

		/* printf("<br>Debug: query = %s<br>\n", $queryString); */

		$this->queryId = @pg_exec($this->linkId, $queryString);
		$this->Row = 0;

		$this->Error = pg_errormessage($this->linkId);
		$this->Errno = ($this->Error == '') ? 0 : 1;
		if (!$this->queryId)
			$this->halt('Invalid SQL: '.$queryString, $line, $file);

		return $this->queryId;
	}

	/* public: perform a query with limited result set */

	/**
	 * Db::limit_query()
	 * @param mixed  $queryString
	 * @param mixed  $offset
	 * @param string $line
	 * @param string $file
	 * @param string $numRows
	 * @return mixed
	 */
	public function limit_query($queryString, $offset, $line = '', $file = '', $numRows = '') {
		if ($offset == 0) {
			$queryString .= ' LIMIT '.$numRows;
		} else {
			$queryString .= ' LIMIT '.$numRows.','.$offset;
		}

		if ($this->Debug)
			printf("Debug: limit_query = %s<br>offset=%d, num_rows=%d<br>\n", $queryString, $offset, $numRows);

		return $this->query($queryString, $line, $file);
	}

	// public: discard the query result

/**
	 * Db::free()
	 *
	 * @return void
	 */
	public function free() {
		@pg_freeresult($this->queryId);
		$this->queryId = 0;
	}

	/**
	 * Db::next_record()
	 * @param mixed $resultType
	 * @return bool
	 */
	public function next_record($resultType = PGSQL_BOTH) {
		$this->Record = @pg_fetch_array($this->queryId, $this->Row++, $resultType);

		$this->Error = pg_errormessage($this->linkId);
		$this->Errno = ($this->Error == '') ? 0 : 1;

		$stat = is_array($this->Record);
		if (!$stat && $this->autoFree) {
			pg_freeresult($this->queryId);
			$this->queryId = 0;
		}
		return $stat;
	}

	/**
	 * Db::seek()
	 *
	 * @param mixed $pos
	 * @return void
	 */
	public function seek($pos) {
		$this->Row = $pos;
	}

	/**
	 * Db::transaction_begin()
	 *
	 * @return mixed
	 */
	public function transaction_begin() {
		return $this->query('begin');
	}

	/**
	 * Db::transaction_commit()
	 * @return bool|mixed
	 */
	public function transaction_commit() {
		if (!$this->Errno) {
			return pg_exec($this->linkId, 'commit');
		} else {
			return FALSE;
		}
	}

	/**
	 * Db::transaction_abort()
	 * @return mixed
	 */
	public function transaction_abort() {
		return pg_exec($this->linkId, 'rollback');
	}

	/**
	 * Db::getLastInsertId()
	 * @param mixed $table
	 * @param mixed $field
	 * @return int
	 */
	public function getLastInsertId($table, $field) {
		/* This will get the last insert ID created on the current connection.  Should only be called
		* after an insert query is run on a table that has an auto incrementing field.  Of note, table
		* and field are required because pgsql returns the last inserted OID, which is unique across
		* an entire installation.  These params allow us to retrieve the sequenced field without adding
		* conditional code to the apps.
		*/
		if (!isset($table) || $table == '' || !isset($field) || $field == '')
			return - 1;

		$oid = pg_getlastoid($this->queryId);
		if ($oid == -1)
			return - 1;

		$result = @pg_exec($this->linkId, "select $field from $table where oid=$oid");
		if (!$result)
			return - 1;

		$Record = @pg_fetch_array($result, 0);
		@pg_freeresult($result);
		if (!is_array($Record)) /* OID not found? */
		{
			return - 1;
		}

		return $Record[0];
	}

	/**
	 * Db::lock()
	 * @param mixed  $table
	 * @param string $mode
	 * @return int|mixed
	 */
	public function lock($table, $mode = 'write') {
		$result = $this->transaction_begin();

		if ($mode == 'write') {
			if (is_array($table)) {
				while ($t = each($table))
					$result = pg_exec($this->linkId, 'lock table '.$t[1].' in share mode');
			} else {
				$result = pg_exec($this->linkId, 'lock table '.$table.' in share mode');
			}
		} else {
			$result = 1;
		}

		return $result;
	}

	/**
	 * Db::unlock()
	 * @return bool|mixed
	 */
	public function unlock() {
		return $this->transaction_commit();
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
			$id = @pg_exec($this->linkId, $q);
			$res = @pg_fetch_array($id, 0);

			/* No current value, make one */
			if (!is_array($res)) {
				$currentid = 0;
				$q = sprintf("insert into %s values('%s', %s)", $this->seqTable, $seqName, $currentid);
				$id = @pg_exec($this->linkId, $q);
			} else {
				$currentid = $res['nextid'];
			}
			$nextid = $currentid + 1;
			$q = sprintf("update %s set nextid = '%s' where seq_name = '%s'", $this->seqTable, $nextid, $seqName);
			$id = @pg_exec($this->linkId, $q);
			$this->unlock();
		} else {
			$this->halt('cannot lock '.$this->seqTable.' - has it been created?');
			return 0;
		}
		return $nextid;
	}

	/**
	 * Db::affected_rows()
	 * @return void
	 */
	public function affected_rows() {
		return pg_cmdtuples($this->queryId);
	}

	/**
	 * Db::num_rows()
	 * @return int
	 */
	public function num_rows() {
		return pg_numrows($this->queryId);
	}

	/**
	 * Db::num_fields()
	 * @return int
	 */
	public function num_fields() {
		return pg_numfields($this->queryId);
	}

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

	/**
	 * Db::halt()
	 *
	 * @param mixed  $msg
	 * @param string $line
	 * @param string $file
	 * @return void
	 */
	public function halt($msg, $line = '', $file = '') {
		if ($this->haltOnError == 'no')
			return;

		/* Just in case there is a table currently locked */
		$this->transaction_abort();

		$s = sprintf("Database error: %s\n", $msg);
		$s .= sprintf("PostgreSQL Error: %s\n\n (%s)\n\n", $this->Errno, $this->Error);

		if ($file)
			$s .= sprintf("File: %s\n", $file);
		if ($line)
			$s .= sprintf("Line: %s\n", $line);
		if ($this->haltOnError == 'yes')
			$s .= '<p><b>Session halted.</b>';
		error_log($s);
		echo $s;
		if (isset($GLOBALS['tf']))
			$GLOBALS['tf']->terminate();
		die($s);
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
		$this->query("select relname from pg_class where relkind = 'r' and not relname like 'pg_%'");
		$i = 0;
		while ($this->next_record()) {
			$return[$i]['table_name'] = $this->f(0);
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
		$return = [];
		$this->query("SELECT relname FROM pg_class WHERE NOT relname ~ 'pg_.*' AND relkind ='i' ORDER BY relname");
		$i = 0;
		while ($this->next_record()) {
			$return[$i]['index_name'] = $this->f(0);
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
		}

		if (!$this->host) {
			system('createdb '.$currentDatabase, $outval);
		} else {
			system('createdb -h '.$this->host.' '.$currentDatabase, $outval);
		}

		if ($outval != 0) {
			/* either the rights r not available or the postmaster is not running .... */
			echo 'database creation failure <BR>';
			echo 'please setup the postreSQL database manually<BR>';
		}

		$this->user = $currentUser;
		$this->password = $currentPassword;
		$this->database = $currentDatabase;
		$this->connect();
	}

}
