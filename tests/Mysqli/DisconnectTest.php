<?php

namespace MyDb\Tests\Mysqli;

use MyDb\Mysqli\Db;
use PHPUnit\Framework\TestCase;

/**
* stands in for a mysqli somebody else already closed: PHP 8 throws an Error
* out of close() in that state.
*/
class AlreadyClosedLink
{
	public $closeCalls = 0;

	public function close()
	{
		$this->closeCalls++;
		throw new \Error('mysqli object is already closed');
	}
}

/**
* stands in for a live mysqli.
*/
class OpenLink
{
	public $closeCalls = 0;

	public function close()
	{
		$this->closeCalls++;
		return true;
	}
}

class DisconnectTest extends TestCase
{
	public function testDisconnectClosesAnOpenLink()
	{
		$db = new Db();
		$link = new OpenLink();
		$db->linkId = $link;

		$this->assertTrue($db->disconnect());
		$this->assertSame(1, $link->closeCalls);
		$this->assertSame(0, $db->linkId);
	}

	public function testDisconnectSurvivesAnAlreadyClosedLink()
	{
		$db = new Db();
		$link = new AlreadyClosedLink();
		$db->linkId = $link;

		$this->assertFalse($db->disconnect());
		$this->assertSame(1, $link->closeCalls);
		$this->assertSame(0, $db->linkId);
	}

	/**
	* clones share one handle, so the first disconnect() closes it out from
	* under the second -- which used to be fatal.
	*/
	public function testDisconnectingTwoClonesOfOneHandleIsNotFatal()
	{
		$db = new Db();
		$db->linkId = new AlreadyClosedLink();

		$first = clone $db;
		$second = clone $db;

		$first->disconnect();
		$second->disconnect();

		$this->assertSame(0, $first->linkId);
		$this->assertSame(0, $second->linkId);
	}

	public function testDisconnectWithNoLinkReportsFalse()
	{
		$db = new Db();
		$db->linkId = 0;

		$this->assertFalse($db->disconnect());
		$this->assertSame(0, $db->linkId);
	}

	public function testDisconnectClearsTheTransactionFlag()
	{
		$db = new Db();
		$db->linkId = new AlreadyClosedLink();

		$reflection = new \ReflectionProperty(\MyDb\Generic::class, 'inTransaction');
		$reflection->setAccessible(true);
		$reflection->setValue($db, true);

		$db->disconnect();

		$this->assertFalse($db->inTransaction());
	}
}
