<?php
	/**
	* Generic SQL Driver Related Functionality
	* by detani@interserver.net
	* Last Changed: $LastChangedDate$
	* @copyright 2017
	* @package MyAdmin
	* @category SQL
	*/

namespace MyDb;


	interface Db_Interface
	{

		/**
		 * Db_Interface constructor.
		 *
		 * @param string $Database
		 * @param string $User
		 * @param string $Password
		 * @param string $Host
		 * @param string $query
		 */
		public function __construct($Database = '', $User = '', $Password = '', $Host = 'localhost', $query = '');

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

