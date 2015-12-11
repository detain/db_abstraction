<?php
	/**
	 * MDB2 Wrapper Made To Handle Liike Our Other ClasssesRelated Functionality
	 * Last Changed: $LastChangedDate$
	 * @author $Author$
	 * @version $Revision$
	 * @copyright 2015
	 * @package MyAdmin
	 * @category SQL
	 */

	/**
	 * db_mdb2_result
	 *
	 * @access public
	 */
	class db_mdb2_result
	{
		public $query;
		public $result;
		public $error = false;
		public $message = '';
		public $Link_ID = 0;
		public $Query_ID = 0;

		/**
		 * db_mdb2_result::db_mdb2_result()
		 * @param mixed $query
		 * @return \db_mdb2_result
		 */
		public function __construct($query)
		{
			$this->query = $query;
			$this->result = mysql_query($query);
			if (mysql_errno())
			{
				$this->message = "MySQL error " . mysql_errno() . ": " . mysql_error() . ' Query: ' . $this->query;
				$this->error = true;
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
		 * db_mdb2_result::numRows()
		 * @return int
		 */
		public function numRows()
		{
			return mysql_num_rows($this->result);
		}

		/**
		 * db_mdb2_result::fetchRow()
		 * @return array
		 */
		public function fetchRow()
		{
			return mysql_fetch_array($this->result);
		}

		/**
		 * db_mdb2_result::getMessage()
		 * @return string
		 */
		public function getMessage()
		{
			return $this->message;
		}
	}

	/**
	 * db_mdb2
	 *
	 * @package MyAdmin



	 * @author cpaneldirect
	 * @copyright Owner
	 * @version 2011
	 * @access public
	 */
	class db_mdb2 implements db_interface
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

		public $Link_ID = 0;
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
			$this->Database = $Database;
			$this->User = $User;
			$this->Password = $Password;
			$this->Host = $Host;
			if ($query != '')
			{
				$this->query($query);
			}
		}

		public function connect()
		{
			$this->Link_ID = mysql_connect($this->Host, $this->User, $this->Password);
			mysql_select_db($this->Database, $this->Link_ID);
		}

		/**
		 * @param strnig $message
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
		 * @return int
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
		 * db_mdb2::quote()
		 * @param string $text
		 * @param string $type
		 * @return string
		 */
		public function quote($text = '', $type = 'text')
		{
			switch ($type)
			{
				case 'text':
					return "'" . mysql_real_escape_string($text) . "'";
					break;
				case 'integer':
				default:
					return $text;
					break;
			}
		}

		/**
		 * db_mdb2::queryOne()
		 * @param mixed $query
		 * @return bool
		 */
		public function queryOne($query)
		{
			if ($this->Link_ID == 0)
			{
				$this->connect();
			}
			$result = mysql_query($query);
			$this->Query_ID = $result;
			if (mysql_num_rows($result) > 0)
			{
				$row = mysql_fetch_array($result);
				return $row[0];
			}
			else
				return false;
		}

		/**
		 * db_mdb2::queryRow()
		 * @param mixed $query
		 * @return array|bool
		 */
		public function queryRow($query)
		{
			if ($this->Link_ID === 0)
			{
				$this->connect();
			}
			$result = mysql_query($query);
			if (mysql_num_rows($result) > 0)
			{
				$row = mysql_fetch_array($result);
				return $row;
			}
			else
				return false;
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
		 *  Sends an SQL query to the database
		 * @param $query
		 * @internal param mixed $Query_String
		 * @internal param string $line
		 * @internal param string $file
		 * @return mixed 0 if no query or query id handler, safe to ignore this return
		 */
		public function query($query)
		{
			if ($this->Link_ID === 0)
			{
				$this->connect();
			}
			//$result = new db_mdb2_result($query);
			//$this->Query_ID = $result->result;
			//return $this->Query_ID;
			return new db_mdb2_result($query);
		}

		/**
		 * db_mdb2::lastInsertId()
		 * @param mixed $table
		 * @param mixed $field
		 * @return int
		 */
		public function lastInsertId($table, $field)
		{
			return mysql_insert_id();
		}

		/**
		 * db_mdb2::disconnect()
		 * @return void
		 */
		public function disconnect()
		{
			mysql_close();
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
		 *
		 * @param mixed $msg
		 * @return void
		 */
		public function haltmsg($msg)
		{
			$this->log("Database error: $msg", __LINE__, __FILE__);
			if ($this->Errno != "0" || $this->Error != "()")
			{
				$this->log("MySQL Error: " . $this->Errno . " (" . $this->Error . ")", __LINE__, __FILE__);
			}
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
	}
?>
