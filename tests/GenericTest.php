<?php

namespace MyDb\Tests;

use MyDb\Generic;
use MyDb\Mysqli\Db;
use PHPUnit\Framework\TestCase;

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

    public function testEmailError()
    {
        ob_start();
        $this->db->emailError('SELECT * FROM test_table', 'Test error', __LINE__, __FILE__);
        $output = ob_get_clean();
        $this->assertNotEmpty($output);
    }

    public function testHaltmsg()
    {
        ob_start();
        $this->db->haltmsg('Test error', __LINE__, __FILE__);
        $output = ob_get_clean();
        $this->assertNotEmpty($output);
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
