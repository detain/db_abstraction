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
		public $type = 'mysql';
		public $revision = '1.2';

		/* private: link and query handles */
		public $Link_ID = false;
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
			$DSN = "$Driver:dbname=$Database;host=$Host";
			if ($this->Link_ID === false)
			{
				try
				{
					$this->Link_ID = &new PDO($DSN, $User, $Password);
				}
				catch (PDOException $e)
				{
					$this->halt("Connection Failed " . $e->getMessage());
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
		}

		public function real_escape($string)
		{
			return mysql_escape_string($string);
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
		 * 
		 * @param mixed $timestamp
		 * @return
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
			//			@mysql_free_result($this->Query_ID);
			//			$this->Query_ID = 0;
		}

		/* public: perform a query */
		/* I added the line and file section so we can have better error reporting. (jengo) */
		/**
		 * db::query()
		 * 
		 * @param mixed $Query_String
		 * @param string $line
		 * @param string $file
		 * @return
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
			;

			# New query, discard previous result.
			if ($this->Query_ID !== false)
			{
				$this->free();
			}

			if ($this->Debug)
			{
				printf("Debug: query = %s<br>\n", $Query_String);
			}
			//			if (isset($GLOBALS['tf']))
			//			{
			if ($GLOBALS['log_queries'] !== false)
			{
				billingd_log($Query_String, $line, $file, false);
			}
			//			}

			$this->Query_ID = $this->Link_ID->prepare($Query_String);
			$success = $this->Query_ID->execute();
			$this->Rows = $this->Query_ID->fetchAll();
			billingd_log("PDO Query $Query_String (S:$success) - " . sizeof($this->Rows) . " Rows", __line__, __file__);
			$this->Row = 0;
			if ($success === false)
			{
				$email = "MySQL Error<br>\n" . "Query: " . $Query_String . "<br>\n" . "Error #" . print_r($this->Query_ID->errorInfo(), true) . "<br>\n" . "Line: " . $line . "<br>\n" . "File: " . $file . "<br>\n" . "User: " . $GLOBALS['tf']->session->account_id . "<br>\n";

				$email .= "<br><br>Request Variables:<br>";
				foreach ($GLOBALS['tf']->variables->request as $key => $value)
				{
					$email .= $key . ': ' . $value . "<br>\n";
				}

				$email .= "<br><br>Server Variables:<br>";
				foreach ($_SERVER as $key => $value)
				{
					$email .= $key . ': ' . $value . "<br>\n";
				}
				$subject = DOMAIN . ' PDO MySQL Error On ' . TITLE;
				$headers = '';
				$headers .= "MIME-Version: 1.0" . EMAIL_NEWLINE;
				$headers .= "Content-type: text/html; charset=iso-8859-1" . EMAIL_NEWLINE;
				$headers .= "From: " . TITLE . " <" . EMAIL_FROM . ">" . EMAIL_NEWLINE;
				$headers .= "To: John <john@interserver.net>" . EMAIL_NEWLINE;
				$headers .= "X-Priority: 1" . EMAIL_NEWLINE;
				$headers .= "X-MimeOLE: Produced By TF Admin Suite" . EMAIL_NEWLINE;
				$headers .= "X-MSMail-Priority: High" . EMAIL_NEWLINE;
				$headers .= "X-Mailer: Trouble-Free.Net Admin Center" . EMAIL_NEWLINE;
				admin_mail($subject, $email, $headers);
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
		public function next_record($result_type = MYSQL_ASSOC)
		{
			// PDO result types so far seem to be +1
			$result_type += 1;
			if (!$this->Query_ID)
			{
				$this->halt('next_record called with no query pending.');
				return 0;
			}

			$this->Row += 1;
			$this->Record = $this->Rows[$this->Row];

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
		 * 
		 * @param integer $pos
		 * @return
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
			if (!isset($table) || $table == '' || !isset($field) || $field == '')
			{
				return - 1;
			}
			return $this->Link_ID->lastInsertId();
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
		 * 
		 * @return
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
			return @$this->Query_ID->rowCount();
		}

		/**
		 * db::num_rows()
		 * 
		 * @return
		 */
		public function num_rows()
		{
			return sizeof($this->Rows);
		}

		/**
		 * db::num_fields()
		 * 
		 * @return
		 */
		public function num_fields()
		{
			return sizeof($this->Rows[$this->Rows]);
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
			billingd_log("Database error: $msg", __line__, __file__);
			if ($this->Errno != "0" && $this->Error != "()")
			{
				billingd_log("PDO MySQL Error: " . print_r($this->Link_ID->errorInfo(), true), __line__, __file__);
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
			foreach ($this->Rows as $i => $info)
			{
				$return[$i]['table_name'] = $info[0];
				$return[$i]['tablespace_name'] = $this->Database;
				$return[$i]['database'] = $this->Database;
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
