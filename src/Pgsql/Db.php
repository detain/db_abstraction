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
class Db extends Generic implements Db_Interface
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
	 * Db::transactionBegin()
	 *
	 * @return mixed
	 */
	public function transactionBegin() {
		return $this->query('begin');
	}

	/**
	 * Db::transactionCommit()
	 * @return bool|mixed
	 */
	public function transactionCommit() {
		if (!$this->Errno) {
			return pg_exec($this->linkId, 'commit');
		} else {
			return FALSE;
		}
	}

	/**
	 * Db::transactionAbort()
	 * @return mixed
	 */
	public function transactionAbort() {
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
		$result = $this->transactionBegin();

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
		return $this->transactionCommit();
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
	 * Db::tableNames()
	 *
	 * @return array
	 */
	public function tableNames() {
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
	 * Db::indexNames()
	 *
	 * @return array
	 */
	public function indexNames() {
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
