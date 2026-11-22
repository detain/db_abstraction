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
			// a plain ROLLBACK (or transactionAbort()) of a handle with no transaction of its own is not sent while
			// another handle has one open on the link (see
			// testARollbackOfAHandleWithNoTransactionOfItsOwnFailsAndLeavesAnotherHandlesTransactionAlone), nor is a COMMIT
			// (query() or transactionCommit(), see testACommitOfAHandleWithNoTransactionOfItsOwnLeavesAnotherHandlesTransactionAlone)
			// a START of a handle with no transaction of its own moves to a connection of its own (see
			// testAStartOfAHandleWithNoTransactionRunsOnAConnectionOfItsOwnWhenAnotherHasOneOpen), except when the
			// shared connection's session state was changed: then it still ends the transaction there
			'START TRANSACTION (session state pinned)' => ['START TRANSACTION'],
			'BEGIN (session state pinned)' => ['BEGIN'],
			'DDL (implicit commit)' => ['CREATE TABLE x (a int)'],
			'TRUNCATE (implicit commit)' => ['TRUNCATE TABLE t'],
			'SET autocommit (implicit commit)' => ['SET autocommit=1'],
			'transactionBegin() (session state pinned)' => ['transactionBegin'],
			'lock()' => ['lock'],
			// round 7 F1: an executable comment stays doubtful for another holder (counted as an end), and the
			// statements MySQL commits on that endsTransaction() did not know before
			'an executable comment (doubtful)' => ['CREATE /*!32312 TEMPORARY */ TABLE tmp (a int)'],
			'a statement held by an executable comment (doubtful)' => ['/*!40000 ALTER TABLE t DISABLE KEYS */'],
			'START REPLICA (implicit commit)' => ['START REPLICA'],
			'STOP REPLICA (implicit commit)' => ['STOP REPLICA'],
			'CHANGE REPLICATION SOURCE (implicit commit)' => ["CHANGE REPLICATION SOURCE TO SOURCE_HOST='h'"],
			'IMPORT TABLE (implicit commit)' => ["IMPORT TABLE FROM '/tmp/t.sdi'"],
			'CLONE (implicit commit)' => ["CLONE LOCAL DATA DIRECTORY = '/tmp/c'"],
			'SET DEFAULT ROLE (implicit commit)' => ["SET DEFAULT ROLE ALL TO 'u'@'h'"],
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
	* A2: the same, found at the COMMIT itself (the other handle's START stays on the shared link, whose session state
	* was changed, and commits the transaction there first, as MySQL does).
	*
	* @dataProvider commits
	* @param string $how
	*/
	#[DataProvider('commits')]
	public function testAnotherHandleEndingTheTransactionIsFoundAtCommit($how)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SET @pin = 1'));
		$other = clone $db;
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($other, 'START TRANSACTION'));

		$this->assertFalse($how === 'COMMIT' ? $this->q($db, 'COMMIT') : $db->transactionCommit());

		$this->assertSame(2006, (int) $db->Errno, 'outcome unknown to this handle: not a conflict to retry');
		$this->assertSame(0, FakeServer::sent('COMMIT'), 'this handle\'s COMMIT is not sent');
		$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes(), 'committed once, by the other handle\'s START');
		$this->assertSame([], FakeServer::$open[1] ?? null, 'the other handle\'s transaction is left open on the link');
	}

	public static function harmlessForeign()
	{
		return [
			'SELECT' => ['SELECT 1'],
			'ROLLBACK TO SAVEPOINT' => ['ROLLBACK TO SAVEPOINT sp'],
			'CREATE TEMPORARY TABLE' => ['CREATE TEMPORARY TABLE tmp (a int)'],
			'DROP TEMPORARY TABLE' => ['DROP TEMPORARY TABLE tmp'],
			'UPDATE' => ['UPDATE other SET z=1'],
			// round 7 F1: they commit nothing, a comment between the words or not (4233584 counted them as ends)
			'CREATE /* c */ TEMPORARY TABLE' => ['CREATE /* c */ TEMPORARY TABLE tmp (a int)'],
			'DROP /* c */ TEMPORARY TABLE' => ['DROP /* c */ TEMPORARY TABLE tmp'],
			'DROP PREPARE' => ['DROP PREPARE s1'],
			'RESET PERSIST' => ['RESET PERSIST'],
			"CREATE TEMPORARY TABLE ... '/*'" => ["CREATE TEMPORARY TABLE tt AS SELECT '/*' AS x"],
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
		$this->assertTrue($this->q($other, 'TRUNCATE TABLE x'));
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

	public static function preparedImplicitCommitFailures()
	{
		$cases = [];
		foreach ([
			'1213 (outcome unknown)' => [1213, 2006],
			'1060 after the implicit commit (outcome unknown)' => [1060, 2006],
			'3101 at the implicit commit (rolled back)' => [3101, 3101],
			'1064 never ran (still open)' => [1064, null],
		] as $name => $case) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$name.', '.$mode] = [$case[0], $case[1], $strict];
			}
		}
		return $cases;
	}

	/**
	* Round 6 A, the execute() twin of queryInTransaction()'s rule: a prepared statement that commits implicitly (here
	* ALTER TABLE) and fails inside a transaction after the server ran it gives the transaction up with the errno of
	* lockFailed()'s rule (2006 for a 1213 or any error that may come after the commit; Errno, and the code of the
	* exception under strict mode, never the server's 1213), and its COMMIT is refused with it; one the server never
	* ran leaves the transaction open. Before, a 1213 gave it up as "rolled back" (1213) and a 1060 left it open.
	*
	* @dataProvider preparedImplicitCommitFailures
	* @param int $errno
	* @param int|null $lostWith null: not lost
	* @param bool $strict
	*/
	#[DataProvider('preparedImplicitCommitFailures')]
	public function testAPreparedStatementThatCommitsImplicitlyAndFailsGivesTheTransactionUpLikeQuery($errno, $lostWith, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotSame(0, $db->prepare('ALTER TABLE t ADD COLUMN c INT'));
		FakeServer::failNext('/^ALTER TABLE/', $errno);

		try {
			$this->assertFalse($db->execute());
			$this->assertFalse($strict, 'returned only with reporting off');
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			$this->assertSame($lostWith === null ? $errno : $lostWith, $e->getCode());
			if ($lostWith !== null && $lostWith !== $errno) {
				$this->assertSame($errno, $e->getPrevious()->getCode(), 'the server\'s exception stays reachable');
			}
		}

		if ($lostWith === null) {
			$this->assertTrue($db->inTransaction(), 'still open');
			$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
			$this->assertTrue($db->transactionCommit());
			$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
			return;
		}
		$this->assertSame($lostWith, (int) $db->Errno);
		$this->assertStringNotContainsString('the server has rolled it back', implode("\n", FakeServer::$log));
		$this->assertSame(1, FakeServer::sent('ROLLBACK'));
		$this->assertLostAndRefused($db, $lostWith);
		$this->assertSame([], self::writes());
	}

	/**
	* Round 6 A(c), execute(): a prepared statement that commits implicitly and works inside a transaction ended it (the
	* writes before it committed), so a 1213 on the next statement is retried on its own and the INSERT lands once.
	*/
	public function testAPreparedStatementThatCommitsImplicitlyEndsTheTransaction()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotSame(0, $db->prepare('TRUNCATE TABLE x'));
		self::implicitCommit();

		$this->assertTrue($db->execute());

		$this->assertFalse($db->inTransaction(), 'the transaction is over');
		$this->assertFalse($db->lost());
		FakeServer::failNext('/^UPDATE t/', 1213);
		$this->assertTrue($this->q($db, 'UPDATE t SET x=1'), 'retried on its own');
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE t SET x=1'], self::writes());
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
	* Fix 5 + round 6 B: a plain ROLLBACK (or transactionAbort()) of a handle that has no transaction of its own (and no
	* lost record of its own to clean up), while another handle sharing the connection has one open there, is not sent:
	* on the server it would end that other transaction (one per connection), whose writes would be gone and whose
	* COMMIT would fail. Nor does it succeed: the caller most likely meant that other transaction, and true would tell it
	* the writes were discarded while they stay open for that handle's next COMMIT or START to commit. It fails (2006,
	* "the open transaction belongs to another handle"), is never mailed and never halts, and says so in one log line
	* (no SQL values). Fix 5 answered true; before that it went out and rolled the other handle's transaction back.
	*
	* @dataProvider rollbacksWithoutATransaction
	* @param string $how
	*/
	#[DataProvider('rollbacksWithoutATransaction')]
	public function testARollbackOfAHandleWithNoTransactionOfItsOwnFailsAndLeavesAnotherHandlesTransactionAlone($how)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$sent = count(FakeServer::statements());

		$this->assertFalse($how === 'transactionAbort' ? $other->transactionAbort() : $this->q($other, $how), 'nothing was rolled back: it fails');

		$this->assertSame($sent, count(FakeServer::statements()), 'nothing was sent');
		$this->assertSame(0, self::rollbacksSent());
		$this->assertSame(2006, (int) $other->Errno, 'not a conflict errno: never retried into a duplicate');
		$this->assertSame('ROLLBACK not sent: the open transaction belongs to another handle: roll it back through the handle that began it', $other->Error);
		$this->assertSame(0, $other->halts, 'never halts');
		$this->assertFalse($other->inTransaction());
		$this->assertFalse($other->lost());
		$log = implode("\n", FakeServer::$log);
		$this->assertStringContainsString('ROLLBACK not sent: this handle has no transaction open, and the transaction open on the shared connection belongs to another handle; it fails (2006), nothing was rolled back', $log);
		$this->assertCount(1, FakeServer::$log, 'one line');
		$this->assertSame(['INSERT INTO t VALUES (1)'], FakeServer::$open[1] ?? null, 'the other transaction is untouched on the server');
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
	* Round 6 B, the reviewer's s3: handle A (a get_module_db() clone, say) runs START, an INSERT and an UPDATE that fails
	* on its own (1205: the transaction stays open); the caller rolls back through B, another handle of the clone family
	* (App::db(), or another get_module_db() call), and starts again on A. Fix 5 answered B's ROLLBACK true without
	* sending it, and A's next START committed the "rolled back" INSERT: a retry then applied it twice. Now B's ROLLBACK
	* fails (2006, nothing sent, A's transaction untouched), so a caller that reads the answer rolls back through the
	* handle that began it, and the INSERT lands once.
	*
	* @dataProvider rollbacks
	* @param string $how
	*/
	#[DataProvider('rollbacks')]
	public function testARollbackThroughTheWrongHandleOfTheCloneFamilyIsNeverTakenForDone($how)
	{
		FakeServer::$strict = false;
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$this->assertTrue($this->q($b, 'SELECT 2'));
		FakeServer::failNext('/^UPDATE u/', 1205);
		$wrongHandle = null;
		for ($attempt = 1; $attempt <= 2; $attempt++) {
			$this->assertTrue($this->q($a, 'START TRANSACTION'));
			$this->assertTrue($this->q($a, 'INSERT INTO t VALUES (1)'));
			if ($this->q($a, 'UPDATE u SET x=1')) {
				$this->assertTrue($a->transactionCommit());
				break;
			}
			$this->assertSame(1205, (int) $a->Errno, 'only the statement failed');
			$wrongHandle = $how === 'transactionAbort' ? $b->transactionAbort() : $this->q($b, 'ROLLBACK');
			if (!$wrongHandle) {
				$this->assertSame(2006, (int) $b->Errno);
				$this->assertTrue($a->inTransaction(), 'A\'s transaction goes on');
				$this->assertSame(['INSERT INTO t VALUES (1)'], FakeServer::$open[1] ?? null, 'nothing was rolled back, nothing committed');
				$this->assertSame([], self::writes());
				// told it failed, the caller rolls back through the handle that began it
				$this->assertTrue($how === 'transactionAbort' ? $a->transactionAbort() : $this->q($a, 'ROLLBACK'));
			}
		}

		$this->assertFalse($wrongHandle, 'the ROLLBACK through the wrong handle failed');
		$this->assertSame(1, self::rollbacksSent(), 'only the one through the handle that began it went out');
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (1)'), 'once, not twice');
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE u SET x=1'], self::writes());
		$this->assertSame([], $b->reported);
	}

	/**
	* Round 6 B: a ROLLBACK sent "just in case" through a handle with no transaction of its own, beside another handle's
	* open transaction, stays harmless under haltOnError 'yes': it fails (false, 2006) but is never mailed and never
	* halts (a halt there would end the process), and the other transaction commits whole. A COMMIT through the wrong
	* handle (commitWithoutTransaction()) is reported like any failed statement; a ROLLBACK, which saves nothing, is not.
	*
	* @dataProvider rollbacks
	* @param string $how
	*/
	#[DataProvider('rollbacks')]
	public function testAJustInCaseRollbackThroughAnotherHandleNeverHaltsUnderYes($how)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$other->haltOnError = 'yes';
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));

		$this->assertFalse($how === 'transactionAbort' ? $other->transactionAbort() : $this->q($other, 'ROLLBACK'));

		$this->assertSame(0, $other->halts, 'no halt(): under \'yes\' it would have ended the process');
		$this->assertSame([], $other->reported, 'not mailed');
		$this->assertSame(2006, (int) $other->Errno);
		$this->assertSame('yes', $other->haltOnError);
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes());
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
	* Round 7 N1: what the report of a failed COMMIT writes through the very same handle (the log row MyDbHandler writes
	* through App::db()) is the reporter's, not the caller moving on from its lost transaction: the cleanup ROLLBACK
	* right after it is still the cleanup (true, nothing sent) while a clone has a transaction open on the shared
	* connection, and the clone's writes are left alone.
	*
	* @dataProvider rollbacks
	* @param string $how
	*/
	#[DataProvider('rollbacks')]
	public function testACleanupRollbackAfterAReportThatWroteThroughTheHandleStillSucceeds($how)
	{
		FakeServer::$strict = false;
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
		FakeServer::failNext('/^COMMIT$/', 3100);
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(1, self::committed("INSERT INTO log VALUES ('reported')"), 'the report wrote through the handle');
		$clone = clone $db;
		$this->assertTrue($this->q($clone, 'START TRANSACTION'));
		$this->assertSame($db->linkId, $clone->linkId, 'nothing open beside it: the START stays on the shared connection');
		$this->assertTrue($this->q($clone, 'INSERT INTO b VALUES (2)'));
		$rollbacks = self::rollbacksSent();

		$this->assertTrue($how === 'transactionAbort' ? $db->transactionAbort() : $this->q($db, 'ROLLBACK'), 'still the cleanup of its own give-up');

		$this->assertSame($rollbacks, self::rollbacksSent(), 'not sent');
		$this->assertTrue($clone->transactionCommit(), 'the clone commits what it wrote');
		$this->assertSame(1, self::committed('INSERT INTO b VALUES (2)'));
		$this->assertSame(0, self::committed('INSERT INTO a VALUES (1)'));
	}

	public static function staleLostRecords()
	{
		$cases = [];
		foreach (['its COMMIT failed' => 'commit', 'a statement gave up, its COMMIT refused' => 'statement'] as $lost => $how) {
			foreach (['query()' => 'query', 'execute()' => 'execute', 'lock()' => 'lock'] as $moved => $by) {
				foreach (["query('ROLLBACK')" => 'ROLLBACK', 'transactionAbort()' => 'transactionAbort'] as $rollback => $with) {
					$cases[$lost.', moved on by '.$moved.', '.$rollback] = [$how, $by, $with];
				}
			}
		}
		return $cases;
	}

	/** the handle's transaction is lost and over for the caller (a failed or refused COMMIT), with no ROLLBACK since */
	private function loseAndEnd(ScriptedDb $db, $how)
	{
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		if ($how === 'commit') {
			FakeServer::failNext('/^COMMIT$/', 3100);
		} else {
			FakeServer::failNext('/^UPDATE a/', 1213, self::ALWAYS);
			$this->assertFalse($this->q($db, 'UPDATE a SET x=1'), 'given up: the replays fail too');
			$this->assertTrue($db->lostTransactionPending());
		}
		$this->assertFalse($db->transactionCommit());
		$this->assertTrue($db->lost());
		$this->assertFalse($db->lostTransactionPending(), 'the caller ended it with its COMMIT');
	}

	/**
	* Round 7 N1: a lost record the caller has moved on from (it sent a statement of its own after its transaction was
	* over, through query(), execute() or lock(), with no ROLLBACK) is stale: a ROLLBACK or transactionAbort() through
	* that handle while a clone has a transaction open on the shared link is no longer answered as the handle's cleanup.
	* It fails (2006, not sent) and leaves the clone's transaction alone, as for a handle with no lost record. Before,
	* the stale record made it succeed: a caller that meant the clone's transaction was told its writes were discarded
	* while they stayed open, for the clone's COMMIT to commit.
	*
	* @dataProvider staleLostRecords
	* @param string $how
	* @param string $by
	* @param string $with
	*/
	#[DataProvider('staleLostRecords')]
	public function testAWrongHandleRollbackAfterTheCallerMovedOnFromItsLostTransactionFails($how, $by, $with)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->loseAndEnd($db, $how);
		// the caller moves on without a ROLLBACK
		if ($by === 'query') {
			$this->assertTrue($this->q($db, 'SELECT 1'));
		} elseif ($by === 'execute') {
			$this->assertNotSame(0, $db->prepare('SELECT 1'));
			$this->assertTrue($db->execute());
		} else {
			$this->assertNotSame(0, $db->lock('c'));
		}
		$this->assertTrue($db->lost(), 'the record stays (a COMMIT is still refused)');
		$reported = count($db->reported);
		$clone = clone $db;
		$this->assertTrue($this->q($clone, 'START TRANSACTION'));
		$this->assertSame($db->linkId, $clone->linkId, 'nothing open beside it: the START stays on the shared connection');
		$this->assertTrue($this->q($clone, 'INSERT INTO b VALUES (2)'));
		$rollbacks = self::rollbacksSent();

		$this->assertFalse($with === 'transactionAbort' ? $db->transactionAbort() : $this->q($db, 'ROLLBACK'));

		$this->assertSame(2006, (int) $db->Errno, 'not a conflict errno: never retried into a duplicate');
		$this->assertSame('ROLLBACK not sent: the open transaction belongs to another handle: roll it back through the handle that began it', $db->Error);
		$this->assertSame($rollbacks, self::rollbacksSent(), 'not sent');
		$this->assertSame(['INSERT INTO b VALUES (2)'], FakeServer::$open[1] ?? null, 'the clone\'s transaction is left open');
		$this->assertTrue($clone->transactionCommit(), 'the clone commits what it wrote');
		$this->assertSame(['INSERT INTO b VALUES (2)'], self::writes());
		$this->assertCount($reported, $db->reported, 'the refused ROLLBACK is never reported');
	}

	public static function pendingCleanups()
	{
		$cases = [];
		foreach (['its COMMIT failed' => 'commit', 'a statement gave up, its COMMIT refused' => 'statement'] as $lost => $how) {
			foreach (["query('ROLLBACK')" => 'ROLLBACK', 'transactionAbort()' => 'transactionAbort'] as $rollback => $with) {
				$cases[$lost.', '.$rollback] = [$how, $with];
			}
		}
		return $cases;
	}

	/**
	* Round 7 N1, the other side: a ROLLBACK or transactionAbort() right after the failed or refused COMMIT (nothing of
	* the caller's own sent in between) is still the cleanup of the handle's own lost transaction: it succeeds, is not
	* sent (it would end the clone's transaction begun meanwhile on the shared link) and clears the record.
	*
	* @dataProvider pendingCleanups
	* @param string $how
	* @param string $with
	*/
	#[DataProvider('pendingCleanups')]
	public function testACleanupRollbackRightAfterAFailedOrRefusedCommitStillSucceeds($how, $with)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->loseAndEnd($db, $how);
		$clone = clone $db;
		$this->assertTrue($this->q($clone, 'START TRANSACTION'));
		$this->assertSame($db->linkId, $clone->linkId);
		$this->assertTrue($this->q($clone, 'INSERT INTO b VALUES (2)'));
		$rollbacks = self::rollbacksSent();

		$this->assertTrue($with === 'transactionAbort' ? $db->transactionAbort() : $this->q($db, 'ROLLBACK'));

		$this->assertSame($rollbacks, self::rollbacksSent(), 'not sent');
		$this->assertFalse($db->lost(), 'the record is cleared');
		$this->assertTrue($clone->transactionCommit());
		$this->assertSame(['INSERT INTO b VALUES (2)'], self::writes());
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
			'transactionBegin() (send(), session state pinned)' => ['transactionBegin'],
			'execute()' => ['execute'],
			'lock()' => ['lock'],
		];
	}

	/**
	* Fix 6: the same through the other paths that count an end before sending it: transactionBegin() with nothing open
	* of its own on a link whose session state was changed (send(); the START stays on the shared link), a prepared
	* statement, lock(). (transactionCommit() with nothing open of its own sends nothing beside another handle's
	* transaction.)
	*
	* @dataProvider notRunEndPaths
	* @param string $how
	*/
	#[DataProvider('notRunEndPaths')]
	public function testAnEndAnotherHandleSentThatTheServerNeverRanEndsNothingOnEveryPath($how)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, $how === 'transactionBegin' ? 'SET @pin = 1' : 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		if ($how === 'transactionBegin') {
			FakeServer::failNext('/^START TRANSACTION$/', 2014);
			$this->assertFalse($other->transactionBegin());
			$this->assertSame(2014, (int) $other->Errno);
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

	/** the statements sent on one connection (by its number, in connect order) */
	private static function sentOn($connection)
	{
		$out = [];
		foreach (FakeServer::$wire as $event) {
			if ($event[0] === 'query' && $event[1] === $connection) {
				$out[] = $event[2];
			}
		}
		return $out;
	}

	public static function lockFailuresInATransaction()
	{
		$cases = [];
		foreach ([
			'3101 at the implicit commit (rolled back)' => [3101, 3101],
			'1213 (outcome unknown: a metadata lock deadlock comes after the implicit commit)' => [1213, 2006],
			'1180 (rolled back)' => [1180, 1180],
			'2013 the connection went (outcome unknown)' => [2013, 2013],
			'4031 the connection went (outcome unknown)' => [4031, 4031],
			'3100 a refusal of the implicit commit (outcome unknown here)' => [3100, 2006],
			'1146 no such table, after the implicit commit (outcome unknown here)' => [1146, 2006],
		] as $name => $case) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$name.', '.$mode] = [$case[0], $case[1], $strict];
			}
		}
		return $cases;
	}

	/**
	* Haiku 1 (post-commit T review): LOCK TABLES inside a transaction commits it first, so a lock() that fails after the
	* server ran it gives the transaction up: lost (with the error, or 2006 when whether the implicit commit went through
	* is not known), statements refused, a later COMMIT refused instead of reporting the rolled-back writes as saved, and
	* a quiet ROLLBACK so nothing of it stays open. Before, inTransaction was cleared without the lost record (a 1213/3101
	* at the implicit commit then made the next COMMIT return true over the rolled-back writes), and the exception path
	* left inTransaction up with nothing recorded.
	*
	* @dataProvider lockFailuresInATransaction
	* @param int $errno
	* @param int $lostWith
	* @param bool $strict
	*/
	#[DataProvider('lockFailuresInATransaction')]
	public function testALockThatFailsInsideATransactionGivesItUp($errno, $lostWith, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^lock tables/', $errno);

		try {
			$this->assertSame(0, $db->lock('t'));
			$this->assertFalse($strict, 'only without strict mode does it return');
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			// round 6 A(a): the code is the errno it was lost with, never a 1213 that reads "rolled back, retry"
			$this->assertSame($lostWith, $e->getCode());
			if ($lostWith !== $errno) {
				$this->assertStringContainsString('after the implicit commit of the open transaction may have run', $e->getMessage());
				$this->assertInstanceOf(\mysqli_sql_exception::class, $e->getPrevious(), 'the server\'s exception stays reachable');
				$this->assertSame($errno, $e->getPrevious()->getCode());
			} else {
				$this->assertNull($e->getPrevious(), 'the server\'s own exception, as it was');
			}
		}
		$this->assertSame($lostWith, (int) $db->Errno, 'what the caller reads, thrown or returned');

		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
		$this->assertTrue($db->lostTransactionPending(), 'the caller still takes it to be open: statements are refused');
		$this->assertSame([], FakeServer::$open, 'nothing of it is left open on the server');
		if (!in_array($errno, [2013, 4031], true)) {
			$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'a quiet ROLLBACK on its usable link');
		}
		$this->assertStringContainsString('LOCK TABLES inside a transaction failed (SQL error '.$errno.')', implode("\n", FakeServer::$log));
		$this->assertLostAndRefused($db, $lostWith);
		$this->assertSame([], self::writes(), 'nothing reported as saved');
	}

	/**
	* Haiku 1: a LOCK TABLES the server never ran (it did not parse, or it was never sent) committed nothing: the
	* transaction is still open (no longer replayable), its writes go on and commit once.
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testALockInsideATransactionThatTheServerNeverRanLeavesItOpen($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^lock tables/', 1064);

		try {
			$this->assertSame(0, $db->lock('t'));
			$this->assertFalse($strict);
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
		}

		$this->assertTrue($db->inTransaction(), 'still open');
		$this->assertFalse($db->lost());
		$this->assertSame('lock() ran inside it', $db->replayable());
		$this->assertSame(['INSERT INTO t VALUES (1)'], FakeServer::$open[1] ?? null);
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
		$this->assertSame(0, FakeServer::sent('ROLLBACK'));
	}

	/**
	* Haiku 1 control: a lock() that works inside a transaction ended it (LOCK TABLES committed it): nothing is lost, and
	* a lock() with no transaction open that fails changes no transaction state.
	*/
	public function testALockThatWorksEndsTheTransactionAndOneOutsideATransactionLosesNothing()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertNotSame(0, $db->lock('t'));
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($db->lost());
		$this->assertTrue($this->q($db, 'UNLOCK TABLES'));

		FakeServer::failNext('/^lock tables/', 1146);
		$this->assertSame(0, $db->lock('u'));
		$this->assertFalse($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertSame(0, FakeServer::sent('ROLLBACK'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'), 'nothing is refused');
	}

	/** MySQL's implicit commit of the transaction open on a connection, which it runs before a DDL or LOCK TABLES */
	private static function implicitCommit($connection = 1)
	{
		foreach (FakeServer::$open[$connection] ?? [] as $write) {
			FakeServer::$committed[] = $write;
		}
		unset(FakeServer::$open[$connection]);
	}

	public static function implicitCommitDeadlocks()
	{
		$cases = [];
		foreach ([
			'lock()' => ['lock'],
			"query('LOCK TABLES')" => ['LOCK TABLES t WRITE'],
			"query('ALTER TABLE')" => ['ALTER TABLE t ADD INDEX i (c)'],
			"query('TRUNCATE TABLE')" => ['TRUNCATE TABLE t'],
			"query('CREATE TABLE')" => ['CREATE TABLE x (a int)'],
			"query('DROP TABLE')" => ['DROP TABLE x'],
		] as $name => $case) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$name.', '.$mode] = [$case[0], $strict];
			}
		}
		return $cases;
	}

	/**
	* Round 5 #3 + round 6 A (the reviewer's s1): LOCK TABLES and every other statement that commits implicitly (DDL)
	* runs the implicit commit of the open transaction first, then takes its table and metadata locks, and a deadlock
	* (1213) in that second step comes after the commit went through. So a 1213 from it inside a transaction leaves the
	* outcome unknown: the transaction is lost with 2006, not 1213, whether it came through lock() (Errno, and under
	* MYSQLI_REPORT_STRICT the code of the exception it throws) or query() (Errno), and a caller that retries a
	* transaction only on 1213/3101 (the documented contract: NativeDb, CcMeta, Session) stops instead of running it
	* again, so its writes land once. Before: lock() under strict mode threw the server's 1213, and query() gave the
	* transaction up with 1213 (its COMMIT refused with 1213): such a caller applied the writes twice.
	*
	* @dataProvider implicitCommitDeadlocks
	* @param string $how 'lock' or the statement sent through query()
	* @param bool $strict
	*/
	#[DataProvider('implicitCommitDeadlocks')]
	public function testADeadlockAfterAnImplicitCommitIsNotRetriedIntoADuplicate($how, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$attempts = 0;
		$errno = 0;
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$attempts++;
			$this->assertTrue($this->q($db, 'START TRANSACTION'));
			$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
			if ($attempt === 1) {
				// the implicit commit goes through, then the metadata lock deadlocks
				self::implicitCommit();
				FakeServer::failNext($how === 'lock' ? '/^lock tables/i' : '/^'.preg_quote(strtok($how, ' '), '/').'\\b/', 1213);
			}
			try {
				$ok = $how === 'lock' ? $db->lock('t') : $this->q($db, $how);
				$errno = (int) $db->Errno;
			} catch (\mysqli_sql_exception $e) {
				$this->assertTrue($strict && $how === 'lock', 'only lock() throws');
				$ok = false;
				$errno = (int) $e->getCode();
			}
			if ($ok && $db->transactionCommit()) {
				break;
			}
			if ($ok) {
				$errno = (int) $db->Errno;
			}
			if (!in_array($errno, [1213, 3101], true)) {
				break;
			}
			// the caller rolls back before it runs the transaction again
			$db->transactionAbort();
		}

		$this->assertSame(1, $attempts, 'not retried: whether the transaction committed is not known');
		$this->assertSame(2006, $errno, 'the statement\'s own failure says so');
		$this->assertStringContainsString('failed (SQL error 1213) after the implicit commit of the open transaction may have run', $db->Error);
		$this->assertNotContains('Got SQL error 1213 inside a transaction; the server has rolled it back, so failing rather than retrying the statement alone.', array_map(static function ($line) {
			return substr($line, 0, 115);
		}, FakeServer::$log), 'the log never says the server rolled it back');
		$this->assertFalse($db->transactionCommit(), 'the COMMIT is refused');
		$this->assertSame(2006, (int) $db->Errno, 'with 2006 too');
		$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes(), 'once, not twice');
	}

	public static function implicitCommitFailures()
	{
		$cases = [];
		foreach ([
			'3101 at the implicit commit (rolled back)' => [3101, 3101],
			'1213 (outcome unknown: a metadata lock deadlock comes after the implicit commit)' => [1213, 2006],
			'1180 (rolled back)' => [1180, 1180],
			'2013 the connection went (outcome unknown)' => [2013, 2013],
			'3100 a refusal of the implicit commit (outcome unknown here)' => [3100, 2006],
			'1060 duplicate column, after the implicit commit (outcome unknown here)' => [1060, 2006],
		] as $name => $case) {
			foreach (['LOCK TABLES t WRITE', 'ALTER TABLE t ADD COLUMN c INT'] as $sql) {
				foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
					$cases[strtok($sql, ' ').', '.$name.', '.$mode] = [$sql, $case[0], $case[1], $strict];
				}
			}
		}
		foreach (['TRUNCATE TABLE t', 'CREATE TABLE x (a int)', 'DROP TABLE x', 'RENAME TABLE t TO t2', "GRANT SELECT ON db.* TO 'u'@'h'", 'SET autocommit=1', 'OPTIMIZE TABLE t', 'CREATE /*!32312 TEMPORARY */ TABLE x (a int)', '/*!40000 ALTER TABLE t DISABLE KEYS */', 'LOCK /* c */ TABLES t WRITE', 'START REPLICA', 'CLONE LOCAL DATA DIRECTORY = \'/tmp/c\'', "SET DEFAULT ROLE ALL TO 'u'@'h'"] as $sql) {
			$cases[$sql.', 1213'] = [$sql, 1213, 2006, false];
		}
		return $cases;
	}

	/**
	* Round 6 A(b): a statement that commits implicitly (DDL, LOCK TABLES sent through query(), SET autocommit...) and
	* fails inside a transaction after the server ran it is given up as lock() gives a failed LOCK TABLES up
	* (lockFailed()): rolled back for 3101/1180 (the commit step's own errors), the connection's errno for a lost
	* connection, and lost with 2006 (outcome unknown) for anything else, 1213 included; statements are refused, the
	* COMMIT is refused with that errno, a quiet ROLLBACK makes sure nothing is left open, and the log line never claims
	* the server rolled the transaction back unless the commit was. Before, a 1213 gave it up as "rolled back" (1213)
	* and any other error (1060, 3100) left it open although the commit had gone through.
	*
	* @dataProvider implicitCommitFailures
	* @param string $sql
	* @param int $errno
	* @param int $lostWith
	* @param bool $strict
	*/
	#[DataProvider('implicitCommitFailures')]
	public function testAStatementThatCommitsImplicitlyAndFailsInsideATransactionGivesItUp($sql, $errno, $lostWith, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^'.preg_quote(strtok($sql, ' '), '/').'\\b/', $errno);
		// the statement's first keyword as the messages name it ('?' for one held by an executable comment: it has
		// none outside comments)
		$word = strncmp($sql, '/*!', 3) === 0 ? '?' : strtok($sql, ' ');

		$this->assertFalse($this->q($db, $sql));

		$this->assertSame($lostWith, (int) $db->Errno);
		if ($lostWith !== $errno) {
			$this->assertSame('Transaction lost: '.$word.' failed (SQL error '.$errno.') after the implicit commit of the open transaction may have run, so whether its writes were committed is not known', $db->Error);
		}
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
		$this->assertTrue($db->lostTransactionPending(), 'the caller still takes it to be open: statements are refused');
		$this->assertSame([], FakeServer::$open, 'nothing of it is left open on the server');
		if ($errno !== 2013) {
			$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'a quiet ROLLBACK on its usable link');
		}
		$log = implode("\n", FakeServer::$log);
		$this->assertStringContainsString($word.' inside a transaction failed (SQL error '.$errno.'); it commits the open transaction first, ', $log);
		$this->assertStringContainsString('(lost, #'.$lostWith.')', $log);
		$this->assertStringNotContainsString('the server has rolled it back', $log);
		$this->assertStringContainsString(in_array($errno, [3101, 1180], true) ? 'and that commit was rolled back' : 'whether its writes were committed is not known', $log);
		$this->assertLostAndRefused($db, $lostWith);
		$this->assertSame([], self::writes(), 'nothing reported as saved');
	}

	public static function implicitCommitsNeverRun()
	{
		$cases = [];
		foreach (['LOCK TABLES t WRITE' => 1064, 'ALTER TABLE t ADD (' => 1064, 'CREATE TABLE x (a int)' => 2014] as $sql => $errno) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[strtok($sql, ' ').' '.$errno.', '.$mode] = [$sql, $errno, $strict];
			}
		}
		return $cases;
	}

	/**
	* Round 6 A(b): a statement that commits implicitly which the server never ran (it did not parse, or it was never
	* sent: NOT_RUN_ERRORS) committed nothing: only the statement failed, the transaction is still open (not replayable)
	* and commits whole, once, as with lock() (testALockInsideATransactionThatTheServerNeverRanLeavesItOpen).
	*
	* @dataProvider implicitCommitsNeverRun
	* @param string $sql
	* @param int $errno
	* @param bool $strict
	*/
	#[DataProvider('implicitCommitsNeverRun')]
	public function testAStatementThatCommitsImplicitlyThatTheServerNeverRanLeavesTheTransactionOpen($sql, $errno, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^'.preg_quote(strtok($sql, ' '), '/').'\\b/', $errno);

		$this->assertFalse($this->q($db, $sql));

		$this->assertSame($errno, (int) $db->Errno, 'the statement\'s own error');
		$this->assertTrue($db->inTransaction(), 'still open');
		$this->assertFalse($db->lost());
		$this->assertSame(['INSERT INTO t VALUES (1)'], FakeServer::$open[1] ?? null);
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
		$this->assertSame(0, FakeServer::sent('ROLLBACK'));
	}

	public static function implicitCommitsThatWork()
	{
		$cases = [];
		foreach (['ALTER TABLE t ADD COLUMN c INT', 'CREATE TABLE x (a int)', 'DROP TABLE x', 'TRUNCATE TABLE x', 'RENAME TABLE x TO x2', 'LOCK TABLES t WRITE', "GRANT SELECT ON db.* TO 'u'@'h'", 'ANALYZE TABLE t', 'FLUSH PRIVILEGES'] as $sql) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[strtok($sql, ' ').', '.$mode] = [$sql, $strict];
			}
		}
		// round 7 F1: the strict list (implicitCommitOutcome()), comments between the words included, and the statements
		// endsTransaction() did not know before (replication, IMPORT TABLE, CLONE, SET DEFAULT ROLE)
		foreach ([
			'CREATE /* c */ TABLE x (a int)', "DROP -- c\nTABLE x", 'ALTER /* c */ TABLE t ENGINE=InnoDB',
			"SET PASSWORD = 'x'", "SET DEFAULT ROLE ALL TO 'u'@'h'", 'RESET MASTER', 'OPTIMIZE NO_WRITE_TO_BINLOG TABLE t',
			'CHECK TABLE t', 'CREATE DEFINER=CURRENT_USER VIEW v AS SELECT 1', 'DROP DATABASE x', "CREATE USER 'u'@'h'",
			'START REPLICA', 'STOP SLAVE', "CHANGE REPLICATION SOURCE TO SOURCE_HOST='h'", 'CHANGE MASTER TO MASTER_DELAY=1',
			"IMPORT TABLE FROM '/tmp/t.sdi'", "CLONE LOCAL DATA DIRECTORY = '/tmp/c'",
			// a plain comment between the leading words: endsTransaction() read them with \s+ only, so these were not
			// taken for implicit commits at all (the transaction kept although MySQL committed it: a later 1213 handed
			// out as "rolled back, retry" would make a retrying caller apply the committed writes twice)
			'LOCK /* c */ TABLES t WRITE', "CHECK -- c\nTABLE t", "SET /* c */ PASSWORD = 'x'", 'CHANGE /* c */ MASTER TO MASTER_DELAY=1',
			'START /* c */ REPLICA', "SET /* c */ DEFAULT /* c */ ROLE ALL TO 'u'@'h'",
		] as $sql) {
			$cases[$sql.', returned'] = [$sql, false];
		}
		return $cases;
	}

	/**
	* Round 6 A(c), the reviewer's s4: a statement that commits implicitly and works inside a transaction ended it, as a
	* lock() that works does (testALockThatWorksEndsTheTransactionAndOneOutsideATransactionLosesNothing): the writes
	* before it were committed, inTransaction() is false, nothing is recorded, and what runs next autocommits, so a 1213
	* on the next statement is retried on its own (autocommit, as the server runs it) instead of being handed to the
	* caller as the rollback of a transaction that is gone; the caller's COMMIT (nothing open) succeeds and the INSERT
	* lands once. Before, the tracker kept the transaction open: the 1213 gave it up as "rolled back, retry" and a
	* caller retrying on 1213/3101 applied the committed INSERT twice.
	*
	* @dataProvider implicitCommitsThatWork
	* @param string $sql
	* @param bool $strict
	*/
	#[DataProvider('implicitCommitsThatWork')]
	public function testAStatementThatCommitsImplicitlyEndsTheTransaction($sql, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$attempts = 0;
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$attempts++;
			$this->assertTrue($this->q($db, 'START TRANSACTION'));
			$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
			if ($attempt === 1) {
				// MySQL commits the open transaction before it runs the statement
				self::implicitCommit();
			}
			$this->assertNotFalse($this->q($db, $sql));
			$this->assertFalse($db->inTransaction(), 'the transaction is over');
			$this->assertFalse($db->lost());
			$this->assertSame([], $db->steps());
			if ($attempt === 1) {
				FakeServer::failNext('/^UPDATE t/', 1213);
			}
			if ($this->q($db, 'UPDATE t SET x=1') && $db->transactionCommit()) {
				break;
			}
			if (!in_array((int) $db->Errno, [1213, 3101], true)) {
				break;
			}
			$db->transactionAbort();
		}

		$this->assertSame(1, $attempts);
		$this->assertSame([250000], FakeServer::$sleeps, 'the UPDATE was retried on its own, as every statement outside a transaction');
		$this->assertSame(0, FakeServer::sent('ROLLBACK'));
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE t SET x=1'], self::writes(), 'once each');
		$this->assertSame([], $db->reported);
	}

	public static function ownStatementsThatCommitNothing()
	{
		$cases = [];
		foreach ([
			// round 7 F1: endsTransaction() read TEMPORARY on the statement with its comments, so these were taken for
			// implicit commits, and 4233584 ended the transaction on them
			'CREATE /* c */ TEMPORARY TABLE' => 'CREATE /* scratch */ TEMPORARY TABLE tmp (a int)',
			'CREATE -- c, TEMPORARY TABLE' => "CREATE -- scratch\nTEMPORARY TABLE tmp (a int)",
			'DROP /* c */ TEMPORARY TABLE' => 'DROP /* scratch */ TEMPORARY TABLE tmp',
			'DROP PREPARE' => 'DROP PREPARE s1',
			'DROP /* c */ PREPARE' => 'DROP /* c */ PREPARE s1',
			'RESET PERSIST' => 'RESET PERSIST',
			'RESET PERSIST IF EXISTS' => 'RESET PERSIST IF EXISTS max_connections',
			'DROP /* c */ TEMPORARY TABLE IF EXISTS' => 'DROP /* x */ TEMPORARY TABLE IF EXISTS tt',
			// controls: never taken for an implicit commit, or one that commits nothing (a comment marker inside a
			// string literal past the leading words changes nothing: the reviewer's R7OwnEndGapTest cases)
			'CREATE TEMPORARY TABLE' => 'CREATE TEMPORARY TABLE tmp (a int)',
			'DROP TEMPORARY TABLE IF EXISTS' => 'DROP TEMPORARY TABLE IF EXISTS tt',
			"CREATE TEMPORARY TABLE ... '/*'" => "CREATE TEMPORARY TABLE tt AS SELECT '/*' AS x",
			"CREATE TEMPORARY TABLE ... '/*!'" => "CREATE TEMPORARY TABLE tt AS SELECT '/*!' AS x",
			"CREATE TEMPORARY TABLE ... 'a#b'" => "CREATE TEMPORARY TABLE tt AS SELECT 'a#b' AS x",
			"CREATE /* c */ TEMPORARY TABLE ... '-- x'" => "CREATE /* c */ TEMPORARY TABLE tt AS SELECT '-- x' AS x",
			'DEALLOCATE PREPARE' => 'DEALLOCATE PREPARE s1',
			'SET autocommit=0' => 'SET autocommit=0',
			'SET SESSION autocommit = OFF' => 'SET SESSION autocommit = OFF',
			"SET @@autocommit = 'false'" => "SET @@autocommit = 'false'",
			'SET a user variable named autocommit' => 'SET @autocommit = 1',
			'SET a read of @@autocommit' => 'SET @saved = @@autocommit',
			'SET @@GLOBAL.autocommit' => 'SET @@GLOBAL.autocommit = 1',
		] as $name => $sql) {
			foreach (['query', 'execute'] as $api) {
				foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
					$cases[$name.', '.$api.'(), '.$mode] = [$sql, $api, $strict];
				}
			}
		}
		return $cases;
	}

	/**
	* Round 7 F1 (the reviewer's s1_false_positive_end): a statement MySQL commits nothing on, sent by the handle's
	* own transaction and working, leaves it open (as on the server), through query() and execute(), with failures
	* thrown or returned: a 1213 on the next statement is the rollback of the whole transaction, handed to the caller as
	* "rolled back, retry", and the retried transaction lands once. In 4233584, CREATE /\* c *\/ TEMPORARY TABLE, DROP
	* PREPARE and RESET PERSIST ended the transaction (endsTransaction() over-approximates, and read TEMPORARY with the
	* comment in front of it): the UPDATE was retried on its own after the server had rolled the INSERT back, and the
	* COMMIT reported success with the INSERT lost. SET autocommit=0 and SETs that assign no session autocommit were
	* already right (controls; SET autocommit=1 is doubtful: testAStatementWhoseImplicitCommitCannotBeToldGivesItUp).
	*
	* @dataProvider ownStatementsThatCommitNothing
	* @param string $sql
	* @param string $api
	* @param bool $strict
	*/
	#[DataProvider('ownStatementsThatCommitNothing')]
	public function testAStatementThatCommitsNothingLeavesTheTransactionOpen($sql, $api, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$attempts = 0;
		$commits = [];
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$attempts++;
			$this->assertTrue($this->q($db, 'START TRANSACTION'));
			$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
			// the server commits nothing here: the fake keeps the transaction open
			if ($api === 'execute') {
				$this->assertNotSame(0, $db->prepare($sql));
				$this->assertTrue($db->execute());
			} else {
				$this->assertNotFalse($this->q($db, $sql));
			}
			$this->assertTrue($db->inTransaction(), 'still open, as on the server');
			$this->assertFalse($db->lost());
			if ($attempt === 1) {
				FakeServer::failNext('/^UPDATE u/', 1213);
			}
			if ($this->q($db, 'UPDATE u SET x=1')) {
				$commits[] = self::writes();
				if ($db->transactionCommit()) {
					break;
				}
			}
			$this->assertSame(1213, (int) $db->Errno, 'the server rolled the whole transaction back: retried as one');
			$db->transactionAbort();
		}

		$this->assertSame(2, $attempts, 'retried once, as a rolled-back transaction is');
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE u SET x=1'], self::writes(), 'once each: nothing lost, nothing twice');
		$this->assertSame([[]], $commits, 'nothing was committed before the COMMIT that reported success');
	}

	public static function ownStatementsWhoseCommitCannotBeTold()
	{
		$cases = [];
		foreach ([
			// an executable comment: MySQL runs its content (here: TEMPORARY, so it commits nothing)
			'CREATE /*!32312 TEMPORARY */ TABLE' => 'CREATE /*!32312 TEMPORARY */ TABLE tmp (a int)',
			'DROP /*!32312 TEMPORARY */ TABLE' => 'DROP /*!32312 TEMPORARY */ TABLE tmp',
			'CREATE /*! TEMPORARY */ TABLE' => 'CREATE /*! TEMPORARY */ TABLE tmp (a int)',
			'CREATE /*M! TEMPORARY */ TABLE (MariaDB)' => 'CREATE /*M! TEMPORARY */ TABLE tmp (a int)',
			'ALTER TABLE ... /*!50100 ... */' => 'ALTER TABLE t /*!50100 ENGINE=InnoDB */',
			// SET autocommit=1 commits only "if the value is not already 1": the session's value is not tracked
			'SET autocommit=1' => 'SET autocommit=1',
			'SET SESSION autocommit = ON' => 'SET SESSION autocommit = ON',
			'SET @@session.autocommit := TRUE' => 'SET @@session.autocommit := TRUE',
			'SET autocommit=0, autocommit=1' => 'SET autocommit=0, autocommit=1',
			'SET autocommit = @saved' => 'SET autocommit = @saved',
			'SET autocommit = 0 + 1' => 'SET autocommit = 0 + 1',
			'SET `autocommit` = 1' => 'SET `autocommit` = 1',
			'SET autocommit=0 with a comment in it' => 'SET autocommit=0 /* c */',
			'SET autocommit /* c */ = 1 (a comment hides the assignment)' => 'SET autocommit /* c */ = 1',
			"SET autocommit -- c, then = 1" => "SET autocommit -- c\n= 1",
			// an executable comment before the first keyword (mysqldump): it reads as nothing to the leading keywords
			'/*!40000 ALTER TABLE ... */' => '/*!40000 ALTER TABLE t DISABLE KEYS */',
			// ... or changes what the words after it mean: the server runs SHOW CREATE TABLE t, which commits nothing
			'/*!32312 SHOW */ CREATE TABLE t' => '/*!32312 SHOW */ CREATE TABLE t',
			// atomic DDL: CREATE TABLE ... START TRANSACTION commits nothing
			'CREATE TABLE ... START TRANSACTION' => 'CREATE TABLE x (a int) START TRANSACTION',
			'CREATE TABLE ... START /* c */ TRANSACTION' => 'CREATE TABLE x (a int) START /* c */ TRANSACTION',
			// commits only after it ran, and only if it did
			'STOP GROUP_REPLICATION' => 'STOP GROUP_REPLICATION',
			'ANALYZE without TABLE' => 'ANALYZE x',
		] as $name => $sql) {
			foreach (['query', 'execute'] as $api) {
				foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
					foreach (['the server kept it open' => false, 'the server committed it' => true] as $world => $committed) {
						$cases[$name.', '.$api.'(), '.$mode.', '.$world] = [$sql, $api, $strict, $committed];
					}
				}
			}
		}
		return $cases;
	}

	/**
	* Round 7 F1: a statement that may commit the open transaction implicitly, sent by the handle's own transaction and
	* working, when whether it did cannot be told (an executable comment, whose content the server runs; SET
	* autocommit=1; CREATE TABLE ... START TRANSACTION; STOP GROUP_REPLICATION; a form the strict list does not know),
	* gives the transaction up: lost with 2006 (never 1213: not to be retried), a quiet ROLLBACK, statements and the
	* COMMIT refused, one error_log() line, no mail; its own result stands. Whatever the server did, there is no false
	* success: had it kept the transaction open, the INSERT is discarded and the COMMIT is refused (not reported as
	* saved); had it committed it, the INSERT landed once and is never run again. Run through query() and execute(),
	* with failures thrown or returned. In 4233584 these ended the transaction (CREATE /\*!32312 TEMPORARY *\/ TABLE:
	* the INSERT lost, the COMMIT reporting success) or kept it (SET autocommit=1 after a SET autocommit=0: a 1213 later
	* handed out as "rolled back, retry", the committed INSERT applied twice).
	*
	* @dataProvider ownStatementsWhoseCommitCannotBeTold
	* @param string $sql
	* @param string $api
	* @param bool $strict
	* @param bool $committed whether the (modelled) server committed the transaction before it ran the statement
	*/
	#[DataProvider('ownStatementsWhoseCommitCannotBeTold')]
	public function testAStatementWhoseImplicitCommitCannotBeToldGivesItUp($sql, $api, $strict, $committed)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$attempts = 0;
		$errno = 0;
		$saved = false;
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$attempts++;
			$this->assertTrue($this->q($db, 'START TRANSACTION'));
			$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
			if ($attempt === 1 && $committed) {
				self::implicitCommit();
			}
			if ($api === 'execute') {
				$this->assertNotSame(0, $db->prepare($sql));
				$this->assertTrue($db->execute(), 'it ran: its own result stands');
			} else {
				$this->assertNotFalse($this->q($db, $sql), 'it ran: its own result stands');
			}
			if ($attempt === 1) {
				$this->assertFalse($db->inTransaction());
				$this->assertTrue($db->lost());
				$this->assertTrue($db->lostTransactionPending(), 'the caller still takes it to be open: statements are refused');
				$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'a quiet ROLLBACK: nothing of it is left open');
				$this->assertSame([], FakeServer::$open, 'nothing of it is left open on the server');
				$this->assertStringContainsString('whether it committed the open transaction implicitly is not known', implode("\n", FakeServer::$log));
				FakeServer::failNext('/^UPDATE u/', 1213);
			}
			if ($this->q($db, 'UPDATE u SET x=1') && $db->transactionCommit()) {
				$saved = true;
				break;
			}
			$errno = (int) $db->Errno;
			if (!in_array($errno, [1213, 3101], true)) {
				break;
			}
			$db->transactionAbort();
		}

		$this->assertFalse($saved, 'never reported as saved');
		$this->assertSame(1, $attempts, 'not retried: whether the transaction committed is not known');
		$this->assertSame(2006, $errno);
		$this->assertStringContainsString('ran inside it, and whether it committed the open transaction implicitly is not known', $db->Error);
		$this->assertSame(0, FakeServer::sent('UPDATE u SET x=1'), 'refused, never sent');
		$this->assertSame($committed ? ['INSERT INTO t VALUES (1)'] : [], self::writes(), $committed ? 'once, never twice' : 'nothing reported as saved');
		$this->assertLostAndRefused($db, 2006);
		$this->assertSame([], $db->reported, 'nothing mailed, nothing reported (haltOnError no)');
		$this->assertTrue($db->transactionAbort(), 'the caller ends it');
		$this->assertTrue($this->q($db, 'START TRANSACTION'), 'and the handle works again');
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (4)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (4)'));
	}

	public static function apartStarts()
	{
		return [
			'START TRANSACTION' => ['START TRANSACTION'],
			'transactionBegin()' => ['transactionBegin'],
			'ROLLBACK AND CHAIN' => ['ROLLBACK AND CHAIN'],
		];
	}

	/**
	* Haiku 4: a START moved to a connection of its own (startApart()) cuts innodb_lock_wait_timeout there to
	* $apartLockWaitSeconds before anything else runs on it, so a row lock the other handle holds in the same request
	* costs at most that; the shared connection never sees it. A lock wait timeout (1205) there is handled as any:
	* the statement fails, the transaction stays open and commits.
	*
	* @dataProvider apartStarts
	* @param string $how
	*/
	#[DataProvider('apartStarts')]
	public function testAStartMovedToAConnectionOfItsOwnCutsItsLockWait($how)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SELECT 2'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));

		$this->assertTrue($how === 'transactionBegin' ? $other->transactionBegin() : $this->q($other, $how));

		$this->assertSame(2, FakeServer::$connections);
		$this->assertSame(['SET SESSION innodb_lock_wait_timeout=5', $how === 'ROLLBACK AND CHAIN' ? 'ROLLBACK AND CHAIN' : 'START TRANSACTION'], self::sentOn(2), 'cut first, on the new connection');
		$this->assertNotContains('SET SESSION innodb_lock_wait_timeout=5', self::sentOn(1), 'never on the shared one');
		FakeServer::failNext('/^UPDATE locked/', 1205);
		$this->assertFalse($this->q($other, 'UPDATE locked SET x=1'));
		$this->assertSame(1205, (int) $other->Errno);
		$this->assertTrue($other->inTransaction(), 'only the statement failed');
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($other->transactionCommit());
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (2)', 'INSERT INTO t VALUES (1)'], self::writes());
	}

	/**
	* Haiku 4: every later connection of the moved handle gets the cut too (a replay that has to reconnect), a clone
	* or newConnection() copy of it does not, 0 turns it off, and a SET that fails is logged and changes nothing else.
	*/
	public function testTheLockWaitCutFollowsTheMovedHandleOnly()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SELECT 2'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($other, 'START TRANSACTION'));
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (2)'));
		FakeServer::failNext('/^UPDATE u/', 2006);
		$this->assertTrue($this->q($other, 'UPDATE u SET y=1'), 'replayed on a new connection');
		$this->assertSame(3, FakeServer::$connections);
		$this->assertSame('SET SESSION innodb_lock_wait_timeout=5', self::sentOn(3)[0] ?? null, 'the replay\'s connection is cut too');
		$this->assertTrue($other->transactionCommit());

		// a clone shares the cut connection as it is; a connection it opens later is not cut
		$copy = clone $other;
		FakeServer::failNext('/^SELECT 3/', 2006);
		$this->assertTrue($this->q($copy, 'SELECT 3'), 'no transaction open: retried on a new connection');
		$this->assertSame(4, FakeServer::$connections);
		$this->assertSame(['SELECT 3'], self::sentOn(4), 'a clone starts without the cut');

		// detach() starts the moved handle itself over: its next connection is not cut either
		$other->detach();
		$this->assertTrue($this->q($other, 'SELECT 4'));
		$this->assertSame(5, FakeServer::$connections);
		$this->assertSame(['SELECT 4'], self::sentOn(5), 'a detached handle starts without the cut');

		FakeServer::reset();
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$other->apartLockWaitSeconds = 0;
		$this->assertTrue($this->q($other, 'SELECT 2'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($other, 'START TRANSACTION'));
		$this->assertSame(['START TRANSACTION'], self::sentOn(2), '0: the server\'s own wait');

		FakeServer::reset();
		FakeServer::$strict = true;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SELECT 2'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/^SET SESSION innodb_lock_wait_timeout/', 1227);
		$this->assertTrue($this->q($other, 'START TRANSACTION'), 'a failed cut is logged, the START goes on');
		$this->assertSame(['SET SESSION innodb_lock_wait_timeout=5', 'START TRANSACTION'], self::sentOn(2));
		$this->assertStringContainsString('Could not cut innodb_lock_wait_timeout to 5 s', implode("\n", FakeServer::$log));
		$this->assertSame(0, (int) $other->Errno);
	}

	/**
	* The innodb_lock_wait_timeout in effect for $sql: the last SET of it sent on the connection $sql ran on, before it
	* (null: none, the server's own).
	*
	* @param string $sql
	* @return string|null
	*/
	private static function lockWaitFor($sql)
	{
		$connection = null;
		foreach (FakeServer::$wire as $event) {
			if ($event[0] === 'query' && $event[2] === $sql) {
				$connection = $event[1];
			}
		}
		$last = null;
		foreach (FakeServer::$wire as $event) {
			if ($event[0] !== 'query' || $event[1] !== $connection) {
				continue;
			}
			if ($event[2] === $sql) {
				return $last;
			}
			if (strpos($event[2], 'innodb_lock_wait_timeout') !== false) {
				$last = $event[2];
			}
		}
		return $last;
	}

	public static function movedTransactionEnds()
	{
		return [
			"query('COMMIT')" => ['COMMIT'],
			'transactionCommit()' => ['transactionCommit'],
			"query('ROLLBACK')" => ['ROLLBACK'],
			'transactionAbort()' => ['transactionAbort'],
			'given up (a deadlock every replay hits)' => ['giveup'],
			'lost (a clone sharing its connection ended it)' => ['elsewhere'],
			'lock() (LOCK TABLES commits it)' => ['lock'],
			'detach() inside it' => ['detach'],
			'disconnect() inside it' => ['disconnect'],
		];
	}

	/**
	* Round 5 #4: the shorter lock wait of a START moved off a shared connection belongs to that transaction only. When
	* it is over (committed, rolled back, given up, lost, ended by LOCK TABLES, or the handle lets go of the connection)
	* the connection it ran on gets `SET SESSION innodb_lock_wait_timeout=DEFAULT` (the server's value) once: a clone made
	* while it ran (sharing the connection, and the cut) runs its later statements with the server's wait, so does a
	* clone made afterwards, and a connection the handle opens later outside any transaction is not cut. Making that
	* clone sets nothing back. Before, the cut stayed with the handle and its connection for the rest of the process,
	* and a reconnect outside any transaction set it again.
	*
	* @dataProvider movedTransactionEnds
	* @param string $how
	*/
	#[DataProvider('movedTransactionEnds')]
	public function testTheLockWaitCutEndsWithTheMovedTransaction($how)
	{
		FakeServer::$strict = false;
		$default = 'SET SESSION innodb_lock_wait_timeout=DEFAULT';
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SELECT 2'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($other, 'START TRANSACTION'), 'moved to a connection of its own');
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (2)'));
		$during = clone $other;
		$this->assertTrue($this->q($during, 'SELECT 3'), 'a clone made inside the moved transaction shares its connection');
		$this->assertSame(['SET SESSION innodb_lock_wait_timeout=5', 'START TRANSACTION', 'INSERT INTO t VALUES (2)', 'SELECT 3'], self::sentOn(2), 'cut while it runs; the clone sets nothing back');

		switch ($how) {
			case 'transactionCommit':
				$this->assertTrue($other->transactionCommit());
				break;
			case 'transactionAbort':
				$this->assertTrue($other->transactionAbort());
				break;
			case 'giveup':
				FakeServer::failNext('/^UPDATE g/', 1213, self::ALWAYS);
				$this->assertFalse($this->q($other, 'UPDATE g SET x=1'));
				$this->assertTrue($other->lostTransactionPending());
				break;
			case 'elsewhere':
				$this->assertTrue($this->q($during, 'TRUNCATE x'), 'ends the moved transaction (an implicit commit)');
				$this->assertFalse($this->q($other, 'INSERT INTO t VALUES (3)'), 'found lost');
				$this->assertSame(0, $other->linkId, 'and the shared connection let go of');
				break;
			case 'lock':
				$this->assertNotSame(0, $other->lock('t'));
				$this->assertTrue($this->q($other, 'UNLOCK TABLES'));
				break;
			case 'detach':
				$other->detach();
				break;
			case 'disconnect':
				$other->disconnect();
				break;
			default:
				$this->assertTrue($this->q($other, $how));
		}

		$this->assertFalse($other->inTransaction());
		$on2 = self::sentOn(2);
		$this->assertSame($how === 'disconnect' ? 0 : 1, count(array_keys($on2, $default, true)), 'set back once (a closed connection needs nothing)');
		$this->assertSame('SET SESSION innodb_lock_wait_timeout=5', self::lockWaitFor('SELECT 3'), 'the clone made inside it shared the cut');
		$this->assertTrue($this->q($during, 'SELECT 4'));
		$this->assertContains(self::lockWaitFor('SELECT 4'), [$default, null], 'and runs with the server\'s wait once it is over');
		$after = clone $other;
		$this->assertTrue($this->q($after, 'SELECT 5'));
		$this->assertContains(self::lockWaitFor('SELECT 5'), [$default, null], 'a clone made after it runs with the server\'s wait');
		$this->assertTrue($this->q($other, 'ROLLBACK'), 'whatever it left the caller with is ended');
		$this->assertTrue($this->q($other, 'SELECT 7'));
		$this->assertContains(self::lockWaitFor('SELECT 7'), [$default, null], 'so do its own later autocommit statements');
		FakeServer::failNext('/^SELECT 6$/', 2006);
		$connections = FakeServer::$connections;
		$this->assertTrue($this->q($other, 'SELECT 6'), 'no transaction open: retried on a new connection');
		$this->assertSame($connections + 1, FakeServer::$connections);
		$this->assertSame(['SELECT 6'], self::sentOn(FakeServer::$connections), 'a reconnect outside any transaction is not cut');
		$this->assertTrue($db->transactionCommit());
		$this->assertContains('INSERT INTO t VALUES (1)', self::writes());
		$this->assertSame(1, count(array_keys(FakeServer::statements(), 'SET SESSION innodb_lock_wait_timeout=5', true)), 'cut once, for the moved transaction only');
	}

	public static function movedStartsThatBeginNothing()
	{
		return [
			"query('START TRANSACTION')" => ['START TRANSACTION'],
			'transactionBegin()' => ['transactionBegin'],
		];
	}

	/**
	* Round 5 #4: a START moved off a shared connection that begins nothing (the server refused it) leaves no cut: its
	* connection is set back to the server's wait at once, and the handle's later autocommit statements there, and a
	* reconnect outside any transaction, run with the server's wait.
	*
	* @dataProvider movedStartsThatBeginNothing
	* @param string $how
	*/
	#[DataProvider('movedStartsThatBeginNothing')]
	public function testAMovedStartThatBeginsNothingLeavesNoCut($how)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SELECT 2'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', 1227);

		$this->assertFalse($how === 'transactionBegin' ? $other->transactionBegin() : $this->q($other, $how));

		$this->assertFalse($other->inTransaction());
		// (halt() sends UNLOCK TABLES after the failure query() reports)
		$on2 = array_values(array_diff(self::sentOn(2), ['unlock tables']));
		$this->assertSame(['SET SESSION innodb_lock_wait_timeout=5', 'START TRANSACTION', 'SET SESSION innodb_lock_wait_timeout=DEFAULT'], $on2, 'set back at once');
		$this->assertTrue($this->q($other, 'SELECT 3'));
		$this->assertSame('SELECT 3', array_values(array_diff(self::sentOn(2), ['unlock tables']))[3] ?? null);
		FakeServer::failNext('/^SELECT 4$/', 2006);
		$this->assertTrue($this->q($other, 'SELECT 4'));
		$this->assertSame(3, FakeServer::$connections);
		$this->assertSame(['SELECT 4'], self::sentOn(3), 'a reconnect outside any transaction is not cut');
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes());
	}

	/**
	* Round 5 #4: the cut is set back only once the moved transaction is over. A START inside it that the server never
	* ran (it did not parse) leaves it open, and with it the cut: nothing is set back, and a replay that has to reconnect
	* cuts its new connection too; the COMMIT then sets that one back.
	*/
	public function testAStartTheServerNeverRanInsideTheMovedTransactionKeepsTheCut()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SELECT 2'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($other, 'START TRANSACTION'), 'moved to a connection of its own');
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (2)'));
		FakeServer::failNext('/^START TRANSACTION$/', 1064);

		$this->assertFalse($other->transactionBegin());

		$this->assertTrue($other->inTransaction(), 'never ran: still open');
		$this->assertNotContains('SET SESSION innodb_lock_wait_timeout=DEFAULT', FakeServer::statements(), 'nothing set back');
		FakeServer::failNext('/^UPDATE u/', 2006);
		$this->assertTrue($this->q($other, 'UPDATE u SET y=1'), 'replayed on a new connection');
		$this->assertSame(3, FakeServer::$connections);
		$this->assertSame('SET SESSION innodb_lock_wait_timeout=5', self::sentOn(3)[0] ?? null, 'still cut');
		$this->assertTrue($other->transactionCommit());
		$on3 = self::sentOn(3);
		$this->assertSame('SET SESSION innodb_lock_wait_timeout=DEFAULT', end($on3), 'set back once it is over');
		$this->assertTrue($db->transactionCommit());
	}

	public static function movedStartsThatCannotConnect()
	{
		$cases = [];
		foreach (["query('START TRANSACTION')" => 'START TRANSACTION', "query('ROLLBACK AND CHAIN')" => 'ROLLBACK AND CHAIN', 'transactionBegin()' => 'transactionBegin'] as $name => $how) {
			foreach (['thrown (MYSQLI_REPORT_STRICT)' => true, 'returned' => false] as $mode => $strict) {
				$cases[$name.', '.$mode] = [$how, $strict];
			}
		}
		return $cases;
	}

	/**
	* Round 5 #4: a START (or ROLLBACK ... AND CHAIN) moved off a shared connection whose connection of its own cannot be
	* opened (connect() returns 0, or throws under MYSQLI_REPORT_STRICT) began nothing, so nothing of it is left: a
	* connection the handle opens later outside any transaction is not cut. Before, the moved flag stayed and the next
	* reconnect set the shorter wait on a connection that runs autocommit statements.
	*
	* @dataProvider movedStartsThatCannotConnect
	* @param string $how
	* @param bool $strict
	*/
	#[DataProvider('movedStartsThatCannotConnect')]
	public function testAMovedStartThatCannotConnectLeavesNoCut($how, $strict)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SELECT 2'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::$strict = $strict;
		FakeServer::$connectFails = 1;

		try {
			$result = $how === 'transactionBegin' ? $other->transactionBegin() : $this->q($other, $how);
			$this->assertFalse($strict, 'only without strict mode does it return');
			$this->assertSame(0, $result);
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			$this->assertSame(2002, $e->getCode());
		}

		FakeServer::$strict = false;
		$this->assertFalse($other->inTransaction());
		$this->assertNotContains('SET SESSION innodb_lock_wait_timeout=5', FakeServer::statements());
		FakeServer::failNext('/^SELECT 3$/', 2006);
		$this->assertTrue($this->q($other, 'SELECT 3'), 'no transaction open: retried on a new connection');
		$this->assertSame(['SELECT 3'], self::sentOn(FakeServer::$connections), 'a reconnect outside any transaction is not cut');
		$this->assertNotContains('SET SESSION innodb_lock_wait_timeout=5', FakeServer::statements());
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes());
	}

	/**
	* Haiku 6: a transactionBegin() that fails with nothing open leaves its own error in Errno/Error, not that of the
	* statement before it.
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testAFailedTransactionBeginWithNothingOpenSetsItsOwnError($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/^INSERT/', 1062);
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertSame(1062, (int) $db->Errno);
		FakeServer::failNext('/^START TRANSACTION$/', 3101);

		try {
			$this->assertFalse($db->transactionBegin());
			$this->assertFalse($strict);
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			$this->assertSame(3101, $e->getCode());
		}

		$this->assertSame(3101, (int) $db->Errno, 'its own error, not the INSERT\'s');
		$this->assertStringContainsString('Plugin instructed the server to rollback', $db->Error);
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($db->lost());
	}

	public static function letGoOfPreparedLinks()
	{
		return [
			'a START moved to a connection of its own' => ['apart'],
			'a dead connection closed and reopened' => ['reconnect'],
		];
	}

	/**
	* F-A: a statement prepared on a connection the handle has since let go of is not executed there (it would land
	* inside another handle's transaction on the shared connection, or fail on a closed one): execute() fails like a
	* lost connection (2006, one log line, nothing sent), and prepared again it runs on the handle's own connection.
	*
	* @dataProvider letGoOfPreparedLinks
	* @param string $how
	*/
	#[DataProvider('letGoOfPreparedLinks')]
	public function testAStatementPreparedOnAConnectionTheHandleLetGoOfIsNotExecuted($how)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertNotSame(0, $other->prepare('INSERT INTO p VALUES (1)'));
		if ($how === 'apart') {
			$this->assertTrue($this->q($db, 'START TRANSACTION'));
			$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
			$this->assertTrue($this->q($other, 'START TRANSACTION'), 'moved to a connection of its own');
		} else {
			FakeServer::failNext('/^SELECT 2$/', 2006);
			$this->assertTrue($this->q($other, 'SELECT 2'), 'retried on a new connection');
		}
		$this->assertSame(2, FakeServer::$connections);
		$before = FakeServer::$wire;

		$this->assertFalse($other->execute());

		$this->assertSame($before, FakeServer::$wire, 'nothing sent');
		$this->assertSame(2006, (int) $other->Errno);
		$this->assertStringContainsString('prepare it again', $other->Error);
		$this->assertStringContainsString('execute() not sent: the prepared statement belongs to a connection this handle has let go of', implode("\n", FakeServer::$log));
		if ($how === 'apart') {
			$this->assertSame(['INSERT INTO t VALUES (1)'], FakeServer::$open[1] ?? null, 'the other handle\'s transaction is untouched');
			$this->assertTrue($db->transactionCommit());
			$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes());
		}
		$this->assertNotSame(0, $other->prepare('INSERT INTO p VALUES (1)'));
		$this->assertTrue($other->execute(), 'prepared again, it runs on the handle\'s own connection');
		$this->assertContains('INSERT INTO p VALUES (1)', self::sentOn(2));
	}

	/**
	* F-A control: prepared and executed on the same connection (a clone sharing it included) works as before.
	*/
	public function testAStatementPreparedOnTheConnectionTheHandleStillHoldsIsExecuted()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertNotSame(0, $db->prepare('INSERT INTO p VALUES (1)'));
		$copy = clone $db;
		$this->assertTrue($db->execute());
		$this->assertTrue($copy->execute(), 'a clone shares the connection, and the statement with it');
		$this->assertSame(['INSERT INTO p VALUES (1)', 'INSERT INTO p VALUES (1)'], self::writes());
		$this->assertSame(1, FakeServer::$connections);
	}

	public static function commitsWithoutATransaction()
	{
		return [
			"query('COMMIT')" => ['COMMIT'],
			"query('COMMIT WORK')" => ['COMMIT WORK'],
			"query('COMMIT RELEASE')" => ['COMMIT RELEASE'],
			"query('COMMIT AND CHAIN')" => ['COMMIT AND CHAIN'],
			'commented COMMIT' => ['/* c */ COMMIT'],
			'transactionCommit()' => ['transactionCommit'],
		];
	}

	/**
	* F-B, round 5 #2: a COMMIT from a handle with no transaction of its own, while another handle sharing the
	* connection has one open there, is not sent (it would commit that transaction half done) and FAILS: false, Errno
	* 2006 (a caller retrying on 1213/3101 does not retry it), Error saying the transaction belongs to another handle,
	* one log line without the SQL; query() reports it like any failed statement (emailError()/halt()),
	* transactionCommit() does not. COMMIT ... AND CHAIN too (it begins nothing). Neither handle's state changes: the
	* other handle's transaction goes on and commits whole through its own handle. In a225018 it returned true (Errno
	* 0), and a caller that meant the other handle's transaction took its writes for saved; before that it committed
	* them half done.
	*
	* @dataProvider commitsWithoutATransaction
	* @param string $how
	*/
	#[DataProvider('commitsWithoutATransaction')]
	public function testACommitOfAHandleWithNoTransactionOfItsOwnBesideAnotherHandlesTransactionFails($how)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));

		// (a marker in the statement, to tell whether the log line carries it)
		$sql = $how.' /* caller-x7q */';
		$this->assertFalse($how === 'transactionCommit' ? $other->transactionCommit() : $this->q($other, $sql), 'not reported as saved');

		$this->assertSame(2006, (int) $other->Errno);
		$this->assertStringContainsString('the open transaction belongs to another handle', $other->Error);
		$this->assertSame(0, FakeServer::sent('COMMIT') + FakeServer::sent($sql), 'not sent');
		$this->assertSame([], self::writes(), 'nothing committed behind the other handle\'s back');
		$this->assertSame(['INSERT INTO t VALUES (1)'], FakeServer::$open[1] ?? null);
		$log = implode("\n", FakeServer::$log);
		$this->assertStringContainsString('COMMIT not sent: this handle has no transaction open, and the transaction open on the shared connection belongs to another handle; it fails (2006)', $log);
		$this->assertStringNotContainsString('INSERT INTO t', $log, 'no SQL in the log line');
		$this->assertStringNotContainsString('x7q', $log, 'not even the COMMIT\'s own');
		$this->assertCount($how === 'transactionCommit' ? 0 : 1, $other->reported, 'query() reports it as a failed statement; transactionCommit() never mails');
		$this->assertFalse($other->inTransaction(), 'AND CHAIN began nothing');
		$this->assertFalse($other->lost());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'), 'the transaction goes on');
		$this->assertTrue($db->transactionCommit());
		$this->assertFalse($db->lost());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'INSERT INTO t VALUES (2)'], self::writes());
		$this->assertSame(1, FakeServer::$connections);
		$this->assertTrue($this->q($other, 'COMMIT'), 'nothing open anywhere now: sent as always');
	}

	public static function siblingCommitModes()
	{
		return [
			'clone made before the START' => ['clone_before'],
			'clone made inside the transaction' => ['clone_after'],
		];
	}

	/**
	* Round 5 #2, the reviewer's scenario: a transaction started through handle A and committed through B, a clone of the
	* same family (App::db() and a get_module_db() clone, or two get_module_db() calls). B's COMMIT fails, so a caller
	* that checks it does not take A's writes for saved; when the request then ends without A's COMMIT, nothing is
	* committed, and nothing said it was. Committed through A, they land once.
	*
	* @dataProvider siblingCommitModes
	* @param string $mode
	*/
	#[DataProvider('siblingCommitModes')]
	public function testATransactionCommittedThroughASiblingHandleIsNeverReportedAsSaved($mode)
	{
		FakeServer::$strict = false;
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = $mode === 'clone_before' ? clone $a : null;
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($this->q($a, 'INSERT INTO a VALUES (1)'));
		if ($b === null) {
			$b = clone $a;
		}

		$this->assertFalse($this->q($b, 'COMMIT'));
		$this->assertSame(2006, (int) $b->Errno);
		$this->assertFalse($b->transactionCommit());
		$this->assertSame(2006, (int) $b->Errno);
		$this->assertSame([], self::writes());

		$a->disconnect();
		$this->assertSame([], self::writes(), 'the request ended without A\'s COMMIT: nothing committed, and nothing said so');

		FakeServer::reset();
		FakeServer::$strict = false;
		$a = $this->db();
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($this->q($a, 'INSERT INTO a VALUES (1)'));
		$b = clone $a;
		$this->assertFalse($this->q($b, 'COMMIT'));
		$this->assertTrue($a->transactionCommit(), 'through the handle that began it');
		$this->assertSame(['INSERT INTO a VALUES (1)'], self::writes());
	}

	/**
	* F-B: a lost transaction's COMMIT is still refused (not answered as a COMMIT with nothing open), and with no other
	* handle in a transaction a COMMIT is sent as always.
	*/
	public function testACommitBesideAnotherHandlesTransactionIsStillRefusedWhenItsOwnWasLost()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'START TRANSACTION'));
		FakeServer::failNext('/^INSERT INTO o/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($other, 'INSERT INTO o VALUES (1)'));
		$this->assertTrue($other->lost());
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));

		$this->assertFalse($this->q($other, 'COMMIT'), 'refused: its own transaction was lost');
		$this->assertStringContainsString('COMMIT refused', $other->Error);
		$this->assertFalse($other->transactionCommit());
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes());

		$this->assertTrue($this->q($other, 'ROLLBACK'));
		$this->assertTrue($this->q($other, 'COMMIT'), 'nothing open anywhere: sent as always');
		$this->assertSame(2, FakeServer::sent('COMMIT'));
	}

	/**
	* F-B, round 5 #2: COMMIT ... AND CHAIN from a handle with no transaction of its own, beside another handle's open
	* transaction, fails like a plain COMMIT and begins nothing: no connection of its own is opened (no lock wait cut),
	* nothing is chained (in a225018 it began a transaction on a connection of its own and returned true, so a caller that
	* meant the other handle's transaction took it for committed). ROLLBACK ... AND CHAIN still begins its transaction on
	* a connection of its own: a ROLLBACK with nothing of its own to roll back reports nothing as saved.
	*/
	public function testACommitAndChainOfAHandleWithNoTransactionBeginsNothing()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($this->q($other, 'SELECT 2'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));

		$this->assertFalse($this->q($other, 'COMMIT AND CHAIN'));

		$this->assertSame(2006, (int) $other->Errno);
		$this->assertFalse($other->inTransaction());
		$this->assertSame(1, FakeServer::$connections, 'no connection of its own');
		$this->assertNotContains('COMMIT AND CHAIN', FakeServer::statements());
		$this->assertNotContains('SET SESSION innodb_lock_wait_timeout=5', FakeServer::statements());
		$this->assertSame(['INSERT INTO t VALUES (1)'], FakeServer::$open[1] ?? null);

		$this->assertTrue($this->q($other, 'ROLLBACK AND CHAIN'), 'a ROLLBACK ... AND CHAIN still moves apart');
		$this->assertTrue($other->inTransaction());
		$this->assertSame(2, FakeServer::$connections);
		$this->assertSame(['SET SESSION innodb_lock_wait_timeout=5', 'ROLLBACK AND CHAIN'], self::sentOn(2));
		$this->assertTrue($this->q($other, 'INSERT INTO t VALUES (2)'));
		$this->assertTrue($other->transactionCommit());
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (2)', 'INSERT INTO t VALUES (1)'], self::writes());
	}

	/** stand-ins for MyAdmin's TFSmarty, so emailError() builds its report (no template is rendered),
	* and for \MyAdmin\App, whose presence emailErrorInner() asks about for an account_id (has(): never) */
	private static function smartyStub()
	{
		if (!class_exists('TFSmarty', false)) {
			eval('class TFSmarty { public function assign($key, $value = null) {} public function fetch($template) { return "<p>report</p>"; } }');
		}
		if (!class_exists('MyAdmin\\App', false)) {
			eval('namespace MyAdmin; class App { public static function has($class) { return false; } public static function session() { throw new \\RuntimeException("no session in the test double"); } public static function output() { throw new \\RuntimeException("no output in the test double"); } }');
		}
	}

	/**
	* The error report never writes into an open transaction (known since txn-fix4): while any handle in the process has
	* a transaction open, emailError() mails through \MyAdmin\Mail::failsafeMail() (SMTP only, no database access)
	* instead of adminMail(), whose own failure writes a `log` row through the application's handle. Before, it always
	* went through adminMail().
	*/
	public function testAnErrorReportWhileATransactionIsOpenMailsWithoutTheDatabase()
	{
		FakeServer::$strict = false;
		self::smartyStub();
		$host = php_uname('n');

		// the failing handle's own transaction
		$db = new \MyDb\Mysqli\Db('fake_db', 'fake_user', '', '127.0.0.1', '', '1');
		$db->haltOnError = 'no';
		$this->assertNotFalse($db->query('START TRANSACTION', 1, 'f.php'));
		FakeServer::failNext('/^INSERT INTO dup/', 1062);
		$this->assertFalse($db->query('INSERT INTO dup VALUES (1)', 7, 'f.php'));
		$this->assertTrue($db->inTransaction(), 'only the statement failed');
		$this->assertSame([['failsafeMail', $host.' MySQLi Error at f.php:7'], ['failsafeMail', 'DB Error Invalid SQL: INSERT INTO dup VALUES (1) f.php:7']], \MyAdmin\Mail::$sent);
		\MyAdmin\Mail::$sent = [];

		// another handle's transaction, on a connection of its own
		$plain = new \MyDb\Mysqli\Db('fake_db', 'fake_user', '', '127.0.0.1', '', '1');
		$plain->haltOnError = 'no';
		FakeServer::failNext('/^SELECT missing/', 1146);
		$this->assertFalse($plain->query('SELECT missing FROM x', 9, 'g.php'));
		$this->assertNotSame($db->linkId, $plain->linkId);
		$this->assertSame('failsafeMail', \MyAdmin\Mail::$sent[0][0] ?? null, 'any open transaction in the process counts');
		$this->assertSame([], array_values(array_filter(\MyAdmin\Mail::$sent, static function ($m) {
			return $m[0] === 'adminMail';
		})));
		\MyAdmin\Mail::$sent = [];

		// none open anywhere: adminMail() as always
		$this->assertNotFalse($db->query('COMMIT', 2, 'f.php'));
		FakeServer::failNext('/^SELECT missing/', 1146);
		$this->assertFalse($plain->query('SELECT missing FROM x', 11, 'g.php'));
		$this->assertSame([['adminMail', $host.' MySQLi Error at g.php:11'], ['adminMail', $host.' MySQLi Error at g.php:11'], ['failsafeMail', 'DB Error Invalid SQL: SELECT missing FROM x g.php:11']], \MyAdmin\Mail::$sent);
		\MyAdmin\Mail::$sent = [];
	}
}
