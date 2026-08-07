<?php

namespace MyDb\Tests;

use MyDb\Mysqli\Db as MysqliDb;
use MyDb\Mdb2\Db as Mdb2Db;
use MyDb\Pdo\Db as PdoDb;
use MyDb\Pgsql\Db as PgsqlDb;
use MyDb\Tests\Mysqli\OpenLink;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Mysqli/DisconnectTest.php';

/**
* Cloning a db object is how callers get a handle they can drive independently,
* so the copy has to come with a connection of its own rather than the
* original's.
*/
class CloneTest extends TestCase
{
	/**
	* @return array
	*/
	public function independentDriverProvider()
	{
		return [
			'mysqli' => [MysqliDb::class],
			'mdb2' => [Mdb2Db::class],
			'pgsql' => [PgsqlDb::class],
		];
	}

	/**
	* @dataProvider independentDriverProvider
	* @param string $class
	*/
	public function testCloneStartsWithNoConnection($class)
	{
		$db = new $class();
		$db->linkId = new OpenLink();
		$db->queryId = new OpenLink();

		$clone = clone $db;

		$this->assertSame(0, $clone->linkId, 'the clone must not share the connection');
		$this->assertSame(0, $clone->queryId, 'the clone must not share the result cursor');
	}

	/**
	* @dataProvider independentDriverProvider
	* @param string $class
	*/
	public function testCloneKeepsTheConnectionSettings($class)
	{
		$db = new $class('test_db', 'test_user', 'test_password', 'db.example.org', '', '3307');
		$db->characterSet = 'latin1';

		$clone = clone $db;

		$this->assertSame('db.example.org', $clone->host);
		$this->assertSame('test_user', $clone->user);
		$this->assertSame('test_password', $clone->password);
		$this->assertSame('test_db', $clone->database);
		$this->assertSame('3307', $clone->port);
		$this->assertSame('latin1', $clone->characterSet);
	}

	public function testCloneDoesNotTouchTheOriginalsConnection()
	{
		$db = new MysqliDb();
		$link = new OpenLink();
		$db->linkId = $link;

		$clone = clone $db;
		$clone->disconnect();

		$this->assertSame($link, $db->linkId, 'the original keeps its connection');
		$this->assertSame(0, $link->closeCalls, 'and nobody closed it');
	}

	/**
	* the crash this all started from: cloning a db per host, then disconnecting
	* each clone so it reconnects to the host it was pointed at.
	*/
	public function testCloningPerHostAndDisconnectingEachIsNotFatal()
	{
		$db = new MysqliDb();
		$link = new OpenLink();
		$db->linkId = $link;

		foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $host) {
			$clone = clone $db;
			$clone->host = $host;
			$clone->disconnect();

			$this->assertSame($host, $clone->host);
			$this->assertSame(0, $clone->linkId);
		}

		$this->assertSame($link, $db->linkId);
		$this->assertSame(0, $link->closeCalls);
	}

	public function testCloneDoesNotInheritResultState()
	{
		$db = new MysqliDb();
		$db->Record = ['id' => 7];
		$db->Row = 3;
		$db->Errno = 1064;
		$db->Error = 'You have an error in your SQL syntax';

		$clone = clone $db;

		$this->assertSame([], $clone->Record);
		$this->assertSame(0, $clone->Row);
		$this->assertSame(0, $clone->Errno);
		$this->assertSame('', $clone->Error);
	}

	public function testCloneDoesNotInheritAnOpenTransaction()
	{
		$db = new MysqliDb();
		$reflection = new \ReflectionProperty(\MyDb\Generic::class, 'inTransaction');
		$reflection->setAccessible(true);
		$reflection->setValue($db, true);

		$clone = clone $db;

		$this->assertTrue($db->inTransaction(), 'the original is still in its transaction');
		$this->assertFalse($clone->inTransaction(), 'the clone is on another connection, so it is not');
	}

	public function testCloneDoesNotInheritTheQueryLog()
	{
		$db = new MysqliDb();
		$db->addLog('select 1', 0.01, __LINE__, __FILE__);
		$clone = clone $db;

		$this->assertNotEmpty($db->getLog());
		$this->assertSame([], $clone->getLog(), 'the clone has not run any queries yet');
	}

	public function testCloneDoesNotInheritAPreparedStatement()
	{
		$db = new MysqliDb();
		$db->statement = new OpenLink();
		$db->statement_query = 'select ?';

		$clone = clone $db;

		$this->assertNull($clone->statement, 'a mysqli_stmt belongs to the connection that prepared it');
		$this->assertNull($clone->statement_query);
	}

	public function testCloneDoesNotInheritTheConnectFailureCount()
	{
		$db = new MysqliDb();
		$db->connectionAttempt = 4;

		$clone = clone $db;

		$this->assertSame(0, $clone->connectionAttempt);
	}

	/**
	* pdo and adodb connect() only reopen when linkId is exactly false, so their
	* clones keep sharing until that is fixed -- pinned here so the difference
	* is deliberate rather than a surprise.
	*/
	public function testPdoCloneStillSharesTheConnection()
	{
		$db = new PdoDb();
		$link = new OpenLink();
		$db->linkId = $link;

		$clone = clone $db;

		$this->assertSame($link, $clone->linkId);
	}
}
