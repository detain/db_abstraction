<?php
/**
 * MySQL Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2017
 * @package MyAdmin
 * @category SQL
 */

namespace MyDb\Pdo;

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
	public $Driver = 'mysql';

	/* public: configuration parameters */
	public $Auto_Free = 0; // Set to 1 for automatic mysql_free_result()
	public $Rows = [];

	/* public: this is an api revision, not a CVS revision. */
	public $type = 'pdo';

	public $max_matches = 10000000;

	/**
	 * Constructs the db handler, can optionally specify connection parameters
	 *
	 * @param string $Database Optional The database name
	 * @param string $User Optional The username to connect with
	 * @param string $Password Optional The password to use
	 * @param string $Host Optional The hostname where the server is, or default to localhost
	 * @param string $query Optional query to perform immediately
	 */
	public function __construct($Database = '', $User = '', $Password = '', $Host = 'localhost', $query = '') {
		$this->Database = $Database;
		$this->User = $User;
		$this->Password = $Password;
		$this->Host = $Host;
		if ($query != '') {
			$this->query($query);
		}
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
		$DSN = "{$this->Driver}:dbname={$database};host={$this->Host}";
		if ($this->character_set != '')
			$DSN .= ';charset='.$this->character_set;
		$this->Link_ID = new \PDO($DSN, $this->User, $this->Password);
	}

	/* public: some trivial reporting */

	/**
	 * Db::link_id()
	 * @return bool
	 */
	public function link_id() {
		return $this->Link_ID;
	}

	/**
	 * Db::query_id()
	 * @return int
	 */
	public function query_id() {
		return $this->Query_ID;
	}

	/* public: connection management */

	/**
	 * Db::connect()
	 * @param string $Database
	 * @param string $Host
	 * @param string $User
	 * @param string $Password
	 * @param string $Driver
	 * @return bool|int|\PDO
	 */
	public function connect($Database = '', $Host = '', $User = '', $Password = '', $Driver = 'mysql') {
		/* Handle defaults */
		if ('' == $Database) {
			$Database = $this->Database;
		}
		if ('' == $Host) {
			$Host = $this->Host;
		}
		if ('' == $User) {
			$User = $this->User;
		}
		if ('' == $Password) {
			$Password = $this->Password;
		}
		if ('' == $Driver) {
			$Driver = $this->Driver;
		}
		/* establish connection, select database */
		$DSN = "$Driver:dbname=$Database;host=$Host";
		if ($this->character_set != '')
			$DSN .= ';charset='.$this->character_set;
		if ($this->Link_ID === FALSE) {
			try
			{
				$this->Link_ID = new \PDO($DSN, $User, $Password);
			}
			catch (\PDOException $e) {
				$this->halt('Connection Failed '.$e->getMessage());
				return 0;
			}
		}
		return $this->Link_ID;
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
		return mysql_escape_string($string);
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function escape($string) {
		return mysql_escape_string($string);
	}

	/* public: discard the query result */

	/**
	 * Db::free()
	 * @return void
	 */
	public function free() {
		//			@mysql_free_result($this->Query_ID);
		//			$this->Query_ID = 0;
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
			while ($this->next_record(MYSQL_ASSOC)) {
				$out[] = $this->Record;
			}
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
	 * @param mixed $Query_String
	 * @param string $line
	 * @param string $file
	 * @return mixed 0 if no query or query id handler, safe to ignore this return
	 */
	public function query($Query_String, $line = '', $file = '') {
		/* No empty queries, please, since PHP4 chokes on them. */
		/* The empty query string is passed on from the constructor,
		* when calling the class without a query, e.g. in situations
		* like these: '$db = new db_Subclass;'
		*/
		if ($Query_String == '') {
			return 0;
		}
		if (!$this->connect()) {
			return 0;
			/* we already complained in connect() about that. */
		}
		// New query, discard previous result.
		if ($this->Query_ID !== FALSE) {
			$this->free();
		}

		if ($this->Debug) {
			printf("Debug: query = %s<br>\n", $Query_String);
		}
		if ($GLOBALS['log_queries'] !== FALSE) {
			$this->log($Query_String, $line, $file);
		}

		$this->Query_ID = $this->Link_ID->prepare($Query_String);
		$success = $this->Query_ID->execute();
		$this->Rows = $this->Query_ID->fetchAll();
		$this->log("PDO Query $Query_String (S:$success) - " . count($this->Rows).' Rows', __LINE__, __FILE__);
		$this->Row = 0;
		if ($success === FALSE) {
			$email = "MySQL Error<br>\n".'Query: '.$Query_String . "<br>\n".'Error #'.print_r($this->Query_ID->errorInfo(), TRUE) . "<br>\n".'Line: '.$line . "<br>\n".'File: '.$file . "<br>\n" . (isset($GLOBALS['tf']) ? 'User: '.$GLOBALS['tf']->session->account_id . "<br>\n" : '');

			$email .= '<br><br>Request Variables:<br>';
			foreach ($_REQUEST as $key => $value) {
				$email .= $key.': '.$value . "<br>\n";
			}

			$email .= '<br><br>Server Variables:<br>';
			foreach ($_SERVER as $key => $value) {
				$email .= $key.': '.$value . "<br>\n";
			}
			$subject = DOMAIN.' PDO MySQL Error On '.TITLE;
			$headers = '';
			$headers .= 'MIME-Version: 1.0'.EMAIL_NEWLINE;
			$headers .= 'Content-type: text/html; charset=UTF-8'.EMAIL_NEWLINE;
			$headers .= 'From: '.TITLE.' <'.EMAIL_FROM.'>'.EMAIL_NEWLINE;
			//				$headers .= "To: \"John Quaglieri\" <john@interserver.net>" . EMAIL_NEWLINE;

			$headers .= 'X-Mailer: Trouble-Free.Net Admin Center'.EMAIL_NEWLINE;
			admin_mail($subject, $email, $headers, FALSE, 'admin_email_sql_error.tpl');
			$this->halt('Invalid SQL: '.$Query_String, $line, $file);
		}

		// Will return nada if it fails. That's fine.
		return $this->Query_ID;
	}

	// public: perform a query with limited result set

/**
	 * Db::limit_query()
	 * @param mixed  $Query_String
	 * @param mixed  $offset
	 * @param string $line
	 * @param string $file
	 * @param string|int $num_rows
	 * @return mixed
	 */
	public function limit_query($Query_String, $offset, $line = '', $file = '', $num_rows = '') {
		if (!$num_rows) {
			$num_rows = $this->max_matches;
		}

		if ($offset == 0) {
			$Query_String .= ' LIMIT '.$num_rows;
		} else {
			$Query_String .= ' LIMIT '.$offset.','.$num_rows;
		}

		if ($this->Debug) {
			printf("Debug: limit_query = %s<br>offset=%d, num_rows=%d<br>\n", $Query_String, $offset, $num_rows);
		}

		return $this->query($Query_String, $line, $file);
	}

	/* public: walk result set */

	/**
	 * Db::next_record()
	 * @param mixed $result_type
	 * @return bool
	 */
	public function next_record($result_type = MYSQL_ASSOC) {
		// PDO result types so far seem to be +1
		++$result_type;
		if (!$this->Query_ID) {
			$this->halt('next_record called with no query pending.');
			return 0;
		}

		++$this->Row;
		$this->Record = $this->Rows[$this->Row];

		$stat = is_array($this->Record);
		if (!$stat && $this->Auto_Free) {
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
	 * Db::get_last_insert_id()
	 * @param mixed $table
	 * @param mixed $field
	 * @return int
	 */
	public function get_last_insert_id($table, $field) {
		if (!isset($table) || $table == '' || !isset($field) || $field == '') {
			return - 1;
		}
		return $this->Link_ID->lastInsertId();
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
		* $res = @mysql_query($query, $this->Link_ID);
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
		return @$this->Query_ID->rowCount();
	}

	/**
	 * Db::num_rows()
	 * @return int
	 */
	public function num_rows() {
		return count($this->Rows);
	}

	/**
	 * Db::num_fields()
	 * @return int
	 */
	public function num_fields() {
		$keys = array_keys($this->Rows);
		return count($this->Rows[$keys[0]]);
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
	 * @param mixed  $Name
	 * @param string $strip_slashes
	 * @return string
	 */
	public function f($Name, $strip_slashes = '') {
		if ($strip_slashes || ($this->auto_stripslashes && !$strip_slashes)) {
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
	 * @param mixed $seq_name
	 * @return int
	 */
	public function nextid($seq_name) {
		$this->connect();

		if ($this->lock($this->Seq_Table)) {
			/* get sequence number (locked) and increment */
			$q = sprintf("select nextid from %s where seq_name = '%s'", $this->Seq_Table, $seq_name);
			$id = @mysql_query($q, $this->Link_ID);
			$res = @mysql_fetch_array($id);

			/* No current value, make one */
			if (!is_array($res)) {
				$currentid = 0;
				$q = sprintf("insert into %s values('%s', %s)", $this->Seq_Table, $seq_name, $currentid);
				$id = @mysql_query($q, $this->Link_ID);
			} else {
				$currentid = $res['nextid'];
			}
			$nextid = $currentid + 1;
			$q = sprintf("update %s set nextid = '%s' where seq_name = '%s'", $this->Seq_Table, $nextid, $seq_name);
			$id = @mysql_query($q, $this->Link_ID);
			$this->unlock();
		} else {
			$this->halt('cannot lock '.$this->Seq_Table.' - has it been created?');
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

		//$this->Error = @mysql_error($this->Link_ID);
		//$this->Errno = @mysql_errno($this->Link_ID);
		if ($this->Halt_On_Error == 'no') {
			return;
		}
		$this->haltmsg($msg);

		if ($file) {
			error_log("File: $file");
		}
		if ($line) {
			error_log("Line: $line");
		}

		if ($this->Halt_On_Error != 'report') {
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
	 * @return void
	 */
	public function haltmsg($msg) {
		$this->log("Database error: $msg", __LINE__, __FILE__);
		if ($this->Errno != '0' || $this->Error != '()') {
			$this->log('PDO MySQL Error: '.print_r($this->Link_ID->errorInfo(), TRUE), __LINE__, __FILE__);
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
		foreach ($this->Rows as $i => $info) {
			$return[$i]['table_name'] = $info[0];
			$return[$i]['tablespace_name'] = $this->Database;
			$return[$i]['database'] = $this->Database;
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
		$currentUser = $this->User;
		$currentPassword = $this->Password;
		$currentDatabase = $this->Database;

		if ($adminname != '') {
			$this->User = $adminname;
			$this->Password = $adminpasswd;
			$this->Database = 'mysql';
		}
		$this->disconnect();
		$this->query("CREATE DATABASE $currentDatabase");
		$this->query("grant all on $currentDatabase.* to $currentUser@localhost identified by '$currentPassword'");
		$this->disconnect();

		$this->User = $currentUser;
		$this->Password = $currentPassword;
		$this->Database = $currentDatabase;
		$this->connect();
		/*return $return; */
	}

}
