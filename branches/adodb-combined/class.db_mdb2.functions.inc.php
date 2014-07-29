<?php
	/**
	 * MDB2 Wrapper Made To Handle Liike Our Other ClasssesRelated Functionality
	 * Last Changed: $LastChangedDate$
	 * @author $Author$
	 * @version $Revision$
	 * @copyright 2012
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

		/**
		 * db_mdb2_result::db_mdb2_result()
		 *
		 * @param mixed $query
		 * @return
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

		public function log($message, $line = '', $file = '')
		{
			if (function_exists('billingd_log'))
				billingd_log($message, $line, $file, false);
			else
				error_log($message);
		}

		/**
		 * db_mdb2_result::numRows()
		 *
		 * @return
		 */
		public function numRows()
		{
			return mysql_num_rows($this->result);
		}

		/**
		 * db_mdb2_result::fetchRow()
		 *
		 * @return
		 */
		public function fetchRow()
		{
			return mysql_fetch_array($this->result);
		}

		/**
		 * db_mdb2_result::getMessage()
		 *
		 * @return
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
	class db_mdb2
	{
		public $Host = 'localhost';
		public $User = 'poweradmin';
		public $Password = '';
		public $Database = 'poweradmin';
		public $type = 'mdb2';

		public $iface_lang = 'en_EN';

		public $dns_hostmaster = 'hostmaster.interserver.net';
		public $dns_ns1 = 'cdns1.interserver.net';
		public $dns_ns2 = 'cdns2.interserver.net';
		public $dns_ns3 = 'cdns3.interserver.net';

		public $dbh = false;

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
			$this->dbh = mysql_connect($this->Host, $this->User, $this->Password);
			mysql_select_db($this->Database, $this->dbh);
		}

		/**
		 * db_mdb2::quote()
		 *
		 * @param string $text
		 * @param string $type
		 * @return
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
		 *
		 * @param mixed $query
		 * @return
		 */
		public function queryOne($query)
		{
			if ($this->dbh === false)
			{
				$this->connect();
			}
			$result = mysql_query($query);
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
		 *
		 * @param mixed $query
		 * @return
		 */
		public function queryRow($query)
		{
			if ($this->dbh === false)
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
		 * 
		 *  Sends an SQL query to the database
		 *
		 * @param mixed $Query_String
		 * @param string $line
		 * @param string $file
		 * @return mixed 0 if no query or query id handler, safe to ignore this return
		 */
		public function query($query)
		{
			if ($this->dbh === false)
			{
				$this->connect();
			}
			return new db_mdb2_result($query);
		}

		/**
		 * db_mdb2::lastInsertId()
		 *
		 * @param mixed $table
		 * @param mixed $field
		 * @return
		 */
		public function lastInsertId($table, $field)
		{
			return mysql_insert_id();
		}

		/**
		 * db_mdb2::disconnect()
		 *
		 * @return
		 */
		public function disconnect()
		{
			mysql_close();
		}

	}
?>
