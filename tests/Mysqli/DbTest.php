<?php

namespace MyDb\Tests\Mysqli;

use MyDb\Mysqli\Db;
use PHPUnit\Framework\TestCase;

/**
 * The MySQLi driver against the real server the mysql tests suite runs with.
 *
 * (This file used to drive a MockDb class that never existed anywhere in the repo.
 * The deep statement/transaction semantics — retries, replays, shared links — are
 * covered script-by-script by TransactionRetryTest/TransactionReplayTest/
 * TransactionSafetyTest on fake mysqli; this is the integration basics on a live
 * connection: constructor, connect, escaping, result navigation, the queryReturn
 * shapes, the transaction wrapper, and tableNames. Each test runs inside a
 * transaction that tearDown rolls back, the same isolation the Mdb2/Pdo tests use.)
 */
class DbTest extends TestCase
{
    /**
     * @var Db
     */
    protected $db;

    protected function setUp(): void
    {
        $this->db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));
        $this->db->transactionBegin();
    }

    protected function tearDown(): void
    {
        $this->db->transactionAbort();
    }

    public function testConstructorStoresTheConnectionParameters()
    {
        $db = new Db('test_db', 'test_user', 'test_password', 'localhost', '', '3306');
        $this->assertSame('test_db', $db->database);
        $this->assertSame('localhost', $db->host);
        $this->assertSame('test_user', $db->user);
        $this->assertSame('test_password', $db->password);
        $this->assertSame('3306', $db->port);
        $this->assertSame(0, $db->connectionAttempt);
        $this->assertSame('mysqli', $db->type);
    }

    public function testConnectReturnsAUsableHandle()
    {
        $this->assertNotFalse($this->db->connect());
        $this->assertTrue($this->db->linkIsUsable());
        $this->assertInstanceOf(\mysqli::class, $this->db->linkId);
    }

    public function testDisconnectLetsGoOfTheLink()
    {
        $db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));
        $db->connect();
        $this->assertTrue($db->linkIsUsable());
        $db->disconnect();
        $this->assertSame(0, $db->linkId);
        $this->assertFalse($db->linkIsUsable());
    }

    public function testRealEscapeEscapesQuotesThroughTheLiveLink()
    {
        $this->assertSame('plain', $this->db->real_escape('plain'));
        $this->assertStringContainsString("\\'", $this->db->real_escape("O'Reilly"));
        $this->assertStringContainsString('\\"', $this->db->real_escape('say "hi"'));
    }

    public function testQueryAndNextRecordReadTheFixtureTable()
    {
        $this->assertNotFalse($this->db->query('select st_id, st_name from service_types order by st_id'));
        $this->assertGreaterThan(0, $this->db->num_rows());
        $this->assertTrue((bool) $this->db->next_record());
        $this->assertArrayHasKey('st_name', $this->db->Record);
        $this->assertSame($this->db->Record['st_id'], $this->db->f('st_id'));
        $this->assertSame(2, $this->db->num_fields());
    }

    public function testSeekRewindsTheResult()
    {
        $this->db->query('select st_id, st_name from service_types order by st_id');
        $this->assertTrue((bool) $this->db->next_record());
        $first = $this->db->Record['st_id'];
        $this->assertTrue((bool) $this->db->seek(0));
        $this->assertTrue((bool) $this->db->next_record());
        $this->assertSame($first, $this->db->Record['st_id']);
    }

    public function testQueryReturnShapesSingleRowAndNoRow()
    {
        $row = $this->db->queryReturn('select 7 as lucky');
        $this->assertIsArray($row);
        $this->assertSame('7', $row['lucky']);
        $this->assertFalse($this->db->queryReturn('select st_id from service_types where st_id = -1'));
    }

    public function testQrIsQueryReturn()
    {
        $rows = $this->db->qr('select st_id from service_types order by st_id limit 2');
        $this->assertIsArray($rows);
        $this->assertArrayHasKey('st_id', $rows[0]);
    }

    public function testFetchObjectReadsTheCurrentRow()
    {
        $this->db->query('select st_id, st_name from service_types order by st_id');
        $object = $this->db->fetchObject();
        $this->assertIsObject($object);
        $this->assertObjectHasProperty('st_name', $object);
    }

    public function testInsertAffectedRowsAndLastInsertId()
    {
        $this->assertNotFalse($this->db->query("insert into service_types values (NULL, 'CI Integration', 250, 'ci')"));
        $this->assertSame(1, $this->db->affectedRows());
        $id = $this->db->getLastInsertId('service_types', 'st_id');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, (int) $this->db->queryReturn("select st_id from service_types where st_name = 'CI Integration'")['st_id']);
    }

    public function testLimitQueryAppendsTheLimit()
    {
        $this->assertNotFalse($this->db->limitQuery('select * from service_types order by st_id', 1));
        $this->assertSame(1, $this->db->num_rows());
    }

    public function testPrepareReturnsAStatement()
    {
        $statement = $this->db->prepare('select st_id, st_name from service_types where st_name = ?');
        $this->assertNotFalse($statement);
        $this->assertInstanceOf(\mysqli_stmt::class, $statement);
    }

    public function testTableNamesIncludeTheFixture()
    {
        $names = array_column($this->db->tableNames(), 'table_name');
        $this->assertContains('service_types', $names);
    }

    public function testTransactionApiTracksTheOpenTransaction()
    {
        $db = new Db(getenv('DBNAME'), getenv('DBUSER'), getenv('DBPASS'), getenv('DBHOST'));
        $this->assertFalse($db->inTransaction());
        $this->assertTrue($db->transactionBegin());
        $this->assertTrue($db->inTransaction());
        $this->assertTrue($db->query('select 1') !== false);
        $this->assertTrue($db->transactionCommit());
        $this->assertFalse($db->inTransaction());
        $this->assertTrue($db->transactionAbort());
        $db->transactionBegin();
        $db->query("insert into service_types values (NULL, 'CI Rolled Back', 250, 'ci')");
        $this->assertTrue($db->transactionAbort());
        // the 0-row contract lives in queryReturn()/qr(); query() returns the (truthy, empty) result object
        $this->assertFalse($db->queryReturn("select st_id from service_types where st_name = 'CI Rolled Back'"));
    }
}
