<?php

namespace MyDb\Tests\Mysqli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
* A statement MySQL runs WITHOUT committing the open transaction, sent by the transaction's own handle, must not end
* the tracked transaction: ended there, a 1213 on the next statement is retried on its own (autocommit) while the
* server rolled the earlier INSERT back, and the COMMIT reports success (a lost write and a false success). Words
* between which only whitespace or a plain comment sits are read; a comment marker inside a string literal further on
* changes nothing; an executable comment whose content runs everywhere is read as that content (round 8,
* leadingKeywordSql()). A form whose commit cannot be told (SET autocommit=1, an executable comment that is not read
* among the leading words) keeps the transaction going and loses it with 2006 on a later failure, and one whose
* statement is not known at all (an executable comment that is not read before the first keyword) gives it up (2006):
* never a false success, never a duplicate. Cases from the round-7 review (R7OwnEndGapTest) and round 8.
*
* @runTestsInSeparateProcesses
* @preserveGlobalState disabled
*/
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TransactionOwnEndTest extends TestCase
{
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
		FakeServer::reset();
	}

	private function db()
	{
		$db = new ScriptedDb('fake_db', 'fake_user', '', '127.0.0.1', '', '1');
		$db->haltOnError = 'no';
		$db->transactionReplays = 3;
		return $db;
	}

	private function q(ScriptedDb $db, $sql)
	{
		return $db->query($sql, __LINE__, __FILE__);
	}

	private static function writes()
	{
		return array_values(array_filter(FakeServer::$committed, static function ($sql) {
			return preg_match('/^(?:insert|update|delete|replace)\b/i', $sql) === 1;
		}));
	}

	public static function nonCommitting()
	{
		$cases = [];
		foreach ([
			'plain TEMPORARY (control)' => 'CREATE TEMPORARY TABLE tt (a int)',
			'DROP TEMPORARY (control)' => 'DROP TEMPORARY TABLE IF EXISTS tt',
			'comment before TEMPORARY' => 'CREATE /* scratch */ TEMPORARY TABLE tt (a int)',
			'DROP comment TEMPORARY' => 'DROP /* x */ TEMPORARY TABLE IF EXISTS tt',
			'DROP PREPARE' => 'DROP PREPARE s1',
			'RESET PERSIST' => 'RESET PERSIST',
			'literal /* after TEMPORARY (control)' => "CREATE TEMPORARY TABLE tt AS SELECT '/*' AS x",
			'literal # after TEMPORARY (control)' => "CREATE TEMPORARY TABLE tt AS SELECT 'a#b' AS x",
			'line comment before TEMPORARY' => "CREATE -- c\nTEMPORARY TABLE tt (a int)",
			'# comment before TEMPORARY' => "CREATE # c\nTEMPORARY TABLE tt (a int)",
			'literal /*! after TEMPORARY (control)' => "CREATE TEMPORARY TABLE tt AS SELECT '/*!' AS x",
			'comment, then a literal -- after TEMPORARY' => "CREATE /* c */ TEMPORARY TABLE tt AS SELECT '-- x' AS x",
			'DEALLOCATE PREPARE' => 'DEALLOCATE PREPARE s1',
			'DROP comment PREPARE' => 'DROP /* x */ PREPARE s1',
			'RESET PERSIST IF EXISTS' => 'RESET PERSIST IF EXISTS sort_buffer_size',
			'RESET comment PERSIST' => 'RESET /* x */ PERSIST',
			'SET autocommit=0 (control)' => 'SET autocommit=0',
			// round 8: read as the server runs them (round 7 gave the transaction up on them)
			'executable comment around TEMPORARY' => 'CREATE /*!32312 TEMPORARY */ TABLE tt (a int)',
			'executable comment before the first keyword' => '/*!32312 SHOW */ CREATE TABLE t',
			'executable SET NAMES (mysqldump)' => '/*!40101 SET NAMES utf8mb4 */',
			'SET GLOBAL autocommit=1' => 'SET GLOBAL autocommit=1',
			'SET PERSIST autocommit=1' => 'SET PERSIST autocommit=1',
			'SET GLOBAL x, then a plain autocommit (GLOBAL carries)' => 'SET GLOBAL sort_buffer_size=1, autocommit=1',
			'an executable marker inside a line comment' => "-- see /*!40101 x */\nSELECT 1",
		] as $name => $sql) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$name.', '.$mode] = [$sql, $strict];
			}
		}
		return $cases;
	}

	#[DataProvider('nonCommitting')]
	public function testANonCommittingStatementKeepsTheTransaction($sql, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$this->assertTrue($this->q($db, 'START TRANSACTION'));
			$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
			$this->assertNotFalse($this->q($db, $sql));
			$this->assertTrue($db->inTransaction(), 'MySQL keeps the transaction open');
			if ($attempt === 1) {
				FakeServer::failNext('/^UPDATE u/', 1213);
			}
			if ($this->q($db, 'UPDATE u SET x=1') && $db->transactionCommit()) {
				break;
			}
			$db->transactionAbort();
		}
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE u SET x=1'], self::writes(), 'nothing lost, nothing twice');
	}

	/** the same caller loop, asserting only the outcome: a COMMIT that reported success must have saved the INSERT */
	#[DataProvider('nonCommitting')]
	public function testACommitThatReportsSuccessSavedEveryWrite($sql, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$committedOk = false;
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$this->q($db, 'START TRANSACTION');
			$this->q($db, 'INSERT INTO t VALUES (1)');
			$this->q($db, $sql);
			if ($attempt === 1) {
				FakeServer::failNext('/^UPDATE u/', 1213);
			}
			if ($this->q($db, 'UPDATE u SET x=1') && $db->transactionCommit()) {
				$committedOk = true;
				break;
			}
			if (!in_array((int) $db->Errno, [1213, 3101], true)) {
				break;
			}
			$db->transactionAbort();
		}
		$this->assertTrue($committedOk, 'the caller saw a successful COMMIT');
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE u SET x=1'], self::writes(), 'a successful COMMIT saved the INSERT once');
	}

	public static function doubtful()
	{
		$cases = [];
		foreach ([
			// 'doubt': kept going, lost with 2006 on the 1213 (round 8)
			'MariaDB executable comment around TEMPORARY' => 'DROP /*M! TEMPORARY */ TABLE IF EXISTS tt',
			'later-version executable comment around TEMPORARY' => 'CREATE /*!99999 TEMPORARY */ TABLE tt (a int)',
			'SET autocommit=1' => 'SET autocommit=1',
			'SET GLOBAL x, then SESSION autocommit=1' => 'SET GLOBAL sort_buffer_size=1, SESSION autocommit=1',
			// 'unknown': given up at once (2006)
			'later-version executable comment before the statement' => '/*!99999 ALTER TABLE t DISABLE KEYS */',
			'MariaDB executable comment before the statement' => '/*M! ALTER TABLE t DISABLE KEYS */ SELECT 1',
		] as $name => $sql) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$name.', '.$mode] = [$sql, $strict];
			}
		}
		return $cases;
	}

	/**
	* the same caller loop: after a statement whose implicit commit cannot be told the 1213 loses the transaction with
	* 2006 (not retried), and after one whose statement is not known the transaction is given up at once, so the caller
	* never sees a successful COMMIT and nothing is written twice
	*/
	#[DataProvider('doubtful')]
	public function testAStatementWhoseCommitCannotBeToldNeverReportsSuccess($sql, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$committedOk = false;
		for ($attempt = 1; $attempt <= 3; $attempt++) {
			$this->q($db, 'START TRANSACTION');
			$this->q($db, 'INSERT INTO t VALUES (1)');
			$this->q($db, $sql);
			if ($attempt === 1) {
				FakeServer::failNext('/^UPDATE u/', 1213);
			}
			if ($this->q($db, 'UPDATE u SET x=1') && $db->transactionCommit()) {
				$committedOk = true;
				break;
			}
			if (!in_array((int) $db->Errno, [1213, 3101], true)) {
				break;
			}
			$db->transactionAbort();
		}
		$this->assertFalse($committedOk, 'no COMMIT reported success');
		$this->assertSame(2006, (int) $db->Errno, 'given up as lost: outcome unknown');
		$this->assertSame([], self::writes(), 'the 1213 or the quiet ROLLBACK discarded the INSERT, and the UPDATE never landed');
		$this->assertFalse($db->inTransaction());
	}

	public static function keptGoing()
	{
		$cases = [];
		foreach ([
			'MariaDB executable comment around TEMPORARY' => 'DROP /*M! TEMPORARY */ TABLE IF EXISTS tt',
			'SET autocommit=1' => 'SET autocommit=1',
			'SET GLOBAL x, then SESSION autocommit=1' => 'SET GLOBAL sort_buffer_size=1, SESSION autocommit=1',
		] as $name => $sql) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				$cases[$name.', '.$mode] = [$sql, $strict];
			}
		}
		return $cases;
	}

	/** the same caller loop without the 1213: a statement whose implicit commit cannot be told does not stop the COMMIT */
	#[DataProvider('keptGoing')]
	public function testAStatementWhoseCommitCannotBeToldLetsTheCommitSaveEveryWrite($sql, $strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotFalse($this->q($db, $sql));
		$this->assertTrue($db->inTransaction(), 'it goes on');
		$this->assertTrue($this->q($db, 'UPDATE u SET x=1'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE u SET x=1'], self::writes(), 'saved once each');
		$this->assertSame(0, FakeServer::sent('ROLLBACK'));
	}
}
