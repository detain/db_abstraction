<?php
namespace MyDb\Tests;

use MyDb\Generic;
use MyDb\Mysqli\Db;

class GenericTest extends \PHPUnit\Framework\TestCase
{
	/**
	* @var \MyDB\Mysqli\Db
	*/
	protected $db;

	public function __construct($name = null, array $data = array(), $dataName = '')
	{
		parent::__construct($name, $data, $dataName);
		$this->db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));
		;
		$this->db->Debug = 1;
	}

	/**
	* Sets up the fixture, for example, opens a network connection. This method is called before a test is executed.
	*/
	protected function setUp()
	{
		if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
			$this->db->transactionBegin();
		}
	}

	/**
	* Tears down the fixture, for example, closes a network connection. This method is called after a test is executed.
	*/
	protected function tearDown()
	{
		if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
			$this->db->transactionAbort();
		}
	}

	public function testLink_id()
	{
		$this->db->linkId = 0;
		$this->assertEquals($this->db->linkId, $this->db->linkId(), 'linkId() returns the linkId variable');
		$this->db->connect();
		$this->assertEquals($this->db->linkId, $this->db->linkId(), 'linkId() returns the linkId variable');
	}

	public function testEscaping()
	{
		$oldId = $this->db->linkId;
		$this->db->linkId = 0;
		$string1 = 'hi there"dude';
		$string3 = 'hi there\"dude';
		$oldId = $this->db->linkId;
		$this->db->linkId = 0;
		$string2 = $this->db->real_escape($string1);
		$this->assertEquals($string3, $string2);
		$this->db->linkId = $oldId;
		$string2 = $this->db->real_escape($string1);
		$this->assertEquals($string3, $string2);
		$string2 = $this->db->escape($string1);
		$this->assertEquals($string3, $string2);
		$string2 = $this->db->dbAddslashes($string1);
		$this->assertEquals($string3, $string2);
		$string2 = $this->db->dbAddslashes();
		$this->assertEquals('', $string2);
	}

	public function testTo_timestamp()
	{
		$t = 1502439626;
		$this->assertEquals($this->db->toTimestamp($t), '2017-08-11 04:20:26');
	}

	public function testFrom_timestamp()
	{
		$t = 1502439626;
		$this->assertEquals($this->db->fromTimestamp('2017-08-11 04:20:26'), $t);
	}

	public function testLog()
	{
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testLimit()
	{
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testHalt()
	{
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testHaltmsg()
	{
		$this->markTestIncomplete('This test has not been implemented yet.');
	}
}
