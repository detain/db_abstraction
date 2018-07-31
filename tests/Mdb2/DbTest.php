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
		$this->db->connect();
		$this->assertEquals(1, $this->db->queryOne("select * from service_types limit 1", __LINE__, __FILE__));
		$this->assertEquals(0, $this->db->queryOne("select * from service_types where st_id=-1", __LINE__, __FILE__));
	}

	public function testQueryRow()
	{
		$this->db->connect();
		$row = $this->db->queryRow("select * from service_types limit 1", __LINE__, __FILE__);
		$this->assertTrue(is_array($row));
		$this->assertTrue(array_key_exists('st_id', $row));
		$this->assertEquals(0, $this->db->queryRow("select * from service_types where st_id=-1", __LINE__, __FILE__));
	}

	public function testLastInsertId()
	{
		$this->markTestIncomplete('This test has not been implemented yet.');
	}
}
