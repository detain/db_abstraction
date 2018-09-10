<?php
	/**
	 * Generic SQL Driver Related Functionality
	 * @author Joe Huss <detain@interserver.net>
	 * @copyright 2018
	 * @package MyAdmin
	 * @category SQL
	 */

namespace MyDb;

/**
 * Class Loader
 *
 * @package MyDb
 */
class Loader
{
	/* public: connection parameters */
	public $Type = 'mysqli';
	public $host = 'localhost';
	public $database = '';
	public $user = '';
	public $password = '';

	/* public: configuration parameters */
	public $autoStripslashes = false;
	public $Debug = 0; // Set to 1 for debugging messages.
	public $haltOnError = 'yes'; // "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)

	/* public: result array and current row number */
	public $Record = [];
	public $Row;

	/* public: current error number and error text */
	public $Errno = 0;
	public $Error = '';

	public $type;

	/* private: link and query handles */
	public $linkId = 0;
	public $queryId = 0;

	public $characterSet = '';
	public $collation = '';

	/**
	 * Constructs the db handler, can optionally specify connection parameters
	 *
	 * @param string $Type Optional The database type mysql/mysqli/pdo/adodb/pgsql
	 * @param string $database Optional The database name
	 * @param string $user Optional The username to connect with
	 * @param string $password Optional The password to use
	 * @param string $host Optional The hostname where the server is, or default to localhost
	 * @param string $query Optional query to perform immediately
	 */
	public function __construct($Type = '', $database = '', $user = '', $password = '', $host = 'localhost', $query = '')
	{
		$this->Type = $Type;
		if (!defined('db')) {
			switch ($this->Type) {
				case 'mysqli':
					include_once 'class.db_mysqli.inc.php';
					break;
				case 'mysql':
					include_once 'class.db_mysql.inc.php';
					break;
				case 'adodb':
					include_once 'class.db_adodb.inc.php';
					break;
				case 'mdb2':
					include_once 'class.db_mdb2.inc.php';
					break;
				case 'pdo':
					include_once 'class.db_pdo.inc.php';
					break;
				case 'pgsql':
					include_once 'class.db_pgsql.inc.php';
					break;
				default:
					$this->log('Could not find DB class '.$this->Type, __LINE__, __FILE__);
					break;
			}
		}
		$this->database = $database;
		$this->user = $user;
		$this->password = $password;
		$this->host = $host;
		if ($query != '') {
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
		//if (function_exists('myadmin_log'))
		//myadmin_log('db', 'info', $message, $line, $file, FALSE);
		//else
		error_log($message);
	}

	/**
	 * @return int
	 */
	public function linkId()
	{
		return $this->linkId;
	}

	/**
	 * @return int
	 */
	public function queryId()
	{
		return $this->queryId;
	}

	/**
	 * @param $str
	 * @return string
	 */
	public function dbAddslashes($str)
	{
		if (!isset($str) || $str == '') {
			return '';
		}

		return addslashes($str);
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
	public function qr($query, $line = '', $file = '')
	{
		return $this->queryReturn($query, $line, $file);
	}

	/**
	 * error handling
	 *
	 * @param mixed $msg
	 * @param string $line
	 * @param string $file
	 * @return void
	 */
	public function halt($msg, $line = '', $file = '')
	{
		$this->unlock(false);

		if ($this->haltOnError == 'no') {
			return;
		}
		$this->haltmsg($msg);

		if ($file) {
			error_log("File: $file");
		}
		if ($line) {
			error_log("Line: $line");
		}
		if ($this->haltOnError != 'report') {
			echo '<p><b>Session halted.</b>';
			// FIXME! Add check for error levels
			if (isset($GLOBALS['tf'])) {
				$GLOBALS['tf']->terminate();
			}
		}
	}

	/**
	 * @param $msg
	 */
	public function haltmsg($msg)
	{
		$this->log("Database error: $msg", __LINE__, __FILE__);
		if ($this->Errno != '0' || $this->Error != '()') {
			$this->log('SQL Error: '.$this->Errno.' ('.$this->Error.')', __LINE__, __FILE__);
		}
	}

	/**
	 * @return array
	 */
	public function indexNames()
	{
		return [];
	}
}
