<?php

namespace MyDb\Tests;

use MyDb\Mysqli\Db as MysqliDb;
use MyDb\Mdb2\Db as Mdb2Db;
use MyDb\Pdo\Db as PdoDb;
use MyDb\Pgsql\Db as PgsqlDb;
use MyDb\Tests\Mysqli\AlreadyClosedLink;
use MyDb\Tests\Mysqli\OpenLink;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Mysqli/DisconnectTest.php';

/**
* A clone keeps sharing the original's connection -- that is what makes
* `clone $db` a free way to get a second cursor -- but it does not own it, so it
* can never close the connection out from under the original. detach() and
* newConnection() are how a copy breaks off and gets a connection of its own.
*/
class CloneTest extends TestCase
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
		$link = new OpenLink();
		$db->linkId = $link;

		$clone = clone $db;

		$this->assertSame($link, $clone->linkId, 'a clone reuses the connection rather than opening a second one');
	}

	/**
	* @dataProvider driverProvider
	* @param string $class
	*/
	public function testCloneDoesNotOwnTheConnection($class)
	{
		$db = new $class();
		$db->linkId = new OpenLink();

		$clone = clone $db;

		$this->assertTrue($db->ownsConnection());
		$this->assertFalse($clone->ownsConnection());
	}

	public function testCloneOfACloneAlsoBorrows()
	{
		$db = new MysqliDb();
		$db->linkId = new OpenLink();

		$grandchild = clone (clone $db);

		$this->assertFalse($grandchild->ownsConnection());
	}

	public function testDisconnectingACloneLeavesTheOriginalConnected()
	{
		$db = new MysqliDb();
		$link = new OpenLink();
		$db->linkId = $link;
		$clone = clone $db;

		$this->assertFalse($clone->disconnect(), 'the clone closed nothing, so it reports false');
		$this->assertSame(0, $clone->linkId, 'but it did let go of the handle');
		$this->assertSame(0, $link->closeCalls, 'the shared connection stays open');
		$this->assertSame($link, $db->linkId, 'and the original still has it');
	}

	public function testOriginalStillClosesItsOwnConnection()
	{
		$db = new MysqliDb();
		$link = new OpenLink();
		$db->linkId = $link;
		$clone = clone $db;
		$clone->disconnect();

		$this->assertTrue($db->disconnect());
		$this->assertSame(1, $link->closeCalls);
	}

	/**
	* the crash this all started from: one db cloned per host, each clone
	* disconnected so it reconnects to the host it was pointed at.
	*/
	public function testCloningPerHostAndDisconnectingEachIsNotFatal()
	{
		$db = new MysqliDb();
		$link = new AlreadyClosedLink();
		$db->linkId = $link;

		foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $host) {
			$clone = clone $db;
			$clone->host = $host;
			$clone->disconnect();

			$this->assertSame($host, $clone->host);
			$this->assertSame(0, $clone->linkId);
		}

		$this->assertSame($link, $db->linkId, 'the original was never disturbed');
		$this->assertSame(0, $link->closeCalls, 'and nobody tried to close its connection');
	}

	public function testDetachGivesUpTheHandleWithoutClosingIt()
	{
		$db = new MysqliDb();
		$link = new OpenLink();
		$db->linkId = $link;
		$clone = clone $db;

		$clone->detach();

		$this->assertSame(0, $clone->linkId);
		$this->assertSame(0, $clone->queryId);
		$this->assertSame(0, $link->closeCalls);
		$this->assertTrue($clone->ownsConnection(), 'whatever it connects next is its own to close');
	}

	public function testDetachDropsThePreparedStatement()
	{
		$db = new MysqliDb();
		$db->linkId = new OpenLink();
		$db->statement = new OpenLink();
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
		$db->linkId = new OpenLink();
		$reflection = new \ReflectionProperty(\MyDb\Generic::class, 'inTransaction');
		$reflection->setAccessible(true);
		$reflection->setValue($db, true);

		$clone = clone $db;
		$this->assertTrue($clone->inTransaction(), 'while sharing the connection it shares the transaction');

		$clone->detach();
		$this->assertFalse($clone->inTransaction(), 'once detached that transaction is not ours');
		$this->assertTrue($db->inTransaction(), 'the original is still in it');
	}

	public function testNewConnectionStartsUnconnectedAndOwning()
	{
		$db = new MysqliDb();
		$link = new OpenLink();
		$db->linkId = $link;

		$own = $db->newConnection();

		$this->assertInstanceOf(MysqliDb::class, $own);
		$this->assertSame(0, $own->linkId, 'it connects lazily, on its first query');
		$this->assertTrue($own->ownsConnection());
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
		$db->linkId = new OpenLink();
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
	* @dataProvider driverProvider
	* @param string $class
	*/
	public function testNewConnectionIsAvailableOnEveryDriver($class)
	{
		$db = new $class();
		$db->linkId = new OpenLink();

		$own = $db->newConnection();

		$this->assertInstanceOf($class, $own);
		$this->assertSame(0, $own->linkId);
	}
}
