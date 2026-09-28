<?php

namespace MyDb\Tests;

use MyDb\Generic;
use PHPUnit\Framework\TestCase;

/**
 * The SQL error report (email subject/body and error_log lines) must never
 * carry passwords from the query, the driver error, $_REQUEST or $_SERVER.
 */
class ErrorReportRedactionTest extends TestCase
{
    public function testSecretQueryLiteralsAreRedacted()
    {
        $q = "update accounts set account_passwd=md5('s3cr\\'et') where account_lid='a@b.c'";
        $out = Generic::redactQueryForLog($q);
        $this->assertStringNotContainsString('s3cr', $out);
        $this->assertStringNotContainsString('a@b.c', $out);
        $this->assertSame("update accounts set account_passwd=md5('[redacted]') where account_lid='[redacted]'", $out);
    }

    public function testDoubleQuotedInsertValuesAreRedacted()
    {
        $out = Generic::redactQueryForLog('insert into vps (vps_hostname, vps_rootpass) values ("h", "pw")');
        $this->assertSame('insert into vps (vps_hostname, vps_rootpass) values ("[redacted]", "[redacted]")', $out);
    }

    public function testQueriesWithoutSecretColumnsAreUnchanged()
    {
        $q = "select * from vps where vps_id='12'";
        $this->assertSame($q, Generic::redactQueryForLog($q));
    }

    public function testDriverErrorUsesQueryAsContextAndHandlesTruncation()
    {
        $err = "You have an error in your SQL syntax near 'topsecret";
        $out = Generic::redactQueryForLog($err, "update x set pass='topsecret");
        $this->assertStringNotContainsString('topsecret', $out);
        $closed = Generic::redactQueryForLog("Duplicate entry 'hunter2' for key 'x'", "insert into t (api_key) values ('hunter2')");
        $this->assertSame("Duplicate entry '[redacted]' for key '[redacted]'", $closed);
    }

    public function testServerReportIsAnAllowlistWithRedactedQueryString()
    {
        $out = Generic::serverForReport([
            'PHP_AUTH_PW' => 'basicpw',
            'HTTP_AUTHORIZATION' => 'Bearer tok',
            'HTTP_COOKIE' => 'sessionid=abc',
            'DB_PASS' => 'envsecret',
            'HTTP_HOST' => 'my.interserver.net',
            'REQUEST_URI' => '/login.php?password=zz&id=3',
        ]);
        $this->assertSame(['HTTP_HOST', 'REQUEST_URI'], array_keys($out));
        $this->assertStringNotContainsString('zz', $out['REQUEST_URI']);
        $this->assertStringContainsString('id=3', $out['REQUEST_URI']);
        $flat = json_encode($out);
        foreach (['basicpw', 'tok', 'abc', 'envsecret'] as $secret) {
            $this->assertStringNotContainsString($secret, $flat);
        }
    }

    public function testUrlWithoutSecretsIsUntouched()
    {
        $this->assertSame('/view.php?id=3&x=y', Generic::redactUrlQuery('/view.php?id=3&x=y'));
        $this->assertSame('/plain/path', Generic::redactUrlQuery('/plain/path'));
    }
}
