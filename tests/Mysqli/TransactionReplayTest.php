<?php

namespace MyDb\Tests\Mysqli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
* Whole-transaction replay: a statement or COMMIT inside a transaction that the server rolled back (1213 deadlock,
* 3101 Group Replication certification failure) is recovered by rolling back, starting the transaction again the
* way it was started and re-running every recorded statement, each outcome the caller saw checked against the
* original, before the failed statement is run again. A replay that recovers is silent (no emailError(), no
* halt(), no exception); one that cannot falls back to the give-up of TransactionRetryTest.
*
* No server is involved (fake_mysqli.php, hence the process isolation); see TransactionRetryTest.
*
* @runTestsInSeparateProcesses
* @preserveGlobalState disabled
*/
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TransactionReplayTest extends TestCase
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
	* @param string $haltOnError 'yes' proves a recovery never halts: halt() would end the process
	* @return ScriptedDb
	*/
	private function db($haltOnError = 'no')
	{
		$db = new ScriptedDb('fake_db', 'fake_user', '', '127.0.0.1', '', '1');
		$db->haltOnError = $haltOnError;
		// a replay count that makes a give-up quick to script; the default budget has its own tests (dbDefaultBudget())
		$db->transactionReplays = 3;
		return $db;
	}

	/**
	* a handle with the library's default replay budget (25 replays, 10 s), on a virtual clock
	*
	* @param string $haltOnError
	* @return ScriptedDb
	*/
	private function dbDefaultBudget($haltOnError = 'no')
	{
		$db = new ScriptedDb('fake_db', 'fake_user', '', '127.0.0.1', '', '1');
		$db->haltOnError = $haltOnError;
		return $db;
	}

	private function q(ScriptedDb $db, $sql)
	{
		return $db->query($sql, __LINE__, __FILE__);
	}

	/** the statements sent, without the UNLOCK TABLES halt() sends after every failed statement */
	private static function wire()
	{
		return array_values(array_filter(FakeServer::statements(), static function ($sql) {
			return $sql !== 'unlock tables';
		}));
	}

	/** how many times $sql became durable */
	private static function committed($sql)
	{
		return count(array_keys(FakeServer::$committed, $sql, true));
	}

	private function assertRecoveredQuietly(ScriptedDb $db, $haltOnError = 'yes')
	{
		$this->assertSame([], $db->reported, 'nothing reported through emailError()');
		$this->assertSame(0, $db->halts, 'halt() never ran');
		$this->assertSame($haltOnError, $db->haltOnError, 'haltOnError is restored');
		$this->assertSame(0, (int) $db->Errno);
		$this->assertFalse($db->lost(), 'the transaction is not lost');
	}

	private function assertGaveUp(ScriptedDb $db, $errno, $why)
	{
		$this->assertFalse($db->inTransaction(), 'no transaction is open');
		$this->assertTrue($db->lost(), 'the transaction is lost');
		$this->assertSame($errno, (int) $db->Errno);
		$this->assertSame([], FakeServer::$open, 'nothing is left open on the server');
		$this->assertStringContainsString($why, implode("\n", FakeServer::$log));
		$sent = FakeServer::sent('COMMIT');
		$this->assertFalse($this->q($db, 'COMMIT'), 'a later COMMIT does not report the lost writes as saved');
		$this->assertSame($sent, FakeServer::sent('COMMIT'), 'and is not sent');
		$this->assertStringContainsString('COMMIT refused', $db->Error);
	}

	public static function reportModes()
	{
		return [
			'failures throw (MYSQLI_REPORT_STRICT)' => [true],
			'failures return false' => [false],
		];
	}

	/**
	* Session::destroy(): START, a locking select read with qr(), two deletes, affectedRows(), COMMIT. The delete
	* from sessions deadlocks (production: 9 of 15 mails in 7 minutes).
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTheSessionDestroyShapeRecoversFromADeadlockOnADelete($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db('yes');
		$select = "select as_id from appsession where as_sid=(select session_id from sessions where session_id='abc' for update) for update";
		FakeServer::rows('/^select as_id from appsession/', [['as_id' => '7'], ['as_id' => '8']]);
		$this->assertTrue($this->q($db, 'start transaction'));
		$this->assertSame([['as_id' => '7'], ['as_id' => '8']], $db->qr($select, __LINE__, __FILE__), 'recording the result set left every row for the caller');
		$this->assertTrue($this->q($db, "delete from appsession where as_sid='abc'"));
		FakeServer::failNext('/^delete from sessions /', 1213);

		$this->assertTrue($this->q($db, "delete from sessions where session_id='abc'"));
		$this->assertSame(1, $db->affectedRows());
		$this->assertTrue($this->q($db, 'commit'));

		$this->assertSame([
			'start transaction', $select, "delete from appsession where as_sid='abc'", "delete from sessions where session_id='abc'",
			'ROLLBACK', 'start transaction', $select, "delete from appsession where as_sid='abc'", "delete from sessions where session_id='abc'",
			'commit',
		], self::wire(), 'rolled back, started the way it was, re-ran, then the delete again');
		$this->assertSame([10000], FakeServer::$sleeps, 'one replay, 10 ms after the deadlock');
		$this->assertSame(1, FakeServer::$connections);
		$this->assertSame(1, self::committed("delete from appsession where as_sid='abc'"));
		$this->assertSame(1, self::committed("delete from sessions where session_id='abc'"));
		$this->assertSame([], FakeServer::$open);
		$this->assertFalse($db->inTransaction());
		$this->assertRecoveredQuietly($db);
		$this->assertCount(1, FakeServer::$log, json_encode(FakeServer::$log));
		$this->assertStringContainsString('Replayed a transaction the server rolled back (SQL error 1213; 1 replay(s) of 2 statement(s)); the statement went through.', FakeServer::$log[0]);
	}

	/**
	* Session::destroy() again, the COMMIT failing certification (production: 6 of the 15 mails).
	*
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTheSessionDestroyShapeRecoversFromACertificationFailureAtCommit($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db('yes');
		$select = "select as_id from appsession where as_sid=(select session_id from sessions where session_id='abc' for update) for update";
		FakeServer::rows('/^select as_id from appsession/', [['as_id' => '7']]);
		$this->assertTrue($this->q($db, 'start transaction'));
		$this->assertSame(['as_id' => '7'], $db->qr($select, __LINE__, __FILE__));
		$this->assertTrue($this->q($db, "delete from appsession where as_sid='abc'"));
		$this->assertTrue($this->q($db, "delete from sessions where session_id='abc'"));
		$this->assertSame(1, $db->affectedRows(), 'read before the COMMIT, so the replay must find the row again');
		FakeServer::failNext('/^commit$/', 3101);

		$this->assertTrue($this->q($db, 'commit'));

		$this->assertSame(2, FakeServer::sent('commit'));
		$this->assertSame(2, FakeServer::sent('start transaction'));
		$this->assertSame(1, FakeServer::sent('ROLLBACK'));
		$this->assertSame([10000], FakeServer::$sleeps);
		$this->assertSame(1, self::committed("delete from sessions where session_id='abc'"));
		$this->assertFalse($db->inTransaction());
		$this->assertRecoveredQuietly($db);
		$this->assertCount(1, FakeServer::$log, json_encode(FakeServer::$log));
		$this->assertStringContainsString('(SQL error 3101; 1 replay(s) of 3 statement(s))', FakeServer::$log[0]);
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTransactionCommitRecoversFromACertificationFailureWithoutThrowing($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db('yes');
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^COMMIT$/', 3101);

		$this->assertTrue($db->transactionCommit());

		$this->assertSame(2, FakeServer::$begins, 'replayed through mysqli_begin_transaction(), the way it was started');
		$this->assertSame(['START TRANSACTION', 'UPDATE a SET x=1', 'COMMIT', 'ROLLBACK', 'START TRANSACTION', 'UPDATE a SET x=1', 'COMMIT'], self::wire());
		$this->assertSame(1, self::committed('UPDATE a SET x=1'));
		$this->assertSame([10000], FakeServer::$sleeps);
		$this->assertFalse($db->inTransaction());
		$this->assertRecoveredQuietly($db);
		$this->assertCount(1, FakeServer::$log);
		$this->assertStringContainsString('at COMMIT (SQL error 3101; 1 replay(s) of 1 statement(s)); it committed.', FakeServer::$log[0]);
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTransactionCommitThatKeepsFailingCertificationFailsAsBefore($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db('yes');
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^COMMIT$/', 3101, self::ALWAYS);

		try {
			$result = $db->transactionCommit();
			$this->assertFalse($strict, 'only without strict mode does it return');
			$this->assertFalse($result);
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict, 'strict mode throws the final failure, as mysqli_commit() did');
			$this->assertSame(3101, $e->getCode());
		}
		$this->assertSame(4, FakeServer::sent('COMMIT'), 'once, then once in each of 3 replays');
		$this->assertSame([10000, 20000, 40000], FakeServer::$sleeps);
		$this->assertSame([], $db->reported, 'transactionCommit() never mails');
		$this->assertSame(0, $db->halts);
		$this->assertSame('yes', $db->haltOnError);
		$this->assertSame([], FakeServer::$committed);
		$this->assertFalse($db->transactionCommit(), 'a later COMMIT is refused');
		$this->assertSame(4, FakeServer::sent('COMMIT'));
		$this->assertSame(3101, (int) $db->Errno);
	}

	public function testTheStatementThatIsReplayedHandsBackEveryRowOfItsResultSet()
	{
		$db = $this->db();
		FakeServer::rows('/^SELECT id FROM t/', [['id' => '1'], ['id' => '2'], ['id' => '3']]);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^SELECT id FROM t/', 1213);

		$result = $this->q($db, 'SELECT id FROM t');

		$this->assertInstanceOf(FakeResult::class, $result);
		$this->assertSame(3, $db->num_rows());
		$ids = [];
		while ($db->next_record(MYSQLI_ASSOC)) {
			$ids[] = $db->Record['id'];
		}
		$this->assertSame(['1', '2', '3'], $ids, 'recording the replayed result left every row for the caller');
		$this->assertCount(2, $db->steps(), 'the replayed select is recorded');
		$this->assertRecoveredQuietly($db, 'no');

		// and it is checked by the next replay
		FakeServer::failNext('/^UPDATE b/', 1213);
		$this->assertTrue($this->q($db, 'UPDATE b SET x=1'));
		$this->assertSame(3, FakeServer::sent('SELECT id FROM t'));
	}

	public function testAResultSetThatChangesOnReplayGivesUp()
	{
		$db = $this->db();
		FakeServer::rows('/^SELECT balance/', [['balance' => '10']], [['balance' => '20']]);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertInstanceOf(FakeResult::class, $this->q($db, 'SELECT balance FROM wallet WHERE id=1'));
		FakeServer::failNext('/^UPDATE wallet/', 1213);

		$this->assertFalse($this->q($db, 'UPDATE wallet SET balance=5 WHERE id=1'));

		$this->assertSame(['START TRANSACTION', 'SELECT balance FROM wallet WHERE id=1', 'UPDATE wallet SET balance=5 WHERE id=1', 'ROLLBACK', 'START TRANSACTION', 'SELECT balance FROM wallet WHERE id=1', 'ROLLBACK'], self::wire(), 'the replay stopped at the changed result and rolled back; the update was not re-run');
		$this->assertSame([10000], FakeServer::$sleeps, 'no further replay: the data the caller decided on changed');
		$this->assertCount(1, $db->reported, 'reported like any failed statement');
		$this->assertSame(1, $db->halts);
		$this->assertGaveUp($db, 1213, 'Not recovered: a result set it read changed (statement 1 of 1).');
		$this->assertSame([], FakeServer::$committed);
	}

	public static function observedOutcomes()
	{
		return [
			'insert id read' => [true, 'the insert id it read changed (statement 1 of 1)'],
			'insert id not read' => [false, ''],
		];
	}

	/**
	* InnoDB never hands an auto-increment value out twice, so a replayed INSERT always gets a new id: that only
	* matters if the caller read it.
	*
	* @dataProvider observedOutcomes
	* @param bool $read
	* @param string $why
	*/
	#[DataProvider('observedOutcomes')]
	public function testAnInsertIdThatChangesOnReplayGivesUpOnlyIfTheCallerReadIt($read, $why)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO orders (a) VALUES (1)'));
		if ($read) {
			$this->assertSame(1, (int) $db->getLastInsertId('orders', 'order_id'));
		}
		FakeServer::failNext('/^UPDATE stock/', 1213);

		$result = $this->q($db, 'UPDATE stock SET n=n-1 WHERE id=1');

		$this->assertSame(2, FakeServer::sent('INSERT INTO orders (a) VALUES (1)'));
		$this->assertSame(2, FakeServer::$autoIncrement, 'the replayed insert got another id');
		if ($read) {
			$this->assertFalse($result);
			$this->assertSame(1, FakeServer::sent('UPDATE stock SET n=n-1 WHERE id=1'));
			$this->assertGaveUp($db, 1213, 'Not recovered: '.$why);
		} else {
			$this->assertTrue($result);
			$this->assertRecoveredQuietly($db, 'no');
			$this->assertTrue($this->q($db, 'COMMIT'));
			$this->assertSame(1, self::committed('INSERT INTO orders (a) VALUES (1)'));
		}
	}

	public static function affectedRowsSeen()
	{
		return [
			'affected rows read' => [true],
			'affected rows not read' => [false],
		];
	}

	/**
	* @dataProvider affectedRowsSeen
	* @param bool $read
	*/
	#[DataProvider('affectedRowsSeen')]
	public function testAffectedRowsThatChangeOnReplayGiveUpOnlyIfTheCallerReadThem($read)
	{
		$db = $this->db();
		FakeServer::affected('/^UPDATE counter/', 1, 0);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE counter SET n=n+1 WHERE id=1'));
		if ($read) {
			$this->assertSame(1, $db->affectedRows());
		}
		FakeServer::failNext('/^UPDATE other/', 1213);

		$result = $this->q($db, 'UPDATE other SET n=1');

		if ($read) {
			$this->assertFalse($result);
			$this->assertGaveUp($db, 1213, 'Not recovered: the affected rows it read changed (1, now 0) (statement 1 of 1).');
		} else {
			$this->assertTrue($result);
			$this->assertRecoveredQuietly($db, 'no');
		}
	}

	/**
	* What the caller saw stays checked across replays: the replayed recording keeps the "read" marks.
	*/
	public function testAnOutcomeReadBeforeOneReplayIsStillCheckedByTheNext()
	{
		$db = $this->db();
		FakeServer::affected('/^UPDATE counter/', 1, 1, 0);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE counter SET n=n+1 WHERE id=1'));
		$this->assertSame(1, $db->affectedRows());
		FakeServer::failNext('/^UPDATE a/', 1213);
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'), 'the first replay finds the same count');
		FakeServer::failNext('/^UPDATE b/', 1213);

		$this->assertFalse($this->q($db, 'UPDATE b SET x=1'));

		$this->assertGaveUp($db, 1213, 'Not recovered: the affected rows it read changed (1, now 0) (statement 1 of 2).');
	}

	public static function linkLossCodes()
	{
		return [
			'2006 gone away' => [2006],
			'2013 lost during query' => [2013],
		];
	}

	/**
	* A COMMIT that loses the connection may have committed: it is never replayed.
	*
	* @dataProvider linkLossCodes
	* @param int $code
	*/
	#[DataProvider('linkLossCodes')]
	public function testACommitThatLosesTheConnectionIsNeverReplayed($code)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^COMMIT$/', $code);

		$this->assertFalse($this->q($db, 'COMMIT'));

		$this->assertSame(['START TRANSACTION', 'UPDATE a SET x=1', 'COMMIT'], self::wire(), 'no ROLLBACK, no START, nothing re-run');
		$this->assertSame([], FakeServer::$sleeps);
		$this->assertSame(1, FakeServer::$connections, 'no new connection');
		$this->assertSame(1, FakeServer::closes(), 'the dead connection is let go of');
		$this->assertSame(0, $db->linkId);
		$this->assertSame([], array_values(array_filter(FakeServer::$wire, static function ($event) {
			return $event[0] === 'dead';
		})), 'at once: not even the best-effort ROLLBACK is tried on it');
		$this->assertCount(1, $db->reported, 'a lost connection is reported whatever reportConflicts says');
		$this->assertCount(1, FakeServer::$log, json_encode(FakeServer::$log));
		$this->assertStringContainsString('COMMIT lost the connection (SQL error '.$code.'); it may have committed, so it is not replayed', FakeServer::$log[0]);
		$this->assertGaveUp($db, $code, 'COMMIT lost the connection');
		$this->assertSame(1, FakeServer::$connections, 'the refused COMMIT did not connect either');
		$this->assertStringContainsString('#'.$code, $db->Error, 'refused with the error it was lost with, never as a deadlock');
	}

	public static function linkLossCodesAndReportModes()
	{
		$out = [];
		foreach (self::linkLossCodes() as $name => $code) {
			foreach (self::reportModes() as $mode => $strict) {
				$out[$name.', '.$mode] = [$code[0], $strict[0]];
			}
		}
		return $out;
	}

	/**
	* @dataProvider linkLossCodesAndReportModes
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('linkLossCodesAndReportModes')]
	public function testTransactionCommitThatLosesTheConnectionIsNeverReplayed($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^COMMIT$/', $code);

		try {
			$result = $db->transactionCommit();
			$this->assertFalse($strict, 'only without strict mode does it return');
			$this->assertFalse($result);
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
			$this->assertSame($code, $e->getCode());
		}

		$this->assertSame(['START TRANSACTION', 'UPDATE a SET x=1', 'COMMIT'], self::wire());
		$this->assertSame(1, FakeServer::$begins);
		$this->assertSame([], FakeServer::$sleeps);
		$this->assertSame(1, FakeServer::closes());
		$this->assertSame(0, $db->linkId);
		$this->assertSame([], $db->reported);
		$this->assertTrue($db->lost());
		$this->assertFalse($db->transactionCommit(), 'refused');
		$this->assertSame($code, (int) $db->Errno);
	}

	public function testAReplayedCommitThatLosesTheConnectionIsNotReplayedAgain()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^COMMIT$/', 3101);
		FakeServer::failNext('/^COMMIT$/', 2006);

		$this->assertFalse($this->q($db, 'COMMIT'));

		$this->assertSame(2, FakeServer::sent('COMMIT'));
		$this->assertSame([10000], FakeServer::$sleeps, 'the replayed COMMIT that lost the connection ended it');
		$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'and nothing was sent after it');
		$this->assertSame(0, $db->linkId);
		$this->assertCount(1, $db->reported);
		$this->assertGaveUp($db, 2006, 'Not recovered: the replayed COMMIT lost the connection, so its outcome is unknown.');
		$this->assertSame([], FakeServer::$committed, 'the fake lost it; the driver cannot know that');
	}

	public static function replayBudgets()
	{
		return [
			'recovered by the last replay' => [5, true],
			'one conflict too many' => [6, false],
		];
	}

	/**
	* @dataProvider replayBudgets
	* @param int $conflicts
	* @param bool $recovered
	*/
	#[DataProvider('replayBudgets')]
	public function testReplaysRunOutAfterTheBudgetWithAGrowingCappedWait($conflicts, $recovered)
	{
		$db = $this->db();
		$db->transactionReplays = 5;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE b/', 3101, $conflicts);

		$result = $this->q($db, 'UPDATE b SET x=1');

		$this->assertSame([10000, 20000, 40000, 80000, 160000], FakeServer::$sleeps);
		$this->assertSame(6, FakeServer::sent('UPDATE b SET x=1'));
		$this->assertSame(6, FakeServer::sent('UPDATE a SET x=1'));
		if ($recovered) {
			$this->assertTrue($result);
			$this->assertRecoveredQuietly($db, 'no');
			$this->assertStringContainsString('5 replay(s) of 1 statement(s)', FakeServer::$log[0]);
		} else {
			$this->assertFalse($result);
			$this->assertGaveUp($db, 3101, 'Not recovered: it was rolled back again on each of 5 replay(s).');
		}
	}

	public function testNoReplaysWithAZeroBudgetButTheTransactionIsStillEnded()
	{
		$db = $this->db();
		$db->transactionReplays = 0;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE b/', 1213);

		$this->assertFalse($this->q($db, 'UPDATE b SET x=1'));

		$this->assertSame([], FakeServer::$sleeps);
		$this->assertSame(['START TRANSACTION', 'UPDATE a SET x=1', 'UPDATE b SET x=1', 'ROLLBACK'], self::wire(), 'the best-effort ROLLBACK ends what the deadlock left open');
		$this->assertGaveUp($db, 1213, 'inside a transaction; the server has rolled it back');
		$this->assertStringNotContainsString('Not re', implode("\n", FakeServer::$log));
	}

	public static function notReplayable()
	{
		return [
			'prepare()' => ['prepare', 'prepare() ran inside it'],
			'execute()' => ['execute', 'execute() ran inside it'],
			'SET AUTOCOMMIT' => ['SET AUTOCOMMIT=0', 'it ran a statement a replay cannot repeat (SET)'],
			'a user variable assigned' => ['SELECT @n := @n + 1', 'it ran a statement a replay cannot repeat (SELECT)'],
			'SELECT INTO a user variable' => ['SELECT a INTO @v FROM t', 'it ran a statement a replay cannot repeat (SELECT)'],
			'a user-level lock' => ["SELECT GET_LOCK('k', 1)", 'it ran a statement a replay cannot repeat (SELECT)'],
			'CALL' => ['CALL p()', 'it ran a statement a replay cannot repeat (CALL)'],
			'DDL' => ['CREATE TEMPORARY TABLE x (a int)', 'it ran a statement a replay cannot repeat (CREATE)'],
			'selectDb()' => ['selectDb', 'selectDb() ran inside it'],
			'unlock()' => ['unlock', 'unlock() ran inside it'],
			'another handle on the shared connection' => ['clone', 'another handle sharing the connection ran a statement inside it'],
		];
	}

	/** what a notReplayable() case does inside the transaction: a method, or a statement */
	private function runInside(ScriptedDb $db, $action)
	{
		switch ($action) {
			case 'prepare':
				$db->prepare('UPDATE t SET a=?');
				break;
			case 'execute':
				// prepared before the transaction (see the test): only the execute() is inside it
				$db->execute();
				break;
			case 'selectDb':
				$db->selectDb('other');
				break;
			case 'unlock':
				$db->unlock(false);
				break;
			case 'clone':
				$clone = clone $db;
				$clone->query('UPDATE other SET x=1', __LINE__, __FILE__);
				break;
			default:
				$db->query($action, __LINE__, __FILE__);
		}
	}

	/**
	* A transaction a replay could not repeat gives up on the first conflict, and still ends what the deadlock left
	* open (best-effort ROLLBACK), so the statements after it autocommit instead of joining a transaction no one
	* will commit.
	*
	* @dataProvider notReplayable
	* @param string $action
	* @param string $why
	*/
	#[DataProvider('notReplayable')]
	public function testATransactionThatCannotBeReplayedGivesUpAndIsRolledBack($action, $why)
	{
		$db = $this->db();
		if ($action === 'execute') {
			$db->prepare('UPDATE t SET a=1');
		}
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		$this->assertTrue($db->replayable());
		$this->runInside($db, $action);
		FakeServer::failNext('/^UPDATE b/', 1213);

		$this->assertFalse($this->q($db, 'UPDATE b SET x=1'));

		$this->assertSame([], FakeServer::$sleeps, 'not replayed');
		$this->assertSame(1, FakeServer::sent('UPDATE b SET x=1'));
		$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'the best-effort ROLLBACK');
		$this->assertSame('ROLLBACK', array_slice(self::wire(), -1)[0]);
		$this->assertCount(1, $db->reported);
		$this->assertGaveUp($db, 1213, 'Not replayed: '.$why.'.');
		$this->assertTrue($this->q($db, 'INSERT INTO audit VALUES (1)'));
		$this->assertSame(1, self::committed('INSERT INTO audit VALUES (1)'), 'autocommitted: the deadlock left no transaction open');
	}

	public function testLockTablesEndsTheTransactionSoTheOldStatementRetryApplies()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		$this->assertTrue($db->lock('t'));
		$this->assertFalse($db->inTransaction(), 'LOCK TABLES commits the open transaction');
		$this->assertSame([], $db->steps());
		FakeServer::failNext('/^UPDATE b/', 1213);

		$this->assertTrue($this->q($db, 'UPDATE b SET x=1'));

		$this->assertSame([250000], FakeServer::$sleeps, 'retried on its own, as every statement outside a transaction');
		$this->assertSame(0, FakeServer::sent('ROLLBACK'));
		$this->assertSame(2, FakeServer::sent('UPDATE b SET x=1'));
		$this->assertSame([], FakeServer::$log);
	}

	public static function caps()
	{
		return [
			'statements' => ['replayMaxStatements', 2],
			'bytes' => ['replayMaxBytes', 60],
		];
	}

	/**
	* @dataProvider caps
	* @param string $knob
	* @param int $value
	*/
	#[DataProvider('caps')]
	public function testATransactionOverTheCapsIsNotRecorded($knob, $value)
	{
		$db = $this->db();
		$db->$knob = $value;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "UPDATE a SET x='0123456789'"));
		$this->assertTrue($this->q($db, "UPDATE b SET x='0123456789'"));
		$this->assertTrue($db->replayable(), '2 statements, 54 bytes');
		$this->assertTrue($this->q($db, "UPDATE c SET x='0123456789'"));
		$this->assertSame('it is too large to replay', $db->replayable());
		FakeServer::failNext('/^UPDATE d/', 1213);

		$this->assertFalse($this->q($db, 'UPDATE d SET x=1'));

		$this->assertSame([], FakeServer::$sleeps);
		$this->assertGaveUp($db, 1213, 'Not replayed: it is too large to replay.');
	}

	public static function chainOpeners()
	{
		return [
			'START TRANSACTION READ WRITE' => ['START TRANSACTION READ WRITE'],
			'transactionBegin()' => [null],
		];
	}

	/**
	* COMMIT AND CHAIN commits what came before it: a replay starts at the chain, the way the transaction was opened.
	*
	* @dataProvider chainOpeners
	* @param string|null $opener
	*/
	#[DataProvider('chainOpeners')]
	public function testAReplayAfterAndChainReRunsOnlyTheChainedTransaction($opener)
	{
		$db = $this->db();
		if ($opener === null) {
			$this->assertTrue($db->transactionBegin());
		} else {
			$this->assertTrue($this->q($db, $opener));
		}
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		$this->assertTrue($this->q($db, 'COMMIT AND CHAIN'));
		$this->assertTrue($this->q($db, 'UPDATE b SET x=1'));
		FakeServer::failNext('/^UPDATE c/', 1213);

		$this->assertTrue($this->q($db, 'UPDATE c SET x=1'));

		$this->assertSame(['UPDATE c SET x=1', 'ROLLBACK', $opener === null ? 'START TRANSACTION' : $opener, 'UPDATE b SET x=1', 'UPDATE c SET x=1'], array_slice(self::wire(), -5));
		$this->assertSame($opener === null ? 2 : 0, FakeServer::$begins);
		$this->assertSame(1, FakeServer::sent('UPDATE a SET x=1'), 'committed by the chain, never re-run');
		$this->assertRecoveredQuietly($db, 'no');
		$this->assertTrue($this->q($db, 'COMMIT'));
		foreach (['UPDATE a SET x=1', 'UPDATE b SET x=1', 'UPDATE c SET x=1'] as $write) {
			$this->assertSame(1, self::committed($write), $write);
		}
	}

	public function testAChainStartedWithNoTransactionOpenIsReplayedWithAPlainStart()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'COMMIT AND CHAIN'));
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE b/', 3101);

		$this->assertTrue($this->q($db, 'UPDATE b SET x=1'));

		$this->assertSame(['COMMIT AND CHAIN', 'UPDATE a SET x=1', 'UPDATE b SET x=1', 'ROLLBACK', 'START TRANSACTION', 'UPDATE a SET x=1', 'UPDATE b SET x=1'], self::wire());
		$this->assertRecoveredQuietly($db, 'no');
	}

	public static function retriedOutside()
	{
		return [
			'1213' => [1213],
			'3101' => [3101],
		];
	}

	/**
	* @dataProvider retriedOutside
	* @param int $code
	*/
	#[DataProvider('retriedOutside')]
	public function testOutsideATransactionTheStatementRetryIsUnchanged($code)
	{
		$db = $this->db();
		FakeServer::failNext('/^UPDATE a/', $code);

		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));

		$this->assertSame(['UPDATE a SET x=1', 'UPDATE a SET x=1'], self::wire());
		$this->assertSame([250000], FakeServer::$sleeps);
		$this->assertSame(1, self::committed('UPDATE a SET x=1'));
		$this->assertSame([], FakeServer::$log);
		$this->assertSame([], $db->reported);
	}

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testWithReportConflictsOffAConflictThatIsNotRecoveredGoesToTheCaller($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db('yes');
		$db->reportConflicts = false;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE b/', 1213, self::ALWAYS);

		$this->assertFalse($this->q($db, 'UPDATE b SET x=1'), 'returned, not thrown, and the process was not halted');

		$this->assertSame(1213, (int) $db->Errno);
		$this->assertSame([], $db->reported);
		$this->assertSame(0, $db->halts);
		$this->assertSame('yes', $db->haltOnError);
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertSame(1213, (int) $db->Errno);
		$this->assertSame([], $db->reported, 'the refused COMMIT is not reported either');
		$this->assertSame(0, $db->halts);
		$this->assertCount(2, FakeServer::$log, json_encode(FakeServer::$log));
		$this->assertStringContainsString('Not recovered: it was rolled back again on each of 3 replay(s)', FakeServer::$log[0]);
		$this->assertStringContainsString('COMMIT refused: the transaction was lost (#1213); not reported, reportConflicts is off.', FakeServer::$log[1]);
	}

	public function testReportConflictsOffStillReportsEveryOtherFailure()
	{
		$db = $this->db();
		$db->reportConflicts = false;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/^INSERT/', 1062);
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertCount(1, $db->reported, 'a duplicate key is reported');
		$this->assertTrue($db->inTransaction(), 'and only the statement failed');
		$this->assertTrue($this->q($db, 'SET @tag = 1'));
		FakeServer::failNext('/^UPDATE/', 2006);
		$this->assertFalse($this->q($db, 'UPDATE a SET x=1'));
		$this->assertCount(2, $db->reported, 'so is a lost connection that could not be replayed');
		$this->assertSame(2, $db->halts);
	}

	public static function errorSteps()
	{
		return [
			'the error comes back' => [2, true],
			'it succeeds this time' => [1, false],
		];
	}

	/**
	* A statement that failed on its own (only it was rolled back) is replayed too, and must fail the same way.
	*
	* @dataProvider errorSteps
	* @param int $times
	* @param bool $recovered
	*/
	#[DataProvider('errorSteps')]
	public function testAStatementThatFailedOnItsOwnMustFailTheSameWayOnReplay($times, $recovered)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/^INSERT INTO uniq/', 1062, $times);
		$this->assertFalse($this->q($db, 'INSERT INTO uniq VALUES (1)'));
		$this->assertCount(1, $db->reported);
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE b/', 1213);

		$result = $this->q($db, 'UPDATE b SET x=1');

		$this->assertSame(2, FakeServer::sent('INSERT INTO uniq VALUES (1)'));
		if ($recovered) {
			$this->assertTrue($result);
			$this->assertCount(1, $db->reported, 'the replay added nothing');
			$this->assertFalse($db->lost());
			$this->assertTrue($this->q($db, 'COMMIT'));
			$this->assertSame(0, self::committed('INSERT INTO uniq VALUES (1)'));
			$this->assertSame(1, self::committed('UPDATE b SET x=1'));
		} else {
			$this->assertFalse($result);
			$this->assertGaveUp($db, 1213, 'Not recovered: a statement that failed now succeeds (statement 1 of 2).');
		}
	}

	public static function rollbackFailures()
	{
		return [
			'1205 (retried, reconnected)' => [1205, 3],
			'1181 (retried, reconnected)' => [1181, 3],
			'1064 (not retried)' => [1064, 1],
		];
	}

	/**
	* A plain ROLLBACK that fails ends the transaction as far as the caller is concerned, whatever the error: it is
	* recorded as lost, so a COMMIT after it is refused instead of committing (or reporting as saved) what the
	* caller meant to discard; and when the server may still hold the transaction (an error the retry path does not
	* retry never reconnected) the connection is closed, which ends it there. What the caller sends next is not
	* refused: it ended the transaction itself.
	*
	* @dataProvider rollbackFailures
	* @param int $code
	* @param int $sent
	*/
	#[DataProvider('rollbackFailures')]
	public function testAFailedRollbackWithAnyErrorMarksTheTransactionLost($code, $sent)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^ROLLBACK$/', $code, self::ALWAYS);

		$this->assertFalse($this->q($db, 'ROLLBACK'));

		$this->assertSame($sent, FakeServer::sent('ROLLBACK'), 'the retry and reconnect path a plain ROLLBACK keeps');
		$this->assertSame($sent, FakeServer::$connections, 'each retry of its own ROLLBACK is on a new connection, as before');
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
		$this->assertSame(0, $db->linkId, 'the connection is let go of: the server holds no transaction for it');
		$this->assertSame([], FakeServer::$open);
		$this->assertFalse($this->q($db, 'COMMIT'), 'refused');
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertSame($code, (int) $db->Errno);
		$this->assertTrue($this->q($db, 'UPDATE c SET x=1'), 'not refused: the caller ended the transaction itself');
		$this->assertSame(['UPDATE c SET x=1'], FakeServer::$committed, 'the discarded UPDATE was never committed');
	}

	public function testAFailedRollbackWithARollbackErrorMarksTheTransactionLost()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^ROLLBACK$/', 1213, self::ALWAYS);

		$this->assertFalse($this->q($db, 'ROLLBACK'));

		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(0, FakeServer::sent('COMMIT'), 'refused');
	}

	public function testOnlyAStatementThatStartsWithRollbackIsScannedForAPlainRollback()
	{
		$db = $this->db();
		ScriptedDb::$plainChecks = 0;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "UPDATE a SET x='rollback'"));
		$this->assertTrue($this->q($db, '/* rollback */ UPDATE a SET x=2'));
		$this->assertSame(0, ScriptedDb::$plainChecks);
		$this->assertTrue($this->q($db, '/* note */ ROLLBACK'));
		$this->assertSame(1, ScriptedDb::$plainChecks);
		$this->assertFalse($db->inTransaction());
	}

	/**
	* A START inside an open transaction commits it first, so it recovers from a certification failure like a COMMIT.
	*/
	public function testAStartThatCommitsTheOpenTransactionIsReplayedLikeACommit()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^BEGIN$/', 3101);

		$this->assertTrue($this->q($db, 'BEGIN'));

		$this->assertSame(['START TRANSACTION', 'UPDATE a SET x=1', 'BEGIN', 'ROLLBACK', 'START TRANSACTION', 'UPDATE a SET x=1', 'BEGIN'], self::wire());
		$this->assertSame(1, self::committed('UPDATE a SET x=1'));
		$this->assertTrue($db->inTransaction());
		$this->assertSame([], $db->steps(), 'a new recording, opened by the BEGIN');
		$this->assertRecoveredQuietly($db, 'no');
	}

	// ---------------------------------------------------------------- the replay budget (time based)

	/**
	* In multi-primary Group Replication a replay can fail again (3101) until the winning transaction has been
	* applied on this node: replays go on, with a longer jittered wait each, until the wall-clock budget is spent.
	*/
	public function testAReplayKeepsTryingWithGrowingWaitsUntilTheBudgetIsSpent()
	{
		$db = $this->dbDefaultBudget();
		$this->assertSame(25, $db->transactionReplays);
		$this->assertSame(10000, $db->replayBudgetMs);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE b/', 3101, self::ALWAYS);

		$this->assertFalse($this->q($db, 'UPDATE b SET x=1'));

		$sleeps = FakeServer::$sleeps;
		$this->assertSame([10000, 20000, 40000, 80000, 160000, 320000, 640000, 1000000, 1000000], array_slice($sleeps, 0, 9), 'exponential, capped at one second (jitter pinned to its maximum)');
		$this->assertCount(15, $sleeps, 'the 16th wait would have passed the budget');
		$this->assertLessThanOrEqual(10000000, array_sum($sleeps));
		$this->assertGreaterThan(9000000, array_sum($sleeps));
		$this->assertSame(16, FakeServer::sent('UPDATE b SET x=1'), 'once, then once in each of 15 replays');
		$this->assertGaveUp($db, 3101, 'Not recovered: it was rolled back again on each of 15 replay(s) within the 10000 ms budget.');
	}

	public function testAConflictThatClearsAfterManyReplaysIsStillRecovered()
	{
		$db = $this->dbDefaultBudget('yes');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE b/', 3101, 12);

		$this->assertTrue($this->q($db, 'UPDATE b SET x=1'));

		$this->assertCount(12, FakeServer::$sleeps);
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(1, self::committed('UPDATE a SET x=1'));
		$this->assertSame(1, self::committed('UPDATE b SET x=1'));
		$this->assertSame([], $db->reported);
		$this->assertSame(0, $db->halts);
	}

	public function testTheBudgetStopsReplaysWhenTheNextWaitWouldPassIt()
	{
		$db = $this->dbDefaultBudget();
		$db->replayBudgetMs = 100;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE b/', 1213, self::ALWAYS);

		$this->assertFalse($this->q($db, 'UPDATE b SET x=1'));

		$this->assertSame([10000, 20000, 40000], FakeServer::$sleeps, '10+20+40 ms is 70; the next wait (80) would pass 100');
		$this->assertGaveUp($db, 1213, 'Not recovered: it was rolled back again on each of 3 replay(s) within the 100 ms budget.');
	}

	public function testABudgetSmallerThanTheFirstWaitMeansNoReplayButTheTransactionIsEnded()
	{
		$db = $this->dbDefaultBudget();
		$db->replayBudgetMs = 5;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE b/', 1213, self::ALWAYS);

		$this->assertFalse($this->q($db, 'UPDATE b SET x=1'));

		$this->assertSame([], FakeServer::$sleeps);
		$this->assertSame(1, FakeServer::sent('UPDATE b SET x=1'));
		$this->assertGaveUp($db, 1213, 'Not recovered: the replay budget of 5 ms is shorter than the first wait.');
	}

	public function testTheCountCapEndsReplaysBeforeTheBudgetWhenItIsLower()
	{
		$db = $this->dbDefaultBudget();
		$db->transactionReplays = 4;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/^UPDATE b/', 1213, self::ALWAYS);

		$this->assertFalse($this->q($db, 'UPDATE b SET x=1'));

		$this->assertSame([10000, 20000, 40000, 80000], FakeServer::$sleeps);
		$this->assertGaveUp($db, 1213, 'Not recovered: it was rolled back again on each of 4 replay(s).');
	}

	public function testEachWaitIsHalfFixedAndHalfJitter()
	{
		$db = $this->dbDefaultBudget();
		$db->jitterUs = 0;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/^UPDATE b/', 1213, 3);

		$this->assertTrue($this->q($db, 'UPDATE b SET x=1'));

		$this->assertSame([5000, 10000, 20000], FakeServer::$sleeps, 'the fixed half only');
	}

	public function testTheProductionJitterStaysInsideItsBounds()
	{
		$delay = new \ReflectionMethod(\MyDb\Mysqli\Db::class, 'replayDelayUs');
		$delay->setAccessible(true);
		$db = new \MyDb\Mysqli\Db('fake_db', 'fake_user', '', '127.0.0.1', '', '1');
		$distinct = [];
		foreach ([1 => 10000, 2 => 20000, 7 => 640000, 8 => 1000000, 20 => 1000000] as $attempt => $base) {
			for ($i = 0; $i < 60; $i++) {
				$wait = $delay->invoke($db, $attempt);
				$this->assertGreaterThanOrEqual(intdiv($base, 2), $wait);
				$this->assertLessThanOrEqual($base, $wait);
				$distinct[$attempt][$wait] = true;
			}
			$this->assertGreaterThan(1, count($distinct[$attempt]), 'not the same wait every time: two writers must not collide in lockstep');
		}
	}

	// ---------------------------------------------------------------- a START/COMMIT-like failure ends the transaction

	public function testAFailedStartIsOverTheTransactionTheServerEndedEvenForAnUnknownError()
	{
		$db = $this->dbDefaultBudget();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', 3100);

		$this->assertFalse($this->q($db, 'START TRANSACTION'));

		$this->assertSame([], FakeServer::$open, 'the fake ends the transaction when its implicit commit fails, like MySQL');
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
		$this->assertSame(3100, (int) $db->Errno);
		$this->assertStringContainsString('START failed with SQL error 3100 (it commits the open transaction first); the transaction is over', implode("\n", FakeServer::$log), 'told as a failed START, which commits first');
		$this->assertFalse($this->q($db, 'INSERT INTO b VALUES (1)'), 'refused: the caller takes it for a statement of the new transaction');
		$this->assertSame(0, FakeServer::sent('INSERT INTO b VALUES (1)'));
		$this->assertFalse($this->q($db, 'COMMIT'), 'and a COMMIT is refused, not reported as saving the first insert');
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertSame(0, self::committed('INSERT INTO a VALUES (1)'));
		$this->assertSame(0, self::committed('INSERT INTO b VALUES (1)'), 'nothing ran on its own');
		$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'a ROLLBACK made sure the transaction was over');
	}

	public function testAStartThatCannotBeReplayedAndWasRolledBackByTheServerIsGivenUpWithARollback()
	{
		$db = $this->dbDefaultBudget();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SET @a = 1'), 'a statement a replay cannot repeat');
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', 1213);

		$this->assertFalse($this->q($db, 'START TRANSACTION'));

		$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'every give-up on a usable link ends with a ROLLBACK (a no-op after the server\'s own)');
		$this->assertSame([], FakeServer::$sleeps, 'not replayed');
		$this->assertTrue($db->lost());
		$this->assertSame([], FakeServer::$open);
	}

	public static function startsTheServerNeverRan()
	{
		return [
			'1064, returned' => [1064, false],
			'1064, thrown' => [1064, true],
			'1149, returned' => [1149, false],
			'2014, returned' => [2014, false],
			'2014, thrown' => [2014, true],
		];
	}

	/**
	* A START/BEGIN inside a transaction that the server never ran (it did not parse, or it was never sent) did not
	* commit the open transaction: it fails on its own, the transaction stays open and is not lost, and the failed
	* START is not recorded (a replay must never send one mid-transaction).
	*
	* @dataProvider startsTheServerNeverRan
	* @param int $code
	* @param bool $strict
	*/
	#[DataProvider('startsTheServerNeverRan')]
	public function testAStartTheServerNeverRanLeavesTheTransactionOpen($code, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', $code);

		$this->assertFalse($this->q($db, 'START TRANSACTION'));

		$this->assertSame($code, (int) $db->Errno);
		$this->assertTrue($db->inTransaction(), 'still open');
		$this->assertFalse($db->lost());
		$this->assertSame(0, FakeServer::sent('ROLLBACK'));
		$this->assertCount(1, $db->steps(), 'only the INSERT is recorded');
		$this->assertCount(1, $db->reported, 'a failed statement, reported as one');
		FakeServer::failNext('/INTO b/', 1213);
		$this->assertTrue($this->q($db, 'INSERT INTO b VALUES (1)'), 'a later conflict is replayed');
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(['INSERT INTO a VALUES (1)', 'INSERT INTO b VALUES (1)'], FakeServer::$committed);
		$this->assertSame(1, FakeServer::sent('COMMIT'));
		$this->assertSame(1 + 1 + 1, FakeServer::sent('START TRANSACTION'), 'the first, the failed one and the replay\'s: none mid-replay');
	}

	public function testSessionStateSetInsideAnEarlierTransactionStillCountsForALaterReplay()
	{
		$db = $this->dbDefaultBudget();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SET @x = 1'));
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (@x)'));
		FakeServer::failNext('/^UPDATE u/', 2006);

		$this->assertFalse($this->q($db, 'UPDATE u SET y=1'));

		$this->assertSame(1, FakeServer::$connections, 'no new connection: @x would be NULL on it');
		$this->assertStringContainsString('session state', implode("\n", FakeServer::$log));
	}

	public function testAFailedStartIsOverEvenWhenItsReplayRestoredTheTransaction()
	{
		$db = $this->dbDefaultBudget();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^START TRANSACTION$/', 3101);
		FakeServer::failNext('/^START TRANSACTION$/', 3100);

		$this->assertFalse($this->q($db, 'START TRANSACTION'), 'replayed, then the START failed on its own');

		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
		$this->assertSame([], FakeServer::$open);
		$this->assertSame(0, self::committed('INSERT INTO a VALUES (1)'));
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(0, FakeServer::sent('COMMIT'));
	}

	// ---------------------------------------------------------------- reporting a failure must not change its outcome

	private function loggingDb()
	{
		return new class('fake_db', 'fake_user', '', '127.0.0.1', '', '1') extends ScriptedDb {
			public function emailError($queryString, $error, $line, $file)
			{
				parent::emailError($queryString, $error, $line, $file);
				// what the application's error logging does through its database handle, when that is this one
				$this->query("INSERT INTO log VALUES ('sql error reported')", __LINE__, __FILE__);
			}
		};
	}

	public function testReportingAFailureThroughTheSameHandleDoesNotTurnItIntoASuccess()
	{
		$db = $this->loggingDb();
		$db->haltOnError = 'report';
		$db->transactionReplays = 3;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^INSERT INTO b/', 1213, self::ALWAYS);

		$this->assertFalse($this->q($db, 'INSERT INTO b VALUES (1)'), 'the give-up tail');
		$this->assertSame(1213, (int) $db->Errno, 'the error is the statement\'s, not the log insert\'s');
		$this->assertFalse($this->q($db, 'COMMIT'), 'the refused COMMIT tail: the lost writes are not reported as saved');
		$this->assertSame(1213, (int) $db->Errno);
		$this->assertSame(0, FakeServer::sent('COMMIT'));
		$this->assertCount(2, $db->reported);
		$this->assertSame(0, self::committed('INSERT INTO a VALUES (1)'));
		$this->assertSame(2, self::committed("INSERT INTO log VALUES ('sql error reported')"));
	}

	public function testReportingAFailedStatementOutsideATransactionKeepsItsError()
	{
		$db = $this->loggingDb();
		$db->haltOnError = 'report';
		FakeServer::failNext('/^UPDATE t/', 1064, self::ALWAYS);

		$this->assertFalse($this->q($db, 'UPDATE t SET a=1'));

		$this->assertSame(1064, (int) $db->Errno, 'the statement\'s error, not the log insert\'s');
		$this->assertCount(1, $db->reported);
	}

	// ---------------------------------------------------------------- session state does not survive a reconnect

	public static function sessionStateStatements()
	{
		return [
			['SET @x = 1', true],
			["SET SESSION sql_mode = ''", true],
			['SET time_zone = \'+00:00\'', true],
			['USE otherdb', true],
			['CREATE TEMPORARY TABLE tmp_t (a int)', true],
			['DROP TEMPORARY TABLE tmp_t', true],
			["SELECT GET_LOCK('k', 1)", true],
			["SELECT RELEASE_LOCK('k')", true],
			['SELECT LAST_INSERT_ID(5)', true],
			['SELECT 1 INTO @v', true],
			['SELECT @a := 1', true],
			['SET TRANSACTION ISOLATION LEVEL READ COMMITTED', false],
			['SET SESSION TRANSACTION READ ONLY', false],
			['SELECT LAST_INSERT_ID()', false],
			['SELECT 1', false],
			['CREATE TABLE real_t (a int)', false],
		];
	}

	/**
	* @dataProvider sessionStateStatements
	* @param string $sql
	* @param bool $changes
	*/
	#[DataProvider('sessionStateStatements')]
	public function testWhichStatementsChangeTheSessionState($sql, $changes)
	{
		$db = $this->dbDefaultBudget();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$this->assertFalse($db->sessionDirtyFlag());

		$this->q($db, $sql);

		$this->assertSame($changes, $db->sessionDirtyFlag());
	}

	public function testSelectDbChangesTheSessionStateAndANewConnectionClearsIt()
	{
		$db = $this->dbDefaultBudget();
		$db->selectDb('otherdb');
		$this->assertTrue($db->sessionDirtyFlag());
		$db->disconnect();
		$this->assertTrue($this->q($db, 'SELECT 1'), 'a new connection');
		$this->assertFalse($db->sessionDirtyFlag());
	}

	public function testAClonesSessionStateChangeIsSeenOnTheSharedLink()
	{
		$db = $this->dbDefaultBudget();
		$this->assertTrue($this->q($db, 'SELECT 1'));
		$other = clone $db;
		$this->assertTrue($other->query('SET @a = 1', __LINE__, __FILE__));
		$this->assertTrue($db->sessionDirtyFlag());
	}

	public function testAReplayThatMustReconnectGivesUpAfterAUserVariableWasSet()
	{
		$db = $this->dbDefaultBudget();
		$this->assertTrue($this->q($db, "SET @x = 'five'"));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (@x)'));
		FakeServer::failNext('/^UPDATE u/', 2006);

		$this->assertFalse($this->q($db, 'UPDATE u SET y=1'));

		$this->assertSame(1, FakeServer::$connections, 'no new connection was opened: @x would have been NULL on it');
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertTrue($db->lost());
		$this->assertSame(["SET @x = 'five'"], FakeServer::$committed, 'only the SET: the transaction\'s INSERT is gone');
		$this->assertStringContainsString('Not recovered: it needs a new connection and the session state set on the old one', implode("\n", FakeServer::$log));
	}

	public function testAReplayThatMustReconnectGivesUpAfterSelectDb()
	{
		$db = $this->dbDefaultBudget();
		$db->selectDb('otherdb');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^UPDATE u/', 2013);

		$this->assertFalse($this->q($db, 'UPDATE u SET y=1'));

		$this->assertSame(1, FakeServer::$connections);
		$this->assertSame([], array_values(array_filter(FakeServer::$committed, static function ($w) { return strpos($w, 'INSERT') === 0; })));
		$this->assertStringContainsString('session state', implode("\n", FakeServer::$log));
	}

	public function testAReplayOnTheSameLinkIsUnaffectedByTheSessionState()
	{
		$db = $this->dbDefaultBudget('yes');
		$this->assertTrue($this->q($db, "SET @x = 'five'"));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (@x)'));
		FakeServer::failNext('/^UPDATE u/', 1213);

		$this->assertTrue($this->q($db, 'UPDATE u SET y=1'));

		$this->assertSame(1, FakeServer::$connections);
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(1, self::committed("INSERT INTO t VALUES (@x)"));
	}

	public function testAReplayThatReconnectsWorksWhenNoSessionStateWasSet()
	{
		$db = $this->dbDefaultBudget('yes');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^UPDATE u/', 2006);

		$this->assertTrue($this->q($db, 'UPDATE u SET y=1'));

		$this->assertSame(2, FakeServer::$connections);
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(1, self::committed('INSERT INTO t VALUES (1)'));
	}

	// ---------------------------------------------------------------- a reconnect that fails during a replay

	public static function connectFailureModes()
	{
		return [
			'real_connect() leaves a half-open handle, returned' => ['connectFailsReal', false],
			'real_connect() leaves a half-open handle, thrown' => ['connectFailsReal', true],
			'real_connect() returns false' => ['connectFails', false],
			'real_connect() throws' => ['connectFails', true],
		];
	}

	/**
	* A new connection a replay could not open counts as one attempt: the next attempt (after its wait) tries again,
	* within the budget, and the transaction is recovered once a connection opens.
	*
	* @dataProvider connectFailureModes
	* @param string $knob
	* @param bool $strict
	*/
	#[DataProvider('connectFailureModes')]
	public function testAReconnectThatFailsDuringAReplayIsTriedAgainOnTheNextAttempt($knob, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->dbDefaultBudget('yes');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^INSERT INTO b/', 2006);
		FakeServer::${$knob} = 1;

		$this->assertTrue($this->q($db, 'INSERT INTO b VALUES (1)'), 'recovered on the second attempt');

		$this->assertSame(3, FakeServer::$connections, 'the first link, the one that failed, the one that worked');
		$this->assertSame([10000, 20000], FakeServer::$sleeps, 'one wait per attempt');
		$this->assertSame(0, $db->connectionAttempt, 'a connection that opened resets the count');
		$this->assertSame([], $db->reported);
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(['INSERT INTO a VALUES (1)', 'INSERT INTO b VALUES (1)'], FakeServer::$committed);
	}

	/**
	* connect() exit()s the process once connectionAttempt reaches maxConnectErrors - 1. A replay never drives it
	* there: it stops reconnecting (and gives up) while one more failure could leave the next connect() to exit,
	* so the caller's own next statement still gets a real connect(). It returns false, not an \Error, and the
	* half-open handle is let go of.
	*
	* @dataProvider connectFailureModes
	* @param string $knob
	* @param bool $strict
	*/
	#[DataProvider('connectFailureModes')]
	public function testAReplayStopsReconnectingBeforeConnectCouldEndTheProcess($knob, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->dbDefaultBudget('no');
		$this->assertSame(5, $db->maxConnectErrors);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^INSERT INTO b/', 2006);
		FakeServer::${$knob} = self::ALWAYS;

		$result = $this->q($db, 'INSERT INTO b VALUES (1)');

		$this->assertFalse($result, 'false, not an \Error or an exit()');
		$this->assertSame(2, $db->connectionAttempt, 'two failed reconnects: a third failure would leave the next connect() to exit');
		$this->assertLessThan($db->maxConnectErrors - 2, $db->connectionAttempt);
		$this->assertSame(0, $db->linkId, 'the half-open handle was let go of');
		$this->assertSame([10000, 20000, 40000], FakeServer::$sleeps, 'the third attempt found no reconnect left to try');
		$this->assertCount(1, $db->reported);
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertTrue($db->lost());
		$this->assertStringContainsString('Not recovered: it needs a new connection and connect() has failed 2 time(s) in a row', implode("\n", FakeServer::$log));
		FakeServer::${$knob} = 0;
		$this->assertTrue($this->q($db, 'ROLLBACK'), 'the caller\'s next statement connects anew');
		$this->assertTrue($this->q($db, 'SELECT 2'));
		$this->assertSame(['SELECT 2'], FakeServer::$committed, 'nothing of the transaction');
	}

	public function testAReplayMakesNoReconnectWhenConnectHasNoFailureToSpare()
	{
		$db = $this->dbDefaultBudget('no');
		$db->maxConnectErrors = 3;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^INSERT INTO b/', 2006);

		$this->assertFalse($this->q($db, 'INSERT INTO b VALUES (1)'));

		$this->assertSame(1, FakeServer::$connections, 'no connect() at all: one failure would already leave the next one to exit');
		$this->assertSame(0, $db->connectionAttempt);
		$this->assertTrue($db->lost());
	}

	public function testAFailedReconnectWithReportConflictsOffStillReturnsFalse()
	{
		$db = $this->dbDefaultBudget('no');
		$db->reportConflicts = false;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO a VALUES (1)'));
		FakeServer::failNext('/^INSERT INTO b/', 1213);
		FakeServer::failNext('/^ROLLBACK$/', 2006);
		FakeServer::$connectFailsReal = self::ALWAYS;

		$this->assertFalse($this->q($db, 'INSERT INTO b VALUES (1)'));

		$this->assertSame(0, $db->linkId);
		$this->assertSame(1213, (int) $db->Errno);
		$this->assertSame([], $db->reported, 'a conflict goes to the caller quietly');
	}

	// ---------------------------------------------------------------- mutation follow-ups

	/**
	* @dataProvider reportModes
	* @param bool $strict
	*/
	#[DataProvider('reportModes')]
	public function testTransactionCommitDoesNotReplayAfterAnotherHandleRanAStatementOnTheLink($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->dbDefaultBudget('yes');
		$this->assertTrue($db->transactionBegin());
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		$other = clone $db;
		$this->assertTrue($other->query('UPDATE b SET y=1', __LINE__, __FILE__));
		FakeServer::failNext('/^COMMIT$/', 3101);

		try {
			$result = $db->transactionCommit();
			$this->assertFalse($strict);
			$this->assertFalse($result);
		} catch (\mysqli_sql_exception $e) {
			$this->assertTrue($strict);
		}

		$this->assertSame(1, FakeServer::sent('COMMIT'), 'not replayed: the other handle\'s statement could not be re-run');
		$this->assertSame([], FakeServer::$sleeps);
		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
	}

	public function testAStatementThatFailedWithOneErrorAndNowFailsWithAnotherEndsTheReplay()
	{
		$db = $this->dbDefaultBudget();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		FakeServer::failNext('/^INSERT INTO u/', 1062);
		$this->assertFalse($this->q($db, 'INSERT INTO u VALUES (1)'), 'fails on its own; the transaction stays open');
		$this->assertTrue($db->inTransaction());
		FakeServer::failNext('/^INSERT INTO u/', 1452);
		FakeServer::failNext('/^UPDATE z/', 1213);

		$this->assertFalse($this->q($db, 'UPDATE z SET q=1'));

		$this->assertStringContainsString('a statement that failed with #1062 now fails with #1452 (statement 1 of 1)', implode("\n", FakeServer::$log));
		$this->assertSame(1, FakeServer::sent('UPDATE z SET q=1'), 'never run again');
		$this->assertTrue($db->lost());
	}

	public function testAReplayThatRestoredTheTransactionLeavesItOpenWhenTheStatementFailsOnItsOwn()
	{
		$db = $this->dbDefaultBudget();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'UPDATE a SET x=1'));
		FakeServer::failNext('/^UPDATE z/', 1213);
		FakeServer::failNext('/^UPDATE z/', 1062);

		$this->assertFalse($this->q($db, 'UPDATE z SET q=1'), 'replayed, then it failed on its own (a duplicate key)');

		$this->assertTrue($db->inTransaction(), 'the transaction is back as it was: only the statement failed');
		$this->assertFalse($db->lost());
		$this->assertSame(1062, (int) $db->Errno);
		$this->assertTrue($this->q($db, 'UPDATE c SET w=1'));
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(1, self::committed('UPDATE a SET x=1'));
		$this->assertSame(1, self::committed('UPDATE c SET w=1'));
	}
}
