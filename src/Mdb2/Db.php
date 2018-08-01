<?php
/**
* MDB2 Wrapper Made To Handle Like Our Other Classes Related Functionality
* @author Joe Huss <detain@interserver.net>
* @copyright 2018
* @package MyAdmin
* @category SQL
*/

namespace MyDb\Mdb2;

use \MyDb\Generic;
use \MyDb\Mysqli\Db as MysqliDb;
use \MyDb\Db_Interface;

/**
 * Db
 *
 * @access public
 */
class Db extends MysqliDb implements Db_Interface {
	public $host = 'localhost';
	public $user = 'pdns';
	public $password = '';
	public $database = 'pdns';
	public $type = 'mdb2';
	public $error = false;
	public $message = '';

	/**
	 * Db::quote()
	 * @param string $text
	 * @param string $type
	 * @return string
	 */
	public function quote($text = '', $type = 'text') {
		switch ($type) {
			case 'text':
				return "'".$this->escape($text)."'";
				break;
			case 'integer':
				return (int) $text;
				break;
			default:
				return $text;
				break;
		}
	}

	/**
	 * Db::queryOne()
	 *
	 * @param mixed $query
	 * @param string $line
	 * @param string $file
	 * @return bool
	 */
	public function queryOne($query, $line = '', $file = '') {
		$this->query($query, $line, $file);
		if ($this->num_rows() > 0) {
			$this->next_record();
			return $this->f(0);
		} else
			return 0;
	}

	/**
	 * Db::queryRow()
	 *
	 * @param mixed $query
	 * @param string $line
	 * @param string $file
	 * @return array|bool
	 */
	public function queryRow($query, $line = '', $file = '') {
		$this->query($query, $line, $file);
		if ($this->num_rows() > 0) {
			$this->next_record();
			return $this->Record;
		} else
			return 0;
	}

	/**
	 * Db::lastInsertId()
	 * @param mixed $table
	 * @param mixed $field
	 * @return int
	 */
	public function lastInsertId($table, $field) {
		return $this->getLastInsertId($table, $field);
	}
}
