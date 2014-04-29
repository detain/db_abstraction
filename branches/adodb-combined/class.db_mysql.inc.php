<?php
	/**
	 * MySQL Related Functionality
	 * Last Changed: $LastChangedDate$
	 * @author $Author$
	 * @version $Revision$
	 * @copyright 2012
	 * @package MyAdmin
	 * @category SQL
	 */

	/**
	 * db
	 *
	 * @access public
	 */
	class db
	{
		/* public: connection parameters */
		public $Host = 'localhost';
		public $Database = '';
		public $User = '';
		public $Password = '';

		/* public: configuration parameters */
		public $auto_stripslashes = false;
		public $Auto_Free = 0; ## Set to 1 for automatic mysql_free_result()
		public $Debug = 0; ## Set to 1 for debugging messages.
		public $Halt_On_Error = 'yes'; ## "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore errror, but spit a warning)
		public $Seq_Table = 'db_sequence';

		/* public: result array and current row number */
		public $Record = array();
		public $Row;

		/* public: current error number and error text */
		public $Errno = 0;
		public $Error = '';

		/* public: this is an api revision, not a CVS revision. */
		public $type = 'mysql';
		public $revision = '1.2';

		/* private: link and query handles */
		public $Link_ID = 0;
		public $Query_ID = 0;

		/* public: constructor */
		/**
		 * db::db()
		 *
		 * @param string $query
		 * @return
		 */
		public function __construct($query = '')
		{
			$this->query($query);
		}

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
		 *
		 * @return
		 */
		public function link_id()
		{
			return $this->Link_ID;
		}

		/**
		 * db::query_id()
		 *
		 * @return
		 */
		public function query_id()
		{
			return $this->Query_ID;
		}

		/* public: connection management */
		/**
		 * db::connect()
		 *
		 * @param string $Database
		 * @param string $Host
		 * @param string $User
		 * @param string $Password
		 * @return
		 */
		public function connect($Database = '', $Host = '', $User = '', $Password = '')
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
			/* establish connection, select database */
			if (0 == $this->Link_ID)
			{
				/*				if ($GLOBALS['phpgw_info']['server']['db_persistent'])
				* {
				* $this->Link_ID=mysql_pconnect($Host, $User, $Password);
				* }
				* else
				* {*/
				//$this->log("New MySQL Connection To DB $Database", __LINE__, __FILE__);
				$this->Link_ID = mysql_connect($Host, $User, $Password);
				/* } */

				if (!$this->Link_ID)
				{
					$this->halt("connect($Host, $User, \$Password) failed.");
					return 0;
				}

				if (!@mysql_select_db($Database, $this->Link_ID))
				{
					$this->halt("cannot use database " . $this->Database);
					return 0;
				}
			}
			return $this->Link_ID;
		}

		/* This only affects systems not using persistant connections */
		/**
		 * db::disconnect()
		 *
		 * @return
		 */
		public function disconnect()
		{
			if ($this->Link_ID <> 0)
			{
				@mysql_close($this->Link_ID);
				$this->Link_ID = 0;
				return 1;
			}
			else
			{
				return 0;
			}
		}

		public function real_escape($string)
		{
			if ((is_null($this->Link_ID) || $this->Link_ID == 0) && !$this->connect())
			{
				return mysql_escape_string($string);
			}
			else
			{
				return mysql_real_escape_string($string);
			}
		}

		public function escape($string)
		{
			return mysql_escape_string($string);
		}

		/**
		 * db::db_addslashes()
		 *
		 * @param mixed $str
		 * @return
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
		 *
		 * @param mixed $epoch
		 * @return
		 */
		public function to_timestamp($epoch)
		{
			return date('YmdHis', $epoch);
		}

		/**
		 * db::from_timestamp()
		 * converts a mysql timestamp into a unix timetsamp
		 *
		 * @param string $timestamp mysql formatted timestamp
		 * @return integer unix time
		 */
		public function from_timestamp($timestamp)
		{
			if (strlen($timestamp) == 19)
			{
				preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $timestamp, $parts);
			}
			else
			{
				preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})/', $timestamp, $parts);
			}
			//_debug_array($parts);exit;
			return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
		}

		/**
		 * db::limit()
		 *
		 * @param mixed $start
		 * @return
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
		 *
		 * @return
		 */
		public function free()
		{
			if (is_resource($this->Query_ID))
				@mysql_free_result($this->Query_ID);
			$this->Query_ID = 0;
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
			if ($db->num_rows() == 0)
			{	
				return false;
			}
			elseif ($db->num_rows() == 1)
			{
				$db->next_record(MYSQL_ASSOC);
				return $db->Record;
			}
			else
			{
				$out = array();
				while ($db->next_record(MYSQL_ASSOC))
				{
					$out[] = $db->Record;
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
			if (is_resource($this->Query_ID))
			{
				$this->free();
			}
			if ($this->Debug)
			{
				printf("Debug: query = %s<br>\n", $Query_String);
			}
			if (isset($GLOBALS['log_queries']) && $GLOBALS['log_queries'] !== false)
			{
				$this->log($Query_String, $line, $file);
			}
			$this->Query_ID = @mysql_query($Query_String, $this->Link_ID);
			$this->Row = 0;
			$this->Errno = mysql_errno();
			$this->Error = mysql_error();
			if (!$this->Query_ID)
			{
				$email = "MySQL Error<br>\n" . "Query: " . $Query_String . "<br>\n" . "Error #" . $this->Errno . ": " . $this->Error . "<br>\n" . "Line: " . $line . "<br>\n" . "File: " . $file . "<br>\n" . (isset($GLOBALS['tf']) ? "User: " . $GLOBALS['tf']->session->account_id . "<br>\n" : '');

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
				$subject = DOMAIN . ' MySQL Error On ' . TITLE;
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

			# Will return nada if it fails. That's fine.
			return $this->Query_ID;
		}

		// public: perform a query with limited result set
		/**
		 * db::limit_query()
		 *
		 * @param mixed $Query_String
		 * @param mixed $offset
		 * @param string $line
		 * @param string $file
		 * @param string $num_rows
		 * @return
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
		 *
		 * @param mixed $result_type
		 * @return
		 */
		public function next_record($result_type = MYSQL_BOTH)
		{
			if (!$this->Query_ID)
			{
				$this->halt('next_record called with no query pending.');
				return 0;
			}

			$this->Record = @mysql_fetch_array($this->Query_ID, $result_type);
			$this->Row += 1;
			$this->Errno = mysql_errno();
			$this->Error = mysql_error();

			$stat = is_array($this->Record);
			if (!$stat && $this->Auto_Free && is_resource($this->Query_ID))
			{
				$this->free();
			}
			return $stat;
		}

		/* public: position in result set */
		/**
		 * db::seek()
		 *
		 * @param integer $pos
		 * @return
		 */
		public function seek($pos = 0)
		{
			$status = @mysql_data_seek($this->Query_ID, $pos);
			if ($status)
			{
				$this->Row = $pos;
			}
			else
			{
				$this->halt("seek($pos) failed: result has " . $this->num_rows() . " rows");
				/* half assed attempt to save the day,
				* but do not consider this documented or even
				* desireable behaviour.
				*/
				@mysql_data_seek($this->Query_ID, $this->num_rows());
				$this->Row = $this->num_rows;
				return 0;
			}
			return 1;
		}

		/**
		 * db::transaction_begin()
		 *
		 * @return
		 */
		public function transaction_begin()
		{
			return true;
		}

		/**
		 * db::transaction_commit()
		 *
		 * @return
		 */
		public function transaction_commit()
		{
			return true;
		}

		/**
		 * db::transaction_abort()
		 *
		 * @return
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
			/* This will get the last insert ID created on the current connection.  Should only be called
			* after an insert query is run on a table that has an auto incrementing field.  $table and
			* $field are required, but unused here since it's unnecessary for mysql.  For compatibility
			* with pgsql, the params must be supplied.
			*/

			if (!isset($table) || $table == '' || !isset($field) || $field == '')
			{
				return - 1;
			}

			return @mysql_insert_id($this->Link_ID);
		}

		/* public: table locking */
		/**
		 * db::lock()
		 *
		 * @param mixed $table
		 * @param string $mode
		 * @return
		 */
		public function lock($table, $mode = 'write')
		{
			$this->connect();

			$query = "lock tables ";
			if (is_array($table))
			{
				while (list($key, $value) = each($table))
				{
					if ($key == "read" && $key != 0)
					{
						$query .= "$value read, ";
					}
					else
					{
						$query .= "$value $mode, ";
					}
				}
				$query = substr($query, 0, -2);
			}
			else
			{
				$query .= "$table $mode";
			}
			$res = @mysql_query($query, $this->Link_ID);
			if (!$res)
			{
				$this->halt("lock($table, $mode) failed.");
				return 0;
			}
			return $res;
		}

		/**
		 * db::unlock()
		 *
		 * @return
		 */
		public function unlock()
		{
			$this->connect();

			$res = @mysql_query("unlock tables");
			if (!$res)
			{
				$this->halt("unlock() failed.");
				return 0;
			}
			return $res;
		}

		/* public: evaluate the result (size, width) */
		/**
		 * db::affected_rows()
		 *
		 * @return
		 */
		public function affected_rows()
		{
			return @mysql_affected_rows($this->Link_ID);
		}

		/**
		 * db::num_rows()
		 *
		 * @return
		 */
		public function num_rows()
		{
			return @mysql_num_rows($this->Query_ID);
		}

		/**
		 * db::num_fields()
		 *
		 * @return
		 */
		public function num_fields()
		{
			return @mysql_num_fields($this->Query_ID);
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
		 *
		 * @return
		 */
		public function np()
		{
			print $this->num_rows();
		}

		/**
		 * db::f()
		 *
		 * @param mixed $Name
		 * @param string $strip_slashes
		 * @return
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
		 *
		 * @param mixed $Name
		 * @return
		 */
		public function p($Name)
		{
			print $this->Record[$Name];
		}

		/* public: sequence numbers */
		/**
		 * db::nextid()
		 *
		 * @param mixed $seq_name
		 * @return
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
		 * @param mixed $msg
		 * @param string $line
		 * @param string $file
		 * @return
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
		 *
		 * @param mixed $msg
		 * @return
		 */
		public function haltmsg($msg)
		{
			$this->log("Database error: $msg", __line__, __file__);
			if ($this->Errno != "0" && $this->Error != "()")
			{
				$this->log("MySQL Error: " . $this->Errno . " (" . $this->Error . ")", __line__, __file__);
			}
		}

		/**
		 * db::table_names()
		 *
		 * @return
		 */
		public function table_names()
		{
			$return = array();
			$this->query("SHOW TABLES");
			$i = 0;
			while ($info = mysql_fetch_row($this->Query_ID))
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
		 * @return
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
		 * @return
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
