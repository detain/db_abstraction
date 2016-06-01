<?php
	/**
	 * MySQL Related Functionality
	 * Last Changed: $LastChangedDate$
	 * @author detain
	 * @version $Revision$
	 * @copyright 2016
	 * @package MyAdmin
	 * @category SQL
	 */

	/**
	 * db
	 *
	 * @access public
	 */
	class db implements db_interface
	{
		/* public: connection parameters */
		public $Host = 'localhost';
		public $Database = '';
		public $User = '';
		public $Password = '';
		public $Driver = 'mysql';

		/* public: configuration parameters */
		public $auto_stripslashes = false;
		public $Auto_Free = 0; ## Set to 1 for automatic mysql_free_result()
		public $Debug = 0; ## Set to 1 for debugging messages.
		public $Halt_On_Error = 'yes'; ## "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore errror, but spit a warning)
		public $Seq_Table = 'db_sequence';

		/* public: result array and current row number */
		public $Record = array();
		public $Row;
		public $Rows = array();

		/* public: current error number and error text */
		public $Errno = 0;
		public $Error = '';

		/* public: this is an api revision, not a CVS revision. */
		public $type = 'adodb';

		/* private: link and query handles */
		public $Link_ID = false;
		public $Query_ID = 0;

		/**
		 * Constructs the db handler, can optionally specify connection parameters
		 *
		 * @param string $Database Optional The database name
		 * @param string $User Optional The username to connect with
		 * @param string $Password Optional The password to use
		 * @param string $Host Optional The hostname where the server is, or default to localhost
		 * @param string $query Optional query to perform immediately
		 */
		public function __construct($Database = '', $User = '', $Password = '', $Host = 'localhost', $query = '')
		{
			if (!defined('_ADODB_LAYER'))
			{
				if (file_exists('adodb5/adodb.inc.php'))
					require_once ('adodb5/adodb.inc.php');
				elseif (defined('INSTALL_ROOT') && file_exists(INSTALL_ROOT . '/include/database/adodb5/adodb.inc.php'))
					require_once ('include/database/adodb5/adodb.inc.php');
				elseif (file_exists(realpath(dirname(__FILE__)) . '/../vendor/adodb/adodb-php/adodb.inc.php'))
					require_once (realpath(dirname(__FILE__)) . '/../vendor/adodb/adodb-php/adodb.inc.php');
			}
			$this->Database = $Database;
			$this->User = $User;
			$this->Password = $Password;
			$this->Host = $Host;
			if ($query != '')
			{
				$this->query($query);
			}
		}

		/**
		 * @param        $message
		 * @param string $line
		 * @param string $file
		 */
		public function log($message, $line = '', $file = '')
		{
			if (function_exists('billingd_log'))
				billingd_log($message, $line, $file, false);
			else
				error_log($message);
		}

		/* public: some trivial reporting */
		/**
		 * db::link_id()
		 * @return bool
		 */
		public function link_id()
		{
			return $this->Link_ID;
		}

		/**
		 * db::query_id()
		 * @return int
		 */
		public function query_id()
		{
			return $this->Query_ID;
		}

		/**
		 * db::connect()
		 * @param string $Database
		 * @param string $Host
		 * @param string $User
		 * @param string $Password
		 * @param string $Driver
		 * @return bool|\the
		 */
		public function connect($Database = '', $Host = '', $User = '', $Password = '', $Driver = 'mysql')
		{
			/* Handle defaults */
			if ('' == $Database)
			{
				$Database = $this->Database;
			}
			if ('' == $Host)
			{
				$Host = $this->Host;
			}
			if ('' == $User)
			{
				$User = $this->User;
			}
			if ('' == $Password)
			{
				$Password = $this->Password;
			}
			if ('' == $Driver)
			{
				$Driver = $this->Driver;
			}
			/* establish connection, select database */
			if ($this->Link_ID === false)
			{
				$this->Link_ID = NewADOConnection($Driver);
				$this->Link_ID->Connect($Host, $User, $Password, $Database);
			}
			return $this->Link_ID;
		}

		/* This only affects systems not using persistant connections */
		/**
		 * db::disconnect()
		 * @return void
		 */
		public function disconnect()
		{
		}

		/**
		 * @param $string
		 * @return string
		 */
		public function real_escape($string)
		{
			return mysql_escape_string($string);
		}

		/**
		 * @param $string
		 * @return string
		 */
		public function escape($string)
		{
			return mysql_escape_string($string);
		}

		/**
		 * db::db_addslashes()
		 * @param mixed $str
		 * @return string
		 */
		public function db_addslashes($str)
		{
			if (!isset($str) || $str == '')
			{
				return '';
			}

			return addslashes($str);
		}

		/**
		 * db::to_timestamp()
		 * @param mixed $epoch
		 * @return bool|string
		 */
		public function to_timestamp($epoch)
		{
			return date('YmdHis', $epoch);
		}

		/**
		 * db::from_timestamp()
		 * @param mixed $timestamp
		 * @return bool|int|mixed
		 */
		public function from_timestamp($timestamp)
		{
			if (preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $timestamp, $parts))
				return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
			elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
				return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
			elseif (preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})/', $timestamp, $parts))
				return mktime(1, 1, 1, $parts[2], $parts[3], $parts[1]);
			elseif (is_numeric($timestamp) && $timestamp >= 943938000)
				return $timestamp;
			else
			{
				$this->log('Cannot Match Timestamp from ' . $timestamp, __LINE__, __FILE__);
				return false;
			}
		}

		/**
		 * db::limit()
		 * @param mixed $start
		 * @return string
		 */
		public function limit($start)
		{
			echo '<b>Warning: limit() is no longer used, use limit_query()</b>';

			if ($start == 0)
			{
				$s = 'limit ' . $GLOBALS['phpgw_info']['user']['preferences']['common']['maxmatchs'];
			}
			else
			{
				$s = "limit $start," . $GLOBALS['phpgw_info']['user']['preferences']['common']['maxmatchs'];
			}
			return $s;
		}

		/* public: discard the query result */
		/**
		 * db::free()
		 * @return void
		 */
		public function free()
		{
			//			@mysql_free_result($this->Query_ID);
			//			$this->Query_ID = 0;
		}

		/**
		 * db::query_return()
		 *
		 * Sends an SQL query to the server like the normal query() command but iterates through
		 * any rows and returns the row or rows immediately or false on error
		 *
		 * @param mixed $query SQL Query to be used
		 * @param string $line optionally pass __LINE__ calling the query for logging
		 * @param string $file optionally pass __FILE__ calling the query for logging
		 * @return mixed false if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
		 */
		public function query_return($query, $line = '', $file = '')
		{
			$this->query($query, $line, $file);
			if ($this->num_rows() == 0)
			{
				return false;
			}
			elseif ($this->num_rows() == 1)
			{
				$this->next_record(MYSQL_ASSOC);
				return $this->Record;
			}
			else
			{
				$out = array();
				while ($this->next_record(MYSQL_ASSOC))
				{
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
		public function qr($query, $line = '', $file = '')
		{
			return $this->query_return($query, $line, $file);
		}

		/**
		 * db::query()
		 *
		 *  Sends an SQL query to the database
		 *
		 * @param mixed $Query_String
		 * @param string $line
		 * @param string $file
		 * @return mixed 0 if no query or query id handler, safe to ignore this return
		 */
		public function query($Query_String, $line = '', $file = '')
		{
			/* No empty queries, please, since PHP4 chokes on them. */
			/* The empty query string is passed on from the constructor,
			* when calling the class without a query, e.g. in situations
			* like these: '$db = new db_Subclass;'
			*/
			if ($Query_String == '')
			{
				return 0;
			}
			if (!$this->connect())
			{
				return 0;
				/* we already complained in connect() about that. */
			}

			# New query, discard previous result.
			if ($this->Query_ID !== false)
			{
				$this->free();
			}

			if ($this->Debug)
			{
				printf("Debug: query = %s<br>\n", $Query_String);
			}
			if ($GLOBALS['log_queries'] !== false)
			{
				$this->log($Query_String, $line, $file);

			}

			try
			{
				$this->Query_ID = $this->Link_ID->Execute($Query_String);
			}
			catch (exception $e)
			{
				$email = "MySQL Error<br>\n" . "Query: " . $Query_String . "<br>\n" . "Error #" . print_r($e, true) . "<br>\n" . "Line: " . $line . "<br>\n" . "File: " . $file . "<br>\n" . (isset($GLOBALS['tf']) ?
					"User: " . $GLOBALS['tf']->session->account_id . "<br>\n" : '');

				$email .= "<br><br>Request Variables:<br>";
				foreach ($_REQUEST as $key => $value)
				{
					$email .= $key . ': ' . $value . "<br>\n";
				}

				$email .= "<br><br>Server Variables:<br>";
				foreach ($_SERVER as $key => $value)
				{
					$email .= $key . ': ' . $value . "<br>\n";
				}
				$subject = DOMAIN . ' ADOdb MySQL Error On ' . TITLE;
				$headers = '';
				$headers .= "MIME-Version: 1.0" . EMAIL_NEWLINE;
				$headers .= "Content-type: text/html; charset=iso-8859-1" . EMAIL_NEWLINE;
				$headers .= "From: " . TITLE . " <" . EMAIL_FROM . ">" . EMAIL_NEWLINE;
				//				$headers .= "To: \"John Quaglieri\" <john@interserver.net>" . EMAIL_NEWLINE;
				$headers .= "X-Priority: 1" . EMAIL_NEWLINE;
				$headers .= "X-MimeOLE: Produced By TF Admin Suite" . EMAIL_NEWLINE;
				$headers .= "X-MSMail-Priority: High" . EMAIL_NEWLINE;
				$headers .= "X-Mailer: Trouble-Free.Net Admin Center" . EMAIL_NEWLINE;
				admin_mail($subject, $email, $headers, false, 'admin_email_sql_error.tpl');
				$this->halt("Invalid SQL: " . $Query_String, $line, $file);
			}
			$this->log("ADOdb Query $Query_String (S:$success) - " . sizeof($this->Rows) . " Rows", __LINE__, __FILE__);
			$this->Row = 0;

			# Will return nada if it fails. That's fine.
			return $this->Query_ID;
		}

		// public: perform a query with limited result set
		/**
		 * db::limit_query()
		 * @param mixed  $Query_String
		 * @param mixed  $offset
		 * @param string $line
		 * @param string $file
		 * @param string $num_rows
		 * @return mixed
		 */
		public function limit_query($Query_String, $offset, $line = '', $file = '', $num_rows = '')
		{
			if (!$num_rows)
			{
				$num_rows = $GLOBALS['phpgw_info']['user']['preferences']['common']['maxmatchs'];
			}

			if ($offset == 0)
			{
				$Query_String .= ' LIMIT ' . $num_rows;
			}
			else
			{
				$Query_String .= ' LIMIT ' . $offset . ',' . $num_rows;
			}

			if ($this->Debug)
			{
				printf("Debug: limit_query = %s<br>offset=%d, num_rows=%d<br>\n", $Query_String, $offset, $num_rows);
			}

			return $this->query($Query_String, $line, $file);
		}

		/* public: walk result set */
		/**
		 * db::next_record()
		 * @param mixed $result_type
		 * @return bool
		 */
		public function next_record($result_type = MYSQL_ASSOC)
		{
			if (!$this->Query_ID)
			{
				$this->halt('next_record called with no query pending.');
				return 0;
			}

			$this->Row += 1;
			$this->Record = $this->Query_ID->FetchRow();
			;
			$stat = is_array($this->Record);
			if (!$stat && $this->Auto_Free)
			{
				$this->free();
			}
			return $stat;
		}

		/* public: position in result set */
		/**
		 * db::seek()
		 * @param integer $pos
		 * @return int
		 */
		public function seek($pos = 0)
		{
			if (isset($this->Rows[$pos]))
			{
				$this->Row = $pos;
			}
			else
			{
				$this->halt("seek($pos) failed: result has " . sizeof($this->Rows) . " rows");
				/* half assed attempt to save the day,
				* but do not consider this documented or even
				* desireable behaviour.
				*/
				return 0;
			}
			return 1;
		}

		/**
		 * db::transaction_begin()
		 * @return bool
		 */
		public function transaction_begin()
		{
			return true;
		}

		/**
		 * db::transaction_commit()
		 * @return bool
		 */
		public function transaction_commit()
		{
			return true;
		}

		/**
		 * db::transaction_abort()
		 * @return bool
		 */
		public function transaction_abort()
		{
			return true;
		}

		/**
		 * db::get_last_insert_id()
		 *
		 * @param mixed $table
		 * @param mixed $field
		 * @return
		 */
		public function get_last_insert_id($table, $field)
		{
			return $this->Link_ID->Insert_ID($table, $field);
		}

		/* public: table locking */
		/**
		 * db::lock()
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
			* $query = substr($query,0,-2);
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
		 * db::unlock()
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
		 * db::affected_rows()
		 *
		 * @return
		 */
		public function affected_rows()
		{
			return @$this->Link_ID->Affected_Rows();
			//			return @$this->Query_ID->rowCount();
		}

		/**
		 * db::num_rows()
		 *
		 * @return
		 */
		public function num_rows()
		{
			return $this->Query_ID->NumRows();
		}

		/**
		 * db::num_fields()
		 *
		 * @return
		 */
		public function num_fields()
		{
			return $this->Query_ID->NumCols();
		}

		/* public: shorthand notation */
		/**
		 * db::nf()
		 *
		 * @return
		 */
		public function nf()
		{
			return $this->num_rows();
		}

		/**
		 * db::np()
		 * @return void
		 */
		public function np()
		{
			print $this->num_rows();
		}

		/**
		 * db::f()
		 * @param mixed  $Name
		 * @param string $strip_slashes
		 * @return string
		 */
		public function f($Name, $strip_slashes = "")
		{
			if ($strip_slashes || ($this->auto_stripslashes && !$strip_slashes))
			{
				return stripslashes($this->Record[$Name]);
			}
			else
			{
				return $this->Record[$Name];
			}
		}

		/**
		 * db::p()
		 * @param mixed $Name
		 * @return void
		 */
		public function p($Name)
		{
			print $this->Record[$Name];
		}

		/* public: sequence numbers */
		/**
		 * db::nextid()
		 * @param mixed $seq_name
		 * @return int
		 */
		public function nextid($seq_name)
		{
			$this->connect();

			if ($this->lock($this->Seq_Table))
			{
				/* get sequence number (locked) and increment */
				$q = sprintf("select nextid from %s where seq_name = '%s'", $this->Seq_Table, $seq_name);
				$id = @mysql_query($q, $this->Link_ID);
				$res = @mysql_fetch_array($id);

				/* No current value, make one */
				if (!is_array($res))
				{
					$currentid = 0;
					$q = sprintf("insert into %s values('%s', %s)", $this->Seq_Table, $seq_name, $currentid);
					$id = @mysql_query($q, $this->Link_ID);
				}
				else
				{
					$currentid = $res["nextid"];
				}
				$nextid = $currentid + 1;
				$q = sprintf("update %s set nextid = '%s' where seq_name = '%s'", $this->Seq_Table, $nextid, $seq_name);
				$id = @mysql_query($q, $this->Link_ID);
				$this->unlock();
			}
			else
			{
				$this->halt("cannot lock " . $this->Seq_Table . " - has it been created?");
				return 0;
			}
			return $nextid;
		}

		/* private: error handling */
		/**
		 * db::halt()
		 *
		 * @param mixed  $msg
		 * @param string $line
		 * @param string $file
		 * @return void
		 */
		public function halt($msg, $line = '', $file = '')
		{
			$this->unlock();
			/* Just in case there is a table currently locked */

			//$this->Error = @mysql_error($this->Link_ID);
			//$this->Errno = @mysql_errno($this->Link_ID);
			if ($this->Halt_On_Error == "no")
			{
				return;
			}
			$this->haltmsg($msg);

			if ($file)
			{
				error_log("File: $file");
			}
			if ($line)
			{
				error_log("Line: $line");
			}

			if ($this->Halt_On_Error != "report")
			{
				echo "<p><b>Session halted.</b>";
				// FIXME! Add check for error levels
				if (isset($GLOBALS['tf']))
					$GLOBALS['tf']->terminate();
			}
		}

		/**
		 * db::haltmsg()
		 * @param mixed $msg
		 * @return void
		 */
		public function haltmsg($msg)
		{
			$this->log("Database error: $msg", __LINE__, __FILE__);
			if ($this->Link_ID->ErrorNo() != "0" && $this->Link_ID->ErrorMsg() != "")
			{
				$this->log("ADOdb MySQL Error: " . $this->Link_ID->ErrorMsg(), __LINE__, __FILE__);
			}
		}

		/**
		 * db::table_names()
		 *
		 * @return array
		 */
		public function table_names()
		{
			$return = array();
			$this->query("SHOW TABLES");
			$i = 0;
			while ($info = $this->Query_ID->FetchRow())
			{
				$return[$i]['table_name'] = $info[0];
				$return[$i]['tablespace_name'] = $this->Database;
				$return[$i]['database'] = $this->Database;
				++$i;
			}
			return $return;
		}

		/**
		 * db::index_names()
		 *
		 * @return array
		 */
		public function index_names()
		{
			$return = array();
			return $return;
		}

		/**
		 * db::create_database()
		 *
		 * @param string $adminname
		 * @param string $adminpasswd
		 * @return void
		 */
		public function create_database($adminname = '', $adminpasswd = '')
		{
			$currentUser = $this->User;
			$currentPassword = $this->Password;
			$currentDatabase = $this->Database;

			if ($adminname != '')
			{
				$this->User = $adminname;
				$this->Password = $adminpasswd;
				$this->Database = "mysql";
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
?>
