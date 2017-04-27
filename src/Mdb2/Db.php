<?php
	/**
	* MDB2 Wrapper Made To Handle Like Our Other Classes Related Functionality
	* Last Changed: $LastChangedDate$
	* @author detain
	* @version $Revision$
	* @copyright 2017
	* @package MyAdmin
	* @category SQL
	*/

namespace MyDb\Mdb2;

	/**
	 * Db
	 *
	 * @access public
	 */
	class Db implements \MyDb\Db_Interface
	{
		public $Host = 'localhost';
		public $User = 'pdns';
		public $Password = '';
		public $Database = 'pdns';
		public $type = 'mdb2';

		public $iface_lang = 'en_EN';

		public $dns_hostmaster = 'hostmaster.interserver.net';
		public $dns_ns1 = 'cdns1.interserver.net';
		public $dns_ns2 = 'cdns2.interserver.net';
		public $dns_ns3 = 'cdns3.interserver.net';

		public $character_set = 'utf8mb4';
		public $collation = 'utf8mb4_unicode_ci';

		/**
		 * @var bool
		 */
		public $auto_stripslashes = false;
		/**
		 * @var int
		 */
		public $Auto_Free = 1; // Set to 1 for automatic mysql_free_result()
		/**
		 * @var int
		 */
		public $Debug = 0; // Set to 1 for debugging messages.
		/**
		 * @var string
		 */
		public $Halt_On_Error = 'yes'; // "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)
		/**
		 * @var string
		 */
		public $Seq_Table = 'db_sequence';
		/**
		 * @var array result array and current row number
		 */
		public $Record = array();
		/**
		 * @var array
		 */
		public $Row;

		/* public: current error number and error text */
		public $Errno = 0;
		public $Error = '';
		public $error = false;
		public $message = '';

		/* private: link and query handles */
		/** @var mysqli **/
		public $Link_ID = 0;
		public $Query_ID = 0;

		public $max_connect_errors = 30;
		public $connection_attempt = 0;

		public $max_matches = 10000000;

		/**
		 * Db::quote()
		 * @param string $text
		 * @param string $type
		 * @return string
		 */
		public function quote($text = '', $type = 'text') {
			switch ($type) {
				case 'text':
					return "'" . mysqli_real_escape_string($this->Link_ID, $text) . "'";
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
			return $this->get_last_insert_id($table, $field);
		}

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
					myadmin_log('db', 'info', $message, $line, $file, false);
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
			mysqli_select_db($this->Link_ID, $database);
		}

		/* public: some trivial reporting */

		/**
		 * Db::link_id()
		 * @return int
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
		 * @return int|\mysqli
		 */
		public function connect($Database = '', $Host = '', $User = '', $Password = '') {
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
			/* establish connection, select database */
			if (!is_object($this->Link_ID)) {
				$this->connection_attempt++;
				if ($this->connection_attempt > 1)
					myadmin_log('db', 'info', "MySQLi Connection Attempt #{$this->connection_attempt}/{$this->max_connect_errors}", __LINE__, __FILE__);
				if ($this->connection_attempt >= $this->max_connect_errors) {
					$this->halt("connect($Host, $User, \$Password) failed. " . $mysqli->connect_error);
					return 0;
				}
				$this->Link_ID = mysqli_init();
				$this->Link_ID->options(MYSQLI_INIT_COMMAND, "SET NAMES {$this->character_set} COLLATE {$this->collation}, COLLATION_CONNECTION = {$this->collation}, COLLATION_DATABASE = {$this->collation}");
				$this->Link_ID->real_connect($Host, $User, $Password, $Database);
				$this->Link_ID->set_charset($this->character_set);
				if ($this->Link_ID->connect_errno) {
					$this->halt("connect($Host, $User, \$Password) failed. " . $mysqli->connect_error);
					return 0;
				}
			}
			return $this->Link_ID;
		}

		/* This only affects systems not using persistent connections */

		/**
		 * Db::disconnect()
		 * @return int
		 */
		public function disconnect() {
			if (is_object($this->Link_ID))
				return $this->Link_ID->close();
			else
				return 0;
		}

		/**
		 * @param $string
		 * @return string
		 */
		public function real_escape($string) {
			if ((!is_resource($this->Link_ID) || $this->Link_ID == 0) && !$this->connect()) {
				return mysqli_escape_string($string);
			}
			return mysqli_real_escape_string($this->Link_ID, $string);
		}

		/**
		 * @param $string
		 * @return string
		 */
		public function escape($string) {
			return mysql_escape_string($string);
		}

		/**
		 * Db::db_addslashes()
		 * @param mixed $str
		 * @return string
		 */
		public function db_addslashes($str) {
			if (!isset($str) || $str == '') {
				return '';
			}

			return addslashes($str);
		}

		/**
		 * Db::to_timestamp()
		 * @param mixed $epoch
		 * @return bool|string
		 */
		public function to_timestamp($epoch) {
			return date('YmdHis', $epoch);
		}

		/**
		 * Db::from_timestamp()
		 * @param mixed $timestamp
		 * @return bool|int|mixed
		 */
		public function from_timestamp($timestamp) {
			if (preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $timestamp, $parts))
				return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
			elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
				return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
			elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
				return mktime(1, 1, 1, $parts[2], $parts[3], $parts[1]);
			elseif (is_numeric($timestamp) && $timestamp >= 943938000)
				return $timestamp;
			else {
				$this->log('Cannot Match Timestamp from ' . $timestamp, __LINE__, __FILE__);
				return false;
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
				$s = 'limit ' . $this->max_matches;
			} else {
				$s = "limit $start," . $this->max_matches;
			}
			return $s;
		}

		/* public: discard the query result */

		/**
		 * Db::free()
		 * @return void
		 */
		public function free() {
			if (is_resource($this->Query_ID))
				@mysqli_free_result($this->Query_ID);
			$this->Query_ID = 0;
		}

		/**
		 * Db::query_return()
		 *
		 * Sends an SQL query to the server like the normal query() command but iterates through
		 * any rows and returns the row or rows immediately or false on error
		 *
		 * @param mixed $query SQL Query to be used
		 * @param string $line optionally pass __LINE__ calling the query for logging
		 * @param string $file optionally pass __FILE__ calling the query for logging
		 * @return mixed false if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
		 */
		public function query_return($query, $line = '', $file = '') {
			$this->query($query, $line, $file);
			if ($this->num_rows() == 0) {
				return false;
			} elseif ($this->num_rows() == 1) {
				$this->next_record(MYSQL_ASSOC);
				return $this->Record;
			} else {
				$out = array();
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
		 * @return mixed false if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
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
			$halt_prev = $this->Halt_On_Error;
			$this->Halt_On_Error = 'no';
			// New query, discard previous result.
			if (is_resource($this->Query_ID)) {
				$this->free();
			}
			if ($this->Debug) {
				printf("Debug: query = %s<br>\n", $Query_String);
			}
			if (isset($GLOBALS['log_queries']) && $GLOBALS['log_queries'] !== false) {
				$this->log($Query_String, $line, $file);
			}
			$tries = 3;
			$try = 1;
			$this->Query_ID = @mysqli_query($this->Link_ID, $Query_String, MYSQLI_STORE_RESULT);
			$this->Row = 0;
			$this->Errno = @mysqli_errno($this->Link_ID);
			$this->Error = @mysqli_error($this->Link_ID);
			while ($this->Query_ID === false && $try <= $tries) {
				$this->message = 'MySQL error ' . @mysqli_errno($this->Link_ID) . ': ' . @mysqli_error($this->Link_ID) . ' Query: ' . $this->query;
				$this->error = true;
				@mysqli_close($this->Link_ID);
				$this->connect();
				$try++;
			}
			$this->Halt_On_Error = $halt_prev;
			if ($this->Query_ID === false) {
				$email = "MySQLi Error<br>\n" . 'Query: ' . $Query_String . "<br>\n" . 'Error #' . $this->Errno . ': ' . $this->Error . "<br>\n" . 'Line: ' . $line . "<br>\n" . 'File: ' . $file . "<br>\n" . (isset($GLOBALS['tf']) ?
						'User: ' . $GLOBALS['tf']->session->account_id . "<br>\n" : '');

				$email .= '<br><br>Request Variables:<br>' . print_r($_REQUEST, true);
				$email .= '<br><br>Server Variables:<br>' . print_r($_SERVER, true);
				$subject = DOMAIN . ' MySQLi Error On ' . TITLE;
				$headers = '';
				$headers .= 'MIME-Version: 1.0' . EMAIL_NEWLINE;
				$headers .= 'Content-type: text/html; charset=UTF-8' . EMAIL_NEWLINE;
				$headers .= 'From: ' . TITLE . ' <' . EMAIL_FROM . '>' . EMAIL_NEWLINE;
				//				$headers .= "To: \"John Quaglieri\" <john@interserver.net>" . EMAIL_NEWLINE;

				$headers .= 'X-Mailer: Trouble-Free.Net Admin Center' . EMAIL_NEWLINE;
				admin_mail($subject, $email, $headers, false, 'admin_email_sql_error.tpl');
				$this->halt('Invalid SQL: ' . $Query_String, $line, $file);
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
		 * @param string $num_rows
		 * @return mixed
		 */
		public function limit_query($Query_String, $offset, $line = '', $file = '', $num_rows = '') {
			if (!$num_rows) {
				$num_rows = $this->max_matches;
			}
			if ($offset == 0) {
				$Query_String .= ' LIMIT ' . $num_rows;
			} else {
				$Query_String .= ' LIMIT ' . $offset . ',' . $num_rows;
			}

			if ($this->Debug) {
				printf("Debug: limit_query = %s<br>offset=%d, num_rows=%d<br>\n", $Query_String, $offset, $num_rows);
			}

			return $this->query($Query_String, $line, $file);
		}

		/* public: walk result set */

		/**
		 * Db::next_record()
		 *
		 * @param mixed $result_type
		 * @return bool
		 */
		public function next_record($result_type = MYSQLI_BOTH) {
			if ($this->Query_ID === false) {
				$this->halt('next_record called with no query pending.');
				return 0;
			}

			$this->Record = @mysqli_fetch_array($this->Query_ID, $result_type);
			$this->Row += 1;
			$this->Errno = mysqli_errno($this->Link_ID);
			$this->Error = mysqli_error($this->Link_ID);

			$stat = is_array($this->Record);
			if (!$stat && $this->Auto_Free && is_resource($this->Query_ID)) {
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
			$status = @mysqli_data_seek($this->Query_ID, $pos);
			if ($status) {
				$this->Row = $pos;
			} else {
				$this->halt("seek($pos) failed: result has " . $this->num_rows() . ' rows');
				/* half assed attempt to save the day,
				* but do not consider this documented or even
				* desirable behaviour.
				*/
				@mysqli_data_seek($this->Query_ID, $this->num_rows());
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
			return true;
		}

		/**
		 * Db::transaction_commit()
		 * @return bool
		 */
		public function transaction_commit() {
			return true;
		}

		/**
		 * Db::transaction_abort()
		 * @return bool
		 */
		public function transaction_abort() {
			return true;
		}

		/**
		 * Db::get_last_insert_id()
		 * @param mixed $table
		 * @param mixed $field
		 * @return int|string
		 */
		public function get_last_insert_id($table, $field) {
			/* This will get the last insert ID created on the current connection.  Should only be called
			* after an insert query is run on a table that has an auto incrementing field.  $table and
			* $field are required, but unused here since it's unnecessary for mysql.  For compatibility
			* with pgsql, the params must be supplied.
			*/

			if (!isset($table) || $table == '' || !isset($field) || $field == '') {
				return - 1;
			}

			return @mysqli_insert_id($this->Link_ID);
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
			$res = @mysqli_query($this->Link_ID, $query);
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

			$res = @mysqli_query($this->Link_ID, 'unlock tables');
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
			return @mysqli_affected_rows($this->Link_ID);
		}

		/**
		 * Db::num_rows()
		 * @return int
		 */
		public function num_rows() {
			return @mysqli_num_rows($this->Query_ID);
		}

		/**
		 * Db::num_fields()
		 * @return int
		 */
		public function num_fields() {
			return @mysqli_num_fields($this->Query_ID);
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
				$id = @$this->Link_ID->query($q);
				$res = @$id->fetch_array();

				/* No current value, make one */
				if (!is_array($res)) {
					$currentid = 0;
					$q = sprintf("insert into %s values('%s', %s)", $this->Seq_Table, $seq_name, $currentid);
					$id = @$this->Link_ID->query($q);
				} else {
					$currentid = $res['nextid'];
				}
				$nextid = $currentid + 1;
				$q = sprintf("update %s set nextid = '%s' where seq_name = '%s'", $this->Seq_Table, $nextid, $seq_name);
				$id = @$this->Link_ID->query($q);
				$this->unlock();
			} else {
				$this->halt('cannot lock ' . $this->Seq_Table . ' - has it been created?');
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

			//$this->Error = @$this->Link_ID->error;
			//$this->Errno = @$this->Link_ID->errno;
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
		 * @param string $line
		 * @param string $file
		 * @return mixed|void
		 */
		public function haltmsg($msg, $line = '', $file = '') {
			$this->log("Database error: $msg", $line, $file);
			if ($this->Errno != '0' || !in_array($this->Error, '', '()')) {
				$sqlstate = mysqli_sqlstate($this->Link_ID);
				$this->log("MySQLi SQLState: {$sqlstate}. Error: " . $this->Errno . ' (' . $this->Error . ')', $line, $file);
			}
			$backtrace=(function_exists('debug_backtrace') ? debug_backtrace() : array());
			$this->log(
				(mb_strlen(getenv('REQUEST_URI')) ? ' '.getenv('REQUEST_URI') : '').
				((isset($_POST) && count($_POST)) ? ' POST='.myadmin_stringify($_POST) : '').
				((isset($_GET) && count($_GET)) ? ' GET='.myadmin_stringify($_GET) : '').
				((isset($_FILES) && count($_FILES)) ? ' FILES='.myadmin_stringify($_FILES) : '').
				(mb_strlen(getenv('HTTP_USER_AGENT')) ? ' AGENT="'.getenv('HTTP_USER_AGENT').'"' : '').
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
							(gettype($backtrace[$level]['args'][$argument]) == 'object' ? 'class '.get_class($backtrace[$level]['args'][$argument]) : myadmin_stringify($backtrace[$level]['args'][$argument]));
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
			$return = array();
			$this->query('SHOW TABLES');
			$i = 0;
			while ($info = $this->Query_ID->fetch_row()) {
				$return[$i]['table_name'] = $info[0];
				$return[$i]['tablespace_name'] = $this->Database;
				$return[$i]['database'] = $this->Database;
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
			$return = array();
			return $return;
		}

		/**
		 * Db::create_database()
		 *
		 * @param string $adminname
		 * @param string $adminpasswd
		 * @return void
		 */
		public function create_database($adminname = '', $adminpasswd = '') {
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
