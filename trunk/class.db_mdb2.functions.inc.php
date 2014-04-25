<?php
	/************************************************************************************\
	* Trouble Free CPanel/VPS Services                                                   *
	* (c)2010 Interserver                                                                *
	\************************************************************************************/

class db_mdb2_result
{
	var $query;
	var $result;
	var $error = false;
	var $message = '';

	function db_mdb2_result($query)
	{
		$this->query = $query;
		$this->result = mysql_query($query);
		if (mysql_errno()) {
			$this->message = "MySQL error ".mysql_errno().": ".mysql_error() . ' Query: ' . $this->query;
			$this->error = true;
		}
	}

	function numRows()
	{
		return mysql_num_rows($this->result);
	}

	function fetchRow()
	{
		return mysql_fetch_array($this->result);
	}

	function getMessage()
	{
		return $this->message;
	}
}

class db_mdb2
{
	var $db_host        = '209.159.155.29';
	var $db_user        = 'poweradmin';
	var $db_pass        = 'c0mpt0n1337';
	var $db_name        = 'poweradmin';
	var $db_type        = 'mysql';

	var $iface_lang     = 'en_EN';

	var $dns_hostmaster     = 'hostmaster.interserver.net';
	var $dns_ns1        = 'cdns1.interserver.net';
	var $dns_ns2        = 'cdns2.interserver.net';

	var $dbh			= false;

	function db_mdb2()
	{
		$this->dbh = mysql_connect($this->db_host, $this->db_user, $this->db_pass);
		mysql_select_db($this->db_name, $this->dbh);
	}

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

function query($query)
{
	return new db_mdb2_result($query);
}

function lastInsertId($table, $field)
{
	return mysql_insert_id();
}

function disconnect()
{
	mysql_close();
}

}


?>
