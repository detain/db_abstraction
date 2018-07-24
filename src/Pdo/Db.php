<?php
/**
 * MySQL Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2018
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
class Db extends Generic implements Db_Interface
{
	/* public: connection parameters */
	public $driver = 'mysql';

	/* public: configuration parameters */
	public $autoFree = 0; // Set to 1 for automatic mysql_free_result()
	public $Rows = [];

	/* public: this is an api revision, not a CVS revision. */
	public $type = 'pdo';

	public $maxMatches = 10000000;

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
	 * alias function of select_db, changes the database we are working with.
	 *
	 * @param string $database the name of the database to use
	 * @return void
	 */
	public function useDb($database) {
		$this->selectDb($database);
	}

	/**
	 * changes the database we are working with.
	 *
	 * @param string $database the name of the database to use
	 * @return void
	 */
	public function selectDb($database) {
		$DSN = "{$this->driver}:dbname={$database};host={$this->host}";
		if ($this->characterSet != '')
			$DSN .= ';charset='.$this->characterSet;
		$this->linkId = new \PDO($DSN, $this->user, $this->password);
	}

	/* public: connection management */

	/**
	 * Db::connect()
	 * @param string $database
	 * @param string $host
	 * @param string $user
	 * @param string $password
	 * @param string $driver
	 * @return bool|int|\PDO
	 */
	public function connect($database = '', $host = '', $user = '', $password = '', $driver = 'mysql') {
		/* Handle defaults */
		if ('' == $database)
			$database = $this->database;
		if ('' == $host)
			$host = $this->host;
		if ('' == $user)
			$user = $this->user;
		if ('' == $password)
			$password = $this->password;
		if ('' == $driver)
			$driver = $this->driver;
		/* establish connection, select database */
		$DSN = "$driver:dbname=$database;host=$host";
		if ($this->characterSet != '')
			$DSN .= ';charset='.$this->characterSet;
		if ($this->linkId === FALSE) {
			try
			{
				$this->linkId = new \PDO($DSN, $user, $password);
			}
			catch (\PDOException $e) {
				$this->halt('Connection Failed '.$e->getMessage());
				return 0;
			}
		}
		return $this->linkId;
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
		return escapeshellarg($string);
	}

	/**
	 * @param $string
	 * @return string
	 */
	public function escape($string) {
		return escapeshellarg($string);
	}

	/* public: discard the query result */

	/**
	 * Db::free()
	 * @return void
	 */
	public function free() {
		//			@mysql_free_result($this->queryId);
		//			$this->queryId = 0;
	}

	/**
	 * Db::queryReturn()
	 *
	 * Sends an SQL query to the server like the normal query() command but iterates through
	 * any rows and returns the row or rows immediately or FALSE on error
	 *
	 * @param mixed $query SQL Query to be used
	 * @param string $line optionally pass __LINE__ calling the query for logging
	 * @param string $file optionally pass __FILE__ calling the query for logging
	 * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
	 */
	public function queryReturn($query, $line = '', $file = '') {
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
	 *  alias of queryReturn()
	 *
	 * @param mixed $query SQL Query to be used
	 * @param string $line optionally pass __LINE__ calling the query for logging
	 * @param string $file optionally pass __FILE__ calling the query for logging
	 * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
	 */
	public function qr($query, $line = '', $file = '') {
		return $this->queryReturn($query, $line, $file);
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
		/* No empty queries, please, since PHP4 chokes on them. */
		/* The empty query string is passed on from the constructor,
		* when calling the class without a query, e.g. in situations
		* like these: '$db = new db_Subclass;'
		*/
		if ($queryString == '')
			return 0;
		if (!$this->connect()) {
			return 0;
			/* we already complained in connect() about that. */
		}
		// New query, discard previous result.
		if ($this->queryId !== FALSE)
			$this->free();

		if ($this->Debug)
			printf("Debug: query = %s<br>\n", $queryString);
		if (isset($GLOBALS['log_queries']) && $GLOBALS['log_queries'] !== FALSE)
			$this->log($queryString, $line, $file);

		$this->queryId = $this->linkId->prepare($queryString);
		$success = $this->queryId->execute();
		$this->Rows = $this->queryId->fetchAll();
		$this->log("PDO Query $queryString (S:$success) - " . count($this->Rows).' Rows', __LINE__, __FILE__);
		$this->Row = 0;
		if ($success === FALSE) {
			$email = "MySQL Error<br>\n".'Query: '.$queryString . "<br>\n".'Error #'.print_r($this->queryId->errorInfo(), TRUE) . "<br>\n".'Line: '.$line . "<br>\n".'File: '.$file . "<br>\n" . (isset($GLOBALS['tf']) ? 'User: '.$GLOBALS['tf']->session->account_id . "<br>\n" : '');

			$email .= '<br><br>Request Variables:<br>';
			foreach ($_REQUEST as $key => $value)
				$email .= $key.': '.$value . "<br>\n";

			$email .= '<br><br>Server Variables:<br>';
			foreach ($_SERVER as $key => $value)
				$email .= $key.': '.$value . "<br>\n";
			$subject = $_SERVER['HOSTNAME'].' MySQLi Error';
			$headers = '';
			$headers .= 'MIME-Version: 1.0'.PHP_EOL;
			$headers .= 'Content-type: text/html; charset=UTF-8'.PHP_EOL;
			$headers .= 'From: No-Reply <no-reply@interserver.net>'.PHP_EOL;
			$headers .= 'X-Mailer: Trouble-Free.Net Admin Center'.PHP_EOL;
			admin_mail($subject, $email, $headers, FALSE, 'admin/sql_error.tpl');
			$this->halt('Invalid SQL: '.$queryString, $line, $file);
		}

		// Will return nada if it fails. That's fine.
		return $this->queryId;
	}

	/* public: walk result set */

	/**
	 * Db::next_record()
	 * @param mixed $resultType
	 * @return bool
	 */
	public function next_record($resultType = MYSQL_ASSOC) {
		// PDO result types so far seem to be +1
		++$resultType;
		if (!$this->queryId) {
			$this->halt('next_record called with no query pending.');
			return 0;
		}

		++$this->Row;
		$this->Record = $this->Rows[$this->Row];

		$stat = is_array($this->Record);
		if (!$stat && $this->autoFree)
			$this->free();
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
	 * Initiates a transaction
	 * @return bool
	 */
	public function transactionBegin() {
		return $this->linkId->beginTransaction();
	}

	/**
	 * Commits a transaction
	 * @return bool
	 */
	public function transactionCommit() {
		return $this->linkId->commit();
	}

	/**
	 * Rolls back a transaction
	 * @return bool
	 */
	public function transactionAbort() {
		return $this->linkId->rollBack();
	}

	/**
	 * Db::getLastInsertId()
	 * @param mixed $table
	 * @param mixed $field
	 * @return int
	 */
	public function getLastInsertId($table, $field) {
		if (!isset($table) || $table == '' || !isset($field) || $field == '')
			return - 1;
		return $this->linkId->lastInsertId();
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
		* $res = @mysql_query($query, $this->linkId);
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
		return @$this->queryId->rowCount();
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

	/**
	 * @param mixed $msg
	 * @param string $line
	 * @param string $file
	 * @return mixed|void
	 */
	public function haltmsg($msg, $line = '', $file = '') {
		$this->log("Database error: $msg", $line, $file, 'error');
		if ($this->Errno != '0' || $this->Error != '()')
			$this->log('PDO MySQL Error: '.json_encode($this->linkId->errorInfo()), $line, $file, 'error');
		$this->logBackTrace($msg, $line, $file);
	}

	/**
	 * Db::tableNames()
	 *
	 * @return array
	 */
	public function tableNames() {
		$return = [];
		$this->query('SHOW TABLES');
		foreach ($this->Rows as $i => $info) {
			$return[$i]['table_name'] = $info[0];
			$return[$i]['tablespace_name'] = $this->database;
			$return[$i]['database'] = $this->database;
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
			$this->database = 'mysql';
		}
		$this->disconnect();
		$this->query("CREATE DATABASE $currentDatabase");
		$this->query("grant all on $currentDatabase.* to $currentUser@localhost identified by '{$currentPassword}'");
		$this->disconnect();

		$this->user = $currentUser;
		$this->password = $currentPassword;
		$this->database = $currentDatabase;
		$this->connect();
		/*return $return; */
	}

}
