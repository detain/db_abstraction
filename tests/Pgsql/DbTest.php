<?php
namespace MyDb\Tests\Pgsql;

use MyDb\Pgsql\Db;

/**
* Generated by PHPUnit_SkeletonGenerator on 2017-08-11 at 04:02:14.
*/
class DbTest extends \PHPUnit\Framework\TestCase
{
	/**
	* @var Db
	*/
	protected $db;

	function __construct($name = null, array $data = array(), $dataName = '')
	{
		parent::__construct($name, $data, $dataName);
		$this->db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));;
	}    

	/**
	* Sets up the fixture, for example, opens a network connection.
	* This method is called before a test is executed.
	*/
	protected function setUp()
	{
		$this->db->transactionBegin();
	}

	/**
	* Tears down the fixture, for example, closes a network connection.
	* This method is called after a test is executed.
	*/
	protected function tearDown()
	{
		$this->db->transactionAbort();
	}


	/**
	* @todo   Implement testIfadd().
	*/
	public function testIfadd()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testLink_id().
	*/
	public function testLink_id()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testQuery_id().
	*/
	public function testQuery_id()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testLog().
	*/
	public function testLog()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testConnect().
	*/
	public function testConnect()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testDisconnect().
	*/
	public function testDisconnect()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	*/
	public function testReal_escape()
	{
		$string1 = 'hi there"dude';
		$string2 = $this->db->real_escape($string1);
		$this->assertNotEquals($string1, $string2);
	}

	/**
	*/
	public function testEscape()
	{
		$string1 = 'hi there"dude';
		$string2 = $this->db->real_escape($string1);
		$this->assertNotEquals($string1, $string2);
	}

	/**
	*/
	public function testDb_addslashes()
	{
		$string1 = 'hi there"dude';
		$string2 = $this->db->real_escape($string1);
		$this->assertNotEquals($string1, $string2);
	}

	/**
	*/
	public function testTo_timestamp()
	{
		$t = 1502439626;
		$this->assertEquals($this->db->toTimestamp($t), '2017-08-11 04:20:26');
	}

	/**
	*/
	public function testFrom_timestamp()
	{
		$t = 1502439626;
		$this->assertEquals($this->db->fromTimestamp('2017-08-11 04:20:26'), $t);
	}

	/**
	* @todo   Implement testTo_timestamp_6().
	*/
	public function testTo_timestamp_6()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testFrom_timestamp_6().
	*/
	public function testFrom_timestamp_6()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testTo_timestamp_7().
	*/
	public function testTo_timestamp_7()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testFrom_timestamp_7().
	*/
	public function testFrom_timestamp_7()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testQuery_return().
	*/
	public function testQuery_return()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testQr().
	*/
	public function testQr()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testQuery().
	*/
	public function testQuery()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testLimit_query().
	*/
	public function testLimit_query()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testFree().
	*/
	public function testFree()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testNext_record().
	*/
	public function testNext_record()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testSeek().
	*/
	public function testSeek()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testTransaction_begin().
	*/
	public function testTransaction_begin()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testTransaction_commit().
	*/
	public function testTransaction_commit()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testTransaction_abort().
	*/
	public function testTransaction_abort()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testGet_last_insert_id().
	*/
	public function testGet_last_insert_id()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testLock().
	*/
	public function testLock()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testUnlock().
	*/
	public function testUnlock()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testNextid().
	*/
	public function testNextid()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testAffected_rows().
	*/
	public function testAffected_rows()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testNum_rows().
	*/
	public function testNum_rows()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testNum_fields().
	*/
	public function testNum_fields()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testNf().
	*/
	public function testNf()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testNp().
	*/
	public function testNp()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testF().
	*/
	public function testF()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testP().
	*/
	public function testP()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testHalt().
	*/
	public function testHalt()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testHaltmsg().
	*/
	public function testHaltmsg()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testTable_names().
	*/
	public function testTable_names()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testIndex_names().
	*/
	public function testIndex_names()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	* @todo   Implement testCreateDatabase().
	*/
	public function testCreateDatabase()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}
}
