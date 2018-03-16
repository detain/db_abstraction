<?php
/**
	* Generic SQL Driver Related Functionality
	* by detani@interserver.net
	* @copyright 2018
	* @package MyAdmin
	* @category SQL
	*/

namespace MyDb;
/**
 * Interface Db_Interface
 *
 * @package MyDb
 */
interface Db_Interface
{

	/**
	 * Db_Interface constructor.
	 *
	 * @param string $database
	 * @param string $user
	 * @param string $password
	 * @param string $host
	 * @param string $query
	 */
	public function __construct($database = '', $user = '', $password = '', $host = 'localhost', $query = '');

	/**
	 * @param $message
	 * @param string $line
	 * @param string $file
	 * @return mixed
	 */
	public function log($message, $line = '', $file = '');

	public function link_id();

	public function query_id();

	/**
	 * @param $str
	 * @return mixed
	 */
	public function db_addslashes($str);

	/**
	 * @param $query
	 * @param string $line
	 * @param string $file
	 * @return mixed
	 */
	public function qr($query, $line = '', $file = '');

	/**
	 * @param $msg
	 * @param string $line
	 * @param string $file
	 * @return mixed
	 */
	public function halt($msg, $line = '', $file = '');

	/**
	 * @param $msg
	 * @return mixed
	 */
	public function haltmsg($msg);

	public function index_names();

}

