<?php
namespace MyDb\Tests\Pdo;

use MyDb\Pdo\Db;

/**
 * Generated by PHPUnit_SkeletonGenerator on 2017-08-11 at 04:02:15.
 */
class DbTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @var Db
	 */
	protected $object;

	/**
	 * Sets up the fixture, for example, opens a network connection.
	 * This method is called before a test is executed.
	 */
	protected function setUp()
	{
		$this->object = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));
	}

	/**
	 * Tears down the fixture, for example, closes a network connection.
	 * This method is called after a test is executed.
	 */
	protected function tearDown()
	{
	}

	/**
	 * @covers MyDb\Pdo\Db::log
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
	 * @covers MyDb\Mysqli\Db::use_db
	 */
	public function testUse_db()
	{
	$db = 'tests';
	$this->object->use_db($db);
	$this->object->query("select database()");
	$this->object->next_record(MYSQLI_NUM);
	$this->assertEquals($db, $this->object->Record[0]);
	}

	/**
	 * @covers MyDb\Mysqli\Db::select_db
	 */
	public function testSelect_db()
	{
	$db = 'tests';
	$this->object->use_db($db);
	$this->object->query("select database()");
	$this->object->next_record(MYSQLI_NUM);
	$this->assertEquals($db, $this->object->Record[0]);
	}

	/**
	 * @covers MyDb\Mysqli\Db::link_id
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
	 * @covers MyDb\Mysqli\Db::query_id
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
	 * @covers MyDb\Mysqli\Db::connect
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
	 * @covers MyDb\Mysqli\Db::disconnect
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
	 * @covers MyDb\Mysqli\Db::real_escape
	 */
	public function testReal_escape()
	{
	$string1 = 'hi there"dude';
	$string2 = $this->object->real_escape($string1);
	$this->assertNotEquals($string1, $string2);
	}

	/**
	 * @covers MyDb\Mysqli\Db::escape
	 */
	public function testEscape()
	{
	$string1 = 'hi there"dude';
	$string2 = $this->object->real_escape($string1);
	$this->assertNotEquals($string1, $string2);
	}

	/**
	 * @covers MyDb\Mysqli\Db::db_addslashes
	 */
	public function testDb_addslashes()
	{
	$string1 = 'hi there"dude';
	$string2 = $this->object->real_escape($string1);
	$this->assertNotEquals($string1, $string2);
	}

	/**
	 * @covers MyDb\Mysqli\Db::toTimestamp
	 */
	public function testTo_timestamp()
	{
	$t = 1502439626;
	$this->assertEquals($this->object->toTimestamp($t), '2017-08-11 04:20:26');
	}

	/**
	 * @covers MyDb\Mysqli\Db::fromTimestamp
	 */
	public function testFrom_timestamp()
	{
	$t = 1502439626;
	$this->assertEquals($this->object->fromTimestamp('2017-08-11 04:20:26'), $t);
	}

	/**
	 * @covers MyDb\Pdo\Db::limit
	 * @todo   Implement testLimit().
	 */
	public function testLimit()
	{
		// Remove the following lines when you implement this test.
		$this->markTestIncomplete(
			'This test has not been implemented yet.'
		);
	}

	/**
	 * @covers MyDb\Pdo\Db::free
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
	 * @covers MyDb\Pdo\Db::query_return
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
	 * @covers MyDb\Pdo\Db::qr
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
	 * @covers MyDb\Pdo\Db::query
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
	 * @covers MyDb\Pdo\Db::limit_query
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
	 * @covers MyDb\Pdo\Db::next_record
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
	 * @covers MyDb\Pdo\Db::seek
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
	 * @covers MyDb\Pdo\Db::transaction_begin
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
	 * @covers MyDb\Pdo\Db::transaction_commit
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
	 * @covers MyDb\Pdo\Db::transaction_abort
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
	 * @covers MyDb\Pdo\Db::getLastInsertId
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
	 * @covers MyDb\Pdo\Db::lock
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
	 * @covers MyDb\Pdo\Db::unlock
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
	 * @covers MyDb\Pdo\Db::affected_rows
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
	 * @covers MyDb\Pdo\Db::num_rows
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
	 * @covers MyDb\Pdo\Db::num_fields
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
	 * @covers MyDb\Pdo\Db::nf
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
	 * @covers MyDb\Pdo\Db::np
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
	 * @covers MyDb\Pdo\Db::f
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
	 * @covers MyDb\Pdo\Db::p
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
	 * @covers MyDb\Pdo\Db::nextid
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
	 * @covers MyDb\Pdo\Db::halt
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
	 * @covers MyDb\Pdo\Db::haltmsg
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
	 * @covers MyDb\Pdo\Db::table_names
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
	 * @covers MyDb\Pdo\Db::index_names
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
	 * @covers MyDb\Pdo\Db::createDatabase
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
