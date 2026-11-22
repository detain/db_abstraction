<?php

namespace MyDb\Tests\Mysqli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
* Round 8 (the reviewer's s1-s6b, classify, forms_r8, gap_edges): how the driver reads the leading words of a statement
* (leadingKeywordSql(): a plain comment is a space, an executable comment whose content runs on every server is
* unwrapped, one that is not read stays in the lead), what it makes of a statement that may commit the open transaction
* implicitly (endsTransaction(), implicitCommitOutcome()), what a transaction whose end is not known does ('doubt': it
* goes on, and a later failure loses it with 2006, never 1213), and what one whose statement is not known at all does
* ('unknown': given up, its quiet ROLLBACK sent on its own link whatever a stale flag of another holder says).
*
* @runTestsInSeparateProcesses
* @preserveGlobalState disabled
*/
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class TransactionUnknownEndTest extends TestCase
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

	/** a handle whose halt() counts the calls and returns (haltOnError 'yes' would end the process) */
	private function db($haltOnError = 'no')
	{
		$db = new class('fake_db', 'fake_user', '', '127.0.0.1', '', '1') extends ScriptedDb {
			public $haltCalls = 0;

			public function halt($msg, $line = '', $file = '')
			{
				$this->haltCalls++;
				return true;
			}
		};
		$db->haltOnError = $haltOnError;
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

	private static function committed($sql)
	{
		return count(array_keys(FakeServer::$committed, $sql, true));
	}

	/** MySQL's implicit commit: the open writes become durable and the connection is back in autocommit */
	private static function implicitCommit($connection = 1)
	{
		foreach (FakeServer::$open[$connection] ?? [] as $write) {
			FakeServer::$committed[] = $write;
		}
		unset(FakeServer::$open[$connection]);
	}

	/** [the result, the exception] of $call */
	private static function attempt(callable $call)
	{
		try {
			return [$call(), null];
		} catch (\mysqli_sql_exception $e) {
			return [false, $e];
		}
	}

	public static function leads()
	{
		return [
			'no comment' => ['COMMIT', 'COMMIT'],
			'whitespace and ( before the first word' => ["  (SELECT 1)", 'SELECT 1)'],
			'a leading block comment' => ['/* c */ COMMIT', 'COMMIT'],
			'a leading # comment' => ["# c\nCOMMIT", 'COMMIT'],
			// F2a: an executable comment marker inside a plain comment is no executable comment
			'an executable marker inside a line comment' => ["-- see /*!40101 x */\nSELECT 1", 'SELECT 1'],
			'an executable marker inside a # comment' => ["# /*!40101 x\nSELECT 1", 'SELECT 1'],
			'an executable marker inside a block comment' => ['/* see /*!40101 x */ UPDATE t SET v=1', 'UPDATE t SET v=1'],
			// F5: a plain comment among the leading words is a space
			'a block comment between the words' => ['START /* c */ TRANSACTION', 'START   TRANSACTION'],
			'a line comment between the words' => ["START -- c\nTRANSACTION", 'START  TRANSACTION'],
			'a comment with no space around it' => ['SELECT/*c*/1', 'SELECT 1'],
			// an executable comment whose content runs everywhere is unwrapped (F2d, F3, F4)
			'an executable SET NAMES (mysqldump)' => ['/*!40101 SET NAMES utf8mb4 */', 'SET NAMES utf8mb4  '],
			'an executable comment with no version' => ['/*! SET NAMES utf8mb4 */', 'SET NAMES utf8mb4  '],
			'a statement held by one' => ['/*!50000 CREATE TABLE x AS */ SELECT 1', 'CREATE TABLE x AS   SELECT 1'],
			'a word held by one' => ['LOCK /*!50000 TABLES */ t WRITE', 'LOCK   TABLES   t WRITE'],
			'TEMPORARY held by one' => ['CREATE /*!32312 TEMPORARY */ TABLE t (a int)', 'CREATE   TEMPORARY   TABLE t (a int)'],
			'TO SAVEPOINT held by one' => ['ROLLBACK /*!50000 TO SAVEPOINT s1*/', 'ROLLBACK   TO SAVEPOINT s1 '],
			'a plain comment inside one' => ['/*!40101 SET /* c */ NAMES utf8mb4 */', 'SET   NAMES utf8mb4  '],
			'one stopped inside (the rest is left as it is)' => ['/*!40101 SET autocommit=0 */', 'SET autocommit=0 */'],
			// one that is not read is left where it is, with everything after it
			'a later version' => ['/*!99999 ALTER TABLE t */', '/*!99999 ALTER TABLE t */'],
			'a later version among the words' => ['LOCK /*!99999 TABLES */ t WRITE', 'LOCK /*!99999 TABLES */ t WRITE'],
			'a MariaDB one' => ['/*M! ALTER TABLE t */ SELECT 1', '/*M! ALTER TABLE t */ SELECT 1'],
			'a MariaDB one among the words' => ['CREATE /*M! TEMPORARY */ TABLE t (a int)', 'CREATE /*M! TEMPORARY */ TABLE t (a int)'],
			'a six-digit (MariaDB) version' => ['/*!100100 COMMIT */', '/*!100100 COMMIT */'],
			'one inside another' => ['/*!40101 COMMIT /*!40101 x */ */', '/*!40101 COMMIT /*!40101 x */ */'],
			'a # comment inside one' => ["/*!40101 COMMIT # x */\n", "/*!40101 COMMIT # x */\n"],
			'a # comment inside one, closed on the next line' => ["/*!40101 SET # x\n NAMES utf8mb4 */", "/*!40101 SET # x\n NAMES utf8mb4 */"],
			'a -- comment inside one, among the words' => ["LOCK /*!50000 -- x\nTABLES */ t WRITE", "LOCK /*!50000 -- x\nTABLES */ t WRITE"],
			'one never closed' => ['/*!40101 SET NAMES x', '/*!40101 SET NAMES x'],
			'one never closed after the first word' => ['SET /*!40101 NAMES x', 'SET /*!40101 NAMES x'],
			// what is not a comment stays, and nothing past the leading words is read
			'dashes without a space' => ["--x\nCOMMIT", "--x\nCOMMIT"],
			'dashes and a digit' => ['--1', '--1'],
			'a literal holding a comment marker' => ["SELECT '/* x */' AS a", "SELECT '/* x */' AS a"],
			'a comment past the leading words' => ['UPDATE t SET v = 1 /* c */ WHERE a = 2', 'UPDATE t SET v = 1 /* c */ WHERE a = 2'],
			// nothing but comments
			'dashes only' => ['--', ''],
			'an unterminated block comment' => ['/* unterminated', ''],
			'a # comment without a newline' => ['# c', ''],
		];
	}

	/**
	* @dataProvider leads
	* @param string $sql
	* @param string $lead
	*/
	#[DataProvider('leads')]
	public function testTheLeadingWordsAreReadAsTheServerReadsThem($sql, $lead)
	{
		$this->assertSame($lead, LeadProbe::lead($sql));
	}

	/** the walk stays linear: a long run of comments among the leading words, or of words, costs what its length does */
	public function testTheLeadingWordsAreReadInLinearTime()
	{
		$comments = 'START'.str_repeat(' /* c */', 200000).' TRANSACTION';
		$words = str_repeat('a ', 400000).'(';
		$start = microtime(true);
		$this->assertSame('START'.str_repeat('  ', 200000).' TRANSACTION', LeadProbe::lead($comments));
		$this->assertSame($words, LeadProbe::lead($words));
		$this->assertLessThan(5.0, microtime(true) - $start);
	}

	public static function classified()
	{
		// [statement, endsTransaction(), implicitCommitOutcome() (null: not asked: endsTransaction() does not count it,
		// or it is a START/COMMIT/ROLLBACK), changesSessionState()]
		return [
			// F2a
			'an executable marker inside a line comment' => ["-- see /*!40101 x */\nSELECT 1", false, null, false],
			'an executable marker inside a block comment' => ['/* see /*!40101 x */ UPDATE t SET v=1', false, null, false],
			// F2b: CREATE TABLE ... START TRANSACTION needs the two words next to each other
			'CREATE TABLE with columns named start and transaction' => ['CREATE TABLE t2 (start int, transaction int)', true, 'end', false],
			'CREATE TABLE ... START TRANSACTION' => ['CREATE TABLE t (a int) START TRANSACTION', true, 'doubt', false],
			'CREATE TABLE ... START /* c */ TRANSACTION' => ['CREATE TABLE t (a int) START /* c */ TRANSACTION', true, 'doubt', false],
			'CREATE TABLE ... start, a comment, transaction apart' => ["CREATE TABLE t (a int) COMMENT='start' ENGINE=InnoDB /* transaction */", true, 'end', false],
			'CREATE TABLE ... an executable comment' => ['CREATE TABLE t (a int) /*!50100 START TRANSACTION */', true, 'doubt', false],
			'CREATE TABLE ... START, then TRANSACTION in an executable comment' => ['CREATE TABLE t (a int) START /*!50100 TRANSACTION */', true, 'doubt', false],
			'CREATE TABLE ... /*!50100 PARTITION BY ... */ (mysqldump)' => ['CREATE TABLE t (a INT) /*!50100 PARTITION BY HASH (a) */', true, 'end', false],
			'CREATE TABLE ... /*! ENGINE=InnoDB */' => ['CREATE TABLE t (a INT) /*! ENGINE=InnoDB */', true, 'end', false],
			'CREATE TABLE ... /*!50100 ... a/2 */ (a slash is no comment)' => ['CREATE TABLE t (a INT) /*!50100 PARTITION BY HASH (a/2) */', true, 'end', false],
			'CREATE TABLE ... /*!99999 ... */' => ['CREATE TABLE t (a INT) /*!99999 PARTITION BY HASH (a) */', true, 'doubt', false],
			'CREATE TABLE ... /*!100100 ... */ (six digits)' => ['CREATE TABLE t (a INT) /*!100100 PARTITION BY HASH (a) */', true, 'doubt', false],
			'CREATE TABLE ... /*M! ... */' => ['CREATE TABLE t (a INT) /*M! PAGE_COMPRESSED=1 */', true, 'doubt', false],
			'CREATE TABLE ... /*!50100 never closed' => ['CREATE TABLE t (a INT) /*!50100 PARTITION BY HASH (a)', true, 'doubt', false],
			'CREATE TABLE ... /*!50100 ... /* c */ ... */ (nested)' => ['CREATE TABLE t (a INT) /*!50100 /* c */ START TRANSACTION */', true, 'doubt', false],
			// (the server ends the executable comment at the second */: START TRANSACTION; read pairing the marker with the
			// first, the words are START, a comment and TRANSACTION */)
			'CREATE TABLE ... START/*!50100 /* c */ TRANSACTION */ (nested, hiding the pair)' => ['CREATE TABLE t (a INT) START/*!50100 /* c */ TRANSACTION */', true, 'doubt', false],
			'CREATE TABLE ... /*!50100 ... # ... */' => ["CREATE TABLE t (a INT) /*!50100 # c\n START TRANSACTION */", true, 'doubt', false],
			// (a # comment inside one runs to the end of the line, past its */, as on the server)
			'CREATE TABLE ... START/*!50100 # c */ then TRANSACTION on the next line' => ["CREATE TABLE t (a INT) START/*!50100 # c */\nTRANSACTION */", true, 'doubt', false],
			// (conservative: the pair counts wherever it is, a comment or a string literal included)
			'CREATE TABLE ... the pair inside a # comment' => ["CREATE TABLE t (a INT) /*!50100 PARTITION BY HASH (a) # START TRANSACTION\n */", true, 'doubt', false],
			"CREATE TABLE ... COMMENT 'start transaction'" => ["CREATE TABLE t (a INT) COMMENT 'start transaction'", true, 'doubt', false],
			'CREATE TABLE ... START /* /*!50000 x */ TRANSACTION (a marker in a plain comment)' => ['CREATE TABLE t (a INT) START /* /*!50000 x */ TRANSACTION', true, 'doubt', false],
			// F2c: a scope keyword is in force for the plain names after it
			'SET GLOBAL autocommit=1' => ['SET GLOBAL autocommit=1', true, 'none', true],
			'SET PERSIST autocommit=1' => ['SET PERSIST autocommit=1', true, 'none', true],
			'SET PERSIST_ONLY autocommit=1' => ['SET PERSIST_ONLY autocommit=1', true, 'none', true],
			'SET @@GLOBAL.autocommit=1' => ['SET @@GLOBAL.autocommit=1', true, 'none', true],
			'SET @@persist.autocommit=1' => ['SET @@persist.autocommit=1', true, 'none', true],
			'SET GLOBAL x=1, autocommit=1' => ['SET GLOBAL x=1, autocommit=1', true, 'none', true],
			'SET GLOBAL x=1, SESSION autocommit=1' => ['SET GLOBAL x=1, SESSION autocommit=1', true, 'doubt', true],
			'SET autocommit=1, GLOBAL x=1' => ['SET autocommit=1, GLOBAL x=1', true, 'doubt', true],
			'SET autocommit=0, GLOBAL autocommit=1' => ['SET autocommit=0, GLOBAL autocommit=1', true, 'none', true],
			'SET GLOBAL autocommit=0, autocommit=1' => ['SET GLOBAL autocommit=0, autocommit=1', true, 'none', true],
			'SET GLOBAL autocommit=1, @@autocommit=1' => ['SET GLOBAL autocommit=1, @@autocommit=1', true, 'doubt', true],
			'SET GLOBAL autocommit=1, @@session.autocommit=0' => ['SET GLOBAL autocommit=1, @@session.autocommit=0', true, 'none', true],
			'SET autocommit=0, @@GLOBAL.autocommit=1' => ['SET autocommit=0, @@GLOBAL.autocommit=1', true, 'none', true],
			"SET @a='autocommit=1', autocommit=0 (a count the scopes do not match: taken for the session's)" => ["SET @a='autocommit=1', autocommit=0", true, 'doubt', true],
			'SET LOCAL autocommit=1' => ['SET LOCAL autocommit=1', true, 'doubt', true],
			"SET GLOBAL sql_mode='a,b', autocommit=1" => ["SET GLOBAL sql_mode='a,b', autocommit=1", true, 'none', true],
			"SET sql_mode='a,b', autocommit=1" => ["SET sql_mode='a,b', autocommit=1", true, 'doubt', true],
			'SET GLOBAL `autocommit`=1' => ['SET GLOBAL `autocommit`=1', true, 'none', true],
			"SET autocommit = 'OFF'" => ["SET autocommit = 'OFF'", true, 'none', true],
			"SET autocommit = 'OFF (an unclosed quote)" => ["SET autocommit = 'OFF", true, 'doubt', true],
			'SET autocommit=(0)' => ['SET autocommit=(0)', true, 'doubt', true],
			'SET GLOBAL autocommit="1" (a double quote: not read with scopes)' => ['SET GLOBAL autocommit="1"', true, 'doubt', true],
			// (@@x.autocommit is no variable MySQL has: the statement fails; it is not read with scopes, and the plain
			// look below does not take it for the session's either)
			'SET autocommit=0, then a target the scopes do not read' => ['SET autocommit=0, @@x.autocommit=1', true, 'none', true],
			'SET autocommit=0, then autocommit as a value' => ['SET autocommit=0, @a=autocommit', true, 'none', true],
			// F2d
			'an executable SET NAMES (mysqldump)' => ['/*!40101 SET NAMES utf8mb4 */', false, null, true],
			'an executable SET autocommit=0, stopped inside it' => ['/*!40101 SET autocommit=0 */', true, 'doubt', true],
			'SET autocommit=1' => ['SET autocommit=1', true, 'doubt', true],
			// F3
			'CREATE TABLE ... AS held by an executable comment' => ['/*!50000 CREATE TABLE x AS */ SELECT 1', true, 'end', false],
			// F4
			'LOCK /*!50000 TABLES */' => ['LOCK /*!50000 TABLES */ t WRITE', true, 'end', false],
			'LOCK /*!99999 TABLES */' => ['LOCK /*!99999 TABLES */ t WRITE', true, 'doubt', false],
			'CHECK /*!50000 TABLE */' => ['CHECK /*!50000 TABLE */ t', true, 'end', false],
			"SET /*!50000 PASSWORD */ = 'x'" => ["SET /*!50000 PASSWORD */ = 'x'", true, 'end', true],
			'CACHE /*!50000 INDEX */' => ['CACHE /*!50000 INDEX */ t IN k', true, 'end', false],
			'START /*!99999 REPLICA */' => ['START /*!99999 REPLICA */', true, 'doubt', false],
			'SET /*!99999 autocommit=1 */' => ['SET /*!99999 autocommit=1 */', true, 'doubt', true],
			// read as what MySQL runs
			'CREATE /*!32312 TEMPORARY */ TABLE' => ['CREATE /*!32312 TEMPORARY */ TABLE t (a int)', false, null, true],
			'DROP /*!50000 TEMPORARY */ TABLE' => ['DROP /*!50000 TEMPORARY */ TABLE t', false, null, true],
			'/*!32312 SHOW */ CREATE TABLE' => ['/*!32312 SHOW */ CREATE TABLE t', false, null, false],
			'/*!40000 ALTER TABLE ... */' => ['/*!40000 ALTER TABLE t DISABLE KEYS */', true, 'end', false],
			'ROLLBACK /*!50000 TO SAVEPOINT */' => ['ROLLBACK /*!50000 TO SAVEPOINT s1*/', false, null, false],
			// not read
			'CREATE /*M! TEMPORARY */ TABLE' => ['CREATE /*M! TEMPORARY */ TABLE t (a int)', true, 'doubt', true],
			'/*!99999 ALTER TABLE ... */' => ['/*!99999 ALTER TABLE t */', true, 'unknown', true],
			'/*M! ALTER TABLE ... */ SELECT 1' => ['/*M! ALTER TABLE t */ SELECT 1', true, 'unknown', true],
			'/*!100100 COMMIT */' => ['/*!100100 COMMIT */', true, 'unknown', true],
			'ROLLBACK /*!99999 TO SAVEPOINT */' => ['ROLLBACK /*!99999 TO SAVEPOINT s1*/', true, null, false],
		];
	}

	/**
	* The table of the report: what each form counts as, and what it did once it worked.
	*
	* @dataProvider classified
	* @param string $sql
	* @param bool $ends
	* @param string|null $outcome
	* @param bool $dirty
	*/
	#[DataProvider('classified')]
	public function testWhatAStatementDoesToTheTransactionIsToldFromWhatTheServerRuns($sql, $ends, $outcome, $dirty)
	{
		$this->assertSame($ends, LeadProbe::ends($sql), 'endsTransaction()');
		if ($outcome !== null) {
			$this->assertSame($outcome, LeadProbe::outcome($sql), 'implicitCommitOutcome()');
		}
		$this->assertSame($dirty, LeadProbe::dirty($sql), 'changesSessionState()');
	}

	/** F3: a statement held by an executable comment is not taken for the SELECT after it */
	public function testAStatementHeldByAnExecutableCommentIsNotReplayedAsTheSelectAfterIt()
	{
		$this->assertFalse(LeadProbe::replayable('/*!50000 CREATE TABLE x AS */ SELECT 1 AS a'));
		$this->assertTrue(LeadProbe::replayable('/*!50000 SELECT */ 1 AS a'), 'one that holds a SELECT is one');

		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		// the server's implicit commit at the start of the statement: the INSERT is durable now
		self::implicitCommit();
		FakeServer::failNext('~CREATE TABLE x AS~', 1213);

		$this->assertFalse($this->q($db, '/*!50000 CREATE TABLE x AS */ SELECT 1 AS a'));

		$this->assertSame(2006, (int) $db->Errno, 'lost: outcome unknown, not "rolled back, retry"');
		$this->assertSame(1, FakeServer::sent("INSERT INTO t (v) VALUES ('a')"), 'never replayed after its implicit commit');
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')"], self::writes(), 'once');
	}

	public static function commentedStarts()
	{
		return [
			'START /* c */ TRANSACTION' => ['START /* c */ TRANSACTION'],
			'START -- c, TRANSACTION' => ["START -- c\nTRANSACTION"],
			'BEGIN /* c */ WORK' => ['BEGIN /* c */ WORK'],
			'/*!40101 START TRANSACTION */' => ['/*!40101 START TRANSACTION */'],
			'START /*!50000 TRANSACTION */' => ['START /*!50000 TRANSACTION */'],
		];
	}

	/**
	* F5 (the reviewer's s2): a START with a comment among its words, or held by an executable comment whose content runs
	* everywhere, is a START: tracked, so a 1213 inside it is the rollback of the whole transaction and is replayed as
	* one. Before, it was not tracked: the 1213 was retried on its own (autocommit) and the COMMIT reported success with
	* the first INSERT rolled back.
	*
	* @dataProvider commentedStarts
	* @param string $start
	*/
	#[DataProvider('commentedStarts')]
	public function testAStartWithACommentAmongItsWordsIsTracked($start)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, $start));
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		FakeServer::failNext("/VALUES \\('b'\\)/", 1213);
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('b')"), 'replayed');
		$this->assertTrue($this->q($db, 'COMMIT'));

		$this->assertSame(["INSERT INTO t (v) VALUES ('a')", "INSERT INTO t (v) VALUES ('b')"], self::writes(), 'once each');
		$this->assertSame(2, FakeServer::sent($start), 'the START was replayed with the transaction');
	}

	public static function endsNotRead()
	{
		return [
			'ROLLBACK /*!99999 TO SAVEPOINT */ (a ROLLBACK on this server)' => ['ROLLBACK /*!99999 TO SAVEPOINT s1*/'],
			'ROLLBACK /*!99999 WORK */' => ['ROLLBACK /*!99999 WORK */'],
			'COMMIT /*!99999 AND CHAIN */ (a COMMIT that chains nothing here)' => ['COMMIT /*!99999 AND CHAIN */'],
			'COMMIT /*M! AND CHAIN */' => ['COMMIT /*M! AND CHAIN */'],
		];
	}

	/**
	* COMMIT or ROLLBACK with an executable comment among its words that is not read: the server may have committed,
	* rolled back, chained or rolled back to a savepoint, so the transaction is given up (lost, 2006). Before, the
	* tracker kept it open (ROLLBACK /*!99999 TO SAVEPOINT s1 *\/ is a ROLLBACK of the whole transaction on MySQL 8.0)
	* and the COMMIT reported the rolled-back INSERT as saved.
	*
	* @dataProvider endsNotRead
	* @param string $sql
	*/
	#[DataProvider('endsNotRead')]
	public function testACommitOrRollbackWhoseWordsAreNotReadGivesTheTransactionUp($sql)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SAVEPOINT s1'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($db, $sql));

		$this->assertFalse($db->inTransaction());
		$this->assertTrue($db->lost());
		$this->assertFalse($db->transactionCommit(), 'never reported as saved');
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertSame([], FakeServer::$open, 'nothing of it is left open');
	}

	public static function endsRead()
	{
		return [
			'/*!40101 ROLLBACK */' => ['/*!40101 ROLLBACK */', []],
			'/*!40101 COMMIT */' => ['/*!40101 COMMIT */', ['INSERT INTO t VALUES (1)']],
			'ROLLBACK /*!50000 WORK */' => ['ROLLBACK /*!50000 WORK */', []],
		];
	}

	/**
	* @dataProvider endsRead
	* @param string $sql
	* @param string[] $writes
	*/
	#[DataProvider('endsRead')]
	public function testACommitOrRollbackHeldByAnExecutableCommentEndsTheTransaction($sql, $writes)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($db, $sql));

		$this->assertFalse($db->inTransaction());
		$this->assertFalse($db->lost());
		$this->assertSame($writes, self::writes());
		$this->assertSame([], FakeServer::$open);
	}

	/**
	* F1 (the reviewer's s4): the session-state START stays on the shared link (startApart()) and commits the other
	* holder's transaction there, so that holder's flag is stale. A statement whose content is not known gives this
	* handle's transaction up: its quiet ROLLBACK goes out on its own link whatever the stale flag says. Skipped, the
	* transaction stayed open on the server while the caller's cleanup ROLLBACK was answered without being sent, and the
	* next write the caller took for an autocommit joined it and was rolled back later (a lost write).
	*/
	public function testAGiveUpRollsBackOnItsOwnLinkWhateverAStaleFlagSays()
	{
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$this->assertTrue($this->q($b, 'START TRANSACTION'));
		$this->assertTrue($this->q($b, "INSERT INTO t (v) VALUES ('b1')"));
		$this->assertTrue($this->q($a, 'SET @x = 1'));
		$this->assertTrue($this->q($a, 'START TRANSACTION'), 'stays on the shared link: its session state');
		$this->assertTrue($b->inTransaction(), 'the stale flag');
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));

		$this->assertNotFalse($this->q($a, '/*!99999 SELECT 1 */'));

		$this->assertTrue($a->lost());
		$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'its quiet ROLLBACK went out');
		$this->assertSame([], FakeServer::$open, 'nothing of it is left open');
		$this->assertTrue($this->q($a, 'ROLLBACK'), 'the cleanup');
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a2')"));
		$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('a2')"), 'an autocommit write is committed');
		$this->assertSame(0, self::committed("INSERT INTO t (v) VALUES ('a1')"));
	}

	/** F1, the same in lockFailed(): a LOCK TABLES that failed after its implicit commit may have run */
	public function testAFailedLockRollsBackOnItsOwnLinkWhateverAStaleFlagSays()
	{
		FakeServer::$strict = false;
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$this->assertTrue($this->q($b, 'START TRANSACTION'));
		$this->assertTrue($this->q($b, "INSERT INTO t (v) VALUES ('b1')"));
		$this->assertTrue($this->q($a, 'SET @x = 1'));
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($b->inTransaction(), 'the stale flag');
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));
		FakeServer::failNext('/^lock tables/', 1146);

		$this->assertSame(0, $a->lock('t'));

		$this->assertSame(2006, (int) $a->Errno);
		$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'its quiet ROLLBACK went out');
		$this->assertSame([], FakeServer::$open, 'nothing of it is left open');
		$this->assertTrue($this->q($a, 'ROLLBACK'), 'the cleanup');
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a2')"));
		$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('a2')"), 'an autocommit write is committed');
	}

	public static function failuresAfterADoubt()
	{
		$cases = [];
		foreach ([
			'a statement, 1213' => ['statement', 1213],
			'a statement, 3101' => ['statement', 3101],
			"query('COMMIT'), 1213" => ['query COMMIT', 1213],
			'transactionCommit(), 1213' => ['transactionCommit', 1213],
			'transactionCommit(), 3101' => ['transactionCommit', 3101],
			"query('START TRANSACTION'), 1213" => ['query START', 1213],
			'transactionBegin(), 1213' => ['transactionBegin', 1213],
			'lock(), 3101' => ['lock', 3101],
			'lock(), 1180' => ['lock', 1180],
			'execute(), 1213' => ['execute', 1213],
			'execute() of a DDL, 3101 at its commit' => ['execute ddl', 3101],
			'a DDL, 3101 at its commit' => ['ddl', 3101],
			'a failed ROLLBACK' => ['rollback', 1105],
		] as $name => $case) {
			foreach (['thrown' => true, 'returned' => false] as $mode => $strict) {
				foreach (['the server kept it open' => false, 'the server committed it' => true] as $world => $committed) {
					$cases[$name.', '.$mode.', '.$world] = [$case[0], $case[1], $strict, $committed];
				}
			}
		}
		return $cases;
	}

	/**
	* F2d: once a statement that may have committed the transaction implicitly ran in it (SET autocommit=1), anything
	* that fails and would end it loses it with 2006 (outcome unknown, not to be retried), whatever the server said: a
	* 1213 or 3101 ("rolled back, retry") would make a caller retrying apply the writes before that statement twice had
	* the server committed them, and 3101/1180 at an implicit commit or COMMIT roll back only what was still open. Never
	* replayed; under MYSQLI_REPORT_STRICT the exception is a 2006 with the server's as its previous.
	*
	* @dataProvider failuresAfterADoubt
	* @param string $how
	* @param int $errno
	* @param bool $strict
	* @param bool $committed
	*/
	#[DataProvider('failuresAfterADoubt')]
	public function testAFailureAfterADoubtLosesTheTransactionWith2006($how, $errno, $strict, $committed)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		if ($committed) {
			self::implicitCommit();
		}
		$this->assertTrue($this->q($db, 'SET autocommit=1'));
		$this->assertTrue($db->inTransaction());
		if ($how === 'execute') {
			$this->assertNotSame(0, $db->prepare('UPDATE u SET x=1'));
		} elseif ($how === 'execute ddl') {
			$this->assertNotSame(0, $db->prepare('ALTER TABLE t ADD COLUMN c INT'));
		}
		$patterns = ['execute ddl' => '/^ALTER TABLE/', 'statement' => '/^UPDATE u/', 'query COMMIT' => '/^COMMIT/', 'transactionCommit' => '/^COMMIT/', 'query START' => '/^START TRANSACTION/', 'transactionBegin' => '/^START TRANSACTION/', 'lock' => '/^lock tables/', 'execute' => '/^UPDATE u/', 'ddl' => '/^ALTER TABLE/', 'rollback' => '/^ROLLBACK$/'];
		FakeServer::failNext($patterns[$how], $errno, 1000);
		$calls = [
			'statement' => function () use ($db) { return $this->q($db, 'UPDATE u SET x=1'); },
			'query COMMIT' => function () use ($db) { return $this->q($db, 'COMMIT'); },
			'transactionCommit' => function () use ($db) { return $db->transactionCommit(); },
			'query START' => function () use ($db) { return $this->q($db, 'START TRANSACTION'); },
			'transactionBegin' => function () use ($db) { return $db->transactionBegin(); },
			'lock' => function () use ($db) { return $db->lock('t'); },
			'execute' => function () use ($db) { return $db->execute(); },
			'execute ddl' => function () use ($db) { return $db->execute(); },
			'ddl' => function () use ($db) { return $this->q($db, 'ALTER TABLE t ADD COLUMN c INT'); },
			'rollback' => function () use ($db) { return $this->q($db, 'ROLLBACK'); },
		];

		list($result, $exception) = self::attempt($calls[$how]);

		$this->assertEmpty($result, 'it failed');
		// (a failed ROLLBACK keeps its own error: the caller ended the transaction, which is recorded as lost with 2006)
		$this->assertSame($how === 'rollback' ? $errno : 2006, (int) $db->Errno, 'lost with 2006, not the server\'s '.$errno);
		if ($exception !== null && in_array($how, ['transactionCommit', 'transactionBegin', 'lock', 'execute', 'execute ddl'], true)) {
			$this->assertSame(2006, (int) $exception->getCode());
			$this->assertNotNull($exception->getPrevious());
			$this->assertSame($errno, (int) $exception->getPrevious()->getCode(), 'the server\'s error is its previous');
		}
		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertSame(1, FakeServer::sent('INSERT INTO t VALUES (1)'), 'never replayed');
		$this->assertSame([], FakeServer::$sleeps, 'no replay waits');
		list($commit) = self::attempt(function () use ($db) { return $db->transactionCommit(); });
		$this->assertFalse($commit, 'never reported as saved');
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertSame($committed ? ['INSERT INTO t VALUES (1)'] : [], self::writes(), $committed ? 'once' : 'nothing saved');
	}

	/** F2d: a failure that fails only its statement leaves a transaction whose end is not known as it was */
	public function testAStatementOnlyFailureKeepsATransactionWhoseEndIsNotKnown()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($db, 'SET autocommit=1'));
		FakeServer::failNext('/^INSERT INTO t VALUES \(2\)/', 1062);
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (2)'));
		$this->assertSame(1062, (int) $db->Errno, 'the statement\'s own error');
		$this->assertTrue($db->inTransaction());
		$this->assertFalse($db->lost());
		$this->assertTrue($this->q($db, 'UPDATE u SET x=1'));
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE u SET x=1'], self::writes());
	}

	public static function haltModes()
	{
		$cases = [];
		foreach (['SET autocommit=1', 'CREATE TABLE x (a int) START TRANSACTION', 'CREATE /*M! TEMPORARY */ TABLE tmp (a int)', 'LOCK /*!99999 TABLES */ t WRITE'] as $sql) {
			foreach (['yes', 'report', 'no'] as $halt) {
				$cases[$sql.', haltOnError '.$halt] = [$sql, $halt];
			}
		}
		return $cases;
	}

	/**
	* F2d: a statement whose implicit commit cannot be told halts nothing and reports nothing, whatever haltOnError is
	* (round 7 refused the next statement, which halted a 'yes' handle; 4233584 halted nothing there either).
	*
	* @dataProvider haltModes
	* @param string $sql
	* @param string $halt
	*/
	#[DataProvider('haltModes')]
	public function testADoubtHaltsNothing($sql, $halt)
	{
		$db = $this->db($halt);
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotFalse($this->q($db, $sql));
		$this->assertTrue($this->q($db, 'UPDATE u SET x=1'));
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(0, $db->haltCalls);
		$this->assertSame([], $db->reported);
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE u SET x=1'], self::writes());
	}

	public static function conflictReports()
	{
		return [
			'reportConflicts off: a conflict lost with 2006 is still the caller\'s' => [false, 1213, 0],
			'reportConflicts off: 3101 too' => [false, 3101, 0],
			'reportConflicts on: reported' => [true, 1213, 3],
			'reportConflicts off: a lost connection is no conflict' => [false, 2013, 3],
			'reportConflicts off: a server gone away (2006 of its own) is no conflict' => [false, 2006, 3],
		];
	}

	/**
	* F2d with haltOnError 'yes': a conflict (1213, 3101) that loses a transaction whose end is not known goes to the
	* caller as 2006, and with reportConflicts off it is handed over as a conflict is (no mail, no halt), and so are the
	* statements and the COMMIT refused after it; a connection lost there is reported as always.
	*
	* @dataProvider conflictReports
	* @param bool $reportConflicts
	* @param int $errno
	* @param int $reports how many failures are reported (the statement, the next statement, the COMMIT)
	*/
	#[DataProvider('conflictReports')]
	public function testAConflictLostWith2006IsReportedAsAConflict($reportConflicts, $errno, $reports)
	{
		FakeServer::$strict = false;
		$db = $this->db('yes');
		$db->reportConflicts = $reportConflicts;
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($db, 'SET autocommit=1'));
		FakeServer::failNext('/^UPDATE u/', $errno);

		$this->assertFalse($this->q($db, 'UPDATE u SET x=1'));
		$this->assertSame(in_array($errno, [1213, 3101], true) ? 2006 : $errno, (int) $db->Errno);
		$this->assertFalse($this->q($db, 'INSERT INTO t VALUES (2)'), 'refused');
		$this->assertFalse($this->q($db, 'COMMIT'), 'refused');

		$this->assertSame($reports, $db->haltCalls);
		$this->assertCount($reports, $db->reported);
		$this->assertSame(0, FakeServer::sent('INSERT INTO t VALUES (2)'));
	}

	/**
	* A START that works inside a transaction whose end is not known commits it (as MySQL does) and begins a new one,
	* with no txEnd() in between (txAfter() 'start'): its end is known, so a conflict that loses it is the server's
	* 1213 ("rolled back, retry"), not 2006.
	*/
	public function testAStartAfterADoubtBeginsATransactionWhoseEndIsKnown()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertTrue($this->q($db, 'SET autocommit=1'));
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (2)'));
		FakeServer::failNext('/^UPDATE u/', 1213, 1000);
		$this->assertFalse($this->q($db, 'UPDATE u SET x=1'));
		$this->assertSame(1213, (int) $db->Errno, 'the server rolled the new transaction back: retry');
		$this->assertTrue($db->transactionAbort());
		$this->assertSame(['INSERT INTO t VALUES (1)'], self::writes(), 'the START committed the first, the 1213 rolled the second back');
	}

	/** a transaction whose end is not known stays so for a clone's own: the clone has none */
	public function testACloneOfAHandleWhoseEndIsNotKnownStartsAfresh()
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'SET autocommit=1'));
		$this->assertTrue($db->transactionCommit());
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		FakeServer::failNext('/^UPDATE u/', 1213);
		$this->assertTrue($this->q($db, 'UPDATE u SET x=1'), 'replayed: the new transaction\'s end is known');
		$this->assertTrue($db->transactionCommit());
		$this->assertSame(['INSERT INTO t VALUES (1)', 'UPDATE u SET x=1'], self::writes());
	}
}

/** the driver's statement readers, called on their own */
class LeadProbe extends \MyDb\Mysqli\Db
{
	public function __construct($database = '', $user = '', $password = '', $host = 'localhost', $query = '', $port = '')
	{
	}

	public static function lead($sql)
	{
		return static::leadingKeywordSql($sql);
	}

	public static function ends($sql)
	{
		return static::endsTransaction(static::leadingKeywordSql($sql));
	}

	public static function outcome($sql)
	{
		return static::implicitCommitOutcome(static::leadingKeywordSql($sql));
	}

	public static function dirty($sql)
	{
		return static::changesSessionState(static::leadingKeywordSql($sql));
	}

	public static function replayable($sql)
	{
		return static::replayableStatement(static::leadingKeywordSql($sql));
	}
}
