<?php

namespace MyDb\Tests;

use MyDb\Mysqli\Db as MysqliDb;
use MyDb\Mdb2\Db as Mdb2Db;
use MyDb\Pdo\Db as PdoDb;
use MyDb\Pgsql\Db as PgsqlDb;
use PHPUnit\Framework\TestCase;

/**
* stands in for a live connection handle.
*/
class FakeLink
{
	public $closeCalls = 0;

	public function close()
	{
		$this->closeCalls++;
		return true;
	}
}

/**
* `clone $db` shares the original's connection, which is what makes it a cheap
* way to get a second cursor. detach() and newConnection() are how a copy gets
* a connection of its own instead.
*/
class DetachTest extends TestCase
{
	/**
	* @return array
	*/
	public function driverProvider()
	{
		return [
			'mysqli' => [MysqliDb::class],
			'mdb2' => [Mdb2Db::class],
			'pgsql' => [PgsqlDb::class],
			'pdo' => [PdoDb::class],
		];
	}

	/**
	* @dataProvider driverProvider
	* @param string $class
	*/
	public function testCloneSharesTheConnection($class)
	{
		$db = new $class();
		$link = new FakeLink();
		$db->linkId = $link;

		$clone = clone $db;

		$this->assertSame($link, $clone->linkId, 'a plain clone keeps sharing the connection');
	}

	public function testDetachGivesUpTheHandleWithoutClosingIt()
	{
		$db = new MysqliDb();
		$link = new FakeLink();
		$db->linkId = $link;
		$db->queryId = new FakeLink();
		$clone = clone $db;

		$clone->detach();

		$this->assertSame(0, $clone->linkId);
		$this->assertSame(0, $clone->queryId);
		$this->assertSame(0, $link->closeCalls, 'the connection is still open for everyone else');
		$this->assertSame($link, $db->linkId, 'including the original');
	}

	public function testDetachDropsThePreparedStatement()
	{
		$db = new MysqliDb();
		$db->linkId = new FakeLink();
		$db->statement = new FakeLink();
		$db->statement_query = 'select ?';
		$clone = clone $db;

		$clone->detach();

		$this->assertNull($clone->statement, 'a mysqli_stmt belongs to the connection that prepared it');
		$this->assertNull($clone->statement_query);
		$this->assertNotNull($db->statement, 'the original keeps its own');
	}

	public function testDetachClearsAnInheritedTransactionFlag()
	{
		$db = new MysqliDb();
		$db->linkId = new FakeLink();
		$reflection = new \ReflectionProperty(\MyDb\Generic::class, 'inTransaction');
		$reflection->setAccessible(true);
		$reflection->setValue($db, true);

		$clone = clone $db;
		$this->assertTrue($clone->inTransaction(), 'while sharing the connection it shares the transaction');

		$clone->detach();
		$this->assertFalse($clone->inTransaction(), 'once detached that transaction is not ours');
		$this->assertTrue($db->inTransaction(), 'the original is still in it');
	}

	public function testNewConnectionStartsUnconnected()
	{
		$db = new MysqliDb();
		$link = new FakeLink();
		$db->linkId = $link;

		$own = $db->newConnection();

		$this->assertInstanceOf(MysqliDb::class, $own);
		$this->assertSame(0, $own->linkId, 'it connects lazily, on its first query');
		$this->assertSame($link, $db->linkId, 'the original is untouched');
		$this->assertSame(0, $link->closeCalls);
	}

	public function testNewConnectionKeepsTheConnectionSettings()
	{
		$db = new MysqliDb('test_db', 'test_user', 'test_password', 'db.example.org', '', '3307');
		$db->characterSet = 'latin1';

		$own = $db->newConnection();

		$this->assertSame('db.example.org', $own->host);
		$this->assertSame('test_user', $own->user);
		$this->assertSame('test_password', $own->password);
		$this->assertSame('test_db', $own->database);
		$this->assertSame('3307', $own->port);
		$this->assertSame('latin1', $own->characterSet);
	}

	public function testNewConnectionCarriesNoConnectionState()
	{
		$db = new MysqliDb();
		$db->linkId = new FakeLink();
		$db->Record = ['id' => 7];
		$db->Row = 3;
		$db->Errno = 1064;
		$db->Error = 'You have an error in your SQL syntax';
		$db->connectionAttempt = 4;
		$db->addLog('select 1', 0.01, __LINE__, __FILE__);

		$own = $db->newConnection();

		$this->assertSame([], $own->Record);
		$this->assertSame(0, $own->Row);
		$this->assertSame(0, $own->Errno);
		$this->assertSame('', $own->Error);
		$this->assertSame(0, $own->connectionAttempt);
		$this->assertSame([], $own->getLog());
		$this->assertNotEmpty($db->getLog(), 'the original keeps its own log');
	}

	/**
	* repointing a copy at another host: detach first, or it would keep using
	* the connection it was cloned from.
	*/
	public function testDetachedCopyCanBeRepointedAtAnotherHost()
	{
		$db = new MysqliDb('my', 'user', 'pass', '10.0.0.1');
		$link = new FakeLink();
		$db->linkId = $link;

		$other = $db->newConnection();
		$other->host = '10.0.0.2';

		$this->assertSame('10.0.0.2', $other->host);
		$this->assertSame(0, $other->linkId);
		$this->assertSame('10.0.0.1', $db->host, 'the original still points where it did');
		$this->assertSame($link, $db->linkId);
		$this->assertSame(0, $link->closeCalls);
	}

	/**
	* @dataProvider driverProvider
	* @param string $class
	*/
	public function testNewConnectionIsAvailableOnEveryDriver($class)
	{
		$db = new $class();
		$db->linkId = new FakeLink();

		$own = $db->newConnection();

		$this->assertInstanceOf($class, $own);
		$this->assertSame(0, $own->linkId);
	}
}
