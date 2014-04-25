<?php
/**
* MySQL Related Functionality
* @author Joe Huss <detain@corpmail.interserver.net>
* @package SQL
* @copyright 2010
*/

	/**
	 * db
	 * 
	 * @package   
	 * @author cpaneldirect
	 * @copyright Owner
	 * @version 2011
	 * @access public
	 */
	class db
	{
		/* public: connection parameters */
		public $Host     = 'localhost';
		public $Database = '';
		public $User     = '';
		public $Password = '';

		/* public: configuration parameters */
		public $auto_stripslashes = False;
		public $Auto_Free     = 0;     ## Set to 1 for automatic mysql_free_result()
		public $Debug         = 0;     ## Set to 1 for debugging messages.
		public $Halt_On_Error = 'yes'; ## "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore errror, but spit a warning)
		public $Seq_Table     = 'db_sequence';

		/* public: result array and current row number */
		public $Record   = array();
		public $Row;

		/* public: current error number and error text */
		public $Errno    = 0;
		public $Error    = '';

		/* public: this is an api revision, not a CVS revision. */
		public $type     = 'mysql';
		public $revision = '1.2';

		/* private: link and query handles */
		public $Link_ID  = 0;
		public $Query_ID = 0;

		/* public: constructor */
		/**
		 * db::db()
		 * 
		 * @param string $query
		 * @return
		 */
		function db($query = '')
		{
			$this->query($query);
		}

		/* public: some trivial reporting */
		/**
		 * db::link_id()
		 * 
		 * @return
		 */
		function link_id()
		{
			return $this->Link_ID;
		}

		/**
		 * db::query_id()
		 * 
		 * @return
		 */
		function query_id()
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
		function connect($Database = '', $Host = '', $User = '', $Password = '')
		{
			/* Handle defaults */
			if ('' == $Database)
			{
				$Database = $this->Database;
			}
			if ('' == $Host)
			{
				$Host     = $this->Host;
			}
			if ('' == $User)
			{
				$User     = $this->User;
			}
			if ('' == $Password)
			{
				$Password = $this->Password;
			}
			/* establish connection, select database */
			if ( !is_object($this->Link_ID) )
			{
/*				if ($GLOBALS['phpgw_info']['server']['db_persistent'])
				{
					$this->Link_ID=mysql_pconnect($Host, $User, $Password);
				}
				else
				{*/
					$this->Link_ID = new mysqli($Host, $User, $Password, $Database);
				/* } */

				if ($this->Link_ID->connect_error)
				{
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
		function disconnect()
		{
			return $this->Link_ID->close();
		}

		function real_escape($string)
		{
			return $this->Link_ID->real_escape_string($string);
		}

		function escape($string)
		{
			return mysqli_escape_string($string);
		}

		/**
		 * db::db_addslashes()
		 * 
		 * @param mixed $str
		 * @return
		 */
		function db_addslashes($str)
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
		function to_timestamp($epoch)
		{
			return date('YmdHis',$epoch);
		}

		/**
		 * db::from_timestamp()
		 * 
		 * @param mixed $timestamp
		 * @return
		 */
		function from_timestamp($timestamp)
		{
			if (strlen($timestamp) == 19)
			{
				preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/',$timestamp,$parts);
			}
			else
			{
				preg_match('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})/',$timestamp,$parts);
			}
			//_debug_array($parts);exit;
			return mktime($parts[4],$parts[5],$parts[6],$parts[2],$parts[3],$parts[1]);
		}

		/**
		 * db::limit()
		 * 
		 * @param mixed $start
		 * @return
		 */
		function limit($start)
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
		function free()
		{
			@mysqli_free_result($this->Query_ID);
			$this->Query_ID = 0;
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
		function query($Query_String, $line = '', $file = '')
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
				return 0; /* we already complained in connect() about that. */
			};
			// New query, discard previous result.
			if (is_object($this->Query_ID))
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

			$this->Query_ID = $this->Link_ID->query($Query_String);
/*
echo '<pre>';
echo "Query String: $Query_String<br>";
echo "Query<br>";
echo var_dump($this->Link_ID);
echo "Query ID<br>";
echo var_dump($this->Query_ID);
echo '<br>';
echo "Result ID<br>";
echo var_dump($this->Query_ID);
*/
/*
*/

			$this->Row   = 0;
			$this->Errno = $this->Link_ID->errno;
			$this->Error = $this->Link_ID->error;
			if ($this->Query_ID === FALSE)
			{
				$email = "MySQLi Error<br>\n"
				. "Query: " . $Query_String . "<br>\n"
				. "Error #" . $this->Errno . ": " . $this->Error . "<br>\n"
				. "Line: " . $line . "<br>\n"
				. "File: " . $file . "<br>\n"
				. "User: " . $GLOBALS['tf']->session->account_id . "<br>\n";

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
				$subject = DOMAIN . ' MySQLi Error On ' . TITLE;
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
				$this->halt("Invalid SQL: ".$Query_String, $line, $file);
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
		function limit_query($Query_String, $offset, $line = '', $file = '', $num_rows = '')
		{
			if (! $num_rows)
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
		function next_record($result_type = MYSQLI_BOTH)
		{
			if ($this->Query_ID === FALSE)
			{
				$this->halt('next_record called with no query pending.');
				return 0;
			}

			$this->Record = @$this->Query_ID->fetch_array($result_type);
			$this->Row   += 1;
			$this->Errno  = $this->Link_ID->errno;
			$this->Error  = $this->Link_ID->error;

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
		function seek($pos = 0)
		{
			$status = @$this->Query_ID->data_seek($pos);
			if ($status)
			{
				$this->Row = $pos;
			}
			else
			{
				$this->halt("seek($pos) failed: result has ".$this->num_rows()." rows");
				/* half assed attempt to save the day, 
				* but do not consider this documented or even
				* desireable behaviour.
				*/
				@$this->Query_ID->data_seek($this->num_rows());
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
		function transaction_begin()
		{
			return True;
		}

		/**
		 * db::transaction_commit()
		 * 
		 * @return
		 */
		function transaction_commit()
		{
			return True;
		}

		/**
		 * db::transaction_abort()
		 * 
		 * @return
		 */
		function transaction_abort()
		{
			return True;
		}

		/**
		 * db::get_last_insert_id()
		 * 
		 * @param mixed $table
		 * @param mixed $field
		 * @return
		 */
		function get_last_insert_id($table, $field)
		{
			/* This will get the last insert ID created on the current connection.  Should only be called
			 * after an insert query is run on a table that has an auto incrementing field.  $table and
			 * $field are required, but unused here since it's unnecessary for mysql.  For compatibility
			 * with pgsql, the params must be supplied.
			 */

			if (!isset($table) || $table == '' || !isset($field) || $field == '')
			{
				return -1;
			}

			return @$this->Link_ID->insert_id;
		}

		/* public: table locking */
		/**
		 * db::lock()
		 * 
		 * @param mixed $table
		 * @param string $mode
		 * @return
		 */
		function lock($table, $mode='write')
		{
			$this->connect();

			$query = "lock tables ";
			if (is_array($table))
			{
				while (list($key,$value)=each($table))
				{
					if ($key == "read" && $key!=0)
					{
						$query .= "$value read, ";
					}
					else
					{
						$query .= "$value $mode, ";
					}
				}
				$query = substr($query,0,-2);
			}
			else
			{
				$query .= "$table $mode";
			}
			$res = @$this->Link_ID->query($query);
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
		function unlock()
		{
			$this->connect();

			$res = @$this->Link_ID->query("unlock tables");
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
		function affected_rows()
		{
			return $this->Link_ID->affected_rows;
		}

		/**
		 * db::num_rows()
		 * 
		 * @return
		 */
		function num_rows()
		{
			return $this->Query_ID->num_rows;
		}

		/**
		 * db::num_fields()
		 * 
		 * @return
		 */
		function num_fields()
		{
			return $this->Query_ID->field_count;
		}

		/* public: shorthand notation */
		/**
		 * db::nf()
		 * 
		 * @return
		 */
		function nf()
		{
			return $this->num_rows();
		}

		/**
		 * db::np()
		 * 
		 * @return
		 */
		function np()
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
		function f($Name, $strip_slashes = "")
		{
			if ($strip_slashes || ($this->auto_stripslashes && ! $strip_slashes))
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
		function p($Name)
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
		function nextid($seq_name)
		{
			$this->connect();

			if ($this->lock($this->Seq_Table))
			{
				/* get sequence number (locked) and increment */
				$q  = sprintf("select nextid from %s where seq_name = '%s'",
					$this->Seq_Table,
					$seq_name);
				$id  = @$this->Link_ID->query($q);
				$res = @$id->fetch_array();

				/* No current value, make one */
				if (!is_array($res))
				{
					$currentid = 0;
					$q = sprintf("insert into %s values('%s', %s)",
						$this->Seq_Table,
						$seq_name,
						$currentid);
					$id = @$this->Link_ID->query($q);
				}
				else
				{
					$currentid = $res["nextid"];
				}
				$nextid = $currentid + 1;
				$q = sprintf("update %s set nextid = '%s' where seq_name = '%s'",
					$this->Seq_Table,
					$nextid,
					$seq_name);
				$id = @$this->Link_ID->query($q);
				$this->unlock();
			}
			else
			{
				$this->halt("cannot lock ".$this->Seq_Table." - has it been created?");
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
		function halt($msg, $line = '', $file = '')
		{
			$this->unlock();	/* Just in case there is a table currently locked */

			//$this->Error = @$this->Link_ID->error;
			//$this->Errno = @$this->Link_ID->errno;
			if ($this->Halt_On_Error == "no")
			{
				return;
			}
			$this->haltmsg($msg);

			if ($file)
			{
				printf("<br><b>File:</b> %s",$file);
			}
			if ($line)
			{
				printf("<br><b>Line:</b> %s",$line);
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
		function haltmsg($msg)
		{
			billingd_log("Database error: $msg", __LINE__, __FILE__);
			printf("<b>Database error:</b> %s<br>\n", $msg);
			if ($this->Errno != "0" && $this->Error != "()")
			{
				billingd_log("MySQLi Error: " . $this->Errno . " (" . $this->Error . ")", __LINE__, __FILE__);
				printf("<b>MySQLi Error</b>: %s (%s)<br>\n",$this->Errno,$this->Error);
			}
		}

		/**
		 * db::table_names()
		 * 
		 * @return
		 */
		function table_names()
		{
			$return = array();
			$this->query("SHOW TABLES");
			$i=0;
			while ($info= $this->Query_ID->fetch_row())
			{
				$return[$i]['table_name'] = $info[0];
				$return[$i]['tablespace_name'] = $this->Database;
				$return[$i]['database'] = $this->Database;
				$i++;
			}
			return $return;
		}

		/**
		 * db::index_names()
		 * 
		 * @return
		 */
		function index_names()
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
		function create_database($adminname = '', $adminpasswd = '')
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
