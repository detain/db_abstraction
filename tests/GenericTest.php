<?php

namespace MyDb\Tests;

use MyDb\Generic;
use MyDb\Mysqli\Db;
use PHPUnit\Framework\TestCase;

// Under the core application's autoloader emailError()/haltmsg() would load the real \MyAdmin\Mail and
// send email; declare the recording double first (it never replaces a class that is already loaded).
require_once __DIR__.'/MailDouble.php';

// Create a concrete subclass of Generic for testing
class TestGeneric extends Generic
{
    public function connect()
    {
        // Mock connection logic for testing
        return true;
    }

    public function query($query, $line = '', $file = '')
    {
        // Mock query execution logic for testing
        $this->Record = ['test_field' => 'test_value'];
        return $this->Record;
    }

    public function error()
    {
        // Mock error retrieval logic for testing
        return $this->Error;
    }

    public function errno()
    {
        // Mock error number retrieval logic for testing
        return $this->Errno;
    }
}

class TestGenericTest extends TestCase
{
    protected $db;

    protected function setUp(): void
    {
        $this->db = new TestGeneric('test_db', 'test_user', 'test_password', 'localhost', '', '3306');
    }

    public function testConstructor()
    {
        $this->assertEquals('test_db', $this->db->database);
        $this->assertEquals('test_user', $this->db->user);
        $this->assertEquals('test_password', $this->db->password);
        $this->assertEquals('localhost', $this->db->host);
        $this->assertEquals('3306', $this->db->port);
        $this->assertEquals(0, $this->db->connectionAttempt);
    }

    public function testRealEscape()
    {
        $this->assertEquals("O'Reilly", $this->db->real_escape("O'Reilly"));
    }

    public function testEscape()
    {
        $this->assertEquals("O\\'Reilly", $this->db->escape("O'Reilly"));
    }

    public function testDbAddslashes()
    {
        $this->assertEquals("O\'Reilly", $this->db->dbAddslashes("O'Reilly"));
    }

    public function testToTimestamp()
    {
        $this->assertEquals('2023-10-05 12:34:56', $this->db->toTimestamp('2023-10-05 12:34:56'));
        $this->assertEquals('2023-10-05 01:01:01', $this->db->toTimestamp('20231005010101'));
        $this->assertEquals('2023-10-05 01:01:01', $this->db->toTimestamp('20231005'));
        $this->assertEquals('1696490096', $this->db->toTimestamp('1696490096'));
    }

    public function testFromTimestamp()
    {
        $this->assertEquals(1696490096, $this->db->fromTimestamp('2023-10-05 12:34:56'));
        $this->assertEquals(1696490096, $this->db->fromTimestamp('20231005123456'));
        $this->assertEquals(1696486861, $this->db->fromTimestamp('20231005'));
        $this->assertEquals(1696490096, $this->db->fromTimestamp('1696490096'));
    }

    public function testLimitQuery()
    {
        $result = $this->db->limitQuery('SELECT * FROM test_table', 10, 5);
        $this->assertEquals(['test_field' => 'test_value'], $result);
    }

    public function testQr()
    {
        $result = $this->db->qr('SELECT * FROM test_table');
        $this->assertEquals(['test_field' => 'test_value'], $result);
    }

    public function testF()
    {
        $this->db->Record = ['test_field' => 'test_value'];
        $this->assertEquals('test_value', $this->db->f('test_field'));
    }

    public function testHalt()
    {
        if (!$this->mailIsDouble()) {
            $this->markTestSkipped('the real \\MyAdmin\\Mail is loaded; refusing to risk sending email');
        }
        $this->db->haltOnError = 'no';
        $this->assertTrue($this->db->halt('Test error'));

        $this->db->haltOnError = 'report';
        $this->assertTrue($this->db->halt('Test error'));

        $this->db->haltOnError = 'yes';
        $this->expectOutputString('<p><b>Session halted.</b>');
        $this->db->halt('Test error');
    }

    public function testLogBackTrace()
    {
        ob_start();
        $this->db->logBackTrace('Test error');
        $output = ob_get_clean();
        $this->assertNotEmpty($output);
    }

    /**
     * Whether \MyAdmin\Mail is the recording double. When a real one was loaded first, the test that
     * would reach it is skipped rather than allowed to send email.
     */
    private function mailIsDouble()
    {
        return class_exists('\\MyAdmin\\Mail', false) && defined('\\MyAdmin\\Mail::IS_TEST_DOUBLE');
    }

    public function testEmailError()
    {
        if (!$this->mailIsDouble()) {
            $this->markTestSkipped('the real \\MyAdmin\\Mail is loaded; refusing to risk sending email');
        }
        \MyAdmin\Mail::$sent = [];
        ob_start();
        $this->db->emailError('SELECT * FROM test_table', 'Test error', __LINE__, __FILE__);
        $output = ob_get_clean();
        // the error is reported through the mailer (the double), ending in haltmsg()'s failsafeMail
        $calls = array_column(\MyAdmin\Mail::$sent, 0);
        $this->assertContains('failsafeMail', $calls);
        $subjects = array_column(\MyAdmin\Mail::$sent, 1);
        $this->assertNotEmpty(array_filter($subjects, static function ($subject) {
            return strpos($subject, 'Invalid SQL: SELECT * FROM test_table') !== false;
        }));
        $this->assertSame('', $output);
    }

    public function testHaltmsg()
    {
        if (!$this->mailIsDouble()) {
            $this->markTestSkipped('the real \\MyAdmin\\Mail is loaded; refusing to risk sending email');
        }
        \MyAdmin\Mail::$sent = [];
        ob_start();
        $this->db->haltmsg('Test error', __LINE__, __FILE__);
        $output = ob_get_clean();
        $this->assertSame('', $output);
        $this->assertCount(1, \MyAdmin\Mail::$sent);
        $this->assertSame('failsafeMail', \MyAdmin\Mail::$sent[0][0]);
        $this->assertStringContainsString('DB Error Test error', \MyAdmin\Mail::$sent[0][1]);
    }

    public function testIndexNames()
    {
        $this->assertEquals([], $this->db->indexNames());
    }

    public function testAddLog()
    {
        $this->db->addLog('SELECT * FROM test_table', 0.001, __LINE__, __FILE__);
        $this->assertCount(1, $this->db->getLog());
    }

    public function testGetLog()
    {
        $this->db->addLog('SELECT * FROM test_table', 0.001, __LINE__, __FILE__);
        $log = $this->db->getLog();
        $this->assertCount(1, $log);
        $this->assertArrayHasKey('statement', $log[0]);
        $this->assertArrayHasKey('time', $log[0]);
        $this->assertArrayHasKey('line', $log[0]);
        $this->assertArrayHasKey('file', $log[0]);
    }
}
