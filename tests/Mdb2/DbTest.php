<?php

namespace MyDb\Tests\Mdb2;

use MyDb\Mdb2\Db;

class DbTest extends \PHPUnit\Framework\TestCase
{
    /**
    * @var MyDb\Mdb2\Db
    */
    protected $db;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));
        ;
    }

    protected function setUp(): void
    {
        $this->db->transactionBegin();
    }

    protected function tearDown(): void
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
        $this->db->query("insert into service_types values (NULL, 'Test', 2, 'vps')", __LINE__, __FILE__);
        $id = $this->db->lastInsertId('service_types', 'st_id');
        $this->assertTrue(is_int($id));
        $this->assertFalse($id === false);
    }
}
