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
		$this->db->Debug = 1;
	}    

	/**
	* Sets up the fixture, for example, opens a network connection. This method is called before a test is executed.
	*/
	protected function setUp() {
		if (version_compare(PHP_VERSION, '5.5.0') >= 0)
			$this->db->transactionBegin();
	}

	/**
	* Tears down the fixture, for example, closes a network connection. This method is called after a test is executed.
	*/
	protected function tearDown() {
		if (version_compare(PHP_VERSION, '5.5.0') >= 0)
			$this->db->transactionAbort();
	}

	public function testConnect() {
		$this->db->linkId = 0;
		$this->db->connect();
		$this->assertTrue(is_object($this->db->linkId), 'connect sets the link id');
	}

	public function testLink_id() {
		$this->db->linkId = 0;
		$this->assertEquals($this->db->linkId, $this->db->linkId(), 'linkId() returns the linkId variable');
		$this->db->connect();
		$this->assertEquals($this->db->linkId, $this->db->linkId(), 'linkId() returns the linkId variable');
	}

	public function testUseDb() {
		foreach (['tests', 'tests2', 'tests'] as $db) {
			$this->db->useDb($db);
			$this->db->query("select database()");
			$this->db->next_record(MYSQLI_NUM);
			$this->assertEquals($db, $this->db->Record[0], 'useDb properly changes database');
		}
		foreach (['tests', 'tests2', 'tests'] as $db) {
			$this->db->selectDb($db);
			$this->db->query("select database()");
			$this->db->next_record(MYSQLI_NUM);
			$this->assertEquals($db, $this->db->Record[0], 'useDb properly changes database');
		}
	}

	public function testEscaping() {
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

	public function testTo_timestamp() {
		$t = 1502439626;
		$this->assertEquals($this->db->toTimestamp($t), '2017-08-11 04:20:26');
	}

	public function testFrom_timestamp() {
		$t = 1502439626;
		$this->assertEquals($this->db->fromTimestamp('2017-08-11 04:20:26'), $t);
	}

	public function testQuery_id() {
		$this->assertEquals($this->db->queryId, $this->db->queryId(), 'queryId() returns the queryId variable');
	}

	public function testQuery() {
		$this->db->query("select * from service_types");
		$this->assertEquals(4, $this->db->num_fields(), 'num_fields Returns proper number of rows');
		$old = $this->db->Record;
		$this->db->next_record(MYSQLI_ASSOC);
		$this->assertNotEquals($old, $this->db->Record);
		$this->assertTrue(array_key_exists('st_id', $this->db->Record));
		$this->assertEquals($this->db->f('st_id'), $this->db->Record['st_id']);
		$this->db->query("select * from service_types");
		$this->assertTrue(is_object($this->db->fetchObject()));
		$this->db->query("select * from service_types");
		$this->assertEquals(0, $this->db->query(""));
		//$this->db->query("select * from service_types where", __LINE__, __FILE__);
	}

	public function testTable_names() {
		$tables = $this->db->tableNames();
		$this->assertTrue(is_array($tables), 'tableNames returns array');
		$this->assertEquals(1, count($tables), 'tableNames returns array');
		$this->assertEquals('service_types', $tables[0]['table_name'], 'tableNames returns proper entries');
	}

	public function testQuery_return() {
		$return = $this->db->queryReturn("select * from service_types limit 1");
		$this->assertTrue(is_array($return));
		$this->assertTrue(array_key_exists('st_id', $return));
		$return = $this->db->queryReturn("select * from service_types where st_id=-1 limit 1");
		$this->assertFalse($return);
		$return = $this->db->queryReturn("select * from service_types limit 5");
		$this->assertTrue(is_array($return));
		$this->assertTrue(is_array($return[0]));
		$this->assertTrue(array_key_exists('st_id', $return[0]));
		$return = $this->db->qr("select * from service_types limit 1");
		$this->assertTrue(is_array($return));
		$this->assertTrue(array_key_exists('st_id', $return));
	}

	public function testLog() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testDisconnect() {
		if (is_resource($this->db->linkId)) {
			$return = $this->db->disconnect();
			$this->assertTrue($return);
			$this->db->connect();
		}
	}

	public function testLimit() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testFree() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testPrepare() {
		$return = $this->db->prepare("select * from service_types where st_name = ?");
		$this->assertTrue(is_object($return));
	}

	public function testLimit_query() {
		$this->db->limitQuery("select * from service_types", 1);
		$this->assertEquals(1, $this->db->num_rows());
		$this->db->next_record(MYSQLI_ASSOC);
		$id = $this->db->Record['st_id'];
		$this->db->limitQuery("select * from service_types", 2, 1);
		$this->db->next_record(MYSQLI_ASSOC);
		$this->assertNotEquals($id, $this->db->Record['st_id']);
		$this->db->free();
		$this->assertEquals(0, $this->db->queryId);
	}

	public function testFetch_object() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testSeek() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testTransactions() {
/*		if (version_compare(PHP_VERSION, '5.5.0') < 0) {
			$this->assertTrue($this->db->transactionBegin(), 'transactionBegin returns proper response');;
			$this->assertTrue($this->db->transactionCommit(), 'transactionBegin returns proper response');;
			$this->assertTrue($this->db->transactionAbort(), 'transactionBegin returns proper response');;
		} else {
			$this->db->query("update services_types set st_name='KVM Windows 2' where st_name='KVM Windows'", __LINE__, __FILE__);
			$this->assertTrue($this->db->transactionCommit(), 'transactionBegin returns proper response');
			$this->db->query("select * from service_types where st_name='KVM Windows 2'", __LINE__, __FILE__);
			$this->assertEquals(1, $this->db->num_rows());
			$this->db->query("update services_types set st_name='KVM Windows' where st_name='KVM Windows 2'", __LINE__, __FILE__);
			$this->assertEquals(1, $this->db->affectedRows(), 'affected_rows() returns the proper effected row count after an update');
			$this->assertTrue($this->db->transactionBegin(), 'transactionBegin returns proper response');;
			$this->db->query("update services_types set st_name='KVM Windows 2' where st_name='KVM Windows'", __LINE__, __FILE__);
			$this->assertTrue($this->db->transactionAbort(), 'transactionBegin returns proper response');;
			$this->db->query("select * from service_types where st_name='KVM Windows 2'", __LINE__, __FILE__);
			$this->assertEquals(0, $this->db->num_rows());
			$this->assertTrue($this->db->transactionBegin(), 'transactionBegin returns proper response');;
		}
*/
	}

	public function testGet_last_insert_id() {
		$this->markTestIncomplete('This test has not been implemented yet.');
	}

	public function testLock() {
		$this->assertTrue($this->db->lock('service_types'));
		$this->assertTrue($this->db->unlock());
		$this->assertTrue($this->db->lock([['read', 'service_types']]));
		$this->assertTrue($this->db->unlock());
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

	public function testIndexNames() {
		$names = $this->db->indexNames();
		$this->assertTrue(is_array($names), 'indexNames() returns an array of indexes');
		$this->assertEquals(0, count($names), 'MySQLi indexNames() returns empty array');
	}
}
