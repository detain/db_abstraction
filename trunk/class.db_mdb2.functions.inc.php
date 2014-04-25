<?php
/**
* MDB2 Wrapper Made To Handle Liike Our Other ClasssesRelated Functionality
* @author Joe Huss <detain@corpmail.interserver.net>
* @package SQL
* @subpackage MDB2
* @copyright 2010
*/

/**
 * db_mdb2_result
 * 
 * @package   
 * @author cpaneldirect
 * @copyright Owner
 * @version 2011
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
	function db_mdb2_result($query)
	{
		$this->query = $query;
		$this->result = mysql_query($query);
		if (mysql_errno()) {
			$this->message = "MySQL error ".mysql_errno().": ".mysql_error() . ' Query: ' . $this->query;
			$this->error = true;
		}
	}

	/**
	 * db_mdb2_result::numRows()
	 * 
	 * @return
	 */
	function numRows()
	{
		return mysql_num_rows($this->result);
	}

	/**
	 * db_mdb2_result::fetchRow()
	 * 
	 * @return
	 */
	function fetchRow()
	{
		return mysql_fetch_array($this->result);
	}

	/**
	 * db_mdb2_result::getMessage()
	 * 
	 * @return
	 */
	function getMessage()
	{
		return $this->message;
	}
}

/**
 * db_mdb2
 * 
 * @package   
 * @author cpaneldirect
 * @copyright Owner
 * @version 2011
 * @access public
 */
class db_mdb2
{
	public $db_host        = '209.159.155.29';
	public $db_user        = 'poweradmin';
	public $db_pass        = 'p0w3r4dm1n';
	public $db_name        = 'poweradmin';
	public $db_type        = 'mysql';

	public $iface_lang     = 'en_EN';

	public $dns_hostmaster     = 'hostmaster.interserver.net';
	public $dns_ns1        = 'cdns1.interserver.net';
	public $dns_ns2        = 'cdns2.interserver.net';

	public $dbh			= false;

	/**
	 * db_mdb2::db_mdb2()
	 * 
	 * @return
	 */
	function db_mdb2()
	{
		$this->dbh = mysql_connect($this->db_host, $this->db_user, $this->db_pass);
		mysql_select_db($this->db_name, $this->dbh);
	}

	/**
	 * db_mdb2::quote()
	 * 
	 * @param string $text
	 * @param string $type
	 * @return
	 */
	function quote($text = '', $type = 'text')
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
function queryOne($query)
{
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
function queryRow($query)
{
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
 * db_mdb2::query()
 * 
 * @param mixed $query
 * @return
 */
function query($query)
{
	return new db_mdb2_result($query);
}

/**
 * db_mdb2::lastInsertId()
 * 
 * @param mixed $table
 * @param mixed $field
 * @return
 */
function lastInsertId($table, $field)
{
	return mysql_insert_id();
}

/**
 * db_mdb2::disconnect()
 * 
 * @return
 */
function disconnect()
{
	mysql_close();
}

}


?>
