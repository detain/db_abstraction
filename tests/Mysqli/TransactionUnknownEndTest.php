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
* Round 9 (the reviewer's s9_*): an executable comment that holds nothing but whitespace is a space; outside a
* transaction, a statement whose leading words hold one that is not read is taken for each COMMIT, ROLLBACK and opener
* it may be (unreadLeadMay(): guarded, and tracked as a transaction whose start is not known); a CREATE TABLE ... START
* TRANSACTION is 'chain' (it commits, and keeps a transaction of its own open); after a doubt any failure is a 2006;
* the release ROLLBACK reads stale flags as stale; a SET is counted by the word autocommit; INTO with comments before a
* user variable changes the session; a PCRE failure never reads as harmless.
*
* Round 10 (the reviewer's scale10/quad10): the looks that read a whole statement (INTO @x, START ... TRANSACTION in a
* CREATE TABLE) read what their patterns match in linear time, whatever a string literal in the statement holds.
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
			// r9 F1: one that holds nothing but whitespace runs nothing, run or skipped: a space
			'an empty one of a later version' => ['/*!80000 */ START TRANSACTION', 'START TRANSACTION'],
			'an empty one of a version above any' => ['/*!99999 */ COMMIT', 'COMMIT'],
			'an empty MariaDB one' => ['/*M!50000 */ ROLLBACK', 'ROLLBACK'],
			'an empty MariaDB one with no version' => ['/*M!*/COMMIT', 'COMMIT'],
			'an empty one among the words' => ['START /*!99999 */ TRANSACTION', 'START   TRANSACTION'],
			'an empty one with a sixth digit (MySQL runs the digit)' => ['/*!800001 */ COMMIT', '/*!800001 */ COMMIT'],
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

	/**
	* r9 F6: unwrapping executable comments stays linear too (the lead was copied whole at each one: 64k of them took
	* 2.2 s, 16k 142 ms); 160k of them now take what their length does.
	*/
	public function testUnwrappedExecutableCommentsAreReadInLinearTime()
	{
		$sql = 'CREATE'.str_repeat(' /*!40101 TEMPORARY */', 160000).' TABLE t (a int)';
		$start = microtime(true);
		$lead = LeadProbe::lead($sql);
		$this->assertLessThan(5.0, microtime(true) - $start);
		$this->assertSame(160000, substr_count($lead, 'TEMPORARY'));
		$this->assertStringEndsWith(' TABLE t (a int)', $lead);
		$this->assertStringNotContainsString('/*', $lead);
	}

	public static function classified()
	{
		// [statement, endsTransaction(), implicitCommitOutcome() (null: not asked: endsTransaction() does not count it,
		// or it is a START/COMMIT/ROLLBACK), changesSessionState()]
		return [
			// F2a
			'an executable marker inside a line comment' => ["-- see /*!40101 x */\nSELECT 1", false, null, false],
			'an executable marker inside a block comment' => ['/* see /*!40101 x */ UPDATE t SET v=1', false, null, false],
			// r9 F4: every CREATE TABLE commits the open transaction (CREATE TABLE ... START TRANSACTION in its trans_begin(),
			// before the CREATE runs); one that may hold START TRANSACTION is 'chain' (it keeps a transaction of its own open):
			// the two words next to each other (whitespace or a plain comment between them, a literal holding them too), or
			// both anywhere in one with an executable comment; any other is 'end', whatever an executable comment past its
			// leading words holds
			'CREATE TABLE with columns named start and transaction' => ['CREATE TABLE t2 (start int, transaction int)', true, 'end', false],
			'CREATE TABLE ... START TRANSACTION' => ['CREATE TABLE t (a int) START TRANSACTION', true, 'chain', false],
			'CREATE TABLE ... START /* c */ TRANSACTION' => ['CREATE TABLE t (a int) START /* c */ TRANSACTION', true, 'chain', false],
			'CREATE TABLE ... START TRANSACTION AS SELECT (refused after its commit)' => ['CREATE TABLE t2 START TRANSACTION AS SELECT 1 AS a', true, 'chain', false],
			'CREATE TABLE ... start, a comment, transaction apart' => ["CREATE TABLE t (a int) COMMENT='start' ENGINE=InnoDB /* transaction */", true, 'end', false],
			'CREATE TABLE ... an executable comment' => ['CREATE TABLE t (a int) /*!50100 START TRANSACTION */', true, 'chain', false],
			'CREATE TABLE ... START, then TRANSACTION in an executable comment' => ['CREATE TABLE t (a int) START /*!50100 TRANSACTION */', true, 'chain', false],
			'CREATE TABLE ... /*!50100 PARTITION BY ... */ (mysqldump)' => ['CREATE TABLE t (a INT) /*!50100 PARTITION BY HASH (a) */', true, 'end', false],
			'CREATE TABLE ... /*! ENGINE=InnoDB */' => ['CREATE TABLE t (a INT) /*! ENGINE=InnoDB */', true, 'end', false],
			'CREATE TABLE ... /*!50100 ... a/2 */ (a slash is no comment)' => ['CREATE TABLE t (a INT) /*!50100 PARTITION BY HASH (a/2) */', true, 'end', false],
			'CREATE TABLE ... /*!99999 ... */' => ['CREATE TABLE t (a INT) /*!99999 PARTITION BY HASH (a) */', true, 'end', false],
			'CREATE TABLE ... /*!100100 ... */ (six digits)' => ['CREATE TABLE t (a INT) /*!100100 PARTITION BY HASH (a) */', true, 'end', false],
			'CREATE TABLE ... /*M! ... */' => ['CREATE TABLE t (a INT) /*M! PAGE_COMPRESSED=1 */', true, 'end', false],
			'CREATE TABLE ... /*!50100 never closed' => ['CREATE TABLE t (a INT) /*!50100 PARTITION BY HASH (a)', true, 'end', false],
			'CREATE TABLE ... /*!99999 ... START TRANSACTION */' => ['CREATE TABLE t (a INT) /*!99999 START TRANSACTION */', true, 'chain', false],
			'CREATE TABLE ... /*!50100 ... /* c */ ... */ (nested)' => ['CREATE TABLE t (a INT) /*!50100 /* c */ START TRANSACTION */', true, 'chain', false],
			// (the server ends the executable comment at the second */: START TRANSACTION)
			'CREATE TABLE ... START/*!50100 /* c */ TRANSACTION */ (nested, hiding the pair)' => ['CREATE TABLE t (a INT) START/*!50100 /* c */ TRANSACTION */', true, 'chain', false],
			'CREATE TABLE ... /*!50100 ... # ... */' => ["CREATE TABLE t (a INT) /*!50100 # c\n START TRANSACTION */", true, 'chain', false],
			// (a # comment inside one runs to the end of the line, past its */, as on the server)
			'CREATE TABLE ... START/*!50100 # c */ then TRANSACTION on the next line' => ["CREATE TABLE t (a INT) START/*!50100 # c */\nTRANSACTION */", true, 'chain', false],
			// (conservative: the pair counts wherever it is, a comment or a string literal included)
			'CREATE TABLE ... the pair inside a # comment' => ["CREATE TABLE t (a INT) /*!50100 PARTITION BY HASH (a) # START TRANSACTION\n */", true, 'chain', false],
			"CREATE TABLE ... COMMENT 'start transaction'" => ["CREATE TABLE t (a INT) COMMENT 'start transaction'", true, 'chain', false],
			'CREATE TABLE ... START /* /*!50000 x */ TRANSACTION (a marker in a plain comment)' => ['CREATE TABLE t (a INT) START /* /*!50000 x */ TRANSACTION', true, 'chain', false],
			// r9 F4: CREATE TEMPORARY TABLE ... START TRANSACTION commits in its trans_begin() before the server refuses it
			// (ER_NOT_ALLOWED_WITH_START_TRANSACTION): counted; one that worked held none and committed nothing
			'CREATE TEMPORARY TABLE ... START TRANSACTION' => ['CREATE TEMPORARY TABLE t (a int) START TRANSACTION', true, 'none', true],
			// r10 F4: only next to each other (whitespace or a plain comment between them; in a literal too, on the safe side),
			// or both with an executable comment
			'CREATE TEMPORARY TABLE ... start and transaction apart' => ["CREATE TEMPORARY TABLE t (start int) COMMENT 'transaction'", false, 'none', true],
			'CREATE TEMPORARY TABLE ... transaction_id, start_date' => ['CREATE TEMPORARY TABLE tt AS SELECT transaction_id FROM transactions WHERE start_date > 0', false, 'none', true],
			"CREATE TEMPORARY TABLE ... 'restart transaction'" => ["CREATE TEMPORARY TABLE tt (id int) AS SELECT id FROM invoices WHERE memo = 'restart transaction'", false, 'none', true],
			"CREATE TEMPORARY TABLE ... 'start transaction' (a literal)" => ["CREATE TEMPORARY TABLE tt AS SELECT 'start transaction' AS v", true, 'none', true],
			'CREATE TEMPORARY TABLE ... START /* c */ TRANSACTION' => ['CREATE TEMPORARY TABLE t (a int) START /* c */ TRANSACTION', true, 'none', true],
			'CREATE TEMPORARY TABLE ... /*!99999 START */ x TRANSACTION (an executable comment)' => ['CREATE TEMPORARY TABLE t (a int) /*!99999 START */ x TRANSACTION', true, 'none', true],
			'CREATE TEMPORARY TABLE' => ['CREATE TEMPORARY TABLE t (a int)', false, null, true],
			// r9 F5: a SET that names autocommit is read for its assignments (a ';' in a literal before it included); r10 F4:
			// counted only when one may turn the session's autocommit on (setAutocommitOutcome()), or with a comment in it
			"SET @x='a;b', autocommit=1" => ["SET @x='a;b', autocommit=1", true, 'doubt', true],
			"SET @x=';', @y='autocommit' (no assignment to it)" => ["SET @x=';', @y='autocommit'", false, 'none', true],
			'SET @autocommit_count = 1' => ['SET @autocommit_count = 1', false, 'none', true],
			'SET autocommit_x = 1' => ['SET autocommit_x = 1', false, 'none', true],
			'SET @a = @autocommit' => ['SET @a = @autocommit', false, 'none', true],
			'SET autocommit=0' => ['SET autocommit=0', false, 'none', true],
			'SET @x=1, autocommit=1' => ['SET @x=1, autocommit=1', true, 'doubt', true],
			'SET autocommit /* c */ = 1 (a comment in it)' => ['SET autocommit /* c */ = 1', true, 'doubt', true],
			'SET @x=1, autocommit/* c */=1 (a comment hiding the assignment)' => ['SET @x=1, autocommit/* c */=1', true, 'doubt', true],
			'SET @x=1, autocommit # c, then =1' => ["SET @x=1, autocommit # c\n=1", true, 'doubt', true],
			'SET @x=1, autocommit -- c, then =1' => ["SET @x=1, autocommit -- c\n=1", true, 'doubt', true],
			"SET @x=1 # autocommit, then autocommit=0 (a comment in it)" => ["SET @x=1 # autocommit\n, autocommit=1", true, 'doubt', true],
			// r9 F7: INTO, comments, then a user variable
			'SELECT ... INTO /*!80000 */ @x' => ['SELECT a, b INTO /*!80000 */ @x FROM t', false, null, true],
			'SELECT ... INTO /*!99999 x */ @x (skipped by MySQL 8.0)' => ['SELECT a, b INTO /*!99999 x */ @x FROM t', false, null, true],
			'SELECT ... INTO /*!80000 @x */' => ['SELECT a, b INTO /*!80000 @x */ FROM t', false, null, true],
			'SELECT ... INTO /*M! x */ @x' => ['SELECT a, b INTO /*M! x */ @x FROM t', false, null, true],
			'SELECT ... INTO /* c */ @x' => ['SELECT a, b INTO /* c */ @x FROM t', false, null, true],
			'SELECT ... INTO -- c, then @x' => ["SELECT a, b INTO -- c\n@x FROM t", false, null, true],
			'SELECT ... INTO # c, then @x' => ["SELECT a, b INTO # c\n @x FROM t", false, null, true],
			'SELECT ... INTO@x' => ['SELECT a, b INTO@x FROM t', false, null, true],
			'SELECT ... /*!50000 INTO */ @x' => ['SELECT a, b /*!50000 INTO */ @x FROM t', false, null, true],
			'SELECT ... INTO /* @x */ OUTFILE (a plain comment)' => ["SELECT a, b INTO /* @x */ OUTFILE 'f' FROM t", false, null, false],
			'INSERT INTO t VALUES (@x)' => ['INSERT INTO t VALUES (@x)', false, null, false],
			// F2c: a scope keyword is in force for the plain names after it
			'SET GLOBAL autocommit=1' => ['SET GLOBAL autocommit=1', false, 'none', true],
			'SET PERSIST autocommit=1' => ['SET PERSIST autocommit=1', false, 'none', true],
			'SET PERSIST_ONLY autocommit=1' => ['SET PERSIST_ONLY autocommit=1', false, 'none', true],
			'SET @@GLOBAL.autocommit=1' => ['SET @@GLOBAL.autocommit=1', false, 'none', true],
			'SET @@persist.autocommit=1' => ['SET @@persist.autocommit=1', false, 'none', true],
			'SET GLOBAL x=1, autocommit=1' => ['SET GLOBAL x=1, autocommit=1', false, 'none', true],
			'SET GLOBAL x=1, SESSION autocommit=1' => ['SET GLOBAL x=1, SESSION autocommit=1', true, 'doubt', true],
			'SET autocommit=1, GLOBAL x=1' => ['SET autocommit=1, GLOBAL x=1', true, 'doubt', true],
			'SET autocommit=0, GLOBAL autocommit=1' => ['SET autocommit=0, GLOBAL autocommit=1', false, 'none', true],
			'SET GLOBAL autocommit=0, autocommit=1' => ['SET GLOBAL autocommit=0, autocommit=1', false, 'none', true],
			'SET GLOBAL autocommit=1, @@autocommit=1' => ['SET GLOBAL autocommit=1, @@autocommit=1', true, 'doubt', true],
			'SET GLOBAL autocommit=1, @@session.autocommit=0' => ['SET GLOBAL autocommit=1, @@session.autocommit=0', false, 'none', true],
			'SET autocommit=0, @@GLOBAL.autocommit=1' => ['SET autocommit=0, @@GLOBAL.autocommit=1', false, 'none', true],
			"SET @a='autocommit=1', autocommit=0 (a count the scopes do not match: taken for the session's)" => ["SET @a='autocommit=1', autocommit=0", true, 'doubt', true],
			'SET LOCAL autocommit=1' => ['SET LOCAL autocommit=1', true, 'doubt', true],
			"SET GLOBAL sql_mode='a,b', autocommit=1" => ["SET GLOBAL sql_mode='a,b', autocommit=1", false, 'none', true],
			"SET sql_mode='a,b', autocommit=1" => ["SET sql_mode='a,b', autocommit=1", true, 'doubt', true],
			'SET GLOBAL `autocommit`=1' => ['SET GLOBAL `autocommit`=1', false, 'none', true],
			"SET autocommit = 'OFF'" => ["SET autocommit = 'OFF'", false, 'none', true],
			"SET autocommit = 'OFF (an unclosed quote)" => ["SET autocommit = 'OFF", true, 'doubt', true],
			'SET autocommit=(0)' => ['SET autocommit=(0)', true, 'doubt', true],
			'SET GLOBAL autocommit="1" (a double quote: not read with scopes)' => ['SET GLOBAL autocommit="1"', true, 'doubt', true],
			// (@@x.autocommit is no variable MySQL has: the statement fails; it is not read with scopes, and the plain
			// look below does not take it for the session's either)
			'SET autocommit=0, then a target the scopes do not read' => ['SET autocommit=0, @@x.autocommit=1', false, 'none', true],
			'SET autocommit=0, then autocommit as a value' => ['SET autocommit=0, @a=autocommit', false, 'none', true],
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
			// r9 F1: an executable comment that holds nothing but whitespace is a space, whatever its version
			'/*!80000 */ START TRANSACTION' => ['/*!80000 */ START TRANSACTION'],
			'/*!99999 */ START TRANSACTION' => ['/*!99999 */ START TRANSACTION'],
			'/*M!50000 */ START TRANSACTION' => ['/*M!50000 */ START TRANSACTION'],
			'/*!80000 */ BEGIN' => ['/*!80000 */ BEGIN'],
			'/*!50000 */ /*!80000 */ START TRANSACTION' => ['/*!50000 */ /*!80000 */ START TRANSACTION'],
			'START /*!99999 */ TRANSACTION' => ['START /*!99999 */ TRANSACTION'],
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

	public static function statementOnlyFailures()
	{
		$cases = [];
		foreach (['1062' => [1062, 'INSERT INTO t VALUES (2)'], '1064 (never run)' => [1064, 'INSERT INTO t VALUES (2)'], 'execute(), 1062' => [1062, 'execute'], 'a START never run, 1064' => [1064, 'START TRANSACTION']] as $name => $case) {
			foreach (['the server kept it open' => false, 'the server committed it' => true] as $world => $committed) {
				$cases[$name.', '.$world] = [$case[0], $case[1], $committed];
			}
		}
		return $cases;
	}

	/**
	* r9 F4(b): after a doubt a failure that fails only its statement (1062, one the server never ran) is a 2006 too: its
	* own errno says "not committed" (database.md's rule), while the writes before the doubt may be. Before, the 1062
	* was handed out, the transaction went on, and a caller's ROLLBACK was answered true with the first INSERT committed.
	*
	* @dataProvider statementOnlyFailures
	* @param int $errno
	* @param string $statement
	* @param bool $committed
	*/
	#[DataProvider('statementOnlyFailures')]
	public function testAStatementOnlyFailureAfterADoubtLosesTheTransactionWith2006($errno, $statement, $committed)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		if ($committed) {
			self::implicitCommit();
		}
		$this->assertTrue($this->q($db, 'SET autocommit=1'));
		if ($statement === 'execute') {
			$this->assertNotSame(0, $db->prepare('INSERT INTO t VALUES (2)'));
		}
		FakeServer::failNext($statement === 'START TRANSACTION' ? '/^START TRANSACTION/' : '/^INSERT INTO t VALUES \(2\)/', $errno);

		$this->assertFalse($statement === 'execute' ? $db->execute() : $this->q($db, $statement));

		$this->assertSame(2006, (int) $db->Errno, 'outcome unknown, never the statement\'s own '.$errno.' ("not committed")');
		$this->assertStringContainsString('SQL error '.$errno, (string) $db->Error);
		$this->assertTrue($db->lost());
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($this->q($db, 'UPDATE u SET x=1'), 'refused');
		$this->assertFalse($db->transactionCommit(), 'never reported as saved');
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertTrue($this->q($db, 'ROLLBACK'), 'the cleanup');
		$this->assertSame([], FakeServer::$open, 'nothing of it is left open');
		$this->assertSame($committed ? ['INSERT INTO t VALUES (1)'] : [], self::writes());
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

	public static function unreadOpeners()
	{
		return [
			'/*!99999 x */ START TRANSACTION' => ['/*!99999 x */ START TRANSACTION'],
			'/*M! x */ START TRANSACTION' => ['/*M! x */ START TRANSACTION'],
			'/*M! x */ BEGIN' => ['/*M! x */ BEGIN'],
			'/*!80000 START TRANSACTION */' => ['/*!80000 START TRANSACTION */'],
			'START /*!80000 TRANSACTION */' => ['START /*!80000 TRANSACTION */'],
			'START /*M! x */ TRANSACTION' => ['START /*M! x */ TRANSACTION'],
			'COMMIT /*!80000 AND CHAIN */' => ['COMMIT /*!80000 AND CHAIN */'],
			'ROLLBACK /*!80000 AND CHAIN */' => ['ROLLBACK /*!80000 AND CHAIN */'],
			'/*!99999 SET @x=1 */ START TRANSACTION' => ['/*!99999 SET @x=1 */ START TRANSACTION'],
			"/*!99999 'x' */ BEGIN (a literal in it: told by the words)" => ["/*!99999 'x' */ BEGIN"],
		];
	}

	/**
	* r9 F1: outside a transaction, a statement that may open one by what the server makes of an executable comment the
	* driver does not read (the fake runs up to 80036, as MySQL 8.0.36: these all open one there) is tracked as an open
	* transaction whose start is not known: not replayed, and a 1213 in it is a 2006 (never retried on its own) and the
	* COMMIT is refused. Before, it was not tracked: the 1213 was retried on its own (autocommit) and the COMMIT reported
	* success with the first INSERT rolled back by the server.
	*
	* @dataProvider unreadOpeners
	* @param string $opener
	*/
	#[DataProvider('unreadOpeners')]
	public function testAnOpenerWhoseCommentIsNotReadIsTrackedAsATransactionWhoseStartIsNotKnown($opener)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, $opener));
		$this->assertTrue($db->inTransaction(), 'taken for an open transaction');
		$this->assertCount(1, array_filter(FakeServer::$log, static function ($line) {
			return strpos($line, 'so whether it opened one is not known') !== false;
		}), 'one error_log() line says so');
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		FakeServer::failNext("/VALUES \\('b'\\)/", 1213);

		$this->assertFalse($this->q($db, "INSERT INTO t (v) VALUES ('b')"), 'never retried on its own');

		$this->assertSame(2006, (int) $db->Errno, 'outcome unknown, not "rolled back, retry"');
		$this->assertStringContainsString('may have opened none', (string) $db->Error);
		$this->assertTrue($db->lost());
		$this->assertFalse($this->q($db, 'COMMIT'), 'never reported as saved');
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertSame(1, FakeServer::sent("INSERT INTO t (v) VALUES ('a')"), 'never replayed');
		$this->assertSame([], self::writes(), 'the server rolled the transaction back');
	}

	/**
	* r9 F1: a COMMIT that works after such an opener has saved every write (whether it opened a transaction or not),
	* and the next transaction of the handle is one whose end is known again (its 1213 is replayed).
	*
	* @dataProvider unreadOpeners
	* @param string $opener
	*/
	#[DataProvider('unreadOpeners')]
	public function testACommitAfterAnOpenerWhoseCommentIsNotReadSavesEveryWrite($opener)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, $opener));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertFalse($db->inTransaction());
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')"], self::writes());

		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('c')"));
		FakeServer::failNext("/VALUES \\('d'\\)/", 1213);
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('d')"), 'replayed: its end is known');
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')", "INSERT INTO t (v) VALUES ('c')", "INSERT INTO t (v) VALUES ('d')"], self::writes());
	}

	public static function knownOpeners()
	{
		return [
			'BEGIN /*!99999 x */' => ['BEGIN /*!99999 x */'],
			'START TRANSACTION /*M! WITH CONSISTENT SNAPSHOT */' => ['START TRANSACTION /*M! WITH CONSISTENT SNAPSHOT */'],
		];
	}

	/**
	* r9 F1: a START read as one, with an executable comment that is not read after its words, opens a transaction on
	* every reading: an ordinary one, whose 1213 is replayed (it is not taken for one whose start is not known)
	*
	* @dataProvider knownOpeners
	* @param string $opener
	*/
	#[DataProvider('knownOpeners')]
	public function testAKnownOpenerWithAnUnreadCommentAfterItIsAnOrdinaryTransaction($opener)
	{
		$db = $this->db();
		$this->assertTrue($this->q($db, $opener));
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		FakeServer::failNext("/VALUES \\('b'\\)/", 1213);
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('b')"), 'replayed');
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')", "INSERT INTO t (v) VALUES ('b')"], self::writes());
	}

	public static function unreadNonOpeners()
	{
		return [
			'/*M! x */ SELECT 1' => ['/*M! x */ SELECT 1'],
			'/*!99999 SET @x=1 */' => ['/*!99999 SET @x=1 */'],
			"/*!99999 'x' */ SELECT 1" => ["/*!99999 'x' */ SELECT 1"],
			'/*!99999 x */ START SLAVE' => ['/*!99999 x */ START SLAVE'],
			'COMMIT /*!99999 x */ AND NO CHAIN' => ['COMMIT /*!99999 x */ AND NO CHAIN'],
		];
	}

	/**
	* r9 F1: one whose readings hold no opener is no transaction: an autocommit statement, as before
	*
	* @dataProvider unreadNonOpeners
	* @param string $sql
	*/
	#[DataProvider('unreadNonOpeners')]
	public function testAStatementWhoseCommentIsNotReadAndThatOpensNothingIsNoTransaction($sql)
	{
		$db = $this->db();
		$this->assertNotFalse($this->q($db, $sql));
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($db->lost());
		$this->assertSame(0, (int) $db->Errno);
	}

	public static function sharedOpeners()
	{
		return [
			'/*!80000 */ START TRANSACTION (read)' => ['/*!80000 */ START TRANSACTION'],
			'/*!99999 */ START TRANSACTION (read)' => ['/*!99999 */ START TRANSACTION'],
			'/*M! x */ BEGIN' => ['/*M! x */ BEGIN'],
			'START /*!80000 TRANSACTION */' => ['START /*!80000 TRANSACTION */'],
			'/*!99999 x */ START TRANSACTION' => ['/*!99999 x */ START TRANSACTION'],
		];
	}

	/**
	* r9 F1 (the reviewer's s9_untracked_shared): a clone with no transaction of its own sends such an opener on the link
	* where another handle's transaction is open: it moves to a connection of its own (startApart()), as a START does,
	* so neither transaction ends the other. Before, it ran on the shared link (committing A's first write), A gave its
	* transaction up there, and A's quiet ROLLBACK rolled back B's write after B was told its INSERT and COMMIT worked.
	*
	* @dataProvider sharedOpeners
	* @param string $opener
	*/
	#[DataProvider('sharedOpeners')]
	public function testAnOpenerWhoseCommentIsNotReadNeverRunsBesideAnotherHandlesTransaction($opener)
	{
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));

		$this->assertTrue($this->q($b, $opener));
		$this->assertTrue($this->q($b, "INSERT INTO t (v) VALUES ('b1')"));
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a2')"), 'A\'s transaction is untouched');
		$this->assertTrue($this->q($b, 'COMMIT'));
		$this->assertTrue($this->q($a, 'COMMIT'));

		$this->assertSame(0, FakeServer::sent('ROLLBACK'), 'no quiet ROLLBACK on the shared link');
		$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('b1')"));
		$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('a1')"));
		$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('a2')"));
	}

	public static function clonedEnds()
	{
		return [
			'/*!80000 */ COMMIT (read)' => ['/*!80000 */ COMMIT'],
			'/*!99999 */ COMMIT (read)' => ['/*!99999 */ COMMIT'],
			'/*M! x */ COMMIT' => ['/*M! x */ COMMIT'],
			'/*!99999 x */ COMMIT' => ['/*!99999 x */ COMMIT'],
			'/*M! x */ COMMIT WORK' => ['/*M! x */ COMMIT WORK'],
			'/*!80000 */ COMMIT AND NO CHAIN (read)' => ['/*!80000 */ COMMIT AND NO CHAIN'],
			'/*!80000 COMMIT */' => ['/*!80000 COMMIT */'],
			"/*!99999 'x' */ COMMIT (a literal in it: told by the words)" => ["/*!99999 'x' */ COMMIT"],
			'/*!99999 */ ROLLBACK (read)' => ['/*!99999 */ ROLLBACK'],
			'/*M! x */ ROLLBACK' => ['/*M! x */ ROLLBACK'],
			'/*!99999 x */ ROLLBACK' => ['/*!99999 x */ ROLLBACK'],
			'/*!80000 ROLLBACK */' => ['/*!80000 ROLLBACK */'],
			// (a COMMIT first: refused as one, though it may open the next transaction too)
			'COMMIT /*!80000 AND CHAIN */' => ['COMMIT /*!80000 AND CHAIN */'],
		];
	}

	/**
	* r9 F2 (the reviewer's s9_clone_commit): a COMMIT or ROLLBACK from a handle with no transaction of its own, while
	* another handle sharing the link has one open there, is not sent and fails (2006), with an executable comment before
	* or among its words too (read as a space when it is empty, or taken for one when it is not read). Before, a leading
	* one that was not read hid it: it was sent, committed (or rolled back) A's transaction half done, and A's next
	* statement and COMMIT failed with a1 committed alone.
	*
	* @dataProvider clonedEnds
	* @param string $sql
	*/
	#[DataProvider('clonedEnds')]
	public function testACommitOrRollbackFromAnotherHandleIsNotSentWhateverItsComments($sql)
	{
		FakeServer::$strict = false;
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));

		$this->assertFalse($this->q($b, $sql), 'not sent');

		$this->assertSame(2006, (int) $b->Errno);
		$this->assertSame(0, FakeServer::sent($sql));
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a2')"));
		$this->assertTrue($this->q($a, 'COMMIT'));
		$this->assertSame(["INSERT INTO t (v) VALUES ('a1')", "INSERT INTO t (v) VALUES ('a2')"], self::writes(), 'A commits all of it');
	}

	/** r9 F2: one that may be a COMMIT is refused after a lost transaction, as a COMMIT is (never sent) */
	public function testAStatementThatMayBeACommitIsRefusedAfterALostTransaction()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, 'INSERT INTO t VALUES (1)'));
		$this->assertNotFalse($this->q($db, '/*!99999 SELECT 1 */'));
		$this->assertTrue($db->lost(), 'given up: what it ran is not known');
		$this->assertFalse($this->q($db, '/*M! x */ COMMIT'));
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertSame(0, FakeServer::sent('/*M! x */ COMMIT'));
	}

	/**
	* r9 F1/F2: one that may be a COMMIT and may open the next transaction too (COMMIT AND CHAIN behind a comment that is
	* not read), after a lost transaction the caller moved on from, is refused as a COMMIT and is no START: never sent,
	* and the handle keeps the shared link (a START beside another handle's transaction would move it apart)
	*/
	public function testARefusedCommitThatMayAlsoOpenLeavesTheLinkAsItWas()
	{
		FakeServer::$strict = false;
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$b->transactionReplays = 0;
		$this->assertTrue($this->q($b, 'START TRANSACTION'));
		$this->assertTrue($this->q($b, "INSERT INTO t (v) VALUES ('b1')"));
		FakeServer::failNext("/VALUES \\('b2'\\)/", 1213);
		$this->assertFalse($this->q($b, "INSERT INTO t (v) VALUES ('b2')"));
		$this->assertTrue($b->lost());
		// the caller moves on from it without a ROLLBACK (the statement itself is refused)
		$this->assertFalse($this->q($b, 'SELECT 3'));
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));

		$this->assertFalse($this->q($b, 'COMMIT /*!80000 AND CHAIN */'));

		$this->assertSame(0, FakeServer::sent('COMMIT /*!80000 AND CHAIN */'));
		$this->assertFalse($b->inTransaction());
		$this->assertNotFalse($this->q($b, 'SELECT 2'));
		$this->assertSame(1, FakeServer::$connections, 'no connection apart');
		$this->assertSame($a->linkId, $b->linkId, 'the link as it was');
		$this->assertTrue($this->q($a, 'COMMIT'));
		$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('a1')"));
		$this->assertSame(0, self::committed("INSERT INTO t (v) VALUES ('b1')"));
	}

	public static function readings()
	{
		// [statement, COMMIT, ROLLBACK, opener]
		return [
			'/*!99999 x */ START TRANSACTION' => ['/*!99999 x */ START TRANSACTION', false, false, true],
			'/*!80000 START TRANSACTION */' => ['/*!80000 START TRANSACTION */', false, false, true],
			'START /*!80000 TRANSACTION */' => ['START /*!80000 TRANSACTION */', false, false, true],
			'START /*!80000 x */ TRANSACTION' => ['START /*!80000 x */ TRANSACTION', false, false, true],
			'START /*!80000 x */ y TRANSACTION (y every reading holds)' => ['START /*!80000 x */ y TRANSACTION', false, false, false],
			'/*!80000 START */ /*!99999 x */ TRANSACTION' => ['/*!80000 START */ /*!99999 x */ TRANSACTION', false, false, true],
			'BEGIN /*!80000 WORK */' => ['BEGIN /*!80000 WORK */', false, false, true],
			'/*M! x */ COMMIT' => ['/*M! x */ COMMIT', true, false, false],
			'/*M! x */ ROLLBACK' => ['/*M! x */ ROLLBACK', false, true, false],
			'COMMIT /*!80000 AND CHAIN */' => ['COMMIT /*!80000 AND CHAIN */', true, false, true],
			'ROLLBACK /*!80000 AND CHAIN */' => ['ROLLBACK /*!80000 AND CHAIN */', false, true, true],
			'COMMIT /*!99999 x */ AND NO CHAIN' => ['COMMIT /*!99999 x */ AND NO CHAIN', true, false, false],
			'COMMIT AND /*!99999 NO */ CHAIN' => ['COMMIT AND /*!99999 NO */ CHAIN', true, false, true],
			'/*!99999 SET @x=1 */ START TRANSACTION' => ['/*!99999 SET @x=1 */ START TRANSACTION', false, false, true],
			'/*!99999 SET @x=1 COMMIT */ (no leading word after the =)' => ['/*!99999 SET @x=1 COMMIT */', false, false, false],
			'/*!40101 SET @x=1 */ after one not read: the words end there' => ['/*M! x */ /*!40101 SET @x=1 */ COMMIT', false, false, false],
			'/*M! x */ SELECT begin' => ['/*M! x */ SELECT begin', false, false, false],
			'a CHAIN with no COMMIT or ROLLBACK before it' => ['/*M! x */ SELECT chain', false, false, false],
			'/*M! x */ XA START' => ['/*M! x */ XA START', false, false, false],
			'a word every reading holds, then COMMIT' => ['/*M! x */ SELECT /*M! y */ COMMIT', false, false, false],
			'a quote in one: COMMIT where it is skipped' => ["/*!99999 'x' */ COMMIT", true, false, false],
			'a quote in one: none' => ["/*!99999 'x' */ SELECT 1", false, false, false],
			// r10 F3: a quote in one is read as any other character, not by the words anywhere (the data included)
			"a quote in one: the literal data is not read (begin)" => ["/*!99999 'q' */ SELECT 'begin'", false, false, false],
			"a quote in one: the literal data is not read (rollback)" => ["/*!99999 'q' */ INSERT INTO t (v) VALUES ('rollback x')", false, false, false],
			"a quote in one: the literal data is not read (start transaction, chain)" => ["/*!99999 'q' */ INSERT INTO t (v) VALUES ('start transaction', 'commit and chain')", false, false, false],
			'a quote in one that holds a star-slash: COMMIT where it is skipped' => ["/*!99999 SELECT 'a */ COMMIT' */", true, false, false],
			'a quote in one every server runs: the words end there' => ["/*!40101 'x' */ COMMIT", false, false, false],
			'a quote in one after COMMIT: no CHAIN' => ["COMMIT /*!99999 'x' AND CHAIN */", true, false, false],
			'a quoted name in one: BEGIN where it is skipped' => ['/*!99999 `t` */ BEGIN', false, false, true],
			'a double quote in one: ROLLBACK where it is skipped' => ['/*!99999 "x" */ ROLLBACK', false, true, false],
			'a double quote in one: the literal data is not read' => ["/*!99999 \"q\" */ SELECT 'begin'", false, false, false],
			'a quoted name in one: the literal data is not read' => ["/*!99999 `q` */ SELECT 'begin'", false, false, false],
			'BEGIN in one, then SELECT: BEGIN SELECT 1 or SELECT 1, neither opens' => ['/*!99999 BEGIN */ SELECT 1', false, false, false],
			'BEGIN in one, then WORK: BEGIN WORK opens' => ['/*!99999 BEGIN */ WORK', false, false, true],
			'BEGIN in one, then BEGIN: the second opens' => ['/*!99999 BEGIN */ BEGIN', false, false, true],
			'BEGIN, then a word in one: BEGIN opens where it is skipped' => ['BEGIN /*!99999 x */', false, false, true],
			'BEGIN in one, x in another, then WORK' => ['/*!99999 BEGIN */ /*!80000 x */ WORK', false, false, true],
			'START TRANSACTION in one, then SELECT' => ['/*!99999 START TRANSACTION */ SELECT 1', false, false, false],
			'START TRANSACTION in one, then READ ONLY' => ['/*!99999 START TRANSACTION */ READ ONLY', false, false, true],
			"START TRANSACTION in one, then Percona's FROM SESSION 7" => ['/*!99999 START TRANSACTION */ WITH CONSISTENT SNAPSHOT FROM SESSION 7', false, false, true],
			'START TRANSACTION in one, then AS SELECT (the CREATE TABLE ... START TRANSACTION is not an opener)' => ['/*!80000 CREATE TABLE t2 */ START TRANSACTION AS SELECT 1 AS a', false, false, false],
			'COMMIT AND CHAIN in one, then NO RELEASE' => ['COMMIT /*!80000 AND CHAIN */ NO RELEASE', true, false, true],
			'COMMIT AND CHAIN in one, then RELEASE' => ['COMMIT /*!80000 AND CHAIN */ RELEASE', true, false, true],
			'COMMIT AND CHAIN in one, then x' => ['COMMIT /*!80000 AND CHAIN */ x', true, false, false],
			'BEGIN in one with a comma after it: a character keeps it' => ['/*!99999 BEGIN */, SELECT 1', false, false, true],
			// r10 F3: NO and CHAIN in one comment that is not read are one unit
			'COMMIT /*!80000 AND NO CHAIN */' => ['COMMIT /*!80000 AND NO CHAIN */', true, false, false],
			'ROLLBACK /*!99999 AND NO CHAIN */' => ['ROLLBACK /*!99999 AND NO CHAIN */', false, true, false],
			'COMMIT AND /*!80000 NO CHAIN */' => ['COMMIT AND /*!80000 NO CHAIN */', true, false, false],
			'COMMIT WORK /*M! AND NO CHAIN */' => ['COMMIT WORK /*M! AND NO CHAIN */', true, false, false],
			'COMMIT /*!80000 AND NO */ CHAIN (CHAIN outside it)' => ['COMMIT /*!80000 AND NO */ CHAIN', true, false, true],
			'COMMIT /*!80000 AND NO */ /*!80000 CHAIN */ (two comments)' => ['COMMIT /*!80000 AND NO */ /*!80000 CHAIN */', true, false, true],
			'COMMIT /*!80000 NO AND CHAIN */ (NO not right before it)' => ['COMMIT /*!80000 NO AND CHAIN */', true, false, true],
			'COMMIT /*!80000 AND CHAIN NO */' => ['COMMIT /*!80000 AND CHAIN NO */', true, false, true],
			'a comment in one: told by the words' => ['/*!99999 /* c */ */ ROLLBACK AND CHAIN', false, true, true],
			'a -- comment in one' => ["/*!99999 -- c\n*/ BEGIN", false, false, true],
			'a # comment in one' => ["/*!99999 # c\n*/ START TRANSACTION", false, false, true],
			'one never closed' => ['/*!99999 COMMIT', true, false, false],
			'a minus sign in one (no comment)' => ['/*!99999 a-b */ BEGIN', false, false, true],
			'a plain comment after one' => ['/*M! x */ /* c */ BEGIN', false, false, true],
			'a line comment after one' => ["/*M! x */ -- c\nCOMMIT", true, false, false],
			'a six-digit version' => ['/*!800001 */ COMMIT', true, false, false],
		];
	}

	/**
	* r9 F1/F2: what a statement whose leading words hold an executable comment that is not read may be, each reading
	* taken: a word with no word every reading holds before it may be the first keyword (unreadLeadMay())
	*
	* @dataProvider readings
	* @param string $sql
	* @param bool $commit
	* @param bool $rollback
	* @param bool $open
	*/
	#[DataProvider('readings')]
	public function testTheReadingsOfAnUnreadCommentAreTakenEach($sql, $commit, $rollback, $open)
	{
		$this->assertSame(['commit' => $commit, 'rollback' => $rollback, 'open' => $open], LeadProbe::may($sql));
	}

	public static function unreadFlags()
	{
		return [
			'plain' => ['START TRANSACTION', false],
			'a plain comment' => ['/* c */ COMMIT', false],
			'an empty one' => ['/*!99999 */ COMMIT', false],
			'one read' => ['/*!40101 COMMIT */', false],
			'one not read, first' => ['/*!99999 x */ COMMIT', true],
			'one not read, among the words' => ['START /*M! x */ TRANSACTION', true],
			'a six-digit one' => ['/*!800001 */ COMMIT', true],
			'one never closed' => ['/*!40101 COMMIT', true],
			'a -- comment in one' => ["/*!40101 COMMIT -- x */\n", true],
			'one never closed among the words' => ['START /*!40101 TRANSACTION', true],
			'a comment never closed in one' => ['/*!40101 COMMIT /* x', true],
			'one past the leading words' => ['SELECT a, /*!99999 x */ b', false],
		];
	}

	/**
	* r9 F1: leadingKeywordSql() says when it left an executable comment it could not read in the lead
	*
	* @dataProvider unreadFlags
	* @param string $sql
	* @param bool $unread
	*/
	#[DataProvider('unreadFlags')]
	public function testTheLeadSaysWhenAnExecutableCommentIsNotRead($sql, $unread)
	{
		$flag = null;
		LeadProbe::lead($sql, $flag);
		$this->assertSame($unread, $flag);
	}

	public static function createStarts()
	{
		return [
			'thrown' => [true],
			'returned' => [false],
		];
	}

	/**
	* r9 F4(a) (the reviewer's s9_create_start): CREATE TABLE ... START TRANSACTION inside a transaction committed it (its
	* trans_begin() commits the open transaction before the CREATE runs) and keeps one of its own open, where only
	* COMMIT and ROLLBACK run; the statement after it fails on the server (1105 stands in for
	* ER_STATEMENT_NOT_ALLOWED_AFTER_START_TRANSACTION) and is a 2006, never that errno ("not committed": the INSERT
	* before it is committed, and a caller redoing the work would write it twice).
	*
	* @dataProvider createStarts
	* @param bool $strict
	*/
	#[DataProvider('createStarts')]
	public function testAStatementAfterACreateTableStartTransactionFailsWith2006($strict)
	{
		FakeServer::$strict = $strict;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		$this->assertTrue($this->q($db, 'CREATE TABLE t2 (a int) START TRANSACTION'));
		// what the server did: trans_begin() committed the INSERT
		self::implicitCommit();
		$this->assertTrue($db->inTransaction(), 'a transaction of its own is open on the server');
		FakeServer::failNext("/VALUES \\('b'\\)/", 1105);

		list($result, $exception) = self::attempt(function () use ($db) {
			return $this->q($db, "INSERT INTO t (v) VALUES ('b')");
		});

		$this->assertFalse($result);
		$this->assertSame(2006, (int) $db->Errno, 'outcome unknown: never retried, never "not committed"');
		$this->assertStringContainsString('committed the writes before it', (string) $db->Error);
		if ($exception !== null) {
			$this->assertSame(2006, (int) $exception->getCode());
		}
		$this->assertTrue($db->lost());
		$this->assertTrue($this->q($db, 'ROLLBACK'), 'the cleanup');
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')"], self::writes());
		$this->assertSame([], FakeServer::$open);
	}

	/**
	* r9 F4(a): CREATE TEMPORARY TABLE ... START TRANSACTION inside a transaction commits it (trans_begin()) and is then
	* refused by the server (temporary tables take no START TRANSACTION, sql_table.cc): its failure is a 2006 (the
	* writes before it are committed), never its own errno ("not committed": a caller redoing the work would write it
	* twice), and the COMMIT is refused. Before, the failure kept its errno and the transaction went on.
	*/
	public function testAFailedCreateTemporaryTableStartTransactionIsA2006()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		FakeServer::failNext('/^CREATE TEMPORARY/', 1105);

		$this->assertFalse($this->q($db, 'CREATE TEMPORARY TABLE t2 (a int) START TRANSACTION'));
		// what the server did: trans_begin() committed the INSERT before the CREATE was refused
		self::implicitCommit();

		$this->assertSame(2006, (int) $db->Errno, 'outcome unknown, never "not committed"');
		$this->assertTrue($db->lost());
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertSame(1, FakeServer::sent("INSERT INTO t (v) VALUES ('a')"), 'never replayed');
	}

	/** r9 F4(a): the intended use goes through: CREATE TABLE ... START TRANSACTION and its COMMIT, no halt */
	public function testACreateTableStartTransactionAndItsCommitWork()
	{
		$db = $this->db('yes');
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		$this->assertTrue($this->q($db, 'CREATE TABLE t2 (a int) START TRANSACTION'));
		$this->assertTrue($db->inTransaction());
		$this->assertTrue($this->q($db, 'COMMIT'), 'a COMMIT that works has saved every write');
		$this->assertFalse($db->inTransaction());
		$this->assertFalse($db->lost());
		$this->assertSame(0, $db->haltCalls);
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')"], self::writes());
		$this->assertSame(1, FakeServer::sent('CREATE TABLE t2 (a int) START TRANSACTION'), 'never replayed');
	}

	/**
	* r9 F3 (the reviewer's s9_released_stale): a transaction found ended by another holder sends its quiet ROLLBACK
	* whatever a holder's STALE flag says (C's transaction was committed by A's START, which stayed on the shared link):
	* that holder's own count shows its transaction ended there. Before, the flag alone stopped the ROLLBACK, A's
	* transaction stayed open on the server, and B's next autocommit write joined it and was rolled back when the
	* connection closed (B told its INSERT worked).
	*/
	public function testAReleaseRollbackIsSentWhateverAStaleFlagSays()
	{
		$c = $this->db();
		$this->assertTrue($this->q($c, 'SELECT 1'));
		$a = clone $c;
		$b = clone $c;
		$this->assertTrue($this->q($a, 'SET @x = 1'), 'the shared link\'s session state changed');
		$this->assertTrue($this->q($c, 'START TRANSACTION'));
		$this->assertTrue($this->q($c, "INSERT INTO t (v) VALUES ('c1')"));
		$this->assertTrue($this->q($a, 'START TRANSACTION'), 'stays on the shared link: commits c1');
		$this->assertTrue($c->inTransaction(), 'C\'s stale flag');
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));
		$this->assertTrue($this->q($b, 'SET autocommit=1'), 'counted as an end; ends nothing (autocommit was on)');

		$this->assertFalse($this->q($a, "INSERT INTO t (v) VALUES ('a2')"), 'A\'s transaction was ended elsewhere');

		$this->assertSame(2006, (int) $a->Errno);
		$this->assertSame(1, FakeServer::sent('ROLLBACK'), 'its quiet ROLLBACK went out on the shared link');
		$this->assertSame([], FakeServer::$open, 'nothing of it is left open');
		$this->assertTrue($this->q($a, 'ROLLBACK'), 'the cleanup');
		$this->assertTrue($this->q($b, "INSERT INTO t (v) VALUES ('b1')"));
		$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('b1')"), 'an autocommit write is committed');
		$this->assertSame(0, self::committed("INSERT INTO t (v) VALUES ('a1')"));
	}

	/**
	* r9 F3, the other side: while another handle's transaction is LIVE on the link (its flag up, its count not ended
	* there), the release ROLLBACK is not sent there (it would end that one)
	*/
	public function testAReleaseRollbackIsNotSentOverALiveTransaction()
	{
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$this->assertTrue($this->q($a, 'SET @x = 1'));
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));
		$this->assertTrue($this->q($b, 'START TRANSACTION'), 'stays on the shared link: commits a1, B\'s is live');
		$this->assertTrue($this->q($b, "INSERT INTO t (v) VALUES ('b1')"));

		$this->assertFalse($this->q($a, "INSERT INTO t (v) VALUES ('a2')"), 'A\'s transaction was ended elsewhere');

		$this->assertSame(0, FakeServer::sent('ROLLBACK'), 'B\'s live transaction is not rolled back');
		$this->assertTrue($this->q($b, 'COMMIT'));
		$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('b1')"));
		$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('a1')"));
	}

	public static function pcreLimits()
	{
		return [
			'a SET of about 1 MB' => ['SET '.str_repeat('GLOBAL a=1, ', 90000).'SESSION autocommit=1'],
			'a SET with 1 MB in a literal' => ["SET @j='".str_repeat('{"k":"v"},', 100000)."', autocommit=1"],
		];
	}

	/**
	* r9 F5: a SET that names autocommit past what PCRE's backtrack limit lets a pattern read (1e6, the CLI default) is
	* still counted as an end, and what it did is a doubt, never 'none'. Before, the lazy SET ... autocommit pattern
	* failed at about 1 MB and the SET was no end: the transaction stayed replayable after a SET autocommit=1 that had
	* committed it.
	*
	* @dataProvider pcreLimits
	* @param string $sql
	*/
	#[DataProvider('pcreLimits')]
	public function testALongSetIsCountedPastThePcreLimit($sql)
	{
		ini_set('pcre.backtrack_limit', '1000000');
		$this->assertTrue(LeadProbe::ends($sql), 'endsTransaction()');
		$this->assertSame('doubt', LeadProbe::outcome($sql));
		$this->assertTrue(LeadProbe::dirty($sql));
	}

	/**
	* r9 F5: a pattern that cannot run (a PCRE limit: backtracking or depth, JIT off) is never read as "harmless": under
	* every limit, each statement of the table reads as it does without one, or more cautiously (an end where it was
	* none, a changed session where it was not, not replayable where it was; an outcome that is the same, 'unknown', or
	* 'doubt' or 'chain' where it was not 'unknown': both keep the transaction going, not replayed, any failure in it a
	* 2006), never the other way.
	*/
	public function testAPatternThatCannotRunReadsTheSafeWay()
	{
		$statements = array_map(static function ($row) {
			return $row[0];
		}, self::classified());
		$statements = array_merge(array_values($statements), ['SET autocommit=0', 'SET @x=1, autocommit=1', "SET PASSWORD = 'x'", 'SELECT a, b INTO @x FROM t', 'SELECT 1', 'INSERT INTO t VALUES (1)', 'LOCK /*!99999 TABLES */ t WRITE', 'UPDATE t SET v = (@a := 1)', 'CREATE TABLE t2 (a int)', 'ALTER TABLE t ADD c int', 'CREATE TEMPORARY TABLE t (a int)', 'SET NAMES utf8mb4', 'USE db2', 'DROP TEMPORARY TABLE t', 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED']);
		$read = static function ($sql) {
			return [LeadProbe::ends($sql), LeadProbe::dirty($sql), LeadProbe::replayable($sql), LeadProbe::outcome($sql)];
		};
		// JIT off before any pattern is compiled in this process (the class runs each test in its own): a pattern cached
		// with JIT code ignores pcre.recursion_limit
		ini_set('pcre.jit', '0');
		$free = array_map($read, $statements);
		$checked = 0;
		$changed = 0;
		foreach (['pcre.backtrack_limit' => [1, 2, 5, 10, 20, 50, 200], 'pcre.recursion_limit' => [1, 2, 3, 4, 5, 6, 8]] as $setting => $limits) {
			foreach ($limits as $limit) {
				ini_set($setting, (string) $limit);
				foreach ($statements as $i => $sql) {
					list($ends, $dirty, $replayable, $outcome) = $read($sql);
					ini_restore($setting);
					$changed += [$ends, $dirty, $replayable, $outcome] !== $free[$i] ? 1 : 0;
					$what = $setting.'='.$limit.': '.$sql;
					$this->assertTrue($ends || !$free[$i][0], 'an end stays one, '.$what);
					$this->assertTrue($dirty || !$free[$i][1], 'a changed session stays one, '.$what);
					$this->assertTrue(!$replayable || $free[$i][2], 'never replayable where it was not, '.$what);
					$this->assertTrue($outcome === $free[$i][3] || $outcome === 'unknown' || (in_array($outcome, ['doubt', 'chain'], true) && $free[$i][3] !== 'unknown'), 'outcome '.$outcome.' for '.$free[$i][3].', '.$what);
					$checked++;
					ini_set($setting, (string) $limit);
				}
				ini_restore($setting);
			}
		}
		ini_restore('pcre.jit');
		$this->assertSame(14 * count($statements), $checked);
		$this->assertGreaterThan(0, $changed, 'the limits made patterns fail');
	}

	/**
	* r9 F5 (the reviewer's s9_set_semicolon): SET @x='a;b', autocommit=1 inside a transaction (that committed it, the
	* session's autocommit being off) is counted: a 1213 after it is a 2006, never retried
	*/
	public function testASetWithASemicolonInALiteralIsCounted()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		$this->assertTrue($this->q($db, "SET @x='a;b', autocommit=1"));
		self::implicitCommit();
		FakeServer::failNext("/VALUES \\('b'\\)/", 1213);
		$this->assertFalse($this->q($db, "INSERT INTO t (v) VALUES ('b')"));
		$this->assertSame(2006, (int) $db->Errno, 'never "rolled back, retry"');
		$this->assertSame(1, FakeServer::sent("INSERT INTO t (v) VALUES ('a')"), 'never replayed');
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')"], self::writes());
	}

	public static function intoForms()
	{
		return [
			'INTO /*!80000 */ @x' => ['SELECT a, b INTO /*!80000 */ @x FROM t'],
			'INTO /*!99999 x */ @x' => ['SELECT a, b INTO /*!99999 x */ @x FROM t'],
			'INTO /*!80000 @x */' => ['SELECT a, b INTO /*!80000 @x */ FROM t'],
			'INTO /* c */ @x' => ['SELECT a, b INTO /* c */ @x FROM t'],
		];
	}

	/**
	* r9 F7: SELECT ... INTO with comments before the user variable assigns it: the session changed (a clone's START
	* stays on the shared link, where @x is set), and it is no replayable statement
	*
	* @dataProvider intoForms
	* @param string $sql
	*/
	#[DataProvider('intoForms')]
	public function testIntoAUserVariableBehindACommentChangesTheSession($sql)
	{
		$this->assertTrue(LeadProbe::dirty($sql));
		$this->assertFalse(LeadProbe::replayable($sql));

		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$c = clone $a;
		$this->assertTrue($this->q($c, 'START TRANSACTION'));
		$this->assertNotFalse($this->q($a, $sql));
		$this->assertTrue($this->q($b, 'START TRANSACTION'));
		$this->assertSame(1, FakeServer::$connections, 'B\'s START stays on the shared link: its session holds @x');
	}

	public static function strayDdlTransactions()
	{
		return [
			'CREATE TEMPORARY TABLE ... START TRANSACTION' => ['CREATE TEMPORARY TABLE tt (a int) START TRANSACTION'],
			'ALTER TABLE ..., START TRANSACTION' => ['ALTER TABLE t2 ADD COLUMN c int, START TRANSACTION'],
			'CREATE TEMPORARY TABLE ... START /* c */ TRANSACTION' => ['CREATE TEMPORARY TABLE tt (a int) START /* c */ TRANSACTION'],
			'CREATE TEMPORARY TABLE ... /*!80000 START TRANSACTION */' => ['CREATE TEMPORARY TABLE tt (a int) /*!80000 START TRANSACTION */'],
			'CREATE TEMPORARY TABLE ... START /*!80000 TRANSACTION */' => ['CREATE TEMPORARY TABLE tt (a int) START /*!80000 TRANSACTION */'],
		];
	}

	/**
	* r10 F5 (the reviewer's s10_stray_ddl_tx): a CREATE TEMPORARY TABLE or ALTER TABLE ... START TRANSACTION sent with no
	* transaction open begins one on the server (trans_begin()) before the server refuses it (3909), and that refusal does
	* not roll it back: a quiet ROLLBACK ends it, so the caller's autocommit writes after it are committed. Before, they
	* each answered true and waited in that transaction, lost when the connection closed.
	*
	* @dataProvider strayDdlTransactions
	* @param string $sql
	*/
	#[DataProvider('strayDdlTransactions')]
	public function testARefusedDdlStartTransactionLeavesNoTransactionOpen($sql)
	{
		FakeServer::$strict = false;
		FakeServer::$ddlStartTransaction = true;
		$db = $this->db();
		$this->assertFalse($this->q($db, $sql));
		$this->assertSame(3909, (int) $db->Errno, 'its own error');
		$this->assertFalse($db->inTransaction());
		$this->assertSame([], FakeServer::$open, 'nothing left open on the server');
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('x')"));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('y')"));
		$this->assertSame(["INSERT INTO t (v) VALUES ('x')", "INSERT INTO t (v) VALUES ('y')"], self::writes(), 'autocommitted');
		$this->assertSame(1, FakeServer::sent('ROLLBACK'));
	}

	/** r10 F5: the same through prepare() and execute() */
	public function testARefusedDdlStartTransactionThroughExecuteLeavesNoTransactionOpen()
	{
		FakeServer::$strict = false;
		FakeServer::$ddlStartTransaction = true;
		$db = $this->db();
		$this->assertNotSame(0, $db->prepare('CREATE TEMPORARY TABLE tt (a int) START TRANSACTION'));
		$this->assertFalse($db->execute());
		$this->assertSame([], FakeServer::$open, 'nothing left open on the server');
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('x')"));
		$this->assertSame(["INSERT INTO t (v) VALUES ('x')"], self::writes(), 'autocommitted');
	}

	/**
	* r10 F5, shared link: a clone with no transaction of its own sends the refused statement while another handle has
	* one open there. trans_begin() commits that one's writes; the clone's quiet ROLLBACK ends the empty transaction,
	* and the other handle, whose transaction was ended, is told so (2006, outcome unknown: its writes were committed).
	*/
	public function testARefusedDdlStartTransactionBesideAnotherHandlesTransaction()
	{
		FakeServer::$strict = false;
		FakeServer::$ddlStartTransaction = true;
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));
		$this->assertFalse($this->q($b, 'CREATE TEMPORARY TABLE tt (a int) START TRANSACTION'));
		$this->assertSame(3909, (int) $b->Errno);
		$this->assertSame([], FakeServer::$open, 'nothing left open on the server');
		$this->assertFalse($this->q($a, "INSERT INTO t (v) VALUES ('a2')"));
		$this->assertSame(2006, (int) $a->Errno, 'outcome unknown, never retried');
		$this->assertFalse($this->q($a, 'COMMIT'));
		$this->assertSame(2006, (int) $a->Errno);
		$this->assertSame(["INSERT INTO t (v) VALUES ('a1')"], self::writes(), 'a1 committed by trans_begin(), a2 never sent');
	}

	/** r10 F5: one that never ran (a syntax error) began nothing: no ROLLBACK; nor after an ordinary failed statement */
	public function testAFailedStatementThatBeganNothingSendsNoRollback()
	{
		FakeServer::$strict = false;
		$db = $this->db();
		FakeServer::failNext('/^CREATE TEMPORARY/', 1064);
		$this->assertFalse($this->q($db, 'CREATE TEMPORARY TABLE tt (a int) START TRANSACTION'));
		FakeServer::failNext('/^CREATE TEMPORARY/', 1146);
		$this->assertFalse($this->q($db, "CREATE TEMPORARY TABLE tt AS SELECT transaction_id FROM t9 WHERE start_date > 0"));
		FakeServer::failNext('/^ALTER/', 1146);
		$this->assertFalse($this->q($db, 'ALTER TABLE t9 ADD COLUMN c int'));
		$this->assertSame(0, FakeServer::sent('ROLLBACK'));
	}

	/**
	* r10 F5: through execute() inside the handle's own transaction the refusal gives the transaction up (lost, 2006) as
	* before, with the give-up's own ROLLBACK only (endStrayDdlTransaction() is for a statement outside one).
	*/
	public function testARefusedDdlStartTransactionThroughExecuteInsideATransaction()
	{
		FakeServer::$strict = false;
		FakeServer::$ddlStartTransaction = true;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		$this->assertNotSame(0, $db->prepare('CREATE TEMPORARY TABLE tt (a int) START TRANSACTION'));
		$this->assertFalse($db->execute());
		$this->assertSame(1, FakeServer::sent('ROLLBACK'), "the give-up's ROLLBACK, and no other");
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertTrue($this->q($db, 'ROLLBACK'));
		$this->assertSame([], FakeServer::$open, 'nothing left open on the server');
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('b')"));
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')", "INSERT INTO t (v) VALUES ('b')"], self::writes());
	}

	/** r10 F5: inside the handle's own transaction the refusal is a 2006 (what was open is committed), as before */
	public function testARefusedDdlStartTransactionInsideATransactionIsA2006()
	{
		FakeServer::$strict = false;
		FakeServer::$ddlStartTransaction = true;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		$this->assertFalse($this->q($db, 'CREATE TEMPORARY TABLE tt (a int) START TRANSACTION'));
		$this->assertSame(2006, (int) $db->Errno);
		$this->assertFalse($this->q($db, 'COMMIT'));
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')"], self::writes(), 'committed by trans_begin()');
		$this->assertTrue($this->q($db, 'ROLLBACK'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('b')"));
		$this->assertSame([], FakeServer::$open, 'nothing left open on the server');
		$this->assertSame(["INSERT INTO t (v) VALUES ('a')", "INSERT INTO t (v) VALUES ('b')"], self::writes());
	}

	public static function endsNothing()
	{
		return [
			'CREATE TEMPORARY TABLE ... transaction_id, start_date' => ['CREATE TEMPORARY TABLE tt AS SELECT transaction_id FROM transactions WHERE start_date > 0'],
			"CREATE TEMPORARY TABLE ... 'restart transaction'" => ["CREATE TEMPORARY TABLE tt (id int) AS SELECT id FROM invoices WHERE memo = 'restart transaction'"],
			'SET @autocommit_count = 1' => ['SET @autocommit_count = 1'],
			'SET GLOBAL autocommit = 1' => ['SET GLOBAL autocommit = 1'],
		];
	}

	/**
	* r10 F4 (the reviewer's s10_temp_overcount, own transaction): a statement that ends nothing, failing inside the
	* transaction, keeps its own errno and the transaction goes on. Before, the words start and transaction anywhere in a
	* CREATE TEMPORARY TABLE, or autocommit anywhere in a SET, counted it as an end: its failure was a 2006 and the
	* transaction was given up (a1 lost, the COMMIT a 2006).
	*
	* @dataProvider endsNothing
	* @param string $sql
	*/
	#[DataProvider('endsNothing')]
	public function testAFailedStatementThatEndsNothingKeepsTheTransaction($sql)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a1')"));
		FakeServer::failNext('/^(?:CREATE|SET)/', 1146);
		$this->assertFalse($this->q($db, $sql));
		$this->assertSame(1146, (int) $db->Errno, 'its own error: the transaction is open');
		$this->assertFalse($db->lost());
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a2')"));
		$this->assertTrue($this->q($db, 'COMMIT'));
		$this->assertSame(["INSERT INTO t (v) VALUES ('a1')", "INSERT INTO t (v) VALUES ('a2')"], self::writes());
	}

	/**
	* r10 F4 (s10_temp_overcount, shared link): one that ends nothing, run by a handle with no transaction of its own
	* while another handle sharing the link has one open there, leaves that transaction alone. A CREATE TEMPORARY TABLE
	* that worked held no START TRANSACTION (the server refuses one that does), so its end is taken back, a literal
	* holding the two words included. Before, it was counted as an end on the link: A's next statement sent a quiet
	* ROLLBACK, A's transaction was lost (2006) and nothing was committed.
	*
	* @dataProvider endsNothing
	* @param string $sql
	*/
	#[DataProvider('endsNothing')]
	public function testAStatementThatEndsNothingLeavesAnotherHandlesTransactionAlone($sql)
	{
		$this->assertAnotherHandlesTransactionGoesOn($sql);
	}

	/** r10 F4: a CREATE TEMPORARY TABLE that worked with START TRANSACTION in a literal: counted, then taken back */
	public function testACreateTemporaryTableThatWorkedEndedNothing()
	{
		$this->assertAnotherHandlesTransactionGoesOn("CREATE TEMPORARY TABLE tt AS SELECT 'start transaction' AS v");
	}

	/** r10 F4: the same through prepare() and execute() */
	public function testACreateTemporaryTableThatWorkedThroughExecuteEndedNothing()
	{
		$this->assertAnotherHandlesTransactionGoesOn("CREATE TEMPORARY TABLE tt AS SELECT 'start transaction' AS v", true);
	}

	private function assertAnotherHandlesTransactionGoesOn($sql, $execute = false)
	{
		FakeServer::$strict = false;
		$a = $this->db();
		$this->assertTrue($this->q($a, 'SELECT 1'));
		$b = clone $a;
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));
		if ($execute) {
			$this->assertNotSame(0, $b->prepare($sql));
			$this->assertTrue($b->execute());
		} else {
			$this->assertTrue($this->q($b, $sql));
		}
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a2')"), 'A goes on');
		$this->assertTrue($this->q($a, 'COMMIT'));
		$this->assertSame(0, FakeServer::sent('ROLLBACK'), 'no quiet ROLLBACK');
		$this->assertSame(["INSERT INTO t (v) VALUES ('a1')", "INSERT INTO t (v) VALUES ('a2')"], self::writes());
	}

	public static function notOpeners()
	{
		return [
			'COMMIT /*!80000 AND NO CHAIN */' => ['COMMIT /*!80000 AND NO CHAIN */'],
			'ROLLBACK /*!99999 AND NO CHAIN */' => ['ROLLBACK /*!99999 AND NO CHAIN */'],
			"/*!99999 'q' */ SELECT 'begin'" => ["/*!99999 'q' */ SELECT 'begin'"],
			"/*!99999 'q' */ INSERT ... 'commit and chain'" => ["/*!99999 'q' */ INSERT INTO t (v) VALUES ('commit and chain')"],
			'/*!99999 BEGIN */ SELECT 1' => ['/*!99999 BEGIN */ SELECT 1'],
			'/*!99999 START TRANSACTION */ SELECT 1' => ['/*!99999 START TRANSACTION */ SELECT 1'],
		];
	}

	/**
	* r10 F3 (the reviewer's s10_phantom): a statement that opens no transaction in any reading, sent with none open, is
	* not tracked as an open one once it worked, so the caller's autocommit writes after it go on as before (a 1213 retried
	* on its own, never a 2006 nor a halt). Before, NO CHAIN in one comment that is not read, or a quote in one (the words
	* of the data then read), made it "may open": tracked as a transaction whose start is not known, the 1213 gave it up
	* (2006, mail and halt) and every later statement was refused until a ROLLBACK or START the caller never sends.
	*
	* @dataProvider notOpeners
	* @param string $sql
	*/
	#[DataProvider('notOpeners')]
	public function testAStatementThatOpensNoTransactionInAnyReadingIsNotTrackedAsOne($sql)
	{
		FakeServer::$strict = false;
		$db = $this->db('yes');
		$this->assertNotFalse($this->q($db, $sql));
		$this->assertFalse($db->inTransaction(), 'none open');
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a')"));
		FakeServer::failNext("/VALUES \\('b'\\)/", 1213);
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('b')"), 'retried on its own (autocommit)');
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('c')"));
		$this->assertSame(0, $db->haltCalls);
		foreach (['a', 'b', 'c'] as $v) {
			$this->assertSame(1, self::committed("INSERT INTO t (v) VALUES ('".$v."')"), $v);
		}
		$this->assertSame([], FakeServer::$open, 'nothing left open');
	}

	public static function unreadAfterALoss()
	{
		// MySQL 8.0 skips each comment (a version above its own): every one of them is the INSERT
		return [
			'/*!99999 ROLLBACK; */ INSERT' => ["/*!99999 ROLLBACK; */ INSERT INTO t (v) VALUES ('x')"],
			"/*!99999 'q' */ INSERT ... 'rollback x'" => ["/*!99999 'q' */ INSERT INTO t (v) VALUES ('rollback x')"],
			"/*!99999 'q' */ INSERT ... 'begin x'" => ["/*!99999 'q' */ INSERT INTO t (v) VALUES ('begin x')"],
			'/*!99999 BEGIN; */ INSERT' => ["/*!99999 BEGIN; */ INSERT INTO t (v) VALUES ('x')"],
			'/*!99999 COMMIT; */ INSERT' => ["/*!99999 COMMIT; */ INSERT INTO t (v) VALUES ('x')"],
			'/*M! ROLLBACK; */ INSERT' => ["/*M! ROLLBACK; */ INSERT INTO t (v) VALUES ('x')"],
			'plain INSERT (as before)' => ["INSERT INTO t (v) VALUES ('x')"],
		];
	}

	/**
	* r10 F2 (the reviewer's s10_lost_bypass): after a lost transaction (1213 on one that cannot be replayed), a statement
	* whose unread executable comment makes it a ROLLBACK, an opener or a COMMIT in some reading is refused like any other
	* statement until the caller's own ROLLBACK or START: it never ends the refusal. Before, it was sent and worked (the
	* INSERT on MySQL 8.0), ended the refusal, the next INSERT autocommitted and the COMMIT answered true with a1 rolled
	* back (a false success); a may-COMMIT was taken for the caller's COMMIT, so the next INSERT autocommitted on its own.
	*
	* @dataProvider unreadAfterALoss
	* @param string $sql
	*/
	#[DataProvider('unreadAfterALoss')]
	public function testAStatementThatMayEndTheTransactionNeverEndsTheRefusalAfterALoss($sql)
	{
		FakeServer::$strict = false;
		$db = $this->db();
		$this->assertTrue($this->q($db, 'START TRANSACTION'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('a1')"));
		$this->assertNotFalse($this->q($db, "SELECT GET_LOCK('k', 0)"));
		FakeServer::failNext("/VALUES \\('a2'\\)/", 1213);
		$this->assertFalse($this->q($db, "INSERT INTO t (v) VALUES ('a2')"));
		$this->assertSame(1213, (int) $db->Errno);

		$this->assertFalse($this->q($db, $sql), 'refused');
		$this->assertSame(1213, (int) $db->Errno);
		$this->assertSame(0, FakeServer::sent($sql), 'never sent');
		$this->assertTrue($db->lostTransactionPending(), 'still refusing');
		$this->assertFalse($this->q($db, "INSERT INTO t (v) VALUES ('c')"), 'refused');
		$this->assertFalse($this->q($db, 'COMMIT'), 'never a false success');
		$this->assertSame(1213, (int) $db->Errno);
		$this->assertSame([], self::writes(), 'nothing written on its own');
		$this->assertSame(0, FakeServer::sent("INSERT INTO t (v) VALUES ('c')"));
		// the caller's ROLLBACK ends it, and what it sends next runs again
		$this->assertTrue($this->q($db, 'ROLLBACK'));
		$this->assertTrue($this->q($db, "INSERT INTO t (v) VALUES ('d')"));
		$this->assertSame(["INSERT INTO t (v) VALUES ('d')"], self::writes());
	}

	/**
	* r10 F2: the same beside another handle's transaction on the shared link: one that may be a ROLLBACK is refused, not
	* taken for the cleanup of the lost transaction (a ROLLBACK there is answered true without being sent, and the lost
	* record cleared). Before, it answered true although nothing was sent, and the next INSERT ran in the other handle's
	* transaction
	*/
	public function testAStatementThatMayBeARollbackIsNoCleanupBesideAnotherTransaction()
	{
		FakeServer::$strict = false;
		$b = $this->db();
		$this->assertTrue($this->q($b, 'SELECT 1'));
		$a = clone $b;
		$this->assertTrue($this->q($b, 'START TRANSACTION'));
		$this->assertTrue($this->q($b, "INSERT INTO t (v) VALUES ('b1')"));
		$this->assertNotFalse($this->q($b, "SELECT GET_LOCK('k', 0)"));
		FakeServer::failNext("/VALUES \\('b2'\\)/", 1213);
		$this->assertFalse($this->q($b, "INSERT INTO t (v) VALUES ('b2')"));
		$this->assertTrue($b->lostTransactionPending());
		$this->assertTrue($this->q($a, 'START TRANSACTION'));
		$this->assertTrue($this->q($a, "INSERT INTO t (v) VALUES ('a1')"));
		$this->assertSame(1, FakeServer::$connections, 'one link');

		$sql = "/*!99999 ROLLBACK; */ INSERT INTO t (v) VALUES ('x')";
		$this->assertFalse($this->q($b, $sql), 'refused, never answered true');
		$this->assertSame(1213, (int) $b->Errno);
		$this->assertSame(0, FakeServer::sent($sql));
		$this->assertTrue($b->lostTransactionPending(), 'still refusing');
		$this->assertFalse($this->q($b, "INSERT INTO t (v) VALUES ('c')"));
		$this->assertSame(0, FakeServer::sent("INSERT INTO t (v) VALUES ('c')"), 'not run in A\'s transaction');
		$this->assertTrue($this->q($a, 'COMMIT'));
		$this->assertSame(["INSERT INTO t (v) VALUES ('a1')"], self::writes());
	}

	public static function wordGapForms()
	{
		// [statement, INTO_USER_VARIABLE matches, START_TRANSACTION_ANYWHERE matches]
		return [
			'INTO @x' => ['SELECT a INTO @x FROM t', true, false],
			'INTO@x' => ['SELECT a INTO@x FROM t', true, false],
			'INTO /* c */ @x' => ['SELECT a INTO /* c */ @x FROM t', true, false],
			'INTO /* c */ /* d */ @x' => ['SELECT a INTO /* c */ /* d */ @x FROM t', true, false],
			'INTO -- c then @x' => ["SELECT a INTO -- c\n@x FROM t", true, false],
			'INTO # c then @x' => ["SELECT a INTO # c\n @x FROM t", true, false],
			'INTO --x is no comment' => ["SELECT a INTO --x\n@x FROM t", false, false],
			'INTO -- before DEL is a comment' => ["SELECT a INTO --\x7F c\n@x FROM t", true, false],
			'INTO /* c */ -- before DEL' => ["SELECT a INTO /* c */ --\x7F c\n@x FROM t", true, false],
			'INTO /*!80000 @x */' => ['SELECT a INTO /*!80000 @x */ FROM t', true, false],
			'INTO /*M!@x */' => ['SELECT a INTO /*M!@x */ FROM t', true, false],
			'INTO /*m! @x */ (any case)' => ['SELECT a INTO /*m! @x */ FROM t', true, false],
			'INTO /*!99999 x */ @x' => ['SELECT a INTO /*!99999 x */ @x FROM t', true, false],
			'INTO in an executable comment' => ['SELECT a /*!80000 INTO */ @x FROM t', true, false],
			'INTO /* never closed @x' => ['SELECT a INTO /* @x', false, false],
			'INTO OUTFILE' => ["SELECT a INTO OUTFILE '/tmp/@x' FROM t", false, false],
			'INTO t, then @' => ["INSERT INTO t (v) VALUES ('joe@example.com')", false, false],
			'INTO later in a literal' => ["INSERT INTO t (v) VALUES ('sent into @joe')", true, false],
			'a word ending in into' => ['SELECT a xinto @x FROM t', false, false],
			'the word in a comment run' => ["SELECT a /* into */ INTO /* c */ x, @y FROM t", false, false],
			'INTO through a comment in literals' => ["SELECT 'into /* ', 'into */ @y' FROM t", true, false],
			'an inner INTO by a line comment' => ["SELECT 'into /* into -- x */ zzz\n@' FROM t", true, false],
			'INTO /* c */ /*!80000 @x */' => ['SELECT a INTO /* c */ /*!80000 @x */ FROM t', true, false],
			'INTO /* c */ /*m!50000 @x */' => ['SELECT a INTO /* c */ /*m!50000 @x */ FROM t', true, false],
			'INTO /* c */ /*!80000 x */ @x' => ['SELECT a INTO /* c */ /*!80000 x */ @x FROM t', true, false],
			'INTO /* c */ */ @x' => ['SELECT a INTO /* c */ */ @x FROM t', true, false],
			'INTO /* c */ -- c then @x' => ["SELECT a INTO /* c */ -- c\n @x FROM t", true, false],
			'INTO /* c */ --x' => ["SELECT a INTO /* c */ --x\n@x FROM t", false, false],
			'INTO /* c */ x @x' => ['SELECT a INTO /* c */ x @x FROM t', false, false],
			'START TRANSACTION' => ['CREATE TABLE t2 START TRANSACTION AS SELECT 1', false, true],
			'START/**/TRANSACTION' => ['CREATE TABLE t2 START/**/TRANSACTION AS SELECT 1', false, true],
			'START /* c */ /*!80000 */ TRANSACTION' => ['CREATE TABLE t2 START /* c */ /*!80000 */ TRANSACTION AS SELECT 1', false, false],
			'START /* c */ /*m!80000 */ TRANSACTION' => ['CREATE TABLE t2 START /* c */ /*m!80000 */ TRANSACTION AS SELECT 1', false, false],
			'START /* c */ transactions' => ['CREATE TABLE t2 AS SELECT start /* c */ transactions FROM t', false, false],
			'START /* c */ transaction_id' => ['CREATE TABLE t2 AS SELECT start /* c */ transaction_id FROM t', false, false],
			'START /* c */ transaction$x ($ is no word character)' => ['CREATE TABLE t2 AS SELECT start /* c */ transaction$x FROM t', false, true],
			'START /* c */ transaction then a byte over 127' => ["CREATE TABLE t2 AS SELECT start /* c */ transaction\xC3\xA9 FROM t", false, true],
			'START /* c */ -- c then transaction' => ["CREATE TABLE t2 AS SELECT start /* c */ -- c\ntransaction FROM t", false, true],
			'START /* c */ */ TRANSACTION' => ['CREATE TABLE t2 START /* c */ */ TRANSACTION AS SELECT 1', false, false],
			'START /* c */ TRANSACTION' => ['CREATE TABLE t2 START /* c */ TRANSACTION AS SELECT 1', false, true],
			'START -- c then TRANSACTION' => ["CREATE TABLE t2 START -- c\nTRANSACTION AS SELECT 1", false, true],
			'STARTTRANSACTION' => ['CREATE TABLE t2 STARTTRANSACTION AS SELECT 1', false, false],
			'START /*!80000 */ TRANSACTION is no gap' => ['CREATE TABLE t2 START /*!80000 */ TRANSACTION AS SELECT 1', false, false],
			'START /*M x */ TRANSACTION is a plain comment' => ['CREATE TABLE t2 START /*M x */ TRANSACTION AS SELECT 1', false, true],
			'START TRANSACTIONS' => ['CREATE TABLE t2 (start int, transactions int)', false, false],
			'START TRANSACTION_ID' => ['CREATE TABLE t2 AS SELECT start transaction_id FROM t', false, false],
			'start, transaction' => ['CREATE TABLE t2 (start int, transaction int)', false, false],
			'START through a comment in a literal' => ["CREATE TABLE t2 AS SELECT 'start /* start */ transaction'", false, true],
		];
	}

	/**
	* r10 F1: what the INTO and START ... TRANSACTION looks find is what their patterns match
	*
	* @dataProvider wordGapForms
	* @param string $sql
	* @param bool $into
	* @param bool $start
	*/
	#[DataProvider('wordGapForms')]
	public function testTheWordGapLooksFindWhatTheirPatternsMatch($sql, $into, $start)
	{
		$this->assertSame($into, preg_match('~'.\MyDb\Mysqli\Db::INTO_USER_VARIABLE.'~is', $sql) === 1, 'the pattern itself');
		$this->assertSame($start, preg_match('~'.\MyDb\Mysqli\Db::START_TRANSACTION_ANYWHERE.'~is', $sql) === 1, 'the pattern itself');
		$this->assertSame($into, LeadProbe::into($sql));
		$this->assertSame($start, LeadProbe::startTransaction($sql));
	}

	/**
	* r10 F1: the looks read exactly what the patterns match, on strings made of the pieces that matter to them (a
	* fixed seed: the same strings every run)
	*/
	public function testTheWordGapLooksAgreeWithTheirPatternsOnEveryPieceTogether()
	{
		$all = ['into', 'INTO', 'start', 'START', 'transaction', 'Transaction', ' ', "\n", "\t", "\x0B", "\x7F", '/*', '*/', '/*!', '/*!80000', '/*!99999 ', '/*M!', '/*m!', '/*M', '@', '@x', '#', '--', '-- ', "--\t", "--\x7F", '-', '*', '/', "'", 'x', '1', '!', '_', '$', "\xC3", '(', 'intox', 'xinto', 'transactions'];
		$few = ['into', 'start', 'START', 'transaction', ' ', "\n", '/*', '*/', '/*!', '/*M!', '@', '#', '-- ', '--', 'x', '/', '*'];
		mt_srand(10);
		$intoRef = '~'.\MyDb\Mysqli\Db::INTO_USER_VARIABLE.'~is';
		$startRef = '~'.\MyDb\Mysqli\Db::START_TRANSACTION_ANYWHERE.'~is';
		$matched = [0, 0];
		// (one assertion at the end: an event per assertion of a test run in its own process costs the runner memory)
		$wrong = [];
		for ($i = 0; $i < 40000; $i++) {
			$pieces = $i % 2 === 0 ? $all : $few;
			$sql = '';
			for ($j = mt_rand(1, 16); $j > 0; $j--) {
				$sql .= $pieces[mt_rand(0, count($pieces) - 1)];
			}
			$into = preg_match($intoRef, $sql) === 1;
			$start = preg_match($startRef, $sql) === 1;
			$matched[0] += $into ? 1 : 0;
			$matched[1] += $start ? 1 : 0;
			if ($into !== LeadProbe::into($sql) || $start !== LeadProbe::startTransaction($sql)) {
				$wrong[] = json_encode($sql);
			}
		}
		$this->assertSame([], array_slice($wrong, 0, 20));
		$this->assertGreaterThan(500, $matched[0], 'the strings hold matches');
		$this->assertGreaterThan(100, $matched[1], 'the strings hold matches');
	}

	public static function hostileLiterals()
	{
		// [statement of n repeats, n, the reading it must still give: changesSessionState() / replayableStatement() /
		// implicitCommitOutcome()]; d43abf5 took 10 to 20 s for each (the length squared), the looks a few ms
		return [
			'@, then into /* (never closed)' => ["INSERT INTO t (v) VALUES ('@ %s')", 'into /* ', 40000, ['dirty' => false, 'replayable' => true]],
			'@, then into /* and a close' => ["INSERT INTO t (v) VALUES ('@ %s */')", 'into /* ', 40000, ['dirty' => false, 'replayable' => true]],
			'@, then into /*!99999' => ["INSERT INTO t (v) VALUES ('@ %s')", 'into /*!99999 ', 32000, ['dirty' => false, 'replayable' => true]],
			'@, then into # on one line' => ["INSERT INTO t (v) VALUES ('@ %s')", 'into # ', 80000, ['dirty' => false, 'replayable' => true]],
			'@, then into -- on one line' => ["INSERT INTO t (v) VALUES ('@ %s')", 'into -- ', 80000, ['dirty' => false, 'replayable' => true]],
			'into /* then @ at the end' => ["INSERT INTO t (v) VALUES ('%s */ x @')", 'into /* ', 40000, ['dirty' => false, 'replayable' => true]],
			'into # then @ at the end' => ["INSERT INTO t (v) VALUES ('%s\nx @')", 'into # ', 80000, ['dirty' => false, 'replayable' => true]],
			'into /* then @ at the end, reached' => ["INSERT INTO t (v) VALUES ('%s */ @')", 'into /* ', 40000, ['dirty' => true, 'replayable' => false]],
			'CREATE TABLE, start /* then transaction' => ["CREATE TABLE t2 AS SELECT '%s transaction' AS v", 'start /* ', 40000, ['outcome' => 'end']],
			'CREATE TABLE, start # then transaction' => ["CREATE TABLE t2 AS SELECT '%s transaction' AS v", 'start # ', 80000, ['outcome' => 'end']],
		];
	}

	/**
	* r10 F1: a string literal holding "@" and many "into /*" (or "start /*" in a CREATE TABLE with "transaction")
	* costs what its length does: each size here took d43abf5 10 s or more (from every INTO the pattern walked to the end
	* of the statement), and must take under 3 s; the readings are those d43abf5 gave
	*
	* @dataProvider hostileLiterals
	* @param string $statement
	* @param string $piece
	* @param int $n
	* @param array $reading
	*/
	#[DataProvider('hostileLiterals')]
	public function testAHostileLiteralIsReadInLinearTime($statement, $piece, $n, $reading)
	{
		$sql = sprintf($statement, str_repeat($piece, $n));
		$start = microtime(true);
		if (isset($reading['outcome'])) {
			$this->assertSame($reading['outcome'], LeadProbe::outcome($sql));
		} else {
			$this->assertSame($reading['dirty'], LeadProbe::dirty($sql));
			$this->assertSame($reading['replayable'], LeadProbe::replayable($sql));
		}
		$this->assertLessThan(3.0, microtime(true) - $start, strlen($sql).' bytes');
	}

	/**
	* r10 F1: the end of a block comment or a line is looked up once for every place before it (a look from each place
	* would scan to the end again: 200k of them in 1.6 MB, about 160 GB of scanning)
	*/
	public function testTheEndOfACommentOrALineIsLookedUpOnce()
	{
		foreach ([["INSERT INTO t (v) VALUES ('%s */ x @')", 'into /* '], ["INSERT INTO t (v) VALUES ('%s\nx @')", 'into # '], ["CREATE TABLE t2 AS SELECT '%s */ x transaction' AS v", 'start /* ']] as [$statement, $piece]) {
			$sql = sprintf($statement, str_repeat($piece, 200000));
			$start = microtime(true);
			if ($piece === 'start /* ') {
				$this->assertSame('end', LeadProbe::outcome($sql));
			} else {
				$this->assertFalse(LeadProbe::dirty($sql));
			}
			$this->assertLessThan(3.0, microtime(true) - $start, $piece);
		}
	}

	/**
	* r10 F1: a place many walks reach is read once: 6000 INTOs whose comments all end at one star-slash, then 6000
	* comments before the @ (read again for each walk, 36 million steps)
	*/
	public function testAPlaceManyWalksReachIsReadOnce()
	{
		$sql = "INSERT INTO t (v) VALUES ('".str_repeat('into /* ', 6000).'*/'.str_repeat(' /**/', 6000)." x @')";
		$start = microtime(true);
		$this->assertFalse(LeadProbe::dirty($sql));
		$this->assertTrue(LeadProbe::dirty(str_replace(' x @', ' @', $sql)));
		$this->assertLessThan(3.0, microtime(true) - $start);
	}

	public static function sweepFamilies()
	{
		// [look, the reading]: r11a F1, many INTOs (or STARTs) whose comments all close at one star-slash, then a long
		// run after it (star-slashes, spaces, tabs and form feeds, the whitespace after a line comment's newline): each
		// walk skipped the whole run before it found the place already queued, k times its length; the same through
		// endsTransaction() for a CREATE TEMPORARY TABLE and implicitCommitOutcome(); and the entries of r11a F2 (one
		// case of the word only, words that are no entry). The statements: sweepStatement()
		return [
			'into /*, a star-slash run, @' => ['dirty', true],
			'into /*, a star-slash run, x @' => ['dirty', false],
			'into /*, a close, spaces, @' => ['replayable', false],
			'into /*, a close, tabs and form feeds, @' => ['dirty', true],
			'into #, a newline, spaces, @' => ['dirty', true],
			'into --, a newline, a star-slash run, @' => ['dirty', true],
			'into /*/, star-slash and space runs, @' => ['dirty', true],
			'into /*, a star-slash run, # then @ on the next line' => ['dirty', true],
			'start /*, a close, spaces, transaction' => ['startTransaction', true],
			'start #, a newline, spaces, transaction' => ['outcome', 'chain'],
			'CREATE TEMPORARY TABLE, start /*, a close, spaces, transaction' => ['ends', true],
			'into after a word character' => ['dirty', false],
			'into with no comment after it' => ['dirty', false],
		];
	}

	/**
	* @param string $family a key of sweepFamilies()
	* @param int $n
	* @return string
	*/
	public static function sweepStatement($family, $n)
	{
		$ss = str_repeat('*/', 4 * $n);
		$sp = str_repeat(' ', 8 * $n);
		switch ($family) {
			case 'into /*, a star-slash run, @':
				return "INSERT INTO t (v) VALUES ('".str_repeat('into /* ', $n).$ss."@')";
			case 'into /*, a star-slash run, x @':
				return "INSERT INTO t (v) VALUES ('".str_repeat('into /* ', $n).$ss." x @')";
			case 'into /*, a close, spaces, @':
				return "INSERT INTO t (v) VALUES ('".str_repeat('into /* ', $n).'*/'.$sp."@')";
			case 'into /*, a close, tabs and form feeds, @':
				return "INSERT INTO t (v) VALUES ('".str_repeat('into /* ', $n).'*/'.str_repeat("\t\x0B\f ", 2 * $n)."@')";
			case 'into #, a newline, spaces, @':
				return "SELECT '".str_repeat('into # ', $n)."'\n".$sp.'@x';
			case 'into --, a newline, a star-slash run, @':
				return "SELECT '".str_repeat('into -- ', $n)."'\n".$ss.'@x';
			case 'into /*/, star-slash and space runs, @':
				return "SELECT '".str_repeat('into /*/ ', $n).str_repeat('*/ ', 4 * $n)."@'";
			case 'into /*, a star-slash run, # then @ on the next line':
				return "SELECT '".str_repeat('into /* ', $n).$ss."# x\n@'";
			case 'start /*, a close, spaces, transaction':
				return "CREATE TABLE t2 AS SELECT '".str_repeat('start /* ', $n).'*/'.$sp."transaction' AS v";
			case 'start #, a newline, spaces, transaction':
				return "CREATE TABLE t2 AS SELECT '".str_repeat('start # ', $n)."'\n".$sp.'transaction';
			case 'CREATE TEMPORARY TABLE, start /*, a close, spaces, transaction':
				return "CREATE TEMPORARY TABLE tt AS SELECT '".str_repeat('start /* ', $n).'*/'.$sp."transaction'";
			case 'into after a word character':
				return "SELECT '".str_repeat('xinto /* ', $n)."*/ @'";
			case 'into with no comment after it':
				return "SELECT '@ ".str_repeat('into   x ', $n)."'";
		}
		throw new \LogicException($family);
	}

	/**
	* r11a F1/F2: the work of the sweep (places read and bytes looked at, wordGapReaches()) is bounded by the
	* statement's length and grows with it: four times the statement, at most about four times the work. The count is
	* the library's own (no clock): on e6006ea's sweep, the star-slash runs cost each walk the whole run (k x m)
	*
	* @dataProvider sweepFamilies
	* @param string $look
	* @param mixed $reading
	*/
	#[DataProvider('sweepFamilies')]
	public function testTheSweepsWorkGrowsWithTheStatementOnly($look, $reading)
	{
		$family = $this->dataName();
		$work = [];
		foreach ([1000, 4000] as $n) {
			$sql = static::sweepStatement($family, $n);
			$before = LeadProbe::work();
			$this->assertSame($reading, LeadProbe::$look($sql), 'the reading');
			$work[$n] = LeadProbe::work() - $before;
			$this->assertLessThanOrEqual(6 * strlen($sql), $work[$n], 'work per byte, n = '.$n);
		}
		$this->assertGreaterThan(0, $work[1000], 'the sweep ran');
		$this->assertLessThanOrEqual(4.5 * $work[1000], $work[4000], 'four times the statement');
	}

	/**
	* r11a F2: the words are found (strpos() on a lowercase copy, the word boundary, the run and the comment after it
	* checked by hand) exactly where e6006ea's entry patterns found them, on strings of the pieces that matter: both
	* cases and mixes, word bytes and others before the word, every whitespace byte, star-slashes, plain and executable
	* comments, '#', '--' with every kind of byte after it, at the end of the string too
	*/
	public function testTheWordsAreFoundWhereTheEntryPatternsFoundThem()
	{
		$patterns = [
			'into' => '~\binto(?:\s|\*/)*+(?=/\*|\#|--[\x00-\x20\x7F])~i',
			'start' => '~\bstart\s*+(?=/\*(?!M?!)|\#|--[\x00-\x20\x7F])~i',
		];
		$pieces = ['into', 'INTO', 'iNtO', 'start', 'START', 'StArT', 'x', 'Z', '_', '$', '1', '9', "\xC3\xA9", "\xA0", "\x00",
			' ', "\t", "\n", "\r", "\x0B", "\f", '*/', '*/*/', '*', '/', '/*', '/*!', '/*!80000', '/*M!', '/*m!', '/*M', '/*m', '/**/',
			'/*/', '#', '--', '-- ', "--\t", "--\x00", "--\x1F", "--\x20", "--\x21", "--\x7F", "--\x80", '-', '@', 'transaction'];
		mt_srand(12);
		$entries = 0;
		$wrong = [];
		for ($i = 0; $i < 30000; $i++) {
			$sql = '';
			for ($j = mt_rand(1, 16); $j > 0; $j--) {
				$sql .= $pieces[mt_rand(0, count($pieces) - 1)];
			}
			foreach ($patterns as $word => $pattern) {
				$expected = [];
				$from = 0;
				while (preg_match($pattern, $sql, $match, PREG_OFFSET_CAPTURE, $from) === 1) {
					$from = $match[0][1] + strlen($match[0][0]);
					$expected[] = $from;
				}
				$entries += count($expected);
				if (LeadProbe::entries($sql, $word, $word === 'into') !== $expected) {
					$wrong[] = $word.' '.json_encode($sql);
				}
			}
		}
		$this->assertSame([], array_slice($wrong, 0, 20));
		$this->assertGreaterThan(5000, $entries, 'the strings hold entries');
	}

	/**
	* r11a F2: the words are found without a search from each of them. PCRE's caseless search without its JIT looks for
	* both cases of the first letter to the end of the statement on every call when one of them is not there: a 2 MB
	* statement whose INTOs are all lowercase took 6.7 s that way, and takes well under a second. Run in a PHP of its
	* own with pcre.jit off (a pattern compiled with the JIT stays so in this process)
	*/
	public function testTheWordsAreFoundInLinearTimeWithoutPcreJit()
	{
		$script = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'
			.'$probe = new class extends \MyDb\Mysqli\Db { public function __construct($database = \'\', $user = \'\', $password = \'\', $host = \'localhost\', $query = \'\', $port = \'\') {} public static function into($s) { return static::intoUserVariable($s); } public static function work() { return static::$sweepWork; } };'
			.'$k = 131072; $sql = "INSERT INTO t (v) VALUES (\'".str_repeat("into /* ", $k)."*/".str_repeat(" ", 8 * $k)."@\')";'
			.'$t = microtime(true); $into = $probe::into($sql); $t = microtime(true) - $t;'
			.'echo json_encode(["jit" => ini_get("pcre.jit"), "into" => $into, "seconds" => $t, "work" => $probe::work(), "bytes" => strlen($sql)]);';
		$output = [];
		$status = -1;
		exec(escapeshellarg(PHP_BINARY).' -d pcre.jit=0 -r '.escapeshellarg($script).' 2>&1', $output, $status);
		$this->assertSame(0, $status, implode("\n", $output));
		$result = json_decode(implode('', $output), true);
		$this->assertIsArray($result, implode("\n", $output));
		$this->assertSame('0', $result['jit']);
		$this->assertTrue($result['into']);
		$this->assertLessThanOrEqual(6 * $result['bytes'], $result['work']);
		$this->assertLessThan(3.0, $result['seconds'], 'a 2 MB statement');
	}

	/**
	* r11a F1: the read-once case with a long run after the shared star-slash (6000 star-slashes: each of the 6000 walks
	* skipped them all, 36 million steps, about 11 s on e6006ea), and before the @ a second run (spaces)
	*/
	public function testARunAfterAStarSlashManyWalksShareIsSkippedOnce()
	{
		foreach ([str_repeat('*/', 6000), str_repeat(' ', 48000), str_repeat("\t", 48000)] as $run) {
			$sql = "INSERT INTO t (v) VALUES ('".str_repeat('into /* ', 6000).'*/'.$run." x @')";
			$before = LeadProbe::work();
			$this->assertFalse(LeadProbe::dirty($sql));
			$this->assertLessThanOrEqual(6 * strlen($sql), LeadProbe::work() - $before);
			$before = LeadProbe::work();
			$this->assertTrue(LeadProbe::dirty(str_replace(' x @', ' @', $sql)));
			$this->assertLessThanOrEqual(6 * strlen($sql), LeadProbe::work() - $before);
		}
	}

	/** r10 F1: the other looks of changesSessionState() and replayableStatement() still read as they did */
	public function testTheOtherSessionLooksStillRead()
	{
		foreach (["SELECT 'a' := 1", "SELECT GET_LOCK('k', 1)", "SELECT release_lock ('k')", 'SELECT RELEASE_ALL_LOCKS()', 'SELECT a INTO /* c */ @x FROM t'] as $sql) {
			$this->assertTrue(LeadProbe::dirty($sql), $sql);
			$this->assertFalse(LeadProbe::replayable($sql), $sql);
		}
		foreach (['SELECT LAST_INSERT_ID()', "SELECT 'lock' FROM t", "SELECT 'a@b' FROM t", "SELECT block FROM t WHERE e = 'x@y'", 'SELECT get_locks(1)'] as $sql) {
			$this->assertFalse(LeadProbe::dirty($sql), $sql);
			$this->assertTrue(LeadProbe::replayable($sql), $sql);
		}
		// (replayableStatement() never looked at LAST_INSERT_ID(expr): a replay sets it again)
		foreach (['SELECT LAST_INSERT_ID(5)', 'SELECT last_insert_id ( 5 )'] as $sql) {
			$this->assertTrue(LeadProbe::dirty($sql), $sql);
			$this->assertTrue(LeadProbe::replayable($sql), $sql);
		}
	}
}

/** the driver's statement readers, called on their own */
class LeadProbe extends \MyDb\Mysqli\Db
{
	public function __construct($database = '', $user = '', $password = '', $host = 'localhost', $query = '', $port = '')
	{
	}

	public static function lead($sql, &$unread = null)
	{
		return static::leadingKeywordSql($sql, $unread);
	}

	public static function may($sql)
	{
		return static::unreadLeadMay(static::leadingKeywordSql($sql));
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

	public static function into($sql)
	{
		return static::intoUserVariable($sql);
	}

	public static function startTransaction($sql)
	{
		return static::startTransactionAdjacent($sql);
	}

	/** every entry wordGapEntry() finds in $sql, in order (where the comment after each word begins) */
	public static function entries($sql, $word, $into)
	{
		$lower = strtolower($sql);
		$from = 0;
		$work = 0;
		$found = [];
		while (($entry = static::wordGapEntry($sql, $lower, $word, strlen($word), $into, $from, strlen($sql), strlen($sql), $work)) !== false) {
			$found[] = $entry;
		}
		return $found;
	}

	/** the work wordGapReaches() has done in this process (places read, bytes looked at) */
	public static function work()
	{
		return static::$sweepWork;
	}
}
