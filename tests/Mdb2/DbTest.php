<?php
namespace MyDb\Tests\Mdb2;

use MyDb\Mdb2\Db;

class DbTest extends \PHPUnit\Framework\TestCase
{
	/**
	* @var MyDb\Mdb2\Db
	*/
	protected $db;

	function __construct($name = null, array $data = array(), $dataName = '')
	{
		parent::__construct($name, $data, $dataName);
		$this->db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));;
	}    

	protected function setUp()
	{
		$this->db->transactionBegin();
	}

	protected function tearDown()
	{
		$this->db->transactionAbort();
	}

	public function testQuote()
	{
		$this->assertEquals("'hi'", $this->db->quote('hi', 'text'));
		$this->assertEquals(3, $this->db->quote('3', 'integer'));
		$this->assertFalse($this->db->quote(false, 'bool'));
	}

	public function testQueryOne()
	{
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testQueryRow()
	{
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testLastInsertId()
	{
		$this->markTestIncomplete('This test has not been implemented yet.');
	}
}
