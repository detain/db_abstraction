<?php
	/**
	 * PostgreSQL Related Functionality
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
		public $Host = '';
		public $Database = '';
		public $User = '';
		public $Password = '';

		public $auto_stripslashes = false;

		/* "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore errror, but spit a warning) */
		public $Halt_On_Error = 'yes';

		public $Link_ID = 0;
		public $Query_ID = 0;
		public $Record = array();
		public $Row = 0;

		public $Seq_Table = 'db_sequence';

		public $Errno = 0;
		public $Error = '';

		/* public: this is an api revision, not a CVS revision. */
		public $type = 'pgsql';

		/* Set this to 1 for automatic pg_freeresult on last record. */
		public $Auto_Free = 0;

		// PostgreSQL changed somethings from 6.x -> 7.x
		public $db_version;

		/**
		 * db::ifadd()
		 *
		 * @param mixed $add
		 * @param mixed $me
		 * @return string
		 */
		public function ifadd($add, $me)
		{
			if ('' != $add)
			{
				return ' ' . $me . $add;
			}
			return '';
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
		public function __construct($Database = '', $User = '', $Password = '', $Host = 'localhost', $query = '')
		{
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

		/**
		 * db::connect()
		 * @return void
		 */
		public function connect()
		{
			if (0 == $this->Link_ID)
			{
				$cstr = 'dbname=' . $this->Database . $this->ifadd($this->Host, 'host=') . $this->ifadd($this->Port, 'port=') . $this->ifadd($this->User, 'user=') . $this->ifadd("'" . $this->Password . "'",
					'password=');
				if ($GLOBALS['phpgw_info']['server']['db_persistent'])
				{
					$this->Link_ID = pg_pconnect($cstr);
				}
				else
				{
					$this->Link_ID = pg_connect($cstr);
				}

				if (!$this->Link_ID)
				{
					$this->halt('Link-ID == false, ' . ($GLOBALS['phpgw_info']['server']['db_persistent'] ? 'p' : '') . 'connect failed');
				}
				else
				{
					$this->query("select version()", __LINE__, __FILE__);
					$this->next_record();

					$version = $this->f('version');
					$parts = explode(' ', $version);
					$this->db_version = $parts[1];
				}
			}
		}

		/**
		 * db::to_timestamp()
		 * @param mixed $epoch
		 * @return bool|string|void
		 */
		public function to_timestamp($epoch)
		{
			$db_version = $this->db_version;
			if (floor($db_version) == 6)
			{
				return $this->to_timestamp_6($epoch);
			}
			else
			{
				return $this->to_timestamp_7($epoch);
			}
		}

		/**
		 * db::from_timestamp()
		 * @param mixed $timestamp
		 * @return int|void
		 */
		public function from_timestamp($timestamp)
		{
			if (floor($this->db_version) == 6)
			{
				return $this->from_timestamp_6($timestamp);
			}
			else
			{
				return $this->from_timestamp_7($timestamp);
			}
		}

		// For PostgreSQL 6.x
		/**
		 * db::to_timestamp_6()
		 * @param mixed $epoch
		 * @return void
		 */
		public function to_timestamp_6($epoch)
		{

		}

		// For PostgreSQL 6.x
		/**
		 * db::from_timestamp_6()
		 * @param mixed $timestamp
		 * @return void
		 */
		public function from_timestamp_6($timestamp)
		{

		}

		// For PostgreSQL 7.x
		/**
		 * db::to_timestamp_7()
		 * @param mixed $epoch
		 * @return bool|string
		 */
		public function to_timestamp_7($epoch)
		{
			// This needs the GMT offset!
			return date('Y-m-d H:i:s-00', $epoch);
		}

		// For PostgreSQL 7.x
		/**
		 * db::from_timestamp_7()
		 * @param mixed $timestamp
		 * @return int
		 */
		public function from_timestamp_7($timestamp)
		{
			preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $timestamp, $parts);

			return mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
		}

		/* This only affects systems not using persistant connections */
		/**
		 * db::disconnect()
		 * @return bool
		 */
		public function disconnect()
		{
			return @pg_close($this->Link_ID);
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
			if (!$line && !$file)
			{
				if (isset($GLOBALS['tf']))
				{
					$GLOBALS['tf']->warning(__LINE__, __FILE__, "Lazy developer didnt pass __LINE__ and __FILE__ to db->query() - Actually query: $Query_String");
				}
			}

			/* No empty queries, please, since PHP4 chokes on them. */
			/* The empty query string is passed on from the constructor,
			* when calling the class without a query, e.g. in situations
			* like these: '$db = new db_Subclass;'
			*/
			if ($Query_String == '')
			{
				return 0;
			}

			$this->connect();

			/* printf("<br>Debug: query = %s<br>\n", $Query_String); */

			$this->Query_ID = @pg_Exec($this->Link_ID, $Query_String);
			$this->Row = 0;

			$this->Error = pg_ErrorMessage($this->Link_ID);
			$this->Errno = ($this->Error == '') ? 0 : 1;
			if (!$this->Query_ID)
			{
				$this->halt('Invalid SQL: ' . $Query_String, $line, $file);
			}

			return $this->Query_ID;
		}

		/* public: perform a query with limited result set */
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
			if ($offset == 0)
			{
				$Query_String .= ' LIMIT ' . $num_rows;
			}
			else
			{
				$Query_String .= ' LIMIT ' . $num_rows . ',' . $offset;
			}

			if ($this->Debug)
			{
				printf("Debug: limit_query = %s<br>offset=%d, num_rows=%d<br>\n", $Query_String, $offset, $num_rows);
			}

			return $this->query($Query_String, $line, $file);
		}

		// public: discard the query result
		/**
		 * db::free()
		 *
		 * @return void
		 */
		public function free()
		{
			@pg_freeresult($this->Query_ID);
			$this->Query_ID = 0;
		}

		/**
		 * db::next_record()
		 * @return bool
		 */
		public function next_record()
		{
			$this->Record = @pg_fetch_array($this->Query_ID, $this->Row++);

			$this->Error = pg_ErrorMessage($this->Link_ID);
			$this->Errno = ($this->Error == '') ? 0 : 1;

			$stat = is_array($this->Record);
			if (!$stat && $this->Auto_Free)
			{
				pg_freeresult($this->Query_ID);
				$this->Query_ID = 0;
			}
			return $stat;
		}

		/**
		 * db::seek()
		 *
		 * @param mixed $pos
		 * @return void
		 */
		public function seek($pos)
		{
			$this->Row = $pos;
		}

		/**
		 * db::transaction_begin()
		 *
		 * @return mixed
		 */
		public function transaction_begin()
		{
			return $this->query('begin');
		}

		/**
		 * db::transaction_commit()
		 * @return bool|mixed
		 */
		public function transaction_commit()
		{
			if (!$this->Errno)
			{
				return pg_Exec($this->Link_ID, 'commit');
			}
			else
			{
				return false;
			}
		}

		/**
		 * db::transaction_abort()
		 * @return mixed
		 */
		public function transaction_abort()
		{
			return pg_Exec($this->Link_ID, 'rollback');
		}

		/**
		 * db::get_last_insert_id()
		 * @param mixed $table
		 * @param mixed $field
		 * @return int
		 */
		public function get_last_insert_id($table, $field)
		{
			/* This will get the last insert ID created on the current connection.  Should only be called
			* after an insert query is run on a table that has an auto incrementing field.  Of note, table
			* and field are required because pgsql returns the last inserted OID, which is unique across
			* an entire installation.  These params allow us to retrieve the sequenced field without adding
			* conditional code to the apps.
			*/
			if (!isset($table) || $table == '' || !isset($field) || $field == '')
			{
				return - 1;
			}

			$oid = pg_getlastoid($this->Query_ID);
			if ($oid == -1)
			{
				return - 1;
			}

			$result = @pg_Exec($this->Link_ID, "select $field from $table where oid=$oid");
			if (!$result)
			{
				return - 1;
			}

			$Record = @pg_fetch_array($result, 0);
			@pg_freeresult($result);
			if (!is_array($Record)) /* OID not found? */
			{
				return - 1;
			}

			return $Record[0];
		}

		/**
		 * db::lock()
		 * @param mixed  $table
		 * @param string $mode
		 * @return int|mixed
		 */
		public function lock($table, $mode = 'write')
		{
			$result = $this->transaction_begin();

			if ($mode == 'write')
			{
				if (is_array($table))
				{
					while ($t = each($table))
					{
						$result = pg_Exec($this->Link_ID, 'lock table ' . $t[1] . ' in share mode');
					}
				}
				else
				{
					$result = pg_Exec($this->Link_ID, 'lock table ' . $table . ' in share mode');
				}
			}
			else
			{
				$result = 1;
			}

			return $result;
		}

		/**
		 * db::unlock()
		 * @return bool|mixed
		 */
		public function unlock()
		{
			return $this->transaction_commit();
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
				$id = @pg_Exec($this->Link_ID, $q);
				$res = @pg_Fetch_Array($id, 0);

				/* No current value, make one */
				if (!is_array($res))
				{
					$currentid = 0;
					$q = sprintf("insert into %s values('%s', %s)", $this->Seq_Table, $seq_name, $currentid);
					$id = @pg_Exec($this->Link_ID, $q);
				}
				else
				{
					$currentid = $res['nextid'];
				}
				$nextid = $currentid + 1;
				$q = sprintf("update %s set nextid = '%s' where seq_name = '%s'", $this->Seq_Table, $nextid, $seq_name);
				$id = @pg_Exec($this->Link_ID, $q);
				$this->unlock();
			}
			else
			{
				$this->halt('cannot lock ' . $this->Seq_Table . ' - has it been created?');
				return 0;
			}
			return $nextid;
		}

		/**
		 * db::affected_rows()
		 * @return void
		 */
		public function affected_rows()
		{
			return pg_cmdtuples($this->Query_ID);
		}

		/**
		 * db::num_rows()
		 * @return int
		 */
		public function num_rows()
		{
			return pg_numrows($this->Query_ID);
		}

		/**
		 * db::num_fields()
		 * @return int
		 */
		public function num_fields()
		{
			return pg_numfields($this->Query_ID);
		}

		/**
		 * db::nf()
		 * @return int
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
		public function f($Name, $strip_slashes = '')
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
		 * @return void
		 */
		public function p($Name)
		{
			print $this->Record[$Name];
		}

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
			if ($this->Halt_On_Error == 'no')
			{
				return;
			}

			/* Just in case there is a table currently locked */
			$this->transaction_abort();

			if ($this->xmlrpc || $this->soap)
			{
				$s = sprintf("Database error: %s\n", $msg);
				$s .= sprintf("PostgreSQL Error: %s\n\n (%s)\n\n", $this->Errno, $this->Error);
			}
			else
			{
				$s = sprintf("<b>Database error:</b> %s<br>\n", $msg);
				$s .= sprintf("<b>PostgreSQL Error</b>: %s (%s)<br>\n", $this->Errno, $this->Error);
			}

			if ($file)
			{
				if ($this->xmlrpc || $this->soap)
				{
					$s .= sprintf("File: %s\n", $file);
				}
				else
				{
					$s .= sprintf("<br><b>File:</b> %s", $file);
				}
			}

			if ($line)
			{
				if ($this->xmlrpc || $this->soap)
				{
					$s .= sprintf("Line: %s\n", $line);
				}
				else
				{
					$s .= sprintf("<br><b>Line:</b> %s", $line);
				}
			}

			if ($this->Halt_On_Error == 'yes')
			{
				$s .= '<p><b>Session halted.</b>';
			}

			if ($this->xmlrpc)
			{
				xmlrpcfault($s);
			}
			elseif ($this->soap)
			{

			}
			else
			{
				error_log($s);
				if (isset($GLOBALS['tf']))
					$GLOBALS['tf']->terminate();
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
			$this->query("select relname from pg_class where relkind = 'r' and not relname like 'pg_%'");
			$i = 0;
			while ($this->next_record())
			{
				$return[$i]['table_name'] = $this->f(0);
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
			$this->query("SELECT relname FROM pg_class WHERE NOT relname ~ 'pg_.*' AND relkind ='i' ORDER BY relname");
			$i = 0;
			while ($this->next_record())
			{
				$return[$i]['index_name'] = $this->f(0);
				$return[$i]['tablespace_name'] = $this->Database;
				$return[$i]['database'] = $this->Database;
				++$i;
			}
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

			if ($adminname != "")
			{
				$this->User = $adminname;
				$this->Password = $adminpasswd;
			}

			if (!$this->Host)
			{
				system('createdb ' . $currentDatabase, $outval);
			}
			else
			{
				system('createdb -h ' . $this->Host . ' ' . $currentDatabase, $outval);
			}

			if ($outval != 0)
			{
				/* either the rights r not available or the postmaster is not running .... */
				echo 'database creation failure <BR>';
				echo 'please setup the postreSQL database manually<BR>';
			}

			$this->User = $currentUser;
			$this->Password = $currentPassword;
			$this->Database = $currentDatabase;
			$this->connect();
		}
	}
?>
