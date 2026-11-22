<?php

namespace MyDb\Tests\Mysqli;

use MyDb\Mysqli\Db as MysqliDb;
use PHPUnit\Framework\TestCase;

/**
* B180: connect() used to carry two guards at the consecutive-failure limit - a bare exit (status 0,
* no mail, no message) at maxConnectErrors - 1 and a halt + return 0 at maxConnectErrors that no
* counter value could ever reach. A process running out of connects died silently at status 0, so
* cron and queue supervisors recorded a DB outage as success.
*
* The cure collapses them to one: at the trip point connect() logs, runs halt() and leaves with
* exit(1). The trip point deliberately stays where the old backstop had it - the (maxConnectErrors -
* 1)-th consecutive failure, the 4th with the documented default of 5 - so the timing every outage
* path (and replayReset()'s headroom arithmetic, pinned in TransactionReplayTest) has lived with is
* unchanged; what changed is that the failure is now loud and its status says failure.
*
* Every scenario can end the child process, so all three run as separate PHP processes whose merged
* output and exit status the parent asserts (the pattern of GenericTest::testHaltUnderYesEndsTheSession).
* The child never needs composer: it registers its own PSR-4 loader for MyDb\ (the package's autoload
* mapping) and declares its own \MyAdmin\Mail marker, so a reached halt() is observable as a MAILED
* line without any real mailer being loadable, and the stub-link scenario loads
* tests/Mysqli/fake_mysqli.php before the driver first runs, exactly as the isolated suites do.
*/
class ConnectLimitTest extends TestCase
{
	/**
	* A child script skeleton: a \MyAdmin\Mail marker class that echoes what would have been mailed
	* (declared before anything can load a real one, so no SMTP is reachable), a composer-free PSR-4
	* loader for MyDb\ -> src/, optionally the scripted mysqli stand-in required before the driver
	* first runs (fake_mysqli.php's documented rule), then the scenario body. Brace-namespaced, so
	* the global code rides an explicit namespace { } block.
	*/
	private function childScript(string $body, bool $withFake): string
	{
		$src = var_export(dirname(__DIR__, 2) . '/src/', true);
		$fake = $withFake ? ' require_once ' . var_export(__DIR__ . '/fake_mysqli.php', true) . ';' : '';
		return sprintf(<<<'CHILD'
			namespace MyAdmin { class Mail { public static function failsafeMail(...$args) { echo "MAILED:" . (string) (isset($args[0]) ? $args[0] : "") . PHP_EOL; return true; } public function adminMail(...$args) { return self::failsafeMail(...$args); } } }
			namespace { spl_autoload_register(function ($class) { if (strncmp($class, "MyDb\\", 5) === 0) { require %s . str_replace("\\", "/", substr($class, 5)) . '.php'; } });%s
			%s }
			CHILD, $src, $fake, $body);
	}

	/** runs a child script and returns [exit status, merged stdout+stderr] */
	private function childResult(string $script): array
	{
		$output = [];
		$status = -1;
		exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $status);
		return [$status, implode("\n", $output)];
	}

	/**
	* The limit itself, under 'report' (the mode that mails outages): reaching it logs the attempt,
	* mails through halt(), and ends the process with status 1. Before B180 the same call exited
	* status 0 with nothing but the log line - no mail, no message, a silent success to a supervisor.
	*/
	public function testTheLimitHaltsWithMailAndNonZeroStatus(): void
	{
		$db = new MysqliDb();
		$trip = $db->maxConnectErrors - 1;
		$body = ' $db = new \MyDb\Mysqli\Db();'
			. ' $db->haltOnError = "report";'
			. ' $db->connectionAttempt = ' . ($trip - 1) . ';'
			. ' $db->connect();'
			. ' echo "LIVE-ON" . PHP_EOL; exit(6);';
		[$status, $output] = $this->childResult($this->childScript($body, false));
		$this->assertSame(1, $status, 'the limit ends the process with a status that says failure (was: bare exit, status 0); child said: ' . $output);
		$this->assertStringNotContainsString('LIVE-ON', $output, 'connect() did not hand back a link');
		$this->assertStringContainsString("MySQLi Connection Attempt #{$trip}/{$db->maxConnectErrors}", $output, 'the attempt is still logged');
		$this->assertStringContainsString('MAILED:DB Error connect(', $output, 'halt() runs before the process ends (was: unreachable)');
	}

	/**
	* The same limit under haltOnError 'no' - the mode query()'s inner retry loop uses, the exact
	* shape of the finding's repro ("cron and queue supervisors see exit 0 and record success").
	* 'no' keeps its contract (no mail, the caller-visible false), but the process now leaves at 1.
	*/
	public function testTheLimitExitsNonZeroUnderHaltNoWithoutMailing(): void
	{
		$db = new MysqliDb();
		$trip = $db->maxConnectErrors - 1;
		$body = ' $db = new \MyDb\Mysqli\Db();'
			. ' $db->haltOnError = "no";'
			. ' $db->connectionAttempt = ' . ($trip - 1) . ';'
			. ' $db->connect();'
			. ' echo "LIVE-ON" . PHP_EOL; exit(6);';
		[$status, $output] = $this->childResult($this->childScript($body, false));
		$this->assertSame(1, $status, 'a supervisor sees failure where it used to see success');
		$this->assertStringNotContainsString('LIVE-ON', $output);
		$this->assertStringNotContainsString('MAILED', $output, "'no' mode keeps its silent contract: halt() returns true without mailing");
	}

	/**
	* One failure below the limit must survive exactly as before (the trip point did not move): on the
	* error-reporting-off leg the finding's repro runs on (mysqli_report off, real_connect() returns
	* false - set as the scripted stand-in's documented default stands with its strict throw), connect()
	* halts - mailing under 'report' - and still returns 0 with the counter one short of the trip,
	* leaving the caller's error handling in charge.
	*/
	public function testOneBelowTheLimitStillReturnsAfterHaltingInsteadOfExiting(): void
	{
		$db = new MysqliDb();
		$survived = $db->maxConnectErrors - 2;
		$body = ' \MyDb\Tests\Mysqli\FakeServer::$strict = false;'
			. ' \MyDb\Tests\Mysqli\FakeServer::$connectFails = 1;'
			. ' $db = new \MyDb\Mysqli\Db();'
			. ' $db->haltOnError = "report";'
			. ' $db->connectionAttempt = ' . ($survived - 1) . ';'
			. ' $r = $db->connect();'
			. ' echo "SURVIVED:" . $db->connectionAttempt . ":" . (is_object($r) ? "link" : var_export($r, true)) . PHP_EOL; exit(7);';
		[$status, $output] = $this->childResult($this->childScript($body, true));
		$this->assertSame(7, $status, 'the child ran to its own marker: the limit did not trip early; child said: ' . $output);
		$this->assertStringContainsString('SURVIVED:' . $survived . ':0', $output, 'one failure short of the limit: halt, return 0, process alive');
		$this->assertSame(1, substr_count($output, 'MAILED:DB Error connect('), 'the failed connect() mailed once');
	}
}
