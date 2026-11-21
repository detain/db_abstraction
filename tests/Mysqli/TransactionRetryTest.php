<?php

namespace MyDb\Tests\Mysqli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
* Db::query() retries a statement that failed with 1213/2006/3101/1180, reconnecting if it has to.
* Inside a transaction that retry is the bug it must never commit: the server has already rolled
* the transaction back, so the statement would run on its own with autocommit while every call
* still reported success. Inside a transaction the whole transaction is replayed instead (see
* TransactionReplayTest for the replay itself); when a replay cannot recover it the statement gives up,
* once, and a later COMMIT is refused. The tests here script a conflict that comes back on every replay
* (ALWAYS) when what they are about is that give-up.
*
* No server is involved: fake_mysqli.php replaces the mysqli functions the driver calls, which only
* works in a fresh process, hence the process isolation. The connection is to 127.0.0.1 port 1 so
* that even a stub that failed to take effect could not reach a database.
*
* @runTestsInSeparateProcesses
* @preserveGlobalState disabled
*/
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TransactionRetryTest extends TestCase
{
	protected function tearDown(): void
	{
		$this->assertSame([], \MyAdmin\Mail::$sent, 'nothing was mailed');
	}

	protected function setUp(): void
	{
		// emailError()/haltmsg() are overridden by ScriptedDb; the double makes sure the real mailer can
		// never be the one that loads, whatever a test reaches
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

	/** a scripted failure that also fails every replay, so the replays run out and the statement gives up */
	const ALWAYS = 1000;

	/** the waits before the replays of a transaction ($transactionReplays = 3) */
	const REPLAY_SLEEPS = [10000, 20000, 40000];

	/**
	* @return ScriptedDb
	*/
	private function db()
	{
		$db = new ScriptedDb('fake_db', 'fake_user', '', '127.0.0.1', '', '1');
		$db->haltOnError = 'no';
		// these tests pin what a replay does and gives up on, not how long it keeps trying: the library's default
		// (25 replays within 10 s, see TransactionReplayTest) would only make each give-up slower to script
		$db->transactionReplays = 3;
		return $db;
	}

	private function q(ScriptedDb $db, $sql)
	{
		$result = $db->query($sql, __LINE__, __FILE__);
		// a dead connection is let go of (linkId 0) after a statement that lost it
		$this->assertGreaterThan(0, FakeServer::$connections, 'the driver is talking to the fake');
		if ($db->linkId !== 0) {
			$this->assertInstanceOf(FakeLink::class, $db->linkId, 'the driver is talking to the fake');
		}
		return $result;
	}

	/**
	* proves the transaction is tracked: a deadlock on a statement in it is recovered by one replay of the
	* transaction, never by the retry outside transactions (250 ms waits, the statement autocommitted alone)
	*/
	private function assertTransactionProtected(ScriptedDb $db)
	{
		$sleeps = count(FakeServer::$sleeps);
		$rollbacks = FakeServer::sent('ROLLBACK');
		FakeServer::failNext('/debit/', 1213);
		$this->assertTrue($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'), 'recovered by a replay');
		$this->assertSame([10000], array_slice(FakeServer::$sleeps, $sleeps), 'one replay, no 250 ms statement retry');
		$this->assertSame($rollbacks + 1, FakeServer::sent('ROLLBACK'), 'the replay rolled the transaction back first');
		$this->assertTrue($db->inTransaction(), 'and it is still open');
		$this->assertNotContains('UPDATE wallet SET debit=debit+10 WHERE id=2', FakeServer::$committed, 'not autocommitted');
	}

	public static function reportModes()
	{
		return [
			'failures throw (MYSQLI_REPORT_STRICT)' => [true],
			'failures return false' => [false],
		];
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testADeadlockThatComesBackOnEveryReplayGivesUpWithoutRunningTheStatementAlone($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));
		FakeServer::failNext('/debit/', 1213, self::ALWAYS);

		$result = $this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2');

		$this->assertFalse($result);
		$this->assertSame(4, FakeServer::sent('UPDATE wallet SET debit=debit+10 WHERE id=2'), 'once, then once in each of 3 replays of the transaction');
		$this->assertSame(4, FakeServer::sent('UPDATE wallet SET credit=credit+10 WHERE id=1'), 'every replay re-ran the transaction from its start');
		$this->assertSame(1, FakeServer::$connections, 'no reconnect');
		$this->assertSame(0, FakeServer::closes());
		$this->assertSame(self::REPLAY_SLEEPS, FakeServer::$sleeps, 'the replay waits, never the 250 ms statement retry');
		$this->assertFalse($db->inTransaction(), 'the server rolled the transaction back');
		$this->assertSame(1213, (int) $db->Errno);
		$this->assertCount(1, $db->reported, 'reported through emailError() like any failed statement');
		$this->assertCount(1, FakeServer::$log, 'one line, saying why: '.json_encode(FakeServer::$log));
		$this->assertStringContainsString('inside a transaction; the server has rolled it back', FakeServer::$log[0]);
		$this->assertStringContainsString('Not recovered: it was rolled back again on each of 3 replay(s)', FakeServer::$log[0]);
		$this->assertSame([], FakeServer::$open, 'the abandoned replay was rolled back: no transaction is left open on the server');

		$sent = FakeServer::sent('COMMIT');
		$this->assertFalse($this->q($db, 'COMMIT'), 'the transaction is gone, so a COMMIT must not report success');
		$this->assertSame($sent, FakeServer::sent('COMMIT'), 'and is not sent');
		$this->assertSame(1213, (int) $db->Errno);
		$this->assertStringContainsString('COMMIT refused', $db->Error);
		$this->assertCount(2, $db->reported, 'the refusal is reported like any failed statement');
		$this->assertSame([], FakeServer::$committed, 'neither update became durable on its own');
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testCommitAfterAGiveUpIsRefusedEvenAfterOtherStatementsRan($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue(FakeServer::$implicitBegin, 'MySQL keeps the session in a transaction after a deadlock');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/debit/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));

		// the caller still takes the transaction to be open: a statement it sends now would commit on its own
		$this->assertFalse($this->q($db, 'INSERT INTO audit VALUES (1)'), 'refused, not autocommitted');
		$this->assertSame(0, FakeServer::sent('INSERT INTO audit VALUES (1)'), 'never sent');
		$this->assertSame(1213, (int) $db->Errno, 'with the error the transaction was lost with');
		$this->assertStringContainsString('Statement refused', $db->Error);
		$this->assertSame([], FakeServer::$committed);
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertFalse($this->q($db, 'commit'), 'still refused: only ROLLBACK or a new transaction clears it');
		$this->assertSame(0, FakeServer::sent('COMMIT') + FakeServer::sent('commit'));
		// the caller's COMMIT ended the transaction for it: what it sends next is its own, with autocommit
		$this->assertTrue($this->q($db, 'INSERT INTO audit VALUES (2)'), 'after the COMMIT, a statement runs again');
		$this->assertSame(['INSERT INTO audit VALUES (2)'], FakeServer::$committed, 'autocommitted: the give-up rolled the server\'s transaction back, so the session is out of it');
		$this->assertFalse($this->q($db, 'COMMIT'), 'a COMMIT is still refused until a ROLLBACK or a new transaction');
	}

	public static function clearingStatements()
	{
		return [
			'ROLLBACK then START TRANSACTION' => [['ROLLBACK', 'START TRANSACTION']],
			'ROLLBACK then BEGIN' => [['ROLLBACK', 'BEGIN']],
			'START TRANSACTION alone' => [['START TRANSACTION']],
			'ROLLBACK alone' => [['ROLLBACK']],
		];
	}

	/**
	* @dataProvider clearingStatements
	* @param string[] $statements
	*/
	#[DataProvider('clearingStatements')]
	public function testCommitIsAllowedAgainOnceTheLostTransactionIsAcknowledged(array $statements)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/debit/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$sentCommits = FakeServer::sent('COMMIT');
		foreach ($statements as $statement) {
			$this->assertTrue($this->q($db, $statement));
		}
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));

		$this->assertTrue($this->q($db, 'COMMIT'));

		$this->assertSame($sentCommits + 1, FakeServer::sent('COMMIT'));
		$this->assertSame(['UPDATE wallet SET credit=credit+10 WHERE id=1'], FakeServer::$committed);
	}

	public function testCommitRefusedIsFalseWhenHaltOnErrorIsReport()
	{
		$db = $this->db();
		$db->haltOnError = 'report';
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/debit/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame('report', $db->haltOnError, 'restored');
		$this->assertCount(2, $db->reported);
	}

	public static function retriedCodes()
	{
		return [
			'1213 thrown' => [1213, true],
			'1213 returned' => [1213, false],
			'3101 thrown' => [3101, true],
			'3101 returned' => [3101, false],
			'1180 thrown' => [1180, true],
			'1180 returned' => [1180, false],
			'2006 returned' => [2006, false],
		];
	}

	/**
	* @dataProvider retriedCodes
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('retriedCodes')]
	public function testEveryRetriedCodeGivesUpInsideATransaction($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/debit/', $code, self::ALWAYS);

		$this->assertFalse($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));

		// 1213/3101 and a lost connection are replayed (a lost connection on a new connection each time);
		// 1180 is not: it gives up at once. None of them is retried on its own.
		$replayed = $code !== 1180;
		$this->assertSame($replayed ? 4 : 1, FakeServer::sent('UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertSame($code === 2006 ? 4 : 1, FakeServer::$connections);
		$this->assertSame($replayed ? self::REPLAY_SLEEPS : [], FakeServer::$sleeps);
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
		$this->assertSame($code, (int) $db->Errno);
		$this->assertSame([], FakeServer::$committed, 'never autocommitted on its own');
	}

	/**
	* @dataProvider retriedCodes
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('retriedCodes')]
	public function testEveryRetriedCodeIsStillRetriedOutsideATransaction($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/debit/', $code, 1);

		$this->assertTrue($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));

		$this->assertSame(2, FakeServer::sent('UPDATE wallet SET debit=debit+10 WHERE id=2'));
		// a lost connection is a dead link: the retry only works on a new connection
		$this->assertSame($code === 2006 ? 2 : 1, FakeServer::$connections);
		$this->assertSame(['UPDATE wallet SET debit=debit+10 WHERE id=2'], FakeServer::$committed);
	}

	/**
	* A lost connection kills the link and the server rolls the transaction back with it. The dead link is
	* closed and let go of, and the transaction is replayed on a new connection: the statement is never run
	* on its own, and every write lands exactly once when the transaction commits.
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testLostConnectionInsideATransactionIsReplayedOnANewConnection($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));
		FakeServer::failNext('/debit/', 2006);

		$result = $db->query('UPDATE wallet SET debit=debit+10 WHERE id=2', __LINE__, __FILE__);

		$this->assertTrue($result, 'no exception, no false: the replay recovered it');
		$this->assertSame(2, FakeServer::$connections, 'replayed on a new connection');
		$this->assertSame(1, FakeServer::closes(), 'the dead link was closed');
		$this->assertSame(
			['START TRANSACTION', 'UPDATE wallet SET credit=credit+10 WHERE id=1', 'UPDATE wallet SET debit=debit+10 WHERE id=2'],
			array_values(array_map(static function ($event) {
				return $event[2];
			}, array_filter(FakeServer::$wire, static function ($event) {
				return $event[0] === 'query' && $event[1] === 2;
			}))),
			'the new connection got the whole transaction'
		);
		$this->assertTrue($db->inTransaction());
		$this->assertFalse($db->lost());
		$this->assertSame(0, (int) $db->Errno);
		$this->assertSame([], $db->reported);
		$this->assertSame([], FakeServer::$committed);

		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(['UPDATE wallet SET credit=credit+10 WHERE id=1', 'UPDATE wallet SET debit=debit+10 WHERE id=2'], FakeServer::$committed, 'each write once');
	}

	/**
	* When a lost connection cannot be replayed the statement gives up. The dead link is let go of, so halt()
	* does not throw out of its UNLOCK TABLES (under any haltOnError) and the next statement, outside any
	* transaction, opens a new connection at once instead of failing on the dead one.
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testALostConnectionThatCannotBeReplayedGivesUpAndLetsGoOfTheDeadLink($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$db->haltOnError = 'report';
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SET @tag = 1'), 'SET cannot be replayed');
		FakeServer::failNext('/debit/', 2006);

		$result = $db->query('UPDATE wallet SET debit=debit+10 WHERE id=2', __LINE__, __FILE__);

		$this->assertFalse($result, 'no exception out of halt()');
		$this->assertSame('report', $db->haltOnError);
		$this->assertSame(1, FakeServer::sent('UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertSame(1, FakeServer::$connections, 'no reconnect, not even for halt()\'s UNLOCK TABLES');
		$this->assertSame(1, FakeServer::closes(), 'the dead link was closed');
		$this->assertSame(0, $db->linkId);
		$this->assertTrue($db->lost());
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertCount(1, $db->reported);
		$this->assertStringContainsString('Not replayed: it ran a statement a replay cannot repeat (SET)', FakeServer::$log[0]);

		$this->assertFalse($this->q($db, 'COMMIT'), 'refused');
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertTrue($this->q($db, 'INSERT INTO audit VALUES (1)'));
		$this->assertSame(2, FakeServer::$connections, 'a new connection, with no retry on the dead one first');
		$this->assertSame([], FakeServer::$sleeps);
		$this->assertSame(['INSERT INTO audit VALUES (1)'], FakeServer::$committed);
	}

	public static function haltingModes()
	{
		return [
			'no' => ['no'],
			'report' => ['report'],
		];
	}

	/**
	* halt() runs unlock(false) after every failed statement. On a dead link that UNLOCK TABLES throws under
	* MYSQLI_REPORT_STRICT; it used to escape halt() (so query() threw instead of returning false).
	*
	* @dataProvider haltingModes
	* @param string $mode
	*/
	#[DataProvider('haltingModes')]
	public function testHaltNeverThrowsOutOfItsUnlockOnADeadLink($mode)
	{
		FakeServer::$strict = true;
		$db = $this->db();
		$db->connect();
		$db->linkId->dead = true;
		$db->haltOnError = $mode;

		$this->assertTrue($db->halt(''), 'returned instead of throwing');
		$this->assertSame(1, count(array_filter(FakeServer::$wire, static function ($event) {
			return $event[0] === 'dead' && $event[2] === 'unlock tables';
		})), 'the UNLOCK TABLES was tried');
	}

	/**
	* With haltOnError 'yes' halt() still lets the strict-mode mysqli_sql_exception of its UNLOCK TABLES on a dead
	* link escape, as it always did, instead of reaching its echo + die(): callers under 'yes' that catch it (a cron's
	* retry loop, a failure path that must not end the request) rely on that.
	*/
	public function testHaltUnderYesStillThrowsTheUnlockFailureOnADeadLink()
	{
		FakeServer::$strict = true;
		$db = $this->db();
		$db->connect();
		$db->linkId->dead = true;
		$db->haltOnError = 'yes';

		try {
			$db->halt('');
			$this->fail('no exception');
		} catch (\mysqli_sql_exception $e) {
			$this->assertSame(2006, $e->getCode());
		}
		$this->assertSame(1, count(array_filter(FakeServer::$wire, static function ($event) {
			return $event[0] === 'dead' && $event[2] === 'unlock tables';
		})), 'the UNLOCK TABLES was tried');
	}

	/**
	* halt() swallows only the strict-mode mysqli_sql_exception of its UNLOCK TABLES. The \Error mysqli throws
	* on a half-open link (a connect that failed with reporting off leaves the mysqli_init() object behind)
	* still escapes, as it did before: MyAdmin's opt-out StoreDb relies on it to fail instead of reaching
	* halt()'s echo + die(). A closed fake link that the driver still counts as half-open stands in for it.
	*/
	public function testHaltLetsTheErrorOfAHalfOpenLinkThrough()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$db->haltOnError = 'yes';
		$db->connect();
		$established = new \ReflectionProperty(\MyDb\Mysqli\Db::class, 'linkEstablished');
		$established->setAccessible(true);
		$established->setValue($db, false);
		$db->linkId->close();

		try {
			$db->halt('connect failed');
			$this->fail('halt() returned (or reached its die())');
		} catch (\Error $e) {
			$this->assertSame('mysqli object is already closed', $e->getMessage());
		}
		$this->assertSame([], $db->reported);
	}

	/**
	* unlock() is not changed: on a dead link it throws in strict mode and returns false otherwise.
	*/
	public function testUnlockOnADeadLinkThrowsInStrictModeAndFailsOtherwise()
	{
		$db = $this->db();
		$db->connect();
		$db->linkId->dead = true;
		FakeServer::$strict = false;
		$this->assertFalse($db->unlock(false));
		FakeServer::$strict = true;
		$this->expectException(\mysqli_sql_exception::class);
		$db->unlock(false);
	}

	public static function savepointStatements()
	{
		return [
			'ROLLBACK TO SAVEPOINT' => ['ROLLBACK TO SAVEPOINT sp1'],
			'ROLLBACK TO' => ['rollback to sp1'],
			'ROLLBACK WORK TO SAVEPOINT' => ['ROLLBACK WORK TO SAVEPOINT sp1'],
			'RELEASE SAVEPOINT' => ['RELEASE SAVEPOINT sp1'],
			'SAVEPOINT' => ['SAVEPOINT sp2'],
		];
	}

	/**
	* @dataProvider savepointStatements
	* @param string $statement
	*/
	#[DataProvider('savepointStatements')]
	public function testSavepointStatementsDoNotEndTheTransaction($statement)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SAVEPOINT sp1'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));

		$this->assertTrue($this->q($db, $statement));

		$this->assertTrue($db->inTransaction());
		$before = count(FakeServer::statements());
		$this->assertTransactionProtected($db);
		$this->assertSame(
			['UPDATE wallet SET debit=debit+10 WHERE id=2', 'ROLLBACK', 'START TRANSACTION', 'SAVEPOINT sp1', 'UPDATE wallet SET credit=credit+10 WHERE id=1', $statement, 'UPDATE wallet SET debit=debit+10 WHERE id=2'],
			array_slice(FakeServer::statements(), $before),
			'the savepoint statements are replayed in order with the rest'
		);
	}

	public function testPlainRollbackStillEndsTheTransactionAfterSavepoints()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SAVEPOINT sp1'));
		$this->assertTrue($this->q($db, 'ROLLBACK TO SAVEPOINT sp1'));
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'ROLLBACK'));
		$this->assertFalse($db->inTransaction());
	}

	public static function startStatements()
	{
		return [
			'START TRANSACTION' => ['START TRANSACTION', 1064],
			'BEGIN' => ['BEGIN', 1064],
			'lowercase begin' => ['begin', 1062],
		];
	}

	/**
	* @dataProvider startStatements
	* @param string $statement
	* @param int $code
	*/
	#[DataProvider('startStatements')]
	public function testFailedStartLeavesNoTransactionOpen($statement, $code)
	{
		$db = $this->db();
		FakeServer::failNext('/^'.$statement.'$/i', $code, 1000);

		$this->assertFalse($this->q($db, $statement));

		$this->assertFalse($db->inTransaction(), 'nothing was opened');
		// so a deadlock now is an ordinary retried statement
		FakeServer::failNext('/debit/', 1213, 1);
		$this->assertTrue($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertSame(2, FakeServer::sent('UPDATE wallet SET debit=debit+10 WHERE id=2'));
	}

	public static function endingFailures()
	{
		return [
			'COMMIT 1180, thrown' => ['COMMIT', 1180, true],
			'COMMIT 1180, returned' => ['COMMIT', 1180, false],
			'COMMIT 2006, returned' => ['COMMIT', 2006, false],
			'COMMIT 2006, thrown' => ['COMMIT', 2006, true],
			'lowercase commit 2006' => ['commit', 2006, false],
			'COMMIT 3100 (GR hook), thrown' => ['COMMIT', 3100, true],
			'COMMIT 3100 (GR hook), returned' => ['COMMIT', 3100, false],
		];
	}

	/**
	* A COMMIT on a connection with no transaction succeeds as a no-op, so retrying one the server
	* refused would report the rolled-back transaction as committed. Only a COMMIT the server rolled back
	* with 1213/3101 is replayed (testACommitTheServerRolledBackIsReplayed); one that lost the connection may
	* have committed, and any other failure ends the transaction too: none of them is retried or replayed,
	* and each ends the transaction as lost. (A ROLLBACK is the opposite: it is retried, see
	* testAFailedRollbackIsRetriedAndAnswersTheTruth.)
	*
	* @dataProvider endingFailures
	* @param string $statement
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('endingFailures')]
	public function testFailedCommitIsNotRetried($statement, $code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE accounts SET a=1 WHERE id=1'));
		FakeServer::failNext('/^'.$statement.'$/', $code);

		$result = $this->q($db, $statement);

		$this->assertFalse($result);
		$this->assertSame(1, FakeServer::sent($statement), 'attempted exactly once');
		$this->assertSame(1, FakeServer::$connections, 'no reconnect');
		$this->assertSame($code === 2006 ? 1 : 0, FakeServer::closes(), 'a dead link is let go of');
		$this->assertSame([], FakeServer::$sleeps, 'no replay');
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost(), 'a later COMMIT is refused');
		$this->assertSame($code, (int) $db->Errno);
		$this->assertCount(1, $db->reported);
		$this->assertCount(1, FakeServer::$log, json_encode(FakeServer::$log));
		$this->assertSame([], FakeServer::$committed);
		// every give-up on a usable link is followed by a ROLLBACK (a dead one is let go of instead)
		$this->assertSame($code === 2006 ? 0 : 1, FakeServer::sent('ROLLBACK'));
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame($statement === 'COMMIT' ? 1 : 0, FakeServer::sent('COMMIT'), 'the retried COMMIT was refused');
	}

	public static function statementsAfterTheLinkIsLost()
	{
		return [
			'an update' => ['UPDATE wallet SET debit=debit+10 WHERE id=2'],
			'the commit' => ['COMMIT'],
		];
	}

	/**
	* Clones share one link, and a clone's retry outside a transaction after a connection error closes it for every
	* holder. The holder inside the transaction must not quietly open a new connection, where its statement (or its
	* COMMIT) would run with no transaction at all.
	*
	* @dataProvider statementsAfterTheLinkIsLost
	* @param string $statement
	*/
	#[DataProvider('statementsAfterTheLinkIsLost')]
	public function testLinkLostMidTransactionIsNotReopened($statement)
	{
		$db = $this->db();
		$db->connect();
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));
		FakeServer::failNext('/audit/', 2006, 1);
		$this->assertTrue($this->q($other, 'INSERT INTO audit VALUES (1)'), 'retried on a new connection, outside any transaction');
		$this->assertSame(1, FakeServer::closes(), 'which closed the shared link');
		$this->assertSame(2, FakeServer::$connections);
		$before = count(FakeServer::$wire);

		$result = $db->query($statement, __LINE__, __FILE__);

		$this->assertFalse($result);
		$this->assertSame(0, FakeServer::sent($statement), 'never sent, on the dead link or a new one');
		$this->assertFalse($db->inTransaction());
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertCount(1, $db->reported);
		// halt() always runs unlock(false), which connects to send UNLOCK TABLES; nothing else follows
		foreach (array_slice(FakeServer::$wire, $before) as $event) {
			if ($event[0] === 'query') {
				$this->assertSame('unlock tables', $event[2]);
			}
		}
		$lost = array_values(array_filter(FakeServer::$log, static function ($line) {
			return strpos($line, 'Connection lost mid-transaction') !== false;
		}));
		$this->assertCount(1, $lost);
		$this->assertSame(['INSERT INTO audit VALUES (1)'], FakeServer::$committed, 'the credit update is not durable');
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testDeadlockOutsideATransactionIsStillRetried($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/credit/', 1213, 2);

		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));

		$this->assertSame(3, FakeServer::sent('UPDATE wallet SET credit=credit+10 WHERE id=1'));
		$this->assertSame([250000, 250000], FakeServer::$sleeps);
		$this->assertSame(1, FakeServer::$connections);
		$this->assertFalse($db->inTransaction());
		$this->assertSame([], $db->reported);
		$this->assertSame([], FakeServer::$log);
		$this->assertSame(['UPDATE wallet SET credit=credit+10 WHERE id=1'], FakeServer::$committed);
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testCertificationFailureOutsideATransactionIsRetriedOnTheSameConnectionThenGivesUp($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/credit/', 3101, 1000);

		$this->assertFalse($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));

		$this->assertSame(93, FakeServer::sent('UPDATE wallet SET credit=credit+10 WHERE id=1'), '31 attempts in each of 3 rounds');
		$this->assertCount(93, FakeServer::$sleeps);
		// the connection is alive: closing it would take a transaction another handle has open on it (a clone) along
		$this->assertSame(1, FakeServer::$connections);
		$this->assertSame(0, FakeServer::closes());
		$this->assertCount(1, $db->reported);
		$this->assertCount(1, FakeServer::$log);
		$this->assertStringContainsString('Got MySQLi 3101 Rollback Error 30 Times, Giving Up on UPDATE wallet', FakeServer::$log[0]);
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testSyntaxErrorIsNotRetried($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/SELEC /', 1064, 1000);

		$this->assertFalse($this->q($db, 'SELEC 1'));

		$this->assertSame(1, FakeServer::sent('SELEC 1'));
		$this->assertSame(1, FakeServer::$connections);
		$this->assertSame([], FakeServer::$sleeps);
		$this->assertCount(1, $db->reported);
	}

	public static function endings()
	{
		return [
			'COMMIT' => ['COMMIT', ['UPDATE wallet SET credit=credit+10 WHERE id=1']],
			'ROLLBACK' => ['ROLLBACK', []],
		];
	}

	/**
	* @dataProvider endings
	* @param string $statement
	* @param string[] $durable
	*/
	#[DataProvider('endings')]
	public function testCommitOrRollbackEndsTheTransaction($statement, array $durable)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));
		$this->assertTrue($db->inTransaction());

		$this->assertTrue($this->q($db, $statement));

		$this->assertFalse($db->inTransaction());
		$this->assertSame($durable, FakeServer::$committed);
		$this->assertSame([], $db->reported);
		$this->assertSame([], FakeServer::$log);
	}

	/**
	* A duplicate key rolls back only the statement, so the transaction is still open and must stay
	* protected: a deadlock on a later statement still fails rather than committing that one alone.
	*/
	public function testStatementErrorInsideATransactionKeepsItOpen()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));
		FakeServer::failNext('/uniq/', 1062);

		$this->assertFalse($this->q($db, 'INSERT INTO uniq VALUES (1)'));

		$this->assertSame(1, FakeServer::sent('INSERT INTO uniq VALUES (1)'));
		$this->assertSame(1, FakeServer::$connections, 'no reconnect underneath the transaction');
		$this->assertTrue($db->inTransaction());
		foreach (FakeServer::$log as $line) {
			$this->assertStringNotContainsString('Refusing to reconnect', $line);
			$this->assertStringNotContainsString('inside a transaction', $line);
		}

		// the replay re-runs the INSERT, which now succeeds: the caller saw it fail, so the replay gives up
		FakeServer::failNext('/debit/', 1213);
		$this->assertFalse($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertSame(1, FakeServer::sent('UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertFalse($db->inTransaction());
		$this->assertStringContainsString('a statement that failed now succeeds', end(FakeServer::$log));
		$this->assertSame([], FakeServer::$committed);
	}

	/**
	* START TRANSACTION with none open is not inside a transaction: it is retried like any other
	* statement, and the transaction it opens is tracked as open.
	*/
	public function testStartTransactionIsRetriedAndStaysOpen()
	{
		$db = $this->db();
		FakeServer::failNext('/^START TRANSACTION$/', 2006);

		$this->assertTrue($this->q($db, 'START TRANSACTION'));

		$this->assertSame(2, FakeServer::sent('START TRANSACTION'));
		$this->assertTrue($db->inTransaction());
		$this->assertSame([], $db->reported);

		// the transaction it opened is protected
		$this->assertTransactionProtected($db);
		$this->assertSame([], FakeServer::$committed);
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTransactionLogLinesAreRedacted($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/uniq/', 1062);
		$this->assertFalse($this->q($db, "INSERT INTO uniq (account_passwd) VALUES ('hunter2')"));
		// replay abandoned: the INSERT that failed now succeeds
		FakeServer::failNext('/account_id=2/', 1213);
		$this->assertFalse($this->q($db, "UPDATE accounts SET account_passwd='hunter2' WHERE account_id=2"));
		// replayed
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/account_id=4/', 1213);
		$this->assertTrue($this->q($db, "UPDATE accounts SET account_passwd='hunter2' WHERE account_id=4"));
		// a closed link in a transaction that cannot be replayed
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "SET @secret = 'hunter2'"));
		\MyDb\Mysqli\mysqli_close($db->linkId);
		$this->assertFalse($db->query("UPDATE accounts SET account_passwd='hunter2' WHERE account_id=3", __LINE__, __FILE__));

		$this->assertNotEmpty(FakeServer::$log);
		$this->assertSame([], array_values(array_filter(FakeServer::$log, static function ($line) {
			return strpos($line, 'Refusing to reconnect') !== false;
		})), 'a plain statement error leaves the loop without that line');
		foreach (['inside a transaction; the server has rolled it back', 'Connection lost mid-transaction', 'Replayed a transaction'] as $expected) {
			$matching = array_filter(FakeServer::$log, static function ($line) use ($expected) {
				return strpos($line, $expected) !== false;
			});
			$this->assertCount(1, $matching, $expected.' in '.json_encode(FakeServer::$log));
		}
		foreach (FakeServer::$log as $line) {
			$this->assertStringNotContainsString('hunter2', $line);
			$this->assertStringContainsString('[redacted]', $line);
		}
	}

	/**
	* a connected handle whose transaction was lost to a deadlock
	*
	* @return ScriptedDb
	*/
	private function lostTransaction()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/debit/', 1213, self::ALWAYS);
		$this->assertFalse($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertTrue($db->lost());
		// the conflict is over: what the test runs next is not scripted to fail
		FakeServer::$failures = [];
		return $db;
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTransactionCommitIsRefusedAfterAGiveUp($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->lostTransaction();
		$reported = count($db->reported);

		$this->assertFalse($db->transactionCommit());

		$this->assertSame(0, FakeServer::sent('COMMIT'), 'never sent');
		$this->assertSame(1213, (int) $db->Errno);
		$this->assertStringContainsString('COMMIT refused', $db->Error);
		$this->assertCount($reported, $db->reported, 'a failed mysqli_commit() is not reported: no emailError()');
		$this->assertTrue($db->lost(), 'and still refused');
		$this->assertFalse($db->transactionCommit());
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertSame([], FakeServer::$committed);
	}

	public function testTransactionCommitIsRefusedEvenWithoutALink()
	{
		$db = $this->db();
		$db->lost(true);
		$this->assertSame(0, $db->linkId);

		$this->assertFalse($db->transactionCommit());

		// no halt(), so not even its UNLOCK TABLES opens a connection
		$this->assertSame(0, FakeServer::$connections);
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertSame([], FakeServer::$committed);
		$this->assertCount(0, $db->reported);
		$this->assertSame(1213, (int) $db->Errno);
	}

	public function testTransactionCommitStillShortCircuitsWithoutALinkWhenNothingWasLost()
	{
		$db = $this->db();
		$this->assertTrue($db->transactionCommit());
		$this->assertSame([], $db->reported);
		$this->assertFalse($db->lost());
	}

	public static function everyHaltMode()
	{
		return [
			'yes' => ['yes'],
			'no' => ['no'],
			'report' => ['report'],
		];
	}

	/**
	* A refused transactionCommit() behaves like a failed mysqli_commit(): false, Errno/Error set, and no
	* emailError(), no halt() (halt() under 'yes' ends the process), whatever haltOnError is.
	*
	* @dataProvider everyHaltMode
	* @param string $mode
	*/
	#[DataProvider('everyHaltMode')]
	public function testTransactionCommitRefusalNeverMailsOrHalts($mode)
	{
		$db = $this->lostTransaction();
		$db->haltOnError = $mode;
		$reported = count($db->reported);
		$connections = FakeServer::$connections;
		$wire = count(FakeServer::$wire);

		$this->assertFalse($db->transactionCommit());

		// reaching this line is the proof that 'yes' did not die()
		$this->assertSame($mode, $db->haltOnError);
		$this->assertCount($reported, $db->reported, 'no emailError()');
		$this->assertSame([], \MyAdmin\Mail::$sent, 'nothing mailed');
		$this->assertSame($wire, count(FakeServer::$wire), 'halt()\'s UNLOCK TABLES was not run either');
		$this->assertSame($connections, FakeServer::$connections);
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertSame(1213, (int) $db->Errno);
		$this->assertStringContainsString('COMMIT refused', $db->Error);
		$this->assertTrue($db->lost(), 'and still refused');
	}

	/**
	* @return array[] origin => [how the transaction is lost, errno, strict]
	*/
	public static function lossOrigins()
	{
		return [
			'deadlock' => [1213],
			'certification failure' => [3101],
			'lost connection' => [2006],
		];
	}

	/**
	* The refusal reports what the transaction was lost with, so a caller that replays on 1213 does not
	* replay a COMMIT whose outcome is unknown (2006).
	*
	* @dataProvider lossOrigins
	* @param int $code
	*/
	#[DataProvider('lossOrigins')]
	public function testRefusalReportsTheErrorTheTransactionWasLostWith($code)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/debit/', $code, self::ALWAYS);
		$this->assertFalse($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertSame($code, (int) $db->Errno);

		$this->assertFalse($db->transactionCommit());
		$this->assertSame($code, (int) $db->Errno, 'transactionCommit(): the original error');
		$this->assertStringContainsString('COMMIT refused: the transaction was lost (#'.$code.' ', $db->Error);

		$db->Errno = 0;
		$db->Error = '';
		$this->assertFalse($db->query('COMMIT', __LINE__, __FILE__));
		$this->assertSame($code, (int) $db->Errno, 'query(COMMIT): the original error');
		$this->assertStringContainsString('COMMIT refused: the transaction was lost (#'.$code.' ', $db->Error);
		$this->assertSame(0, FakeServer::sent('COMMIT'));
	}

	/**
	* @dataProvider lossOrigins
	* @param int $code
	*/
	#[DataProvider('lossOrigins')]
	public function testRefusalAfterAFailedCommitReportsTheCommitsError($code)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/^COMMIT$/', $code, self::ALWAYS);
		$this->assertFalse($db->transactionCommit());

		$this->assertFalse($db->transactionCommit());

		$this->assertSame($code, (int) $db->Errno);
		$this->assertStringContainsString('#'.$code.' ', $db->Error);
	}

	public function testTransactionAbortClearsTheLostFlagAndTheTransaction()
	{
		$db = $this->lostTransaction();

		$this->assertTrue($db->transactionAbort());

		$this->assertFalse($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($this->q($db, 'COMMIT'), 'a COMMIT is no longer refused');
		$this->assertSame(1, FakeServer::sent('COMMIT'));
	}

	/**
	* detach() lets go of the connection and with it of the transaction: inTransaction is cleared, so a
	* statement on the new connection is not taken for one inside a transaction (it is retried, not replayed).
	*/
	public function testDetachForgetsAnOpenTransaction()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($db->inTransaction());

		$db->detach();

		$this->assertFalse($db->inTransaction());
		FakeServer::failNext('/debit/', 1213, 1);
		$this->assertTrue($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertSame([250000], FakeServer::$sleeps, 'the retry outside transactions, not a replay');
		$this->assertSame(['UPDATE wallet SET debit=debit+10 WHERE id=2'], FakeServer::$committed);
	}

	public function testTransactionAbortClearsTheLostFlagWithoutALink()
	{
		$db = $this->db();
		$db->lost(true);
		$this->assertTrue($db->transactionAbort());
		$this->assertFalse($db->lost());
		$this->assertTrue($db->transactionCommit());
	}

	public function testTransactionBeginClearsTheLostFlag()
	{
		$db = $this->lostTransaction();
		$this->assertTrue($db->transactionBegin());
		$this->assertFalse($db->lost());
		$this->assertTrue($db->inTransaction());
		$this->assertSame('START TRANSACTION', end(FakeServer::$wire)[2], 'sent through mysqli_begin_transaction');
		$this->assertTrue($this->q($db, 'COMMIT'), 'a COMMIT is no longer refused');
	}

	public static function commitGiveUps()
	{
		$cases = [];
		foreach ([1213, 3101, 1180, 2006] as $code) {
			foreach ([true, false] as $strict) {
				$cases['COMMIT '.$code.($strict ? ', thrown' : ', returned')] = [$code, $strict];
			}
		}
		return $cases;
	}

	/**
	* A COMMIT the server rolled back (1213/3101/1180) or whose outcome is unknown (2006) must not be
	* answered "success" by a second COMMIT, which on a transaction-less connection is a no-op.
	*
	* @dataProvider commitGiveUps
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('commitGiveUps')]
	public function testAFailedCommitMakesARetriedCommitRefused($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE accounts SET a=1 WHERE id=1'));
		FakeServer::failNext('/^COMMIT$/', $code, self::ALWAYS);
		// nothing throws: halt() swallows its UNLOCK TABLES on a dead link
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$sent = FakeServer::sent('COMMIT');
		// 1213/3101 were replayed (the COMMIT ran once in each replay) before giving up; 1180 and 2006 were not
		$this->assertSame(in_array($code, [1213, 3101], true) ? 4 : 1, $sent);

		$this->assertFalse($this->q($db, 'COMMIT'), 'the retry is refused');
		$this->assertFalse($db->transactionCommit());

		$this->assertSame($sent, FakeServer::sent('COMMIT'), 'neither retry reached a server');
		$this->assertSame(1, FakeServer::$connections, 'no new connection for the retry');
		$this->assertSame([], FakeServer::$committed);
	}

	public static function rollbackCodes()
	{
		$cases = [];
		foreach ([1213, 3101, 1180, 2006] as $code) {
			foreach ([true, false] as $strict) {
				$cases['ROLLBACK '.$code.($strict ? ', thrown' : ', returned')] = [$code, $strict];
			}
		}
		return $cases;
	}

	/**
	* A ROLLBACK that fails once takes the retry path every statement had before (reconnecting after a
	* lost connection): the connection loss already discarded the writes, so a ROLLBACK that works on the
	* new connection is the truthful answer. Nothing is lost, and the next COMMIT is not refused.
	*
	* @dataProvider rollbackCodes
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('rollbackCodes')]
	public function testAFailedRollbackIsRetriedAndAnswersTheTruth($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		FakeServer::failNext('/^ROLLBACK$/', $code);

		$this->assertTrue($this->q($db, 'ROLLBACK'));

		$this->assertSame(2, FakeServer::sent('ROLLBACK'), 'retried');
		$this->assertSame($code === 2006 ? 2 : 1, FakeServer::$connections, 'a lost connection is reopened');
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($db->lost(), 'nothing is lost');
		$this->assertSame([], $db->reported);
		$this->assertSame([], FakeServer::$committed, 'the writes did not become durable');

		$this->assertTrue($this->q($db, 'COMMIT'), 'the next COMMIT is not refused');
		$this->assertSame(1, FakeServer::sent('COMMIT'));
	}

	/**
	* A ROLLBACK that keeps failing gives up after the retries, and only then is the transaction recorded
	* as lost: the next COMMIT is refused (never sent).
	*
	* @dataProvider rollbackCodes
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('rollbackCodes')]
	public function testARollbackThatKeepsFailingMakesACommitRefused($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		FakeServer::failNext('/^ROLLBACK$/', $code, 1000);

		$this->assertFalse($this->attempt(function () use ($db) {
			return $this->q($db, 'ROLLBACK');
		}));

		$this->assertGreaterThan(1, FakeServer::sent('ROLLBACK'), 'retried before giving up');
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost(), 'the ROLLBACK never went through');
		$this->assertSame($code, (int) $db->Errno);

		$this->assertFalse($this->attempt(function () use ($db) {
			return $this->q($db, 'COMMIT');
		}));
		$this->assertSame(0, FakeServer::sent('COMMIT'), 'refused, never sent');
		$this->assertStringContainsString('#'.$code.' ', $db->Error);
		$this->assertSame([], FakeServer::$committed);

		// and a ROLLBACK that works acknowledges it
		FakeServer::$failures = [];
		FakeServer::$strict = false;
		$this->assertTrue($this->q($db, 'ROLLBACK'));
		$this->assertFalse($db->lost());
	}

	/**
	* ROLLBACK TO SAVEPOINT stays inside the transaction: replayed with it, never retried on its own or on a
	* new connection, and a give-up once the replays run out.
	*/
	public function testRollbackToSavepointIsStillNeverRetried()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SAVEPOINT sp1'));
		FakeServer::failNext('/^ROLLBACK TO/', 1213, self::ALWAYS);

		$this->assertFalse($this->q($db, 'ROLLBACK TO SAVEPOINT sp1'));

		$this->assertSame(4, FakeServer::sent('ROLLBACK TO SAVEPOINT sp1'), 'once, then in each of 3 replays');
		$this->assertSame(4, FakeServer::sent('SAVEPOINT sp1'), 'each replay re-created the savepoint first');
		$this->assertSame(self::REPLAY_SLEEPS, FakeServer::$sleeps);
		$this->assertSame(1, FakeServer::$connections);
		$this->assertTrue($db->lost());
	}

	public static function commentedStarts()
	{
		return [
			'block comment' => ['/* tag */ START TRANSACTION'],
			'block comment, no space' => ['/*tag*/BEGIN'],
			'several block comments' => ["/*a*/ /*b*/\n\tSTART TRANSACTION"],
			'dash comment' => ["-- tag\nSTART TRANSACTION"],
			'dash comment with a tab' => ["--\ttag\nBEGIN"],
			'hash comment' => ["# tag\nSTART TRANSACTION"],
			'mixed comments' => ["/* a */ -- b\n# c\n  /* d */ BEGIN WORK"],
			'comment, parenthesis' => ['/* a */ (START TRANSACTION'],
		];
	}

	/**
	* @dataProvider commentedStarts
	* @param string $start
	*/
	#[DataProvider('commentedStarts')]
	public function testLeadingCommentsDoNotHideAStart($start)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, $start));
		$this->assertTrue($db->inTransaction(), 'recognised: '.json_encode($start));
		$this->assertTransactionProtected($db);
		$this->assertContains($start, FakeServer::statements(), 'the replay opened the transaction with the same statement');
		$this->assertSame(2, count(array_keys(FakeServer::statements(), $start)));
	}

	public static function commentedCommits()
	{
		return [
			'block' => ['/* tag */ COMMIT'],
			'dash' => ["-- tag\ncommit"],
			'hash' => ["# tag\nCOMMIT"],
			'several' => ["/*a*/ -- b\n# c\nCOMMIT WORK"],
		];
	}

	/**
	* @dataProvider commentedCommits
	* @param string $commit
	*/
	#[DataProvider('commentedCommits')]
	public function testACommentedCommitIsRefusedAfterAGiveUp($commit)
	{
		$db = $this->lostTransaction();
		$this->assertFalse($this->q($db, $commit));
		$this->assertSame(0, FakeServer::sent($commit), 'never sent');
		$this->assertStringContainsString('COMMIT refused', $db->Error);
	}

	public static function commentedRollbacks()
	{
		return [
			'block' => ['/* tag */ ROLLBACK'],
			'dash' => ["-- tag\nrollback"],
			'hash' => ["# tag\nROLLBACK WORK"],
		];
	}

	/**
	* @dataProvider commentedRollbacks
	* @param string $rollback
	*/
	#[DataProvider('commentedRollbacks')]
	public function testACommentedRollbackEndsTheTransactionAndAcknowledgesALostOne($rollback)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, $rollback));
		$this->assertFalse($db->inTransaction());

		$db = $this->lostTransaction();
		$this->assertTrue($this->q($db, $rollback));
		$this->assertFalse($db->lost());
		$this->assertTrue($this->q($db, 'COMMIT'));
	}

	public function testACommentedSavepointRollbackLeavesTheTransactionOpen()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SAVEPOINT sp1'));
		$this->assertTrue($this->q($db, "/* x */ -- y\nROLLBACK TO sp1"));
		$this->assertTrue($db->inTransaction());
	}

	public static function unrecognisedForms()
	{
		return [
			'comment between the words' => ['START /* x */ TRANSACTION'],
			'dashes without a space' => ["--x\nSTART TRANSACTION"],
			'unterminated block comment' => ['/* START TRANSACTION'],
		];
	}

	/**
	* documented limits: these are not recognised as the start of a transaction
	*
	* @dataProvider unrecognisedForms
	* @param string $statement
	*/
	#[DataProvider('unrecognisedForms')]
	public function testFormsThatStayUnrecognised($statement)
	{
		$db = $this->db();
		$this->q($db, $statement);
		$this->assertFalse($db->inTransaction());
	}

	public static function chainStatements()
	{
		return [
			'COMMIT AND CHAIN' => ['COMMIT AND CHAIN'],
			'COMMIT WORK AND CHAIN' => ['commit work and chain'],
			'ROLLBACK AND CHAIN' => ['ROLLBACK AND CHAIN'],
			'ROLLBACK WORK AND CHAIN' => ['ROLLBACK WORK AND CHAIN'],
			'commented' => ["/* x */ COMMIT AND CHAIN"],
			'with RELEASE' => ['COMMIT AND CHAIN NO RELEASE'],
		];
	}

	/**
	* @dataProvider chainStatements
	* @param string $statement
	*/
	#[DataProvider('chainStatements')]
	public function testAndChainOpensTheNextTransaction($statement)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));

		$this->assertTrue($this->q($db, $statement));

		$this->assertTrue($db->inTransaction(), 'the chained transaction is open');
		$this->assertTransactionProtected($db);
	}

	public static function noChainStatements()
	{
		return [
			'COMMIT AND NO CHAIN' => ['COMMIT AND NO CHAIN'],
			'COMMIT WORK AND NO CHAIN' => ['COMMIT WORK AND NO CHAIN'],
			'ROLLBACK AND NO CHAIN' => ['rollback and no chain'],
			'COMMIT RELEASE' => ['COMMIT NO RELEASE'],
			'COMMIT AND CHAIN RELEASE' => ['COMMIT AND CHAIN RELEASE'],
			'COMMIT WORK AND CHAIN RELEASE' => ['commit work and chain release'],
			'ROLLBACK AND CHAIN RELEASE' => ['ROLLBACK AND CHAIN  RELEASE'],
			'ROLLBACK WORK AND CHAIN RELEASE' => ["rollback work and chain\nrelease"],
		];
	}

	/**
	* AND CHAIN RELEASE closes the session after the commit, so there is no next transaction.
	*
	* @dataProvider noChainStatements
	* @param string $statement
	*/
	#[DataProvider('noChainStatements')]
	public function testAndNoChainBehavesAsPlain($statement)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, $statement));
		$this->assertFalse($db->inTransaction());
	}

	public function testRollbackAndChainClearsALostTransactionAndStaysOpen()
	{
		$db = $this->lostTransaction();

		$this->assertTrue($this->q($db, 'ROLLBACK AND CHAIN'));

		$this->assertFalse($db->lost());
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertFalse($db->inTransaction());
	}

	public function testACommitAndChainIsRefusedAfterAGiveUp()
	{
		$db = $this->lostTransaction();
		$this->assertFalse($this->q($db, 'COMMIT AND CHAIN'));
		$this->assertSame(0, FakeServer::sent('COMMIT AND CHAIN'));
		$this->assertFalse($db->inTransaction());
	}

	public static function failingStarts()
	{
		return [
			'START TRANSACTION, syntax' => ['START TRANSACTION', 1064],
			'BEGIN, syntax' => ['BEGIN', 1064],
			'BEGIN WORK, other error' => ['BEGIN WORK', 1062],
			'commented START, syntax' => ["/* c */ START TRANSACTION", 1064],
		];
	}

	/**
	* A START that fails opened nothing, so it must not clear the record that the transaction before
	* it was lost: the next COMMIT is still refused.
	*
	* @dataProvider failingStarts
	* @param string $statement
	* @param int $code
	*/
	#[DataProvider('failingStarts')]
	public function testAFailedStartAfterAGiveUpLeavesTheCommitRefused($statement, $code)
	{
		$db = $this->lostTransaction();
		FakeServer::failNext('/(start|begin)/i', $code, 1000);

		$this->assertFalse($this->q($db, $statement));

		$this->assertTrue($db->lost(), 'still lost');
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(0, FakeServer::sent('COMMIT'));
	}

	public function testAFailedRollbackAfterAGiveUpLeavesTheCommitRefused()
	{
		$db = $this->lostTransaction();
		FakeServer::failNext('/^ROLLBACK$/', 1064);

		$this->assertFalse($this->q($db, 'ROLLBACK'));

		$this->assertTrue($db->lost());
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(0, FakeServer::sent('COMMIT'));
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testStartLostToADroppedConnectionTakesTheOriginalRetryPath($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/^START TRANSACTION$/', 2006);

		$this->assertTrue($this->q($db, 'START TRANSACTION'));

		$this->assertSame(
			[['connect', 1, ''], ['query', 1, 'START TRANSACTION'], ['close', 1, ''], ['connect', 2, ''], ['query', 2, 'START TRANSACTION']],
			FakeServer::$wire,
			'reconnected and re-run, once'
		);
		$this->assertSame([], FakeServer::$sleeps);
		$this->assertTrue($db->inTransaction(), 'open once it has succeeded');
		$this->assertFalse($db->lost());
		$this->assertSame([], $db->reported);
		$this->assertCount(1, FakeServer::$log, 'the one line the original flow logged');
		$this->assertStringContainsString('2006 inside a transaction', FakeServer::$log[0]);
		// and the transaction is protected
		$this->assertTransactionProtected($db);
	}

	public static function linkLostStatements()
	{
		return [
			'an update' => ['UPDATE wallet SET debit=debit+10 WHERE id=2', true],
			'the commit' => ['COMMIT', true],
			// a ROLLBACK on a new connection is the truthful answer, so it works and loses nothing
			'the rollback' => ['ROLLBACK', false],
		];
	}

	/**
	* @dataProvider linkLostStatements
	* @param string $statement
	* @param bool $lost whether a COMMIT after it must be refused (a give-up; a ROLLBACK is not one: it is
	*                   retried on a new connection and succeeds)
	*/
	#[DataProvider('linkLostStatements')]
	public function testALinkLostBeforeAStatementDecidesWhetherACommitIsRefused($statement, $lost)
	{
		$db = $this->db();
		$db->connect();
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/audit/', 2006, 1);
		$this->assertTrue($this->q($other, 'INSERT INTO audit VALUES (1)'), 'closes the shared link');
		$this->assertSame(!$lost, (bool) $db->query($statement, __LINE__, __FILE__));
		$this->assertSame($lost, $db->lost());

		$result = $db->query('COMMIT', __LINE__, __FILE__);

		if ($lost) {
			$this->assertFalse($result);
			$this->assertStringContainsString('COMMIT refused', $db->Error);
		}
		$this->assertSame($lost ? 0 : 1, FakeServer::sent('COMMIT'), 'a refused COMMIT is never sent');
	}

	/**
	* @dataProvider commitGiveUps
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('commitGiveUps')]
	public function testTransactionCommitThatFailsMarksTheTransactionLost($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE accounts SET a=1 WHERE id=1'));
		FakeServer::failNext('/^COMMIT$/', $code, self::ALWAYS);

		$thrown = false;
		$result = null;
		try {
			$result = $db->transactionCommit();
		} catch (\mysqli_sql_exception $e) {
			$thrown = true;
		}

		$this->assertSame($strict, $thrown, 'strict mode still throws, non-strict returns false');
		if (!$strict) {
			$this->assertFalse($result);
		}
		// the state was set before the exception went out
		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());

		$sent = FakeServer::sent('COMMIT');
		$this->assertFalse($this->attempt(function () use ($db) {
			return $this->q($db, 'COMMIT');
		}), 'a later query(COMMIT) is refused');
		$this->assertFalse($this->attempt(function () use ($db) {
			return $db->transactionCommit();
		}), 'and so is transactionCommit()');
		$this->assertSame($code, (int) $db->Errno, 'with the error the COMMIT failed with');
		$this->assertSame($sent, FakeServer::sent('COMMIT'), 'neither was sent');
		$this->assertSame([], FakeServer::$committed);
	}

	/**
	* runs $call, answering false for the exception strict mode throws out of halt() on a dead link
	*/
	private function attempt($call)
	{
		try {
			return $call();
		} catch (\mysqli_sql_exception $e) {
			return false;
		}
	}

	public function testTransactionCommitOnALostLinkMarksTheTransactionLost()
	{
		$db = $this->db();
		$db->connect();
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/audit/', 2006, 1);
		$this->assertTrue($this->q($other, 'INSERT INTO audit VALUES (1)'), 'closes the shared link');

		$this->assertFalse($db->transactionCommit(), 'fails like a failed commit instead of reaching mysqli with a closed link');

		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertStringContainsString('COMMIT refused', $db->Error);
		$this->assertSame(0, FakeServer::sent('COMMIT'));
	}

	public function testTransactionCommitThatSucceedsLeavesNothingLost()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($db->transactionCommit());
		$this->assertFalse($db->lost());
		$this->assertFalse($db->inTransaction());
	}

	/**
	* A COMMIT that fails on the server with any other error (here 3100, a Group Replication hook error, which
	* is not replayed) ended the transaction: it is lost, a ROLLBACK makes sure the server is out of it, and
	* a retried COMMIT is refused instead of "succeeding" with nothing to commit. mysqli_commit() cannot
	* fail with a syntax error; 1064 was a stand-in that hid this.
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTransactionCommitFailingWithAnyOtherServerErrorEndsTheTransaction($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE accounts SET a=1 WHERE id=1'));
		FakeServer::failNext('/^COMMIT$/', 3100);

		$this->assertFalse($this->attempt(function () use ($db) {
			return $db->transactionCommit();
		}));

		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertSame(3100, (int) $db->Errno);
		$this->assertSame(1, FakeServer::sent('COMMIT'), 'not replayed');
		$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'the server is told the transaction is over');
		$this->assertSame([], FakeServer::$sleeps);
		$this->assertFalse($db->transactionCommit(), 'a retried COMMIT is refused');
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(1, FakeServer::sent('COMMIT'));
		$this->assertSame([], FakeServer::$committed);
	}

	public static function neverSentCommits()
	{
		return [
			'transactionCommit(), returned' => ['api', false],
			'transactionCommit(), thrown' => ['api', true],
			'query(COMMIT), returned' => ['query', false],
			'query(COMMIT), thrown' => ['query', true],
		];
	}

	/**
	* 2014 (commands out of sync) is a COMMIT that never reached the server: the transaction is still open there.
	* It is given up (a retried COMMIT, or a replay, must never send it mid-transaction) and its connection closed,
	* which ends the transaction on the server, so nothing is committed and the caller can run it again: the
	* CcMeta shape (a failed COMMIT, then transactionBegin() for the next attempt) must not commit the first
	* attempt's INSERT through the next START's implicit commit.
	*
	* @dataProvider neverSentCommits
	* @param string $how
	* @param bool $strict
	*/
	#[DataProvider('neverSentCommits')]
	public function testACommitThatNeverReachedTheServerIsGivenUpAndItsConnectionClosed($how, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue(FakeServer::$implicitBegin);
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'INSERT INTO cards VALUES (NULL)'));
		FakeServer::failNext('/^COMMIT$/', 2014);

		$this->assertFalse($this->attempt(function () use ($db, $how) {
			return $how === 'api' ? $db->transactionCommit() : $this->q($db, 'COMMIT');
		}));

		$this->assertSame(2014, (int) $db->Errno);
		$this->assertTrue($db->lost(), 'a later COMMIT is refused');
		$this->assertFalse($db->inTransaction());
		$this->assertSame(1, FakeServer::closes(), 'the connection is closed: the server ends the transaction with it');
		$this->assertSame(0, FakeServer::sent('ROLLBACK'), 'a ROLLBACK would be out of sync too');
		$this->assertSame([], FakeServer::$open);
		$this->assertSame([], FakeServer::$committed);
		$this->assertSame([], FakeServer::$sleeps, 'no replay');
		$this->assertSame($how === 'api' ? [] : [['COMMIT', 'Error #2014: '.$db->Error]], $db->reported, 'query() reports it like any failed COMMIT');

		// the caller's next attempt
		$this->assertTrue($db->transactionBegin(), 'a new connection, a new transaction');
		$this->assertSame(2, FakeServer::$connections);
		$this->assertTrue($this->q($db, 'INSERT INTO cards VALUES (NULL)'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO cards VALUES (NULL)'], FakeServer::$committed, 'one card, not two');
	}

	/**
	* A COMMIT that never reached the server (2014) is never recorded as a statement of the transaction: a later
	* replay must not send a COMMIT mid-transaction (it would commit half of the replayed transaction).
	*/
	public function testACommitThatNeverReachedTheServerIsNeverReplayed()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^COMMIT$/', 2014);
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertTrue($this->q($db, 'ROLLBACK'), 'clears the lost record');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO x VALUES (1)'));
		FakeServer::failNext('/INTO y/', 1213);

		$this->assertTrue($this->q($db, 'INSERT INTO y VALUES (1)'), 'replayed');
		$this->assertTrue($this->q($db, 'COMMIT'));

		$this->assertSame(['INSERT INTO x VALUES (1)', 'INSERT INTO y VALUES (1)'], FakeServer::$committed);
		$this->assertSame(2, FakeServer::sent('COMMIT'), 'the failed one and the last one: none mid-replay');
	}

	/**
	* A START whose connect() failed opened nothing: inTransaction stays false, as before the change.
	* Strict: the 2002 exception leaves connect(). Otherwise the handle is half open and connect()
	* returns 0 (a plain refused real_connect() would exit the process after maxConnectErrors tries).
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testAStartWhoseConnectFailsLeavesNoTransactionOpen($strict)
	{
		FakeServer::$strict = $strict;
		FakeServer::$connectRefused = true;
		$db = $this->db();

		try {
			$result = $db->query('START TRANSACTION', __LINE__, __FILE__);
			$this->assertFalse($strict, 'only strict mode throws');
			$this->assertSame(0, $result);
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			$this->assertSame(2002, (int) $e->getCode());
		}

		$this->assertFalse($db->inTransaction(), 'nothing was opened');
		$this->assertSame(0, FakeServer::sent('START TRANSACTION'));
		$this->assertFalse($db->lost());
	}

	public function testTransactionAbortOnADeadLinkKeepsTheLostFlag()
	{
		FakeServer::$strict = false;
		$db = $this->lostTransaction();
		$db->linkId->dead = true;

		$this->assertFalse($db->transactionAbort(), 'the ROLLBACK failed');

		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost(), 'the lost record stays: the writes are gone');
		$sent = FakeServer::sent('COMMIT');
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame($sent, FakeServer::sent('COMMIT'), 'refused, not sent');
	}

	public function testALeadingFormFeedOrVerticalTabDoesNotHideAStart()
	{
		foreach (["\fSTART TRANSACTION", "\x0BBEGIN", " \f\x0B /* a */ \fSTART TRANSACTION"] as $statement) {
			$db = $this->db();
			$this->assertTrue($this->q($db, $statement), $statement);
			$this->assertTrue($db->inTransaction(), json_encode($statement));
		}
	}

	/**
	* Compares N with 4N repetitions (median of 3 runs each) instead of a wall-clock bound, which flakes
	* under load: a linear scan costs about 4 times as much, a quadratic one about 16.
	*/
	public function testManyLeadingCommentsAreScannedInLinearTime()
	{
		$db = $this->db();
		$median = function ($sql) use ($db) {
			$times = [];
			for ($run = 0; $run < 3; $run++) {
				$start = microtime(true);
				$lead = $db->lead($sql);
				$times[] = microtime(true) - $start;
				$this->assertSame('START TRANSACTION', $lead);
			}
			sort($times);
			return $times[1];
		};
		foreach (['/**/', "#\n", "-- \n", ' ', '/*x*/ '] as $unit) {
			$small = $median(str_repeat($unit, 20000).'START TRANSACTION');
			$this->assertLessThan(5.0, $small, 'leading '.json_encode($unit).' x20000 took '.round($small * 1000).' ms');
			$large = $median(str_repeat($unit, 80000).'START TRANSACTION');
			$this->assertLessThan(5.0, $large, 'leading '.json_encode($unit).' x80000 took '.round($large * 1000).' ms');
			// the floor keeps a sub-millisecond baseline from turning timer noise into a ratio
			$ratio = $large / max($small, 0.002);
			$this->assertLessThan(8, $ratio, 'leading '.json_encode($unit).': 4x the input took '.round($ratio, 1).'x as long ('.round($small * 1000, 1).' ms -> '.round($large * 1000, 1).' ms)');
		}
	}

	public function testDetachForgetsThatTheTransactionWasLost()
	{
		$db = $this->lostTransaction();
		$this->assertTrue($db->lost());

		$db->detach();

		$this->assertFalse($db->lost(), 'the lost transaction belonged to the connection let go of');
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($this->q($db, 'COMMIT'), 'a COMMIT on the fresh connection is not refused');
		$this->assertSame(1, FakeServer::sent('COMMIT'));
		$this->assertTrue($db->transactionCommit());
	}

	public function testNewConnectionDoesNotCarryTheLostFlag()
	{
		$db = $this->lostTransaction();

		$copy = $db->newConnection();

		$this->assertFalse($copy->lost(), 'a fresh connection has no lost transaction');
		$this->assertTrue($db->lost(), 'the original keeps its own');
		$this->assertTrue($this->q($copy, 'COMMIT'));
		$this->assertSame(1, FakeServer::sent('COMMIT'));
		$this->assertFalse($this->q($db, 'COMMIT'), 'the original is still refused');
		$this->assertSame(1, FakeServer::sent('COMMIT'));
	}

	public function testDetachForgetsTheErrorTheTransactionWasLostWith()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/debit/', 2006, self::ALWAYS);
		$this->assertFalse($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'));
		$this->assertTrue($db->lost());
		$db->detach();
		$db->lost(true);

		$this->assertFalse($db->transactionCommit());

		$this->assertSame(1213, (int) $db->Errno, 'the generic refusal, not the earlier 2006');
		$this->assertStringNotContainsString('gone away', $db->Error);
	}

	public static function controlDashComments()
	{
		return [
			'vertical tab' => ["--\x0B\nCOMMIT"],
			'form feed' => ["--\x0C\nCOMMIT"],
			'tab' => ["--\t\nCOMMIT"],
			'NUL' => ["--\x00\nCOMMIT"],
			'DEL' => ["--\x7F\nCOMMIT"],
			'space' => ["-- \nCOMMIT"],
			'end of statement' => ["--"],
		];
	}

	/**
	* MySQL starts a "--" comment when the dashes are followed by whitespace or any control character.
	*
	* @dataProvider controlDashComments
	* @param string $commit
	*/
	#[DataProvider('controlDashComments')]
	public function testADashCommentFollowedByAControlCharacterIsSkipped($commit)
	{
		$this->assertSame($commit === '--' ? '' : 'COMMIT', $this->db()->lead($commit));
		if ($commit === '--') {
			return;
		}
		$db = $this->lostTransaction();
		$this->assertFalse($this->q($db, $commit), 'refused');
		$this->assertSame(0, FakeServer::sent($commit), 'never sent');
		$this->assertStringContainsString('COMMIT refused', $db->Error);
	}

	public function testDashesFollowedByAPrintableCharacterAreNotAComment()
	{
		$this->assertSame("--x\nCOMMIT", $this->db()->lead("--x\nCOMMIT"));
		$this->assertSame('--1', $this->db()->lead('--1'));
	}

	public function testStartWithATransactionOpenCommitsItLikeMySql()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		$this->assertSame([], FakeServer::$committed);
		$this->assertTrue($this->q($db, 'BEGIN'));
		$this->assertSame(['INSERT INTO a VALUES (1)'], FakeServer::$committed, 'the open transaction was committed, not discarded');
	}

	/**
	* a handle with a transaction open whose shared link another clone closed (and replaced)
	*
	* @return ScriptedDb[] [$db, $other]
	*/
	private function closedUnderTransaction()
	{
		$db = $this->db();
		$db->connect();
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/audit/', 2006, 1);
		$this->assertTrue($this->q($other, 'INSERT INTO audit VALUES (1)'), 'closes the shared link');
		return [$db, $other];
	}

	public function testTransactionCommitOutsideATransactionFailingWith2006IsNotLost()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=1 WHERE id=1'), 'autocommit');
		FakeServer::failNext('/^COMMIT$/', 2006);

		$this->assertFalse($db->transactionCommit());

		$this->assertFalse($db->lost(), 'nothing was open, so nothing was lost');
		$this->assertTrue($this->q($db, 'COMMIT'), 'as before: reconnected, and the COMMIT is not refused');
		$this->assertSame(1, FakeServer::sent('COMMIT') - 1);
	}

	public function testTransactionCommitOutsideATransactionFailingWithAThrownErrorIsNotLost()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=1 WHERE id=1'));
		FakeServer::failNext('/^COMMIT$/', 1213);
		try {
			$db->transactionCommit();
			$this->fail('strict mode throws');
		} catch (\mysqli_sql_exception $e) {
			$this->assertSame(1213, $e->getCode());
		}
		$this->assertFalse($db->lost());
	}

	public function testLockOnALostLinkInATransactionDoesNotReconnect()
	{
		list($db) = $this->closedUnderTransaction();
		$connections = FakeServer::$connections;

		$this->assertSame(0, $db->lock('wallet'));

		$this->assertSame($connections, FakeServer::$connections, 'no reconnect underneath the transaction');
		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertFalse($this->q($db, 'COMMIT'), 'refused');
		$this->assertSame(0, FakeServer::sent('COMMIT'));
	}

	public function testUnlockOnALostLinkInATransactionDoesNotReconnectOrHalt()
	{
		list($db) = $this->closedUnderTransaction();
		$connections = FakeServer::$connections;
		$halts = count($db->reported);

		$this->assertFalse($db->unlock(false));

		$this->assertSame($connections, FakeServer::$connections);
		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertSame($halts, count($db->reported));
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(0, FakeServer::sent('COMMIT'));
	}

	public function testUnlockWithHaltOnALostLinkInATransactionReturnsWithoutLooping()
	{
		list($db) = $this->closedUnderTransaction();
		$connections = FakeServer::$connections;

		$this->assertSame(0, $db->unlock());

		$this->assertSame($connections, FakeServer::$connections);
		$this->assertTrue($db->lost());
	}

	public function testTransactionBeginOnALostLinkInATransactionDoesNotReconnectOrClearTheLostFlag()
	{
		list($db) = $this->closedUnderTransaction();
		$connections = FakeServer::$connections;

		$this->assertFalse($db->transactionBegin());

		$this->assertSame($connections, FakeServer::$connections);
		$this->assertTrue($db->lost(), 'the flag survives');
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(0, FakeServer::sent('COMMIT'));
	}

	public function testLockUnlockAndBeginStillReconnectOutsideATransaction()
	{
		$db = $this->db();
		$db->connect();
		$other = clone $db;
		FakeServer::failNext('/audit/', 2006, 1);
		$this->assertTrue($this->q($other, 'INSERT INTO audit VALUES (1)'));
		$connections = FakeServer::$connections;

		$this->assertTrue((bool) $db->lock('wallet'));
		$this->assertSame($connections + 1, FakeServer::$connections, 'outside a transaction the closed link is reopened, as before');
		$this->assertFalse($db->lost());
		$this->assertTrue((bool) $db->unlock());
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($db->inTransaction());
	}

	public function testDisconnectInsideATransactionMakesACommitRefused()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=1 WHERE id=1'));

		$db->disconnect();

		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertFalse($db->transactionCommit());
		$this->assertSame([], FakeServer::$committed);
	}

	public function testDisconnectOutsideATransactionLosesNothing()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=1 WHERE id=1'));
		$db->disconnect();
		$this->assertFalse($db->lost());
		$this->assertTrue($this->q($db, 'COMMIT'));
	}

	public static function inTransactionRollbackForms()
	{
		// [statement, whether a replay can repeat it (only a ROLLBACK TO it can read without a comment in the way)]
		return [
			'comment between the words' => ['ROLLBACK /*c*/ TO SAVEPOINT s1', false],
			'comments, no spaces' => ['ROLLBACK/**/WORK/**/TO s1', false],
			'dash comment' => ["ROLLBACK -- c\n TO SAVEPOINT s1", false],
			'hash comment' => ["ROLLBACK # c\n TO SAVEPOINT s1", false],
			'executable comment' => ['ROLLBACK /*!50000 TO SAVEPOINT s1*/', false],
			'leading and inner comment' => ['/* a */ ROLLBACK /* b */ WORK /* c */ TO s1', false],
			'quoted' => ["ROLLBACK TO SAVEPOINT `s1`", true],
			'unknown form' => ['ROLLBACK FOO', false],
		];
	}

	/**
	* @dataProvider inTransactionRollbackForms
	* @param string $statement
	* @param bool $replayable
	*/
	#[DataProvider('inTransactionRollbackForms')]
	public function testAnyRollbackFormButAPlainOneIsGuardedAndNeverRetried($statement, $replayable)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/^'.preg_quote($statement, '/').'$/', 1213, self::ALWAYS);

		$this->assertFalse($this->q($db, $statement));

		$this->assertSame($replayable ? 4 : 1, FakeServer::sent($statement), 'sent once, or once more in each replay; never retried on its own');
		$this->assertSame($replayable ? self::REPLAY_SLEEPS : [], FakeServer::$sleeps);
		$this->assertSame(1, FakeServer::$connections);
		$this->assertTrue($db->lost(), 'the transaction is lost');
	}

	public static function savepointRollbackForms()
	{
		return [
			'comment between the words' => ['ROLLBACK /*c*/ TO SAVEPOINT s1'],
			'comments, no spaces' => ['ROLLBACK/**/WORK/**/TO s1'],
			'dash comment' => ["ROLLBACK -- c\n TO SAVEPOINT s1"],
			'leading and inner comment' => ['/* a */ ROLLBACK /* b */ WORK /* c */ TO s1'],
		];
	}

	/**
	* @dataProvider savepointRollbackForms
	* @param string $statement
	*/
	#[DataProvider('savepointRollbackForms')]
	public function testACommentedSavepointRollbackKeepsTheTransactionOpenAndNothingIsLost($statement)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SAVEPOINT s1'));
		$this->assertTrue($this->q($db, $statement));
		$this->assertTrue($db->inTransaction(), 'the tracker sees through the comment');
		$this->assertFalse($db->lost());
	}

	public static function plainRollbackForms()
	{
		return [
			'plain' => ['ROLLBACK'],
			'lower, semicolon' => ['rollback;'],
			'work' => ['ROLLBACK WORK'],
			'comments inside' => ['ROLLBACK /*c*/ WORK'],
			'comment, no spaces' => ['ROLLBACK/**/WORK'],
			'trailing comment' => ['ROLLBACK /* c */'],
			'and no chain' => ['ROLLBACK AND NO CHAIN'],
			'no release' => ['ROLLBACK NO RELEASE'],
			'leading comment' => ["-- c\nROLLBACK"],
		];
	}

	/**
	* @dataProvider plainRollbackForms
	* @param string $statement
	*/
	#[DataProvider('plainRollbackForms')]
	public function testPlainRollbackFormsKeepTheRetryPathEvenWithCommentsInside($statement)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/rollback/i', 1213);

		$this->assertTrue($this->q($db, $statement), 'retried once, and the answer is true');

		$this->assertSame(2, FakeServer::sent($statement));
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($db->lost());
	}

	public function testAStatementHoldingAQuoteIsNeverAPlainRollback()
	{
		$db = $this->db();
		$this->assertTrue($db->plain('ROLLBACK /* c */'));
		$this->assertFalse($db->plain("ROLLBACK /* ' */"), 'guarded: a quote makes the form unreadable');
		$this->assertFalse($db->plain('ROLLBACK /* " */'));
		$this->assertFalse($db->plain('ROLLBACK /* ` */'));
	}

	public function testALongRunOfUnterminatedCommentsInARollbackIsScannedInLinearTime()
	{
		$db = $this->db();
		$small = str_repeat('/*', 2000);
		$large = str_repeat('/*', 8000);
		$time = function ($sql) use ($db) {
			$best = INF;
			for ($i = 0; $i < 5; $i++) {
				$start = microtime(true);
				$db->plain($sql);
				$best = min($best, microtime(true) - $start);
			}
			return max($best, 1e-6);
		};
		$this->assertFalse($db->plain('ROLLBACK '.$small));
		$this->assertLessThan(8, $time('ROLLBACK '.$large) / $time('ROLLBACK '.$small));
	}

	public static function startForms()
	{
		return [
			'START TRANSACTION' => ['START TRANSACTION'],
			'BEGIN' => ['BEGIN'],
			'BEGIN WORK' => ['BEGIN WORK'],
		];
	}

	public static function startGiveUps()
	{
		$cases = [];
		foreach (self::startForms() as $name => $form) {
			foreach ([1213, 2006] as $code) {
				$cases[$name.' fails with '.$code] = [$form[0], $code];
			}
		}
		return $cases;
	}

	/**
	* A START/BEGIN inside an open transaction (it would commit it) that fails with 1213 or 2006 is a
	* give-up like any other statement: not retried, and the transaction is recorded as lost.
	*
	* @dataProvider startGiveUps
	* @param string $statement
	* @param int $code
	*/
	#[DataProvider('startGiveUps')]
	public function testAStartInsideAnOpenTransactionThatFailsGivesUpAndMarksTheTransactionLost($statement, $code)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=1 WHERE id=1'));
		FakeServer::failNext('/^(start|begin)/i', $code, self::ALWAYS);
		$before = FakeServer::sent($statement);

		$this->assertFalse($this->q($db, $statement));

		// a START that commits the open transaction is replayed like a COMMIT on 1213 (each replay fails on its
		// START TRANSACTION here), never after a lost connection; it is not retried on its own either way
		$this->assertSame($before + 1 + ($code === 1213 && $statement === 'START TRANSACTION' ? 3 : 0), FakeServer::sent($statement), 'not retried');
		$this->assertSame($code === 1213 ? self::REPLAY_SLEEPS : [], FakeServer::$sleeps);
		$this->assertSame(1, FakeServer::$connections, 'no reconnect');
		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($this->q($db, 'COMMIT'), 'refused');
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertSame([], FakeServer::$committed);
	}

	/**
	* @dataProvider startForms
	* @param string $statement
	*/
	#[DataProvider('startForms')]
	public function testASuccessfulStartAcknowledgesALostTransaction($statement)
	{
		$db = $this->lostTransaction();

		$this->assertTrue($this->q($db, $statement));

		$this->assertFalse($db->lost());
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'COMMIT'));
	}

	public function testTransactionBeginOutsideATransactionStartsOne()
	{
		$db = $this->db();
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($db->inTransaction());
		$this->assertTransactionProtected($db);
		$this->assertSame(2, FakeServer::sent('START TRANSACTION'), 'the replay began it again through mysqli_begin_transaction()');
	}

	public static function noRetryErrors()
	{
		$cases = [];
		foreach ([1062, 1054, 1146, 1064, 1142] as $code) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$code.' '.$mode] = [$code, $strict];
			}
		}
		return $cases;
	}

	/**
	* F5: outside a transaction a statement that would fail the same way again (a duplicate key, a syntax error, an
	* unknown column or table, a missing privilege: NO_RETRY_ERRORS) is sent once: no retry, no reconnect. It fails,
	* and is reported, as it always did. Before, it was sent three times, the connection closed and reopened twice.
	*
	* @dataProvider noRetryErrors
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('noRetryErrors')]
	public function testAnErrorTheStatementWouldHitAgainIsNotRetriedOutsideATransaction($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/^INSERT INTO uniq/', $code, self::ALWAYS);

		$this->assertFalse($this->attempt(function () use ($db) {
			return $this->q($db, 'INSERT INTO uniq VALUES (1)');
		}));

		$this->assertSame(1, FakeServer::sent('INSERT INTO uniq VALUES (1)'));
		$this->assertSame(1, FakeServer::$connections);
		$this->assertSame(0, FakeServer::closes());
		$this->assertSame([], FakeServer::$sleeps);
		$this->assertSame($code, (int) $db->Errno);
		$this->assertCount(1, $db->reported);
	}

	/**
	* F5: any other error may go away (a lock wait timeout, a read-only member...): it is retried for the three rounds
	* it always had, with the same result, but on the same connection, which is never closed for it.
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testAnyOtherErrorOutsideATransactionIsRetriedOnTheSameConnection($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/^UPDATE wallet/', 1205, 2);

		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));

		$this->assertSame(3, FakeServer::sent('UPDATE wallet SET credit=credit+10 WHERE id=1'), 'it went through on the third round, as before');
		$this->assertSame(1, FakeServer::$connections);
		$this->assertSame(0, FakeServer::closes());
		$this->assertSame([], $db->reported);
		$this->assertSame(['UPDATE wallet SET credit=credit+10 WHERE id=1'], FakeServer::$committed);
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testAnyOtherErrorThatPersistsOutsideATransactionFailsAfterThreeRoundsOnTheSameConnection($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/^UPDATE wallet/', 1205, self::ALWAYS);

		$this->assertFalse($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));

		$this->assertSame(3, FakeServer::sent('UPDATE wallet SET credit=credit+10 WHERE id=1'));
		$this->assertSame(1, FakeServer::$connections);
		$this->assertSame(0, FakeServer::closes());
		$this->assertSame(1205, (int) $db->Errno);
		$this->assertCount(1, $db->reported);
		$this->assertSame([], FakeServer::$committed);
	}

	public static function connectionErrors()
	{
		$cases = [];
		foreach ([2006, 2013, 2055, 4031] as $code) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$code.' '.$mode] = [$code, $strict];
			}
		}
		return $cases;
	}

	/**
	* F5: a statement outside a transaction that lost the connection (RECONNECT_ERRORS) is still retried on a new
	* connection, the dead one closed first, as always.
	*
	* @dataProvider connectionErrors
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('connectionErrors')]
	public function testAConnectionErrorOutsideATransactionIsRetriedOnANewConnection($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		FakeServer::failNext('/^UPDATE wallet/', $code, 1);

		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));

		$this->assertSame(2, FakeServer::sent('UPDATE wallet SET credit=credit+10 WHERE id=1'));
		$this->assertSame(2, FakeServer::$connections);
		$this->assertSame(1, FakeServer::closes());
		$this->assertSame(['UPDATE wallet SET credit=credit+10 WHERE id=1'], FakeServer::$committed);
	}

	public static function failuresBesideAnOpenTransaction()
	{
		return [
			'1062 duplicate key (not retried)' => [1062, 1],
			'1054 unknown column (not retried)' => [1054, 1],
			'1205 lock wait timeout (retried on the same connection)' => [1205, 3],
		];
	}

	/**
	* F5: a statement another handle sharing the connection (a get_module_db() clone) runs outside a transaction of its
	* own fails: its retry must never close the connection, which holds this handle's open transaction (closing it
	* rolls that back). Before, the second round closed it and this transaction was lost.
	*
	* @dataProvider failuresBesideAnOpenTransaction
	* @param int $code
	* @param int $sent
	*/
	#[DataProvider('failuresBesideAnOpenTransaction')]
	public function testAnotherHandlesFailureOutsideATransactionNeverClosesTheConnectionUnderAnOpenOne($code, $sent)
	{
		$db = $this->db();
		$db->connect();
		$other = clone $db;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));
		FakeServer::failNext('/^INSERT INTO uniq/', $code, self::ALWAYS);

		$this->assertFalse($this->attempt(function () use ($other) {
			return $this->q($other, 'INSERT INTO uniq VALUES (1)');
		}));

		$this->assertSame($sent, FakeServer::sent('INSERT INTO uniq VALUES (1)'));
		$this->assertSame(0, FakeServer::closes(), 'the connection holding the transaction was not closed');
		$this->assertSame(1, FakeServer::$connections);
		$this->assertTrue($this->q($db, 'UPDATE wallet SET debit=debit+10 WHERE id=2'), 'the transaction is still open');
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertFalse($db->lost());
		$this->assertSame(['UPDATE wallet SET credit=credit+10 WHERE id=1', 'UPDATE wallet SET debit=debit+10 WHERE id=2'], FakeServer::$committed);
	}

	public static function outOfSync()
	{
		return [
			'nothing open on the connection' => [false],
			'another handle has a transaction open on it' => [true],
		];
	}

	/**
	* F5: commands out of sync (2014) leaves a live connection with results pending, which a new connection clears:
	* the retry closes it and opens a new one as before, unless another handle sharing it has a transaction open
	* there (closing it would roll that back); then it stays on it.
	*
	* @dataProvider outOfSync
	* @param bool $open
	*/
	#[DataProvider('outOfSync')]
	public function testCommandsOutOfSyncReconnectsOnlyWhenNoOtherHandleHasATransactionOpen($open)
	{
		$db = $this->db();
		$db->connect();
		$other = clone $db;
		if ($open) {
			$this->assertTrue($this->q($db, 'START TRANSACTION'));
			$this->assertTrue($this->q($db, 'UPDATE wallet SET credit=credit+10 WHERE id=1'));
		}
		FakeServer::failNext('/^INSERT INTO log/', 2014, 1);

		$this->assertTrue($this->attempt(function () use ($other) {
			return $this->q($other, 'INSERT INTO log VALUES (1)');
		}));

		$this->assertSame($open ? 0 : 1, FakeServer::closes());
		$this->assertSame($open ? 1 : 2, FakeServer::$connections);
		if ($open) {
			$this->assertTrue($this->q($db, 'COMMIT'));
			$this->assertFalse($db->lost());
			$this->assertContains('UPDATE wallet SET credit=credit+10 WHERE id=1', FakeServer::$committed);
		}
	}
}
