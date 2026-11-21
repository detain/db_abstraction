<?php

namespace MyDb\Tests\Mysqli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
* A transaction must never be reported as saved when its writes are gone, and a statement must never run on its own
* (autocommit) when the caller takes it for part of a transaction: a link closed, replaced or ended underneath the
* transaction by another holder of the shared handle, a lost transaction the caller has not ended yet, a
* transactionBegin() whose implicit commit failed. Also the state the driver leaves behind when something throws,
* and what it reads and logs on the way (see TransactionReplayTest for the replay itself).
*
* No server is involved (fake_mysqli.php, hence the process isolation); see TransactionRetryTest.
*
* @runTestsInSeparateProcesses
* @preserveGlobalState disabled
*/
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TransactionSafetyTest extends TestCase
{
	/** a scripted failure that also fails every replay */
	const ALWAYS = 1000;

	protected function tearDown(): void
	{
		$this->assertSame([], \MyAdmin\Mail::$sent, 'nothing was mailed');
	}

	protected function setUp(): void
	{
		require_once __DIR__.'/../MailDouble.php';
		if (!defined('\\MyAdmin\\Mail::IS_TEST_DOUBLE')) {
			$this->markTestSkipped('the real \\MyAdmin\\Mail is loaded; refusing to risk sending email');
		}
		\MyAdmin\Mail::$sent = [];
		require_once __DIR__.'/fake_mysqli.php';
		if (!function_exists('MyDb\\Mysqli\\mysqli_query')) {
			$this->fail('the fake mysqli functions are not loaded');
		}
		FakeServer::reset();
	}

	/**
	* @param string $haltOnError
	* @return ScriptedDb
	*/
	private function db($haltOnError = 'no')
	{
		$db = new ScriptedDb('fake_db', 'fake_user', '', '127.0.0.1', '', '1');
		$db->haltOnError = $haltOnError;
		$db->transactionReplays = 3;
		return $db;
	}

	private function q(ScriptedDb $db, $sql)
	{
		return $db->query($sql, __LINE__, __FILE__);
	}

	/** the writes that became durable (an autocommitted SELECT is left out) */
	private static function writes()
	{
		return array_values(array_filter(FakeServer::$committed, static function ($sql) {
			return preg_match('/^(?:insert|update|delete|replace)\b/i', $sql) === 1;
		}));
	}

	/** how many times $sql became durable */
	private static function committed($sql)
	{
		return count(array_keys(FakeServer::$committed, $sql, true));
	}

	/**
	* what a reconnect that failed leaves in linkId (ext-mysqli: "not fully initialized"; every call on it throws \Error),
	* made by hand on the handle's link
	*/
	private static function halfOpen(ScriptedDb $db)
	{
		$established = new \ReflectionProperty(\MyDb\Mysqli\Db::class, 'linkEstablished');
		$established->setAccessible(true);
		$established->setValue($db, false);
		$db->linkId->halfOpen = true;
	}

	/** the caller's transaction is gone: nothing more of it may run, and its COMMIT must fail */
	private function assertLostAndRefused(ScriptedDb $db, $errno = 2006)
	{
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (2)'), 'not run: sent now it would commit on its own');
		$this->assertSame(0, FakeServer::sent('INSERT INTO t VALUES (2)'), 'and never sent');
		$this->assertSame($errno, (int) $db->Errno);
		$this->assertTrue($db->lost(), 'the transaction is lost');
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (3)'), 'refused until the caller ends the transaction');
		$this->assertSame(0, FakeServer::sent('INSERT INTO t VALUES (3)'));
		$this->assertSame($errno, (int) $db->Errno);
		$this->assertStringContainsString('Statement refused', $db->Error);
		$sent = FakeServer::sent('COMMIT');
		$this->assertFalse($db->transactionCommit(), 'the COMMIT does not report the lost write as saved');
		$this->assertSame($sent, FakeServer::sent('COMMIT'));
		$this->assertSame(0, self::committed('INSERT INTO t VALUES (2)'));
	}

	public static function reportModes()
	{
		return [
			'failures throw (MYSQLI_REPORT_STRICT)' => [true],
			'failures return false' => [false],
		];
	}

	public static function closers()
	{
		return [
			'disconnect()' => ['disconnect'],
			'its own statement retry after a connection error (query() closes the dead link and reconnects)' => ['retry'],
		];
	}

	/**
	* @param ScriptedDb $other a clone sharing the link
	* @param string $how
	*/
	private function closeSharedLink(ScriptedDb $other, $how)
	{
		if ($how === 'disconnect') {
			$other->disconnect();
			return;
		}
		// outside a transaction a statement that lost the connection is retried, and the second round closes the dead
		// link first (after any other error the retry stays on the same connection)
		FakeServer::failNext('/^SELECT x$/', 2006, self::ALWAYS);
		$this->assertFalse($this->q($other, 'SELECT x'));
		$this->assertNotSame(0, $other->linkId, 'the other handle reconnected for itself');
	}

	/**
	* A1: real_escape() on a link another holder closed must not reconnect underneath the transaction: the statements
	* after it would autocommit on the new connection and the COMMIT report the lost write as saved.
	*
	* @dataProvider closers
	* @param string $how
	*/
	#[DataProvider('closers')]
	public function testRealEscapeDoesNotReconnectUnderTheTransactionWhenAnotherHandleClosedTheLink($how)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->closeSharedLink($other, $how);
		$connections = FakeServer::$connections;

		$this->assertSame("o\\'k", $db->real_escape("o'k"), 'escaped without a connection');

		$this->assertSame($connections, FakeServer::$connections, 'no new connection for this handle');
		$this->assertLostAndRefused($db);
		$this->assertSame(0, self::committed('INSERT INTO t VALUES (1)'));
	}

	public static function linkUsers()
	{
		return [
			'selectDb()' => ['selectDb'],
			'prepare()' => ['prepare'],
			'lock()' => ['lock'],
			'unlock()' => ['unlock'],
		];
	}

	/**
	* A1: the other calls that connect() on their own do not reconnect underneath the transaction either.
	*
	* @dataProvider linkUsers
	* @param string $call
	*/
	#[DataProvider('linkUsers')]
	public function testNoCallReconnectsUnderTheTransactionWhenAnotherHandleClosedTheLink($call)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$other->disconnect();

		if ($call === 'selectDb') {
			$db->selectDb('other');
		} elseif ($call === 'prepare') {
			$this->assertSame(0, $db->prepare('INSERT INTO t VALUES (3)'));
		} elseif ($call === 'lock') {
			$this->assertSame(0, $db->lock('t'));
		} else {
			$db->unlock(false);
		}

		$this->assertSame(1, FakeServer::$connections, 'no reconnect');
		$this->assertLostAndRefused($db);
	}

	/**
	* A1: a statement prepared inside the transaction is not executed once the link went: it would run on its own.
	*/
	public function testAPreparedStatementIsNotExecutedOnceTheTransactionsLinkWentAway()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertNotSame(0, $db->prepare('INSERT INTO t VALUES (3)'));
		$other->disconnect();

		$this->assertFalse($db->execute());

		$this->assertSame(0, FakeServer::sent('INSERT INTO t VALUES (3)'));
		$this->assertLostAndRefused($db);
	}

	public static function afterReconnect()
	{
		return [
			'a statement' => ['statement'],
			'transactionCommit()' => ['transactionCommit'],
			"query('COMMIT')" => ['COMMIT'],
		];
	}

	/**
	* A1: a link that replaced the transaction's own (something called connect() after another holder closed it) is
	* not where the transaction ran: the statement after it is not sent, the COMMIT is refused.
	*
	* @dataProvider afterReconnect
	* @param string $next
	*/
	#[DataProvider('afterReconnect')]
	public function testALinkThatReplacedTheTransactionsOwnLosesTheTransaction($next)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$other->disconnect();
		$this->assertIsObject($db->connect(), 'connect() is public: application code can reconnect a handle');
		$this->assertSame(2, FakeServer::$connections);

		if ($next === 'statement') {
			$this->assertLostAndRefused($db);
		} elseif ($next === 'transactionCommit') {
			$this->assertFalse($db->transactionCommit());
			$this->assertSame(2006, (int) $db->Errno);
		} else {
			$this->assertFalse($this->q($db, 'COMMIT'));
			$this->assertSame(2006, (int) $db->Errno);
		}
		$this->assertSame(0, FakeServer::sent('COMMIT'), 'no COMMIT reached the new connection');
		$this->assertSame([], array_filter(FakeServer::$wire, static function ($event) {
			return $event[0] === 'query' && $event[1] === 2;
		}), 'nothing at all was sent on the new connection (not even a ROLLBACK: the transaction went with the old one)');
		$this->assertSame([], self::writes());
		$this->assertStringContainsString('the connection it ran on was replaced underneath it', implode("\n", FakeServer::$log)."\n".$db->Error, 'the log or the error says why');
	}

	/**
	* A1/F: transactionCommit() with no link while a transaction is open: it was not committed, so it is not true.
	*/
	public function testTransactionCommitWithNoLinkWhileATransactionIsOpenIsRefused()
	{
		$db = $this->db();
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$db->linkId = 0;

		$this->assertFalse($db->transactionCommit());

		$this->assertSame(2006, (int) $db->Errno);
		$this->assertTrue($db->lost());
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertSame([], self::writes());
	}

	public static function foreignEnds()
	{
		return [
			'COMMIT' => ['COMMIT'],
			// a plain ROLLBACK (or transactionAbort()) of a handle with no transaction of its own is not sent while
			// another handle has one open on the link (see
			// testARollbackOfAHandleWithNoTransactionOfItsOwnLeavesAnotherHandlesTransactionAlone)
			// a START of a handle with no transaction of its own moves to a connection of its own (see
			// testAStartOfAHandleWithNoTransactionRunsOnAConnectionOfItsOwnWhenAnotherHasOneOpen), except when the
			// shared connection's session state was changed: then it still ends the transaction there
			'START TRANSACTION (session state pinned)' => ['START TRANSACTION'],
			'BEGIN (session state pinned)' => ['BEGIN'],
			'DDL (implicit commit)' => ['CREATE TABLE x (a int)'],
			'TRUNCATE (implicit commit)' => ['TRUNCATE TABLE t'],
			'SET autocommit (implicit commit)' => ['SET autocommit=1'],
			'transactionBegin() (session state pinned)' => ['transactionBegin'],
			'transactionCommit()' => ['transactionCommit'],
			'lock()' => ['lock'],
		];
	}

	private function foreign(ScriptedDb $other, $what)
	{
		if (in_array($what, ['START TRANSACTION', 'BEGIN', 'transactionBegin'], true)) {
			// a user variable on the shared connection: a connection of its own would not have it, so the START stays
			$this->assertNotFalse($this->q($other, 'SET @pin = 1'));
		}
		if ($what === 'transactionAbort' || $what === 'transactionBegin' || $what === 'transactionCommit') {
			$this->assertTrue((bool) $other->{$what}());
		} elseif ($what === 'lock') {
			$this->assertNotSame(0, $other->lock('t'));
		} else {
			$this->assertNotFalse($this->q($other, $what));
		}
	}

	/**
	* A2: another holder of the shared link ended the transaction (committed, rolled back, or committed it implicitly):
	* it is lost, not merely not replayable. Before, the next statement ran with autocommit and the COMMIT returned true
	* over the rolled-back write.
	*
	* @dataProvider foreignEnds
	* @param string $what
	*/
	#[DataProvider('foreignEnds')]
	public function testAnotherHandleEndingTheTransactionOnTheSharedLinkLosesIt($what)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));

		$this->foreign($other, $what);

		$this->assertLostAndRefused($db);
		$this->assertStringContainsString('another handle sharing the connection ended it', implode("\n", FakeServer::$log));
	}

	public static function commits()
	{
		return [
			'transactionCommit()' => ['transactionCommit'],
			"query('COMMIT')" => ['COMMIT'],
		];
	}

	/**
	* A2: the same, found at the COMMIT itself.
	*
	* @dataProvider commits
	* @param string $how
	*/
	#[DataProvider('commits')]
	public function testAnotherHandleEndingTheTransactionIsFoundAtCommit($how)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($other, 'COMMIT'));

		$this->assertFalse($how === 'COMMIT' ? $this->q($db, 'COMMIT') : $db->transactionCommit());

		$this->assertSame(2006, (int) $db->Errno, 'outcome unknown to this handle: not a conflict to retry');
		$this->assertSame(1, FakeServer::sent('COMMIT'), 'the other handle\'s only: this one is not sent');
		$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes(), 'committed once, by the other handle\'s COMMIT');
	}

	public static function harmlessForeign()
	{
		return [
			'SELECT' => ['SELECT 1'],
			'ROLLBACK TO SAVEPOINT' => ['ROLLBACK TO SAVEPOINT sp'],
			'CREATE TEMPORARY TABLE' => ['CREATE TEMPORARY TABLE tmp (a int)'],
			'DROP TEMPORARY TABLE' => ['DROP TEMPORARY TABLE tmp'],
			'UPDATE' => ['UPDATE other SET z=1'],
		];
	}

	/**
	* A2: what another handle sends that does not end the transaction only stops it being replayable.
	*
	* @dataProvider harmlessForeign
	* @param string $sql
	*/
	#[DataProvider('harmlessForeign')]
	public function testAnotherHandlesStatementThatDoesNotEndTheTransactionOnlyStopsReplays($sql)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotFalse($this->q($other, $sql));

		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertSame('another handle sharing the connection ran a statement inside it', $db->replayable());
		$this->assertTrue($db->transactionCommit());

		$this->assertFalse($db->lost());
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (1)'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (2)'));
	}

	/**
	* A2: once another handle ended the transaction, a statement of this handle's own that went out without the check
	* does not hide it: the next one still finds the transaction lost.
	*/
	public function testAForeignEndStaysFoundAfterAStatementOfThisHandlesOwn()
	{
		$db = new class('fake_db', 'fake_user', '', '127.0.0.1', '', '1') extends ScriptedDb {
			public function countOwn()
			{
				$this->countStatement();
			}
		};
		$db->haltOnError = 'no';
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($other, 'COMMIT'));
		$db->countOwn();

		$this->assertLostAndRefused($db);
	}

	public static function rollingBackFailures()
	{
		$cases = [];
		foreach ([1213, 3101, 1180] as $errno) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$errno.' '.$mode] = [$errno, $strict];
			}
		}
		return $cases;
	}

	/**
	* A2: a statement another holder of the shared link sends outside a transaction of its own still runs inside the one
	* this handle has open there, and a deadlock or certification failure on it makes the server roll that transaction
	* back. Before, the other handle's own retry went through, this handle's next statements were sent (into the
	* transaction the server keeps implicitly open after the deadlock) and its COMMIT returned true over the
	* rolled-back write (replay-opus scenario C7).
	*
	* @dataProvider rollingBackFailures
	* @param int $errno
	* @param bool $strict
	*/
	#[DataProvider('rollingBackFailures')]
	public function testAnotherHandlesStatementTheServerRolledBackLosesTheTransaction($errno, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));

		FakeServer::failNext('/^INSERT INTO log/', $errno);
		$this->assertTrue($this->q($other, 'INSERT INTO log VALUES (1)'), 'the other handle retries its own statement, as it always did');

		$this->assertLostAndRefused($db);
		$this->assertSame(0, self::committed('INSERT INTO t VALUES (1)'));
		$this->assertStringContainsString('whose failure made the server roll it back', implode("\n", FakeServer::$log));
	}

	/**
	* A2: the same through a prepared statement of the other handle's (execute() is not retried).
	*
	* @dataProvider rollingBackFailures
	* @param int $errno
	* @param bool $strict
	*/
	#[DataProvider('rollingBackFailures')]
	public function testAnotherHandlesPreparedStatementTheServerRolledBackLosesTheTransaction($errno, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotSame(0, $other->prepare('INSERT INTO log VALUES (1)'));

		FakeServer::failNext('/^INSERT INTO log/', $errno);
		try {
			$this->assertFalse($other->execute());
			$this->assertFalse($strict, 'returned only with reporting off');
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			$this->assertSame($errno, $e->getCode());
		}

		$this->assertLostAndRefused($db);
		$this->assertSame(0, self::committed('INSERT INTO t VALUES (1)'));
	}

	/**
	* A2: a failure the server rolls back only the statement for leaves the transaction open: it only stops being
	* replayable, and its COMMIT commits it (1064 is the one failure query() does not retry, so the shared link is not
	* closed and reopened under it).
	*/
	public function testAnotherHandlesStatementThatFailsOnItsOwnLeavesTheTransactionOpen()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::$strict = false;
		FakeServer::failNext('/^INSERT INTO log/', 1064);
		$this->assertFalse($this->q($other, 'INSERT INTO log VALUES (1)'));
		$this->assertNotSame(0, $other->prepare('INSERT INTO log VALUES (2)'));
		foreach ([1062, 1205] as $errno) {
			FakeServer::failNext('/^INSERT INTO log/', $errno);
			$this->assertFalse($other->execute());
		}

		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());

		$this->assertFalse($db->lost());
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (1)'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (2)'));
	}

	/**
	* A prepared statement of this handle's own that the server rolled the transaction back for gives the transaction up
	* (prepare()/execute() make it not replayable). Before, the statements after it were sent into the transaction the
	* server keeps implicitly open after a deadlock, and the COMMIT returned true without the rolled-back write.
	*
	* @dataProvider rollingBackFailures
	* @param int $errno
	* @param bool $strict
	*/
	#[DataProvider('rollingBackFailures')]
	public function testAPreparedStatementTheServerRolledTheTransactionBackForLosesIt($errno, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotSame(0, $db->prepare('INSERT INTO p VALUES (1)'));

		FakeServer::failNext('/^INSERT INTO p/', $errno);
		try {
			$this->assertFalse($db->execute());
			$this->assertFalse($strict, 'returned only with reporting off');
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			$this->assertSame($errno, $e->getCode());
		}

		$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'the transaction the server keeps open after the failure is ended');
		$this->assertLostAndRefused($db, $errno);
		$this->assertSame(0, self::committed('INSERT INTO t VALUES (1)'));
	}

	/**
	* A prepared statement of this handle's own that fails on its own leaves the transaction open.
	*/
	public function testAPreparedStatementThatFailsOnItsOwnLeavesTheTransactionOpen()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotSame(0, $db->prepare('INSERT INTO p VALUES (1)'));
		FakeServer::failNext('/^INSERT INTO p/', 1062);
		$this->assertFalse($db->execute());

		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());

		$this->assertFalse($db->lost());
		$this->assertSame(0, FakeServer::sent('ROLLBACK'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (1)'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (2)'));
	}

	public static function sessionStates()
	{
		return [
			'a user variable' => ['variable'],
			'selectDb()' => ['selectDb'],
		];
	}

	/**
	* A3: session state a clone set on the shared link is still known once the link is let go of: a replay that needs a
	* new connection gives up rather than re-run the statements without it (@x would be NULL, the database another).
	*
	* @dataProvider sessionStates
	* @param string $state
	*/
	#[DataProvider('sessionStates')]
	public function testSessionStateAnotherHandleSetIsKeptWhenTheLinkIsLetGoOf($state)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		if ($state === 'variable') {
			$this->assertTrue($this->q($other, "SET @x = 'five'"));
		} else {
			$other->selectDb('otherdb');
		}
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (@x)'));
		FakeServer::failNext('/^UPDATE u/', 2006);

		$this->assertFalse($this->q($db, 'UPDATE u SET y=1'));

		$this->assertSame(1, FakeServer::$connections, 'no new connection');
		$this->assertTrue($db->lost());
		$this->assertStringContainsString('session state', implode("\n", FakeServer::$log));
		$this->assertSame([], self::writes());
	}

	public static function lostModes()
	{
		$out = [];
		foreach (['no', 'report'] as $halt) {
			foreach ([true, false] as $strict) {
				$out[$halt.($strict ? ', strict' : ', returning false')] = [$halt, $strict];
			}
		}
		return $out;
	}

	/**
	* B1 (A7b): after a statement gave up, the caller still takes the transaction to be open; what it sends next is
	* refused (quietly under 'no'/'report', with the error the transaction was lost with) instead of committing on
	* its own, until it ends the transaction.
	*
	* @dataProvider lostModes
	* @param string $halt
	* @param bool $strict
	*/
	#[DataProvider('lostModes')]
	public function testStatementsAfterAGiveUpAreRefusedUntilTheTransactionIsEnded($halt, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db($halt);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/INTO b/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'INSERT INTO b VALUES (1)'));
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lostTransactionPending(), 'the caller has not ended it yet');
		$reported = count($db->reported);
		$halts = $db->halts;

		$this->assertFalse($this->q($db, 'INSERT INTO c VALUES (1)'));
		$this->assertFalse($this->q($db, 'SELECT 1'));

		$this->assertSame(0, FakeServer::sent('INSERT INTO c VALUES (1)'));
		$this->assertSame(1213, (int) $db->Errno, 'the error the transaction was lost with');
		$this->assertStringContainsString('Statement refused', $db->Error);
		$this->assertSame($reported, count($db->reported), 'quiet: not reported');
		$this->assertSame($halts, $db->halts, 'and not halted');
		$this->assertStringContainsString('Statement refused', implode("\n", FakeServer::$log));
		$this->assertTrue($db->lostTransactionPending());
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertFalse($db->lostTransactionPending(), 'its own COMMIT (refused) ended it for the caller');
		$this->assertTrue($this->q($db, 'ROLLBACK'));
		$this->assertTrue($this->q($db, 'INSERT INTO d VALUES (1)'), 'the caller ended it: what it sends now is its own');
		$this->assertSame(['INSERT INTO d VALUES (1)'], FakeServer::$committed);
	}

	public static function enders()
	{
		return [
			'ROLLBACK' => ['ROLLBACK'],
			'START TRANSACTION' => ['START TRANSACTION'],
			'BEGIN' => ['BEGIN'],
			'transactionAbort()' => ['transactionAbort'],
			'transactionBegin()' => ['transactionBegin'],
		];
	}

	/**
	* B1: ROLLBACK, START/BEGIN and transactionAbort()/transactionBegin() are not refused, and end the refusal.
	*
	* @dataProvider enders
	* @param string $ender
	*/
	#[DataProvider('enders')]
	public function testALostTransactionIsEndedByRollbackStartOrAbort($ender)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/INTO b/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'INSERT INTO b VALUES (1)'));

		if ($ender === 'transactionAbort' || $ender === 'transactionBegin') {
			$this->assertTrue((bool) $db->{$ender}());
		} else {
			$this->assertTrue($this->q($db, $ender));
		}

		$this->assertFalse($db->lost());
		$this->assertFalse($db->lostTransactionPending());
		$this->assertTrue($this->q($db, 'INSERT INTO c VALUES (1)'));
		$this->assertSame(1, FakeServer::sent('INSERT INTO c VALUES (1)'));
	}

	public static function conflictReporting()
	{
		return [
			'reportConflicts on: reported' => [true, 1],
			'reportConflicts off and lost to a conflict: not reported' => [false, 0],
		];
	}

	/**
	* B1: under haltOnError 'yes' a refused statement fails like the refused COMMIT: emailError(), then halt() (which
	* ends the process; recorded here instead).
	*
	* @dataProvider conflictReporting
	* @param bool $reportConflicts
	* @param int $reports
	*/
	#[DataProvider('conflictReporting')]
	public function testARefusedStatementIsReportedAndHaltsUnderHaltOnErrorYes($reportConflicts, $reports)
	{
		$db = new class('fake_db', 'fake_user', '', '127.0.0.1', '', '1') extends ScriptedDb {
			public $haltCalls = 0;

			public function halt($msg, $line = '', $file = '')
			{
				$this->haltCalls++;
				return true;
			}
		};
		$db->transactionReplays = 3;
		$db->haltOnError = 'yes';
		$db->reportConflicts = $reportConflicts;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/INTO b/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'INSERT INTO b VALUES (1)'));
		$reported = count($db->reported);
		$halts = $db->haltCalls;

		$this->assertFalse($this->q($db, 'INSERT INTO c VALUES (1)'));

		$this->assertSame(0, FakeServer::sent('INSERT INTO c VALUES (1)'));
		$this->assertSame($reported + $reports, count($db->reported));
		$this->assertSame($halts + $reports, $db->haltCalls);
		if ($reports > 0) {
			$this->assertStringContainsString('Statement refused', $db->reported[$reported][1]);
		}
	}

	/**
	* B1: what the error report writes through the very handle whose transaction was lost (the log row MyDbHandler
	* writes through App::db()) is the reporter's, not the caller's: it is not refused.
	*/
	public function testTheReportOfAGiveUpCanWriteThroughTheSameHandle()
	{
		$db = new class('fake_db', 'fake_user', '', '127.0.0.1', '', '1') extends ScriptedDb {
			public function emailError($queryString, $error, $line, $file)
			{
				parent::emailError($queryString, $error, $line, $file);
				$this->query("INSERT INTO log VALUES ('reported')", __LINE__, __FILE__);
			}

			public function halt($msg, $line = '', $file = '')
			{
				return true;
			}
		};
		$db->transactionReplays = 3;
		$db->haltOnError = 'yes';
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/INTO b/', 1213, self::ALWAYS);

		$this->assertFalse($this->q($db, 'INSERT INTO b VALUES (1)'));
		$this->assertFalse($this->q($db, 'INSERT INTO c VALUES (1)'));

		$this->assertSame(2, self::committed("INSERT INTO log VALUES ('reported')"), 'both reports were logged');
		$this->assertSame(0, FakeServer::sent('INSERT INTO c VALUES (1)'), 'the caller\'s statement was still refused');
		$this->assertSame(1213, (int) $db->Errno);
	}

	/**
	* B1: the same when another handle reports (Generic::emailError()) and its report writes through the handle whose
	* transaction was lost.
	*/
	public function testAnotherHandlesReportCanWriteThroughAHandleWhoseTransactionWasLost()
	{
		$lost = $this->db();
		$this->assertTrue($this->q($lost, 'START TRANSACTION'));
		$this->assertTrue($this->q($lost, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/INTO b/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($lost, 'INSERT INTO b VALUES (1)'));
		$reporter = new class('fake_db', 'fake_user', '', '127.0.0.1', '', '1') extends ScriptedDb {
			/** @var ScriptedDb */
			public $logTo;

			public function emailError($queryString, $error, $line, $file)
			{
				// the library's own report (no TFSmarty here, so no mail): it ends in haltmsg()
				\MyDb\Generic::emailError($queryString, $error, $line, $file);
			}

			public function haltmsg($msg, $line = '', $file = '')
			{
				$this->logTo->query("INSERT INTO log VALUES ('reported')", __LINE__, __FILE__);
			}
		};
		$reporter->haltOnError = 'no';
		$reporter->logTo = $lost;
		FakeServer::failNext('/^SELECT boom$/', 1064);

		$this->assertFalse($this->q($reporter, 'SELECT boom'));

		$this->assertSame(1, self::committed("INSERT INTO log VALUES ('reported')"));
		$this->assertFalse($this->q($lost, 'INSERT INTO c VALUES (1)'), 'outside the report it is refused again');
		$this->assertSame(0, FakeServer::sent('INSERT INTO c VALUES (1)'));
	}

	/**
	* B2: transactionBegin() with a transaction open commits it first; when the server rolls that commit back the
	* transaction is replayed and the new one begins (R4, J6: before, its write was silently gone).
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTransactionBeginRecoversAnImplicitCommitTheServerRolledBack($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db('yes');
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', 3101);

		$this->assertTrue($db->transactionBegin());

		$this->assertSame(1, self::committed('INSERT INTO t VALUES (1)'), 'the first transaction committed once');
		$this->assertTrue($db->inTransaction());
		$this->assertFalse($db->lost());
		$this->assertSame([10000], FakeServer::$sleeps);
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (2)'));
		$this->assertSame([], $db->reported);
		$this->assertSame(0, $db->halts);
	}

	/**
	* B2: the transaction transactionBegin() opens once it recovered the implicit commit is recorded from its own start:
	* a replay of it re-runs only its own statements, never those of the transaction that committed (they would be
	* written twice).
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTheTransactionBegunAfterARecoveredImplicitCommitReplaysOnlyItsOwnStatements($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db('yes');
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', 3101);
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		FakeServer::failNext('/^INSERT INTO t VALUES \(3\)$/', 1213);

		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (3)'));
		$this->assertTrue($db->transactionCommit());

		$this->assertSame(2, FakeServer::sent('INSERT INTO t VALUES (1)'), 'run, then replayed once by transactionBegin(), never again');
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (1)'), 'never written twice');
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (2)'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (3)'));
		$this->assertSame([], $db->reported);
	}

	/**
	* B2: a statement another handle ran inside the open transaction makes the implicit commit of transactionBegin() not
	* replayable: a replay would leave that statement out and commit the rest as if it were all there.
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTransactionBeginDoesNotReplayATransactionAnotherHandleRanAStatementIn($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($other, 'INSERT INTO o VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', 3101);

		try {
			$this->assertFalse($db->transactionBegin());
			$this->assertFalse($strict, 'returned only with reporting off');
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			$this->assertSame(3101, $e->getCode());
		}

		$this->assertSame(1, FakeServer::sent('INSERT INTO t VALUES (1)'), 'not replayed');
		$this->assertSame([], self::writes(), 'nothing committed without the other handle\'s write');
		$this->assertTrue($db->lost());
		$this->assertStringContainsString('another handle sharing the connection ran a statement inside it', implode("\n", FakeServer::$log));
	}

	public static function failedImplicitCommits()
	{
		$out = [];
		foreach ([true, false] as $strict) {
			$mode = $strict ? ', strict' : ', returning false';
			$out['3100 (not a conflict)'.$mode] = [3100, 3, $strict];
			$out['1180'.$mode] = [1180, 3, $strict];
			$out['3101, not replayable'.$mode] = [3101, 0, $strict];
			$out['3101 on every replay'.$mode] = [3101, 3, $strict];
		}
		return $out;
	}

	/**
	* B2: an implicit commit that fails for good ends the open transaction: transactionBegin() fails, the transaction
	* is lost, statements are refused until it is ended and the COMMIT returns false (R4b, J6).
	*
	* @dataProvider failedImplicitCommits
	* @param int $code
	* @param int $replays
	* @param bool $strict
	*/
	#[DataProvider('failedImplicitCommits')]
	public function testTransactionBeginWhoseImplicitCommitFailsLosesTheOpenTransaction($code, $replays, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$db->transactionReplays = $replays;
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', $code, $replays === 3 && $code === 3101 ? self::ALWAYS : 1);

		$threw = null;
		try {
			$this->assertFalse($db->transactionBegin());
		} catch (\mysqli_sql_exception $e) {
			$threw = $e->getCode();
		}

		$this->assertSame($strict ? $code : null, $threw);
		$this->assertSame($code, (int) $db->Errno);
		$this->assertStringContainsString('transactionBegin(): the transaction that was open could not be committed first', implode("\n", FakeServer::$log));
		$this->assertLostAndRefused($db, $code);
		$this->assertSame([], self::writes());
		$this->assertSame([], FakeServer::$open, 'nothing is left open on the server');
		$this->assertSame([], $db->reported, 'transactionBegin() never mails');
	}

	public static function startsNeverRun()
	{
		$out = [];
		foreach ([1064, 2014] as $code) {
			foreach ([true, false] as $strict) {
				$out[$code.($strict ? ', strict' : ', returning false')] = [$code, $strict];
			}
		}
		return $out;
	}

	/**
	* B2/B4: a START the server never ran (it did not parse; or it was never sent) committed nothing: the transaction is
	* still open, and only transactionBegin() failed.
	*
	* @dataProvider startsNeverRun
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('startsNeverRun')]
	public function testTransactionBeginTheServerNeverRanLeavesTheTransactionOpen($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', $code);

		$threw = null;
		try {
			$this->assertFalse($db->transactionBegin());
		} catch (\mysqli_sql_exception $e) {
			$threw = $e->getCode();
		}

		$this->assertSame($strict ? $code : null, $threw);
		$this->assertTrue($db->inTransaction());
		$this->assertFalse($db->lost());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], FakeServer::$committed);
	}

	/**
	* B6: transactionCommit() on a handle mysqli cannot use (half-open) rethrows the \Error, and the transaction it had
	* open is over: a later COMMIT does not report it as saved.
	*/
	public function testTransactionCommitThatThrowsAnErrorLosesTheTransactionFirst()
	{
		$db = $this->db();
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		self::halfOpen($db);

		try {
			$db->transactionCommit();
			$this->fail('the \\Error of a half-open handle goes on to the caller');
		} catch (\Error $e) {
			$this->assertStringContainsString('not fully initialized', $e->getMessage());
		}

		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
		$this->assertFalse($db->transactionCommit());
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertSame([], self::writes());
	}

	/**
	* C7: a statement inside a transaction on a handle mysqli cannot use (half-open: every call throws \Error) is a
	* lost connection (send() maps the \Error to 2006): the transaction is replayed on a new connection.
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testAStatementOnAHalfOpenLinkInsideATransactionIsALostConnection($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db('yes');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		self::halfOpen($db);

		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));

		$this->assertSame(2, FakeServer::$connections, 'replayed on a new connection');
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (1)'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (2)'));
		$this->assertSame([], $db->reported);
		$this->assertSame(0, $db->halts);
	}

	public static function rollbackFailuresBeforeAReplay()
	{
		return [
			'1205 (a server error)' => [1205],
			'1064' => [1064],
		];
	}

	/**
	* C2 (R3): the ROLLBACK a replay sends first fails: the connection is closed (which ends the transaction on the
	* server) and the replay goes on on a new one.
	*
	* @dataProvider rollbackFailuresBeforeAReplay
	* @param int $code
	*/
	#[DataProvider('rollbackFailuresBeforeAReplay')]
	public function testAReplayWhoseRollbackFailsClosesTheConnectionAndGoesOnOnANewOne($code)
	{
		$db = $this->db('yes');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^UPDATE u/', 1213);
		FakeServer::failNext('/^ROLLBACK$/', $code);

		$this->assertTrue($this->q($db, 'UPDATE u SET y=1'));

		$this->assertSame(2, FakeServer::$connections);
		$this->assertSame(1, FakeServer::closes());
		$this->assertArrayNotHasKey(1, FakeServer::$open, 'the old connection holds no transaction');
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE u SET y=1'], FakeServer::$committed);
		$this->assertSame([], $db->reported);
	}

	/**
	* C2 (J5): a replay whose ROLLBACK lost the connection gives up (session state would be lost with a new one) with the
	* dead link let go of: the next statement connects at once instead of failing 31 times, 0.25 s apart, on it.
	*/
	public function testAReplayThatGivesUpOnADeadLinkLetsGoOfIt()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, "SET @x = 'v'"));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^UPDATE u/', 1213);
		FakeServer::failNext('/^ROLLBACK$/', 2006);

		$this->assertFalse($this->q($db, 'UPDATE u SET y=1'));

		$this->assertStringContainsString('session state', implode("\n", FakeServer::$log));
		$this->assertFalse($db->linkIsUsable(), 'the dead link was let go of');
		$this->assertTrue($this->q($db, 'ROLLBACK'));
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$this->assertNotContains(250000, FakeServer::$sleeps, 'no statement retried on the dead link');
		$this->assertSame(2, FakeServer::$connections);
	}

	/**
	* C2: a replay abandoned at once (the budget is shorter than the first wait) whose ROLLBACK fails with a server error
	* closes the connection: the server holds nothing for it.
	*/
	public function testAnAbandonedReplayWhoseRollbackFailsClosesTheConnection()
	{
		$db = $this->db();
		$db->replayBudgetMs = 5;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^UPDATE u/', 1213);
		FakeServer::failNext('/^ROLLBACK$/', 1205);

		$this->assertFalse($this->q($db, 'UPDATE u SET y=1'));

		$this->assertSame([], FakeServer::$open, 'nothing is left open on the server');
		$this->assertSame(1, FakeServer::closes());
		$this->assertTrue($db->lost());
		$this->assertSame([], self::writes());
	}

	/**
	* C1/C2: a reconnect that failed during a replay leaves a half-open handle (every call on it throws \Error): it is let
	* go of at once (closed), so nothing else touches it, not even the ROLLBACK of the replay given up right after.
	*/
	public function testAHalfOpenHandleLeftByAFailedReconnectIsNeverTouched()
	{
		$db = $this->db();
		$db->transactionReplays = 1;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^INSERT INTO t VALUES \(2\)$/', 2006);
		FakeServer::$connectFailsReal = 1;

		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (2)'));

		$this->assertSame(2, FakeServer::$connections, 'one reconnect was tried, and it failed');
		$this->assertSame(['mysqli_close'], FakeServer::$halfOpenCalls, 'nothing but the close that lets go of it');
		$this->assertSame(0, $db->linkId, 'it was let go of');
		$this->assertTrue($db->lost());
		$this->assertSame([], self::writes());
	}

	/**
	* C3: a result set larger than $replayMaxBytes is not read to its end to be hashed: the recording stops at the limit
	* (the transaction is then not replayable), and the caller still reads every row.
	*/
	public function testAResultSetTooLargeToReplayIsNotReadToTheEnd()
	{
		$db = $this->db();
		$db->replayMaxBytes = 2000;
		$rows = [];
		for ($i = 0; $i < 1000; $i++) {
			$rows[] = ['id' => (string) $i, 'pad' => str_repeat('x', 40)];
		}
		FakeServer::rows('/^select id, pad from big/', $rows);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));

		$this->assertNotFalse($this->q($db, 'select id, pad from big'));

		$this->assertLessThan(100, FakeServer::$fetches, 'stopped reading once past the limit');
		$this->assertSame('it is too large to replay', $db->replayable());
		$read = 0;
		while ($db->next_record(MYSQLI_ASSOC)) {
			$read++;
		}
		$this->assertSame(1000, $read, 'rewound: the caller reads every row');
	}

	/**
	* C3: on a replay, a result set that grew past the limit is a changed result, found without reading all of it.
	*/
	public function testAResultSetThatGrowsPastTheLimitOnReplayIsAChangeFoundEarly()
	{
		$db = $this->db();
		$db->replayMaxBytes = 2000;
		$small = [];
		$big = [];
		for ($i = 0; $i < 1000; $i++) {
			$row = ['id' => (string) $i, 'pad' => str_repeat('x', 40)];
			if ($i < 5) {
				$small[] = $row;
			}
			$big[] = $row;
		}
		FakeServer::rows('/^select id, pad from s/', $small, $big);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertNotFalse($this->q($db, 'select id, pad from s'));
		FakeServer::failNext('/^UPDATE u/', 1213);
		$fetches = FakeServer::$fetches;

		$this->assertFalse($this->q($db, 'UPDATE u SET y=1'));

		$this->assertLessThan(100, FakeServer::$fetches - $fetches);
		$this->assertStringContainsString('it grew past the replay size limit', implode("\n", FakeServer::$log));
	}

	/**
	* C5 (J3c): after a COMMIT that lost the connection, affectedRows() and getLastInsertId() answer as on a dead link
	* (-1, 0) instead of throwing a TypeError on the let-go-of link.
	*/
	public function testTheReadersAnswerAsOnADeadLinkOnceTheConnectionWasLetGoOf()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (NULL)'));
		FakeServer::failNext('/^COMMIT$/', 2006);
		$this->assertFalse($db->transactionCommit());
		$this->assertSame(0, $db->linkId);

		$this->assertSame(-1, $db->affectedRows());
		$this->assertSame(0, $db->getLastInsertId('t', 'id'));
	}

	/**
	* C5 (J3): the same after a statement gave up and let go of its connection.
	*/
	public function testTheReadersAnswerAsOnADeadLinkAfterAStatementGaveUp()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "SET @x = 'v'"));
		FakeServer::failNext('/^UPDATE t/', 2006);
		$this->assertFalse($this->q($db, 'UPDATE t SET a=1'));

		$this->assertSame(-1, $db->affectedRows());
		$this->assertSame(0, $db->getLastInsertId('t', 'id'));
	}

	/**
	* C5: a buffered result set is still read after its connection was let go of (a COMMIT never sent closes it).
	*/
	public function testABufferedResultIsReadAfterTheConnectionWasLetGoOf()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		FakeServer::rows('/^select id from t/', [['id' => '1'], ['id' => '2']]);
		$this->assertTrue($db->transactionBegin());
		$this->assertNotFalse($this->q($db, 'select id from t'));
		FakeServer::failNext('/^COMMIT$/', 2014);
		$this->assertFalse($db->transactionCommit());
		$this->assertSame(0, $db->linkId);

		$this->assertTrue($db->next_record(MYSQLI_ASSOC));
		$this->assertSame(['id' => '1'], $db->Record);
		$this->assertTrue($db->next_record(MYSQLI_ASSOC));
		$this->assertFalse($db->next_record(MYSQLI_ASSOC));
		$this->assertSame(2014, (int) $db->Errno, 'the errors stay as they were');
	}

	/**
	* C6: an \Error out of the statement retry (a reconnect that failed left a half-open handle) leaves haltOnError as
	* the caller set it.
	*/
	public function testAnErrorOutOfTheStatementRetryRestoresHaltOnError()
	{
		FakeServer::$strict = false;
		$db = $this->db('yes');
		$this->assertTrue($this->q($db, 'SELECT 1'));
		FakeServer::failNext('/^SELECT x$/', 2006, self::ALWAYS);
		FakeServer::$connectFailsReal = 1;

		try {
			$this->q($db, 'SELECT x');
			$this->fail('the \\Error of the half-open handle goes on to the caller');
		} catch (\Error $e) {
			$this->assertStringContainsString('not fully initialized', $e->getMessage());
		}

		$this->assertSame('yes', $db->haltOnError);
	}

	/**
	* C6: the same for a START TRANSACTION, which is also not left taken for open.
	*/
	public function testAnErrorOutOfAStartLeavesNoTransactionOpen()
	{
		$db = $this->db('yes');
		$this->assertTrue($this->q($db, 'SELECT 1'));
		self::halfOpen($db);

		try {
			$this->q($db, 'START TRANSACTION');
			$this->fail('the \\Error of the half-open handle goes on to the caller');
		} catch (\Error $e) {
		}

		$this->assertSame('yes', $db->haltOnError);
		$this->assertFalse($db->inTransaction());
	}

	/**
	* C6: prepare() puts haltOnError back, also when mysqli throws.
	*/
	public function testPrepareRestoresHaltOnError()
	{
		$db = $this->db('yes');
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$this->assertNotSame(0, $db->prepare('SELECT 2'));
		$this->assertSame('yes', $db->haltOnError);
		self::halfOpen($db);

		try {
			$db->prepare('SELECT 3');
			$this->fail('the \\Error of the half-open handle goes on to the caller');
		} catch (\Error $e) {
		}

		$this->assertSame('yes', $db->haltOnError);
	}

	/**
	* C6: a halt() inside the UNLOCK TABLES of another halt() puts haltUnlocking back as it found it (still true), not
	* cleared, so the outer one's unlock keeps its guard.
	*/
	public function testANestedHaltKeepsTheOuterHaltsUnlockingFlag()
	{
		$db = new class('fake_db', 'fake_user', '', '127.0.0.1', '', '1') extends ScriptedDb {
			public $seen = [];
			private $nested = false;

			public function unlock($haltOnError = true)
			{
				if (!$this->nested) {
					$this->nested = true;
					$this->halt('inner');
					$this->seen[] = $this->haltUnlocking;
				}
				return parent::unlock($haltOnError);
			}

			public function unlocking()
			{
				return $this->haltUnlocking;
			}
		};
		$db->haltOnError = 'no';
		$this->assertTrue($this->q($db, 'SELECT 1'));

		$db->halt('outer');

		$this->assertSame([true], $db->seen);
		$this->assertFalse($db->unlocking());
	}

	/**
	* D1: the line a recovered replay logs redacts a session id (a bearer token) in the statement.
	*/
	public function testTheReplayLogLineRedactsASessionId()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "delete from appsession where as_sid='s3ss10nt0k3n'"));
		FakeServer::failNext('/^delete from sessions/', 1213);

		$this->assertTrue($this->q($db, "delete from sessions where session_id='s3ss10nt0k3n'"));

		$this->assertCount(1, FakeServer::$log);
		$this->assertStringContainsString('the statement went through', FakeServer::$log[0]);
		$this->assertStringNotContainsString('s3ss10nt0k3n', FakeServer::$log[0]);
	}

	public static function startsBesideAnOpenTransaction()
	{
		return [
			'START TRANSACTION' => ['START TRANSACTION'],
			'BEGIN' => ['BEGIN'],
			'transactionBegin()' => ['transactionBegin'],
		];
	}

	/**
	* F1: a handle with no transaction of its own (Session's, a get_module_db() clone) starting one while another handle
	* sharing the connection has one open: the START would commit that transaction (MySQL commits the open one
	* first), and its caller would find it lost. The START goes to a connection of its own instead, the shared one is
	* left alone (not closed), and both transactions commit on their own.
	*
	* @dataProvider startsBesideAnOpenTransaction
	* @param string $how
	*/
	#[DataProvider('startsBesideAnOpenTransaction')]
	public function testAStartOfAHandleWithNoTransactionRunsOnAConnectionOfItsOwnWhenAnotherHasOneOpen($how)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SELECT 2'), 'the other handle has used the shared connection');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$shared = $db->linkId;

		$this->assertTrue($how === 'transactionBegin' ? $other->transactionBegin() : $this->q($other, $how));

		$this->assertSame([], self::writes(), 'the START committed nothing of the other transaction');
		$this->assertTrue($other->inTransaction());
		$this->assertNotSame($shared, $other->linkId, 'it began on a connection of its own');
		$this->assertSame($shared, $db->linkId);
		$this->assertSame(2, FakeServer::$connections);
		$this->assertSame(0, FakeServer::closes(), 'the shared connection was not closed');
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (3)'), 'the first transaction is still open');
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (3)'], self::writes());
		$this->assertFalse($db->linkHasOpenTransaction(), 'the other handle\'s transaction is on its own connection, not this one');
		$this->assertTrue($other->linkHasOpenTransaction());
		$this->assertTrue($other->transactionCommit());
		$this->assertFalse($db->lost());
		$this->assertFalse($other->lost());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (3)', 'INSERT INTO t VALUES (2)'], self::writes());
		$this->assertStringContainsString('opening a connection of its own for it', implode("\n", FakeServer::$log));
	}

	/**
	* F1 control: with no transaction open on the shared connection (none yet, one committed, or one lost and given
	* up, which the server no longer holds), a START stays on it.
	*
	* @dataProvider startsBesideAnOpenTransaction
	* @param string $how
	*/
	#[DataProvider('startsBesideAnOpenTransaction')]
	public function testAStartStaysOnTheSharedConnectionWhenNoOtherHandleHasATransactionOpen($how)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$shared = $db->linkId;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($db, 'COMMIT'));

		$this->assertTrue($how === 'transactionBegin' ? $other->transactionBegin() : $this->q($other, $how));

		$this->assertSame($shared, $other->linkId);
		$this->assertSame(1, FakeServer::$connections);
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($other->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
		$this->assertSame([], FakeServer::$log);
	}

	/**
	* F1: the START stays on the shared connection when its session state was changed (a user variable here): a new
	* connection would not have it. Then it ends the other transaction there as it always did (that one is lost, see
	* testAnotherHandleEndingTheTransactionOnTheSharedLinkLosesIt), and a log line says so.
	*/
	public function testAStartOnAConnectionWhoseSessionStateWasChangedStaysThereAndSaysSo()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'SET @pin = 1'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));

		$this->assertTrue($this->q($other, 'START TRANSACTION'));

		$this->assertSame($db->linkId, $other->linkId);
		$this->assertSame(1, FakeServer::$connections);
		$this->assertStringContainsString('the session state of the connection was changed', implode("\n", FakeServer::$log));
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->lost());
	}

	/**
	* F1: linkHasOpenTransaction() answers for every handle sharing the connection, whichever is asked, and only for
	* those: a transaction open, or lost and not yet ended by its caller.
	*/
	public function testLinkHasOpenTransactionAsksEveryHandleSharingTheConnection()
	{
		$db = $this->db();
		$this->assertFalse($db->linkHasOpenTransaction(), 'no connection yet');
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$silent = clone $db;
		$apart = $this->db();
		$this->assertTrue($this->q($apart, 'SELECT 1'));
		$this->assertFalse($db->linkHasOpenTransaction());
		$this->assertFalse($other->linkHasOpenTransaction());

		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($db->linkHasOpenTransaction(), 'its own');
		$this->assertTrue($other->linkHasOpenTransaction(), 'another handle\'s');
		$this->assertTrue($silent->linkHasOpenTransaction(), 'asked by a handle that never sent a statement');
		$this->assertFalse($apart->linkHasOpenTransaction(), 'a handle on another connection is not asked');
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertFalse($other->linkHasOpenTransaction());

		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertFalse($db->linkHasOpenTransaction());
		$this->assertFalse($other->linkHasOpenTransaction());

		$this->assertTrue($other->transactionBegin());
		$this->assertTrue($db->linkHasOpenTransaction(), 'another handle\'s, begun by transactionBegin()');
		$this->assertTrue($other->transactionAbort());
		$this->assertFalse($db->linkHasOpenTransaction());
	}

	/**
	* F1: a transaction that was lost while its caller still takes it to be open (its statements refused until it ends
	* it) counts as open, for its own handle and for the handles sharing its connection.
	*/
	public function testLinkHasOpenTransactionCountsALostTransactionItsCallerHasNotEnded()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($other, 'INSERT INTO u VALUES (1)'), 'not replayable now');
		FakeServer::failNext('/VALUES \(2\)/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->lostTransactionPending());
		$this->assertFalse($db->inTransaction());

		$this->assertTrue($db->linkHasOpenTransaction(), 'its own');
		$this->assertSame($db->linkId, $other->linkId, 'the give-up kept the connection (it sent a ROLLBACK on it)');
		$this->assertTrue($other->linkHasOpenTransaction(), 'another handle\'s');

		$this->assertTrue($this->q($db, 'ROLLBACK'));
		$this->assertFalse($db->linkHasOpenTransaction());
		$this->assertFalse($other->linkHasOpenTransaction());
	}

	/**
	* @return array[] [what makes the handle find its transaction ended by the other handle]
	*/
	public static function noticesOfAnEndElsewhere()
	{
		return [
			'its next statement' => ['statement'],
			"query('COMMIT')" => ['COMMIT'],
			'transactionCommit()' => ['transactionCommit'],
			'transactionBegin()' => ['transactionBegin'],
			'real_escape()' => ['real_escape'],
			'selectDb()' => ['selectDb'],
		];
	}

	/**
	* The two handles of F2 (a user variable keeps the other handle's START on the shared connection), the first
	* handle's transaction ended there by the other handle's START, which has its own open.
	*
	* @return ScriptedDb[] [the handle whose transaction was ended, the other handle]
	*/
	private function endedByAnotherStart()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SET @pin = 1'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($other, 'START TRANSACTION'), 'commits the first transaction, then begins its own');
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (2)'));
		return [$db, $other];
	}

	/**
	* F2: a handle that finds its transaction ended by another handle sharing the connection marks only its own
	* transaction lost and lets go of the connection without closing it: the other handle's transaction, open there,
	* commits. Before, it closed the connection, which rolled that transaction back, and the other handle's COMMIT
	* failed.
	*
	* @dataProvider noticesOfAnEndElsewhere
	* @param string $how
	*/
	#[DataProvider('noticesOfAnEndElsewhere')]
	public function testAHandleWhoseTransactionAnotherEndedLetsGoOfTheConnectionWithoutClosingIt($how)
	{
		list($db, $other) = $this->endedByAnotherStart();
		$shared = $other->linkId;

		if ($how === 'statement') {
			$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (3)'));
			$this->assertSame(0, FakeServer::sent('INSERT INTO t VALUES (3)'));
		} elseif ($how === 'COMMIT') {
			$this->assertFalse($this->q($db, 'COMMIT'));
		} elseif ($how === 'real_escape') {
			$this->assertSame("a\\'b", $db->real_escape("a'b"));
		} elseif ($how === 'selectDb') {
			$db->selectDb('other_db');
		} else {
			$this->assertFalse($db->{$how}());
		}

		$this->assertSame(2006, (int) $db->Errno);
		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertSame(0, $db->linkId, 'it let go of the shared connection');
		$this->assertSame(0, FakeServer::closes(), 'without closing it');
		$this->assertSame($shared, $other->linkId);
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (4)'), 'the other handle\'s transaction is still open');
		$this->assertTrue($other->transactionCommit());
		$this->assertFalse($other->lost());
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (2)'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (4)'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (1)'), 'committed by the START, once');
		$this->assertTrue($this->q($db, 'ROLLBACK'), 'its caller ends the lost transaction, on a connection of its own');
		$this->assertNotSame(0, $db->linkId);
		$this->assertNotSame($shared, $db->linkId);
		$this->assertSame(0, FakeServer::closes());
	}

	public static function rollbacks()
	{
		return [
			"query('ROLLBACK')" => ['ROLLBACK'],
			'transactionAbort()' => ['transactionAbort'],
		];
	}

	/**
	* F2: a ROLLBACK of a transaction another handle ended is not sent: it would roll back what is open on the shared
	* connection now (the other handle's transaction). It fails (2006: whether this transaction's writes were
	* committed is not known), the transaction is over and lost (a COMMIT is refused, what the caller sends next is
	* not), and the handle lets go of the connection without closing it. Before, the ROLLBACK went out on the shared
	* connection and the other handle's COMMIT then committed nothing of its transaction.
	*
	* @dataProvider rollbacks
	* @param string $how
	*/
	#[DataProvider('rollbacks')]
	public function testARollbackOfATransactionAnotherHandleEndedIsNotSent($how)
	{
		list($db, $other) = $this->endedByAnotherStart();
		$shared = $other->linkId;
		$rollbacks = FakeServer::sent('ROLLBACK');

		$this->assertFalse($how === 'transactionAbort' ? $db->transactionAbort() : $this->q($db, 'ROLLBACK'));

		$this->assertSame($rollbacks, FakeServer::sent('ROLLBACK'), 'not sent');
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertStringContainsString('ROLLBACK not sent', $db->Error);
		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertSame(0, $db->linkId);
		$this->assertSame(0, FakeServer::closes());
		$this->assertStringContainsString('ROLLBACK not sent', implode("\n", FakeServer::$log));
		$this->assertCount($how === 'transactionAbort' ? 0 : 1, $db->reported, 'a failed statement is reported; transactionAbort() never mails');
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (4)'));
		$this->assertTrue($other->transactionCommit());
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (2)'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (4)'));
		$this->assertFalse($db->transactionCommit(), 'a COMMIT is refused: the outcome is unknown');
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (5)'), 'not refused: its caller ended the transaction');
		$this->assertNotSame($shared, $db->linkId);
		$this->assertSame(0, FakeServer::closes());
	}

	/**
	* F3: a clone of a handle whose transaction gave up (and whose caller has not ended it) has no transaction of its
	* own: it refuses nothing, and its COMMIT is not refused. Before, it copied the refusal and refused its own
	* statements (get_module_db() clones the application's handle).
	*/
	public function testACloneOfAHandleThatGaveUpRefusesNothing()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/VALUES \(2\)/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->lostTransactionPending());

		$clone = clone $db;

		$this->assertFalse($clone->lostTransactionPending());
		$this->assertFalse($clone->lost());
		$this->assertFalse($clone->inTransaction());
		$this->assertSame([], $clone->steps());
		$this->assertTrue($this->q($clone, 'INSERT INTO u VALUES (1)'), 'its own statement runs');
		$this->assertSame(1, self::committed('INSERT INTO u VALUES (1)'));
		$this->assertTrue($clone->transactionCommit(), 'nothing of its own to refuse');
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (3)'), 'its source still refuses: its caller has not ended it');
		$this->assertSame(0, FakeServer::sent('INSERT INTO t VALUES (3)'));
		$this->assertTrue($db->lostTransactionPending());
	}

	/**
	* F3: a clone made inside a transaction shares the connection, and so runs inside that transaction on the server,
	* but the transaction is its source's: the clone has none of its own (nothing recorded, no COMMIT of its own to
	* refuse), and the source commits it.
	*/
	public function testACloneMadeInsideATransactionHasNoTransactionOfItsOwn()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));

		$clone = clone $db;

		$this->assertFalse($clone->inTransaction());
		$this->assertSame([], $clone->steps());
		$this->assertSame($db->linkId, $clone->linkId, 'the connection is shared');
		$this->assertTrue($clone->linkHasOpenTransaction(), 'its source\'s, on the shared connection');
		$this->assertTrue($this->q($clone, 'SELECT 2'));
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertFalse($db->lost());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
	}

	/**
	* F3: every piece of transaction bookkeeping a clone could copy starts as on a new handle, whatever its source was
	* doing; the connection is shared.
	*/
	public function testACloneStartsWithTheTransactionBookkeepingOfANewHandle()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$fresh = $this->db();
		$busy = [
			'inTransaction' => true,
			'transactionLost' => true,
			'transactionLostErrno' => 1213,
			'transactionLostError' => 'Deadlock',
			'txRefuse' => true,
			'txOpener' => 'START TRANSACTION',
			'txSteps' => [['sql' => 'INSERT INTO t VALUES (1)']],
			'txReplayable' => true,
			'txBytes' => 24,
			'txSeq' => 3,
			'txLastStep' => 0,
			'txNotReplayableWhy' => 'why',
			'txLink' => $db->linkId,
			'txEnds' => 2,
			'txEndedElsewhere' => true,
			'replaying' => true,
			'reportingFailure' => true,
			'haltUnlocking' => true,
		];
		foreach ($busy as $name => $value) {
			$property = new \ReflectionProperty($db, $name);
			$property->setAccessible(true);
			$property->setValue($db, $value);
		}

		$clone = clone $db;

		foreach (array_keys($busy) as $name) {
			$property = new \ReflectionProperty($db, $name);
			$property->setAccessible(true);
			$this->assertSame($property->getValue($fresh), $property->getValue($clone), $name);
			$this->assertSame($busy[$name], $property->getValue($db), $name.' of the source is left alone');
		}
		$this->assertSame($db->linkId, $clone->linkId);
	}

	public static function unusableHandles()
	{
		return [
			'closed by another holder, transaction open' => ['closed', true],
			'closed by another holder, nothing open' => ['closed', false],
			'half-open (a connect that failed), transaction open' => ['halfOpen', true],
			'half-open, nothing open' => ['halfOpen', false],
		];
	}

	/**
	* transactionAbort() on a handle mysqli cannot use (closed by another holder of the shared link, or half-open)
	* returns false instead of letting mysqli's \Error escape (CcMeta::transaction() calls it after a failure), and lets
	* go of the handle, so the next call connects anew. Before, mysqli_rollback() threw.
	*
	* @dataProvider unusableHandles
	* @param string $how
	* @param bool $open
	*/
	#[DataProvider('unusableHandles')]
	public function testTransactionAbortOnAHandleMysqliCannotUseReturnsFalseInsteadOfThrowing($how, $open)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		if ($open) {
			$this->assertTrue($this->q($db, 'START TRANSACTION'));
			$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		}
		if ($how === 'closed') {
			$other = clone $db;
			$other->disconnect();
		} else {
			self::halfOpen($db);
		}

		$this->assertFalse($db->transactionAbort(), 'false, not an \\Error');

		$this->assertSame(2006, (int) $db->Errno);
		$this->assertFalse($db->inTransaction());
		$this->assertSame(0, $db->linkId, 'let go of: the next call connects anew');
		$this->assertSame([], self::writes());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertSame(['INSERT INTO t VALUES (2)'], self::writes());
	}

	/**
	* The same with a transaction that gave up (lost, its statements refused): the failed abort keeps the lost record,
	* as a ROLLBACK that fails on a dead link does, so a COMMIT is still refused.
	*/
	public function testTransactionAbortOnAClosedHandleKeepsTheLostRecord()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/VALUES \(2\)/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->lost());
		$this->assertSame($db->linkId, $other->linkId, 'the give-up kept the connection');
		$other->disconnect();

		$this->assertFalse($db->transactionAbort());

		$this->assertTrue($db->lost());
		$this->assertSame(0, $db->linkId);
		$sent = FakeServer::sent('COMMIT');
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame($sent, FakeServer::sent('COMMIT'), 'refused, not sent');
		$this->assertSame([], self::writes());
	}

	/**
	* After another handle's statement, outside a transaction of its own, made the server roll back the transaction
	* this handle has open on the shared connection (1213, 3101), the server keeps the connection in a transaction
	* (OPTION_BEGIN, see fake_mysqli.php). Its retry, and its statements after it, must not run in that transaction:
	* nobody would commit them (they would be lost when the connection closes, or committed by whatever COMMIT comes
	* next), while query() reports them done. A ROLLBACK ends it first (a 1180 leaves none behind: no ROLLBACK).
	* Before, the first handle closed the connection on finding its transaction lost, which threw the other handle's
	* reported-done statement away; with F2 it lets go of the connection without closing it.
	*
	* @dataProvider rollingBackFailures
	* @param int $errno
	* @param bool $strict
	*/
	#[DataProvider('rollingBackFailures')]
	public function testAnotherHandlesRetryAfterItRolledATransactionBackRunsWithAutocommit($errno, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^INSERT INTO log/', $errno);

		$this->assertTrue($this->q($other, 'INSERT INTO log VALUES (1)'));

		$this->assertSame(['INSERT INTO log VALUES (1)'], self::writes(), 'committed, as reported');
		$this->assertSame([], FakeServer::$open, 'no transaction left open on the connection');
		$this->assertSame($errno === 1180 ? 0 : 1, FakeServer::sent('ROLLBACK'));
		$this->assertTrue($this->q($other, 'INSERT INTO log VALUES (2)'));
		$this->assertSame(['INSERT INTO log VALUES (1)', 'INSERT INTO log VALUES (2)'], self::writes());
		$this->assertLostAndRefused($db);
		$this->assertSame(0, FakeServer::closes());
		$this->assertSame(['INSERT INTO log VALUES (1)', 'INSERT INTO log VALUES (2)'], self::writes());
	}

	/**
	* The same after a prepared statement of the other handle's (execute() is not retried): its next statement runs
	* with autocommit.
	*
	* @dataProvider rollingBackFailures
	* @param int $errno
	* @param bool $strict
	*/
	#[DataProvider('rollingBackFailures')]
	public function testAnotherHandlesStatementAfterAPreparedStatementRolledATransactionBackRunsWithAutocommit($errno, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotSame(0, $other->prepare('INSERT INTO log VALUES (1)'));
		FakeServer::failNext('/^INSERT INTO log/', $errno);
		try {
			$this->assertFalse($other->execute());
			$this->assertFalse($strict, 'returned only with reporting off');
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			$this->assertSame($errno, $e->getCode());
		}

		$this->assertSame([], FakeServer::$open, 'no transaction left open on the connection');
		$this->assertTrue($this->q($other, 'INSERT INTO log VALUES (2)'));
		$this->assertSame(['INSERT INTO log VALUES (2)'], self::writes(), 'committed, as reported');
		$this->assertLostAndRefused($db);
	}

	/** @return int how many ROLLBACKs (any form, savepoints aside) reached a server */
	private static function rollbacksSent()
	{
		return count(array_filter(FakeServer::statements(), static function ($sql) {
			return preg_match('/^\s*(?:\/\*.*?\*\/\s*)*rollback\b(?!\s+(?:work\s+)?to\b)/is', $sql) === 1;
		}));
	}

	public static function rollbacksWithoutATransaction()
	{
		return [
			"query('ROLLBACK')" => ['ROLLBACK'],
			"query('ROLLBACK WORK')" => ['ROLLBACK WORK'],
			"query('/* cleanup */ rollback')" => ['/* cleanup */ rollback'],
			"query('ROLLBACK AND NO CHAIN')" => ['ROLLBACK AND NO CHAIN'],
			"query('ROLLBACK RELEASE')" => ['ROLLBACK RELEASE'],
			'transactionAbort()' => ['transactionAbort'],
		];
	}

	/**
	* Fix 5: a plain ROLLBACK (or transactionAbort()) of a handle that has no transaction of its own, while another
	* handle sharing the connection has one open there, is not sent: on the server it would end that other
	* transaction (one per connection), whose writes would be gone and whose COMMIT would fail. It succeeds as a
	* ROLLBACK with nothing open does, and says so in one log line. Before, it went out and rolled the other
	* handle's transaction back.
	*
	* @dataProvider rollbacksWithoutATransaction
	* @param string $how
	*/
	#[DataProvider('rollbacksWithoutATransaction')]
	public function testARollbackOfAHandleWithNoTransactionOfItsOwnLeavesAnotherHandlesTransactionAlone($how)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$sent = count(FakeServer::statements());

		$this->assertTrue($how === 'transactionAbort' ? $other->transactionAbort() : $this->q($other, $how), 'a ROLLBACK with nothing to roll back succeeds');

		$this->assertSame($sent, count(FakeServer::statements()), 'nothing was sent');
		$this->assertSame(0, self::rollbacksSent());
		$this->assertSame(0, (int) $other->Errno);
		$this->assertFalse($other->inTransaction());
		$this->assertFalse($other->lost());
		$this->assertStringContainsString('ROLLBACK not sent: this handle has no transaction open', implode("\n", FakeServer::$log));
		$this->assertSame($db->linkId, $other->linkId, 'the connection is still shared');
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertFalse($db->lost());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
		$this->assertSame([], $db->reported);
		$this->assertSame([], $other->reported);
		$this->assertSame(0, FakeServer::closes());
		$this->assertTrue($this->q($other, 'ROLLBACK'), 'with nothing open on the connection it is sent, as before');
		$this->assertSame(1, self::rollbacksSent());
	}

	/**
	* Fix 5, the reviewers' shape (W1): a handle whose COMMIT failed (3100: given up, rolled back) cleans up with a
	* ROLLBACK or transactionAbort() after a clone of it began a transaction of its own on the shared connection. The
	* cleanup must not end the clone's transaction; the handle's lost record is cleared (its next transaction starts
	* normally). Before, the ROLLBACK went out and the clone's writes were gone (its COMMIT found the transaction
	* ended: 2006).
	*
	* @dataProvider rollbacks
	* @param string $how
	*/
	#[DataProvider('rollbacks')]
	public function testACleanupRollbackAfterAGiveUpLeavesACloneOfItsTransactionAlone($how)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^COMMIT$/', 3100);
		$this->assertFalse($db->transactionCommit());
		$this->assertFalse($db->inTransaction());
		$clone = clone $db;
		$this->assertTrue($this->q($clone, 'START TRANSACTION'));
		$this->assertSame($db->linkId, $clone->linkId, 'nothing open beside it: the START stays on the shared connection');
		$this->assertTrue($this->q($clone, 'INSERT INTO b VALUES (2)'));
		$rollbacks = self::rollbacksSent();

		$this->assertTrue($how === 'transactionAbort' ? $db->transactionAbort() : $this->q($db, 'ROLLBACK'));

		$this->assertSame($rollbacks, self::rollbacksSent(), 'not sent');
		$this->assertFalse($db->lost(), 'the lost record is cleared, as by any successful ROLLBACK');
		$this->assertTrue($clone->transactionCommit(), 'the clone commits what it wrote');
		$this->assertSame(['INSERT INTO b VALUES (2)'], self::writes());
		$this->assertTrue($this->q($db, 'START TRANSACTION'), 'the handle\'s next transaction starts normally');
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (3)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO b VALUES (2)', 'INSERT INTO a VALUES (3)'], self::writes());
	}

	/**
	* Fix 5: a ROLLBACK ... AND CHAIN of a handle with no transaction of its own begins one (MySQL chains even with
	* nothing open): beside another handle's open transaction it does that on a connection of its own, like a START,
	* and both transactions commit whole. Before, it went out on the shared connection and rolled the other back.
	*/
	public function testARollbackAndChainOfAHandleWithNoTransactionBeginsItOnAConnectionOfItsOwn()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$shared = $db->linkId;

		$this->assertTrue($this->q($other, 'ROLLBACK AND CHAIN'));

		$this->assertTrue($other->inTransaction());
		$this->assertNotSame($shared, $other->linkId, 'on a connection of its own');
		$this->assertSame($shared, $db->linkId);
		$this->assertSame(2, FakeServer::$connections);
		$this->assertSame(0, FakeServer::closes());
		$this->assertTrue($this->q($other, 'INSERT INTO u VALUES (1)'));
		$this->assertTrue($other->transactionCommit());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertFalse($db->lost());
		$this->assertSame(['INSERT INTO u VALUES (1)', 'INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
		$this->assertStringContainsString('opening a connection of its own for it', implode("\n", FakeServer::$log));
	}

	public static function notRunEnds()
	{
		$cases = [];
		foreach ([1064, 1065, 1149, 2014] as $errno) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$errno.' '.$mode] = [$errno, $strict];
			}
		}
		return $cases;
	}

	/**
	* Fix 6 (NeverRunEndReproTest): a statement of another handle's that is taken for the end of the transaction
	* (a DDL commits implicitly) but that the server never ran (it did not parse, or it was never sent) ended
	* nothing: the transaction goes on and commits whole. Before, it was counted as the end, the holder gave the
	* transaction up as ended elsewhere without a ROLLBACK, and its write stayed open on the shared connection for the
	* next START there to commit.
	*
	* @dataProvider notRunEnds
	* @param int $errno
	* @param bool $strict
	*/
	#[DataProvider('notRunEnds')]
	public function testAnEndAnotherHandleSentThatTheServerNeverRanEndsNothing($errno, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^CREATE TABLE/', $errno, self::ALWAYS);

		$this->assertFalse($this->q($other, 'CREATE TABLE x ('));
		$this->assertSame(['INSERT INTO t VALUES (1)'], FakeServer::$open[1] ?? null, 'still open on the server');

		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'), 'the transaction goes on');
		$this->assertTrue($db->transactionCommit());
		$this->assertFalse($db->lost());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes(), 'committed whole, once');
		$this->assertTrue($this->q($other, 'START TRANSACTION'));
		$this->assertTrue($this->q($other, 'COMMIT'));
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
	}

	/**
	* Fix 6: the same for a statement of the holder's own inside its transaction (a DDL that did not parse): its count
	* is taken back on the link and in the transaction's own record alike, so the transaction is not taken for ended
	* elsewhere and goes on.
	*/
	public function testAnEndOfItsOwnThatTheServerNeverRanLeavesItsTransactionGoingOn()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^CREATE TABLE/', 1064, self::ALWAYS);

		$this->assertFalse($this->q($db, 'CREATE TABLE x ('));

		$this->assertTrue($db->inTransaction());
		$this->assertFalse($db->lost());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
	}

	public static function notRunEndPaths()
	{
		return [
			'transactionCommit() (send())' => ['transactionCommit'],
			'execute()' => ['execute'],
			'lock()' => ['lock'],
		];
	}

	/**
	* Fix 6: the same through the other paths that count an end before sending it: transactionCommit() with nothing
	* open (send()), a prepared statement, lock().
	*
	* @dataProvider notRunEndPaths
	* @param string $how
	*/
	#[DataProvider('notRunEndPaths')]
	public function testAnEndAnotherHandleSentThatTheServerNeverRanEndsNothingOnEveryPath($how)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		if ($how === 'transactionCommit') {
			FakeServer::failNext('/^COMMIT$/', 2014);
			$this->assertFalse($other->transactionCommit());
		} elseif ($how === 'execute') {
			$this->assertNotSame(0, $other->prepare('CREATE TABLE x ('));
			FakeServer::failNext('/^CREATE TABLE/', 1064);
			$this->assertFalse($other->execute());
		} else {
			FakeServer::failNext('/^lock tables/i', 1064);
			$this->assertSame(0, $other->lock('t'));
		}
		$this->assertSame(['INSERT INTO t VALUES (1)'], FakeServer::$open[1] ?? null, 'still open on the server');

		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'), 'the transaction goes on');
		$this->assertTrue($db->transactionCommit());
		$this->assertFalse($db->lost());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
	}

	/**
	* Fix 6: an end the server did run is still an end: a COMMIT of another handle's that ran (here: the server ran
	* it, then the reply was lost, 2013) keeps the transaction lost; only the not-run errors take the count back.
	*/
	public function testAnEndAnotherHandleSentThatFailedAfterRunningStillEndsTheTransaction()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^CREATE TABLE/', 1050, self::ALWAYS);

		$this->assertFalse($this->q($other, 'CREATE TABLE x (a int)'), '1050: table exists (the implicit commit ran first)');

		$this->assertLostAndRefused($db);
	}

	public static function miscountedEnds()
	{
		return [
			'SET autocommit=1 with autocommit already on' => ['SET autocommit=1'],
			'a DDL refused before its implicit commit' => ['CREATE TABLE x (a int)'],
		];
	}

	/**
	* Fix 6: a statement of another handle's taken for an end that ended nothing on the server although it ran (SET
	* autocommit=1 with autocommit already on; the fake does not commit on it, as MySQL does not then) makes the
	* holder give its transaction up (lost, 2006), and the holder rolls it back with a quiet ROLLBACK before letting
	* go of the connection, since no other handle has a transaction open there: its write is not left open on the
	* shared connection for the next START or COMMIT there to commit. Before, it was left open and the other
	* handle's next START committed it.
	*
	* @dataProvider miscountedEnds
	* @param string $sql
	*/
	#[DataProvider('miscountedEnds')]
	public function testAMiscountedEndRollsTheGivenUpTransactionBackInsteadOfLeavingItOpen($sql)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotFalse($this->q($other, $sql));
		$this->assertArrayHasKey(1, FakeServer::$open, 'the fake left the transaction open, as the server would');

		$this->assertLostAndRefused($db);

		$this->assertSame([], FakeServer::$open, 'the given-up transaction was rolled back');
		$this->assertSame(1, self::rollbacksSent());
		$this->assertSame(0, FakeServer::closes(), 'the shared connection is not closed');
		$this->assertTrue($this->q($other, 'START TRANSACTION'));
		$this->assertTrue($this->q($other, 'INSERT INTO u VALUES (1)'));
		$this->assertTrue($other->transactionCommit());
		$this->assertSame(['INSERT INTO u VALUES (1)'], self::writes(), 'nothing of the given-up transaction was committed');
	}

	public static function usableHandlesWithNothingOpen()
	{
		return [
			'transactionCommit(), closed by another holder' => ['transactionCommit', 'closed', false],
			'transactionCommit(), half-open' => ['transactionCommit', 'halfOpen', false],
			'transactionBegin(), half-open' => ['transactionBegin', 'halfOpen', false],
			// connect() finds a closed link and connects anew: the START succeeds there, as before
			'transactionBegin(), closed by another holder (reconnects, as before)' => ['transactionBegin', 'closed', true],
		];
	}

	/**
	* Fix 7: transactionCommit() and transactionBegin() with nothing open on a handle mysqli cannot use (closed by
	* another holder of the shared link, or half-open) return false (2006) instead of letting mysqli's \Error escape,
	* and let go of the handle, so the next call connects anew. Before, the \Error escaped. transactionBegin() on a
	* closed handle still reconnects and begins (connect() finds it closed), as before.
	*
	* @dataProvider usableHandlesWithNothingOpen
	* @param string $call
	* @param string $how
	* @param bool $begins
	*/
	#[DataProvider('usableHandlesWithNothingOpen')]
	public function testCommitOrBeginWithNothingOpenOnAHandleMysqliCannotUseReturnsFalseInsteadOfThrowing($call, $how, $begins)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		if ($how === 'closed') {
			$other = clone $db;
			$other->disconnect();
		} else {
			self::halfOpen($db);
		}

		if ($begins) {
			$this->assertTrue($db->{$call}());
			$this->assertTrue($db->inTransaction());
			$this->assertSame(2, FakeServer::$connections, 'on a new connection');
		} else {
			$this->assertFalse($db->{$call}(), 'false, not an \\Error');
			$this->assertSame(2006, (int) $db->Errno);
			$this->assertFalse($db->inTransaction());
			$this->assertSame(0, $db->linkId, 'let go of: the next call connects anew');
			$this->assertTrue($db->transactionBegin());
		}
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes());
	}

	/**
	* linkHasOpenTransaction(true) asks only the other handles sharing the connection: this instance's own open or
	* lost-and-pending transaction is left out (Session at shutdown rolls its own back, but must not end another's).
	*/
	public function testLinkHasOpenTransactionOthersOnlyLeavesThisInstancesOwnOut()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($db->linkHasOpenTransaction());
		$this->assertFalse($db->linkHasOpenTransaction(true), 'its own is left out');
		$this->assertTrue($other->linkHasOpenTransaction(true), 'another handle\'s counts');
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/VALUES \(2\)/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->lostTransactionPending());
		$this->assertTrue($db->linkHasOpenTransaction());
		$this->assertFalse($db->linkHasOpenTransaction(true), 'its own lost-and-pending one is left out too');
		$this->assertTrue($other->linkHasOpenTransaction(true));
		$this->assertTrue($this->q($db, 'ROLLBACK'));
		$this->assertFalse($other->linkHasOpenTransaction(true));
	}

	/**
	* $reportFailures off: a failed statement is not mailed (emailError() is not called); one log line says the
	* caller reports it, and halt() still runs. On (the default) it is reported as always.
	*/
	public function testReportFailuresOffLeavesTheReportToTheCaller()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		FakeServer::failNext('/^SELECT x$/', 1146);
		$this->assertFalse($this->q($db, 'SELECT x'));
		$this->assertCount(1, $db->reported, 'reported by default');
		$this->assertSame(1, $db->halts);

		$db->reportFailures = false;
		FakeServer::failNext('/^SELECT y$/', 1146);
		$this->assertFalse($this->q($db, 'SELECT y'));
		$this->assertSame(1146, (int) $db->Errno);
		$this->assertCount(1, $db->reported, 'not reported');
		$this->assertSame(2, $db->halts, 'halt() still runs');
		$this->assertStringContainsString('SQL error #1146 not reported here (reportFailures is off', implode("\n", FakeServer::$log));
	}
}
