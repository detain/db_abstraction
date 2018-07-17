<?php
namespace MyDb\Tests\Mysqli;

use MyDb\Mysqli\Db;

class DbTest extends \PHPUnit\Framework\TestCase
{
	/**
	* @var \MyDB\Mysqli\Db
	*/
	protected $db;

	function __construct($name = null, array $data = array(), $dataName = '') {
		parent::__construct($name, $data, $dataName);
		$this->db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));;
	}    

	/**
	* Sets up the fixture, for example, opens a network connection. This method is called before a test is executed.
	*/
	protected function setUp() {
		$this->db->transaction_begin();
	}

	/**
	* Tears down the fixture, for example, closes a network connection. This method is called after a test is executed.
	*/
	protected function tearDown() {
		$this->db->transaction_abort();
	}

	public function testConnect() {
		$this->db->linkId = null;
		$this->db->connect();
		$this->assertTrue(is_object($this->db->linkId), 'connect sets the link id');
	}

	public function testLink_id() {
		$this->db->linkId = null;
		$this->assertEquals($this->db->linkId, $this->db->link_id(), 'link_id() returns the linkId variable');
		$this->db->connect();
		$this->assertEquals($this->db->linkId, $this->db->link_id(), 'link_id() returns the linkId variable');
	}

	public function testUse_db() {
		foreach (['tests', 'tests2', 'tests'] as $db) {
			$this->db->use_db($db);
			$this->db->query("select database()");
			$this->db->next_record(MYSQLI_NUM);
			$this->assertEquals($db, $this->db->Record[0], 'use_db properly changes database');
		}
	}

	public function testSelect_db() {
		foreach (['tests', 'tests2', 'tests'] as $db) {
			$this->db->use_db($db);
			$this->db->query("select database()");
			$this->db->next_record(MYSQLI_NUM);
			$this->assertEquals($db, $this->db->Record[0], 'use_db properly changes database');
		}
	}

	public function testReal_escape() {
		$string1 = 'hi there"dude';
		$string2 = $this->db->real_escape($string1);
		$this->assertNotEquals($string1, $string2);
	}

	public function testEscape() {
		$string1 = 'hi there"dude';
		$string2 = $this->db->real_escape($string1);
		$this->assertNotEquals($string1, $string2);
	}

	public function testDb_addslashes() {
		$string1 = 'hi there"dude';
		$string2 = $this->db->real_escape($string1);
		$this->assertNotEquals($string1, $string2);
	}

	public function testTo_timestamp() {
		$t = 1502439626;
		$this->assertEquals($this->db->toTimestamp($t), '2017-08-11 04:20:26');
	}

	public function testFrom_timestamp() {
		$t = 1502439626;
		$this->assertEquals($this->db->fromTimestamp('2017-08-11 04:20:26'), $t);
	}

	public function testQuery_id() {
		$this->assertEquals($this->db->queryId, $this->db->query_id(), 'query_id() returns the queryId variable');
	}

	public function testQuery() {
		$this->db->query("select * from service_types");
		$this->assertEquals(37, $this->db->num_rows(), 'num_rows Returns proper number of rows');
		$this->db->next_record(MYSQL_ASSOC);
		$this->assertTrue(array_key_exists('st_it', $this->db->Record));
	}

	public function testTable_names() {
		$tables = $this->db->table_names();
		$this->assertTrue(is_array($tables), 'table_names returns array');
		$this->assertEquals(1, count($tables), 'table_names returns array');
		$this->assertEquals('service_types', $tables[0]['table_name'], 'table_names returns proper entries');
	}

	public function testLog() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testDisconnect() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testLimit() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testFree() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testQuery_return() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testQr() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testPrepare() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testLimit_query() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testFetch_object() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testSeek() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testTransaction_begin() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testTransaction_commit() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testTransaction_abort() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testGet_last_insert_id() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testLock() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testUnlock() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testAffected_rows() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testNum_fields() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testNf() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testNp() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testF() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testP() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testNextid() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testHalt() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testHaltmsg() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testIndex_names() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testCreateDatabase() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}
}
