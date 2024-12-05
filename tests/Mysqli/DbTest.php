<?php

namespace MyDb\Tests\Mysqli;

use MyDb\Mysqli\Db;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class DbTest extends TestCase
{
    protected $db;

    protected function setUp(): void
    {
        $this->db = new MockDb();
        $this->db->host = 'localhost';
        $this->db->user = 'test_user';
        $this->db->password = 'test_password';
        $this->db->database = 'test_db';
        $this->db->port = '3306';
    }

    public function testConstructor()
    {
        $this->assertEquals('localhost', $this->db->host);
        $this->assertEquals('test_user', $this->db->user);
        $this->assertEquals('test_password', $this->db->password);
        $this->assertEquals('test_db', $this->db->database);
        $this->assertEquals('3306', $this->db->port);
        $this->assertEquals(0, $this->db->connectionAttempt);
    }

    public function testConnect()
    {
        $this->db->setMockMethodReturn('mysqli_init', $this->db);
        $this->db->setMockMethodReturn('mysqli_real_connect', true);
        $this->db->setMockMethodReturn('mysqli_select_db', true);
        $this->db->setMockMethodReturn('mysqli_set_charset', true);

        $this->assertTrue($this->db->connect());
        $this->assertInstanceOf(MockDb::class, $this->db->linkId);
    }

    public function testDisconnect()
    {
        $this->db->linkId = $this->db;
        $this->db->setMockMethodReturn('mysqli_close', true);

        $this->assertTrue($this->db->disconnect());
        $this->assertEquals(0, $this->db->linkId);
    }

    public function testRealEscape()
    {
        $this->db->linkId = $this->db;
        $this->db->setMockMethodReturn('mysqli_real_escape_string', "O\\'Reilly");

        $this->assertEquals("O\\'Reilly", $this->db->real_escape("O'Reilly"));
    }

    public function testFree()
    {
        $this->db->queryId = $this->db;
        $this->db->setMockMethodReturn('mysqli_free_result', true);

        $this->db->free();
        $this->assertEquals(0, $this->db->queryId);
    }

    public function testQueryReturn()
    {
        $this->db->setMockMethodReturn('mysqli_query', $this->db);
        $this->db->setMockMethodReturn('mysqli_fetch_array', ['test_field' => 'test_value']);
        $this->db->setMockMethodReturn('mysqli_num_rows', 1);

        $result = $this->db->queryReturn('SELECT * FROM test_table');
        $this->assertEquals(['test_field' => 'test_value'], $result);
    }

    public function testPrepare()
    {
        $this->db->setMockMethodReturn('mysqli_prepare', $this->db);

        $result = $this->db->prepare('SELECT * FROM test_table');
        $this->assertInstanceOf(MockDb::class, $result);
    }

    public function testQuery()
    {
        $this->db->setMockMethodReturn('mysqli_init', $this->db);
        $this->db->setMockMethodReturn('mysqli_real_connect', true);
        $this->db->setMockMethodReturn('mysqli_query', $this->db);
        $this->db->setMockMethodReturn('mysqli_num_rows', 1);

        $this->db->query('SELECT * FROM test_table');
        $this->assertInstanceOf(MockDb::class, $this->db->queryId);
    }

    public function testFetchObject()
    {
        $this->db->setMockMethodReturn('mysqli_fetch_object', (object) ['test_field' => 'test_value']);

        $result = $this->db->fetchObject();
        $this->assertEquals((object) ['test_field' => 'test_value'], $result);
    }

    public function testNextRecord()
    {
        $this->db->setMockMethodReturn('mysqli_fetch_array', ['test_field' => 'test_value']);
        $this->db->setMockMethodReturn('mysqli_num_rows', 1);

        $this->assertTrue($this->db->next_record());
        $this->assertEquals(['test_field' => 'test_value'], $this->db->Record);
    }

    public function testSeek()
    {
        $this->db->setMockMethodReturn('mysqli_data_seek', true);

        $this->assertTrue($this->db->seek(0));
        $this->assertEquals(0, $this->db->Row);
    }

    public function testTransactionBegin()
    {
        $this->db->setMockMethodReturn('mysqli_begin_transaction', true);

        $this->assertTrue($this->db->transactionBegin());
    }

    public function testTransactionCommit()
    {
        $this->db->setMockMethodReturn('mysqli_commit', true);

        $this->assertTrue($this->db->transactionCommit());
    }

    public function testTransactionAbort()
    {
        $this->db->setMockMethodReturn('mysqli_rollback', true);

        $this->assertTrue($this->db->transactionAbort());
    }

    public function testGetLastInsertId()
    {
        $this->db->setMockMethodReturn('mysqli_insert_id', 123);

        $this->assertEquals(123, $this->db->getLastInsertId('test_table', 'test_field'));
    }

    public function testLock()
    {
        $this->db->setMockMethodReturn('mysqli_query', true);

        $this->assertTrue($this->db->lock('test_table', 'write'));
    }

    public function testUnlock()
    {
        $this->db->setMockMethodReturn('mysqli_query', true);

        $this->assertTrue($this->db->unlock());
    }

    public function testAffectedRows()
    {
        $this->db->setMockMethodReturn('mysqli_affected_rows', 1);

        $this->assertEquals(1, $this->db->affectedRows());
    }

    public function testNumRows()
    {
        $this->db->setMockMethodReturn('mysqli_num_rows', 1);

        $this->assertEquals(1, $this->db->num_rows());
    }

    public function testNumFields()
    {
        $this->db->setMockMethodReturn('mysqli_num_fields', 1);

        $this->assertEquals(1, $this->db->num_fields());
    }

    public function testTableNames()
    {
        $this->db->setMockMethodReturn('mysqli_query', $this->db);
        $this->db->setMockMethodReturn('fetch_row', ['test_table']);
        $this->db->setMockMethodReturn('mysqli_num_rows', 1);

        $result = $this->db->tableNames();
        $this->assertEquals([['table_name' => 'test_table', 'tablespace_name' => 'test_db', 'database' => 'test_db']], $result);
    }
}
