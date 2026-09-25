<?php

namespace MyDb\Tests;

use MyDb\Generic;
use PHPUnit\Framework\TestCase;

/**
 * A Generic that records what it would have logged instead of writing it.
 */
class RecordingGeneric extends Generic
{
    public $logged = [];

    public function connect()
    {
        return true;
    }

    public function query($query, $line = '', $file = '')
    {
        return true;
    }

    public function log($message, $line = '', $file = '', $level = 'info')
    {
        $this->logged[] = $message;
    }

    public function authenticate($login, $passwd)
    {
        $this->logBackTrace('failed');
    }

    public function lookupThing($name, $count)
    {
        $this->logBackTrace('failed');
    }
}

/**
 * logBackTrace() runs on every SQL error and its output is written to the
 * log table and emailed, so it must not carry passwords from the request or
 * from the arguments of the function that failed.
 */
class LogBackTraceRedactionTest extends TestCase
{
    private $savedPost;
    private $savedGet;
    private $savedMethod;

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
        $this->savedGet = $_GET;
        $this->savedMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        $_GET = $this->savedGet;
        if ($this->savedMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->savedMethod;
        }
    }

    public function testFallbackRedactsSecretKeysRecursively()
    {
        $in = [
            'login' => 'user@example.com',
            'passwd' => 'Hunter2!',
            'rootpass' => 'Root123',
            'pwd' => 'x1',
            'client_secret' => 's3',
            'csrf_token' => 't0k',
            'cc_ccv2' => '123',
            'cvv' => '999',
            'api_key' => 'k',
            'apikey' => 'k2',
            'cc' => '4111111111111111',
            'x_card_num' => '4111111111111111',
            'empty_password' => '',
            'nested' => ['new_password' => 'Deep1', 'hostname' => 'h.example.com'],
            'hostname' => 'vps.example.com',
            'cc_exp' => '12/30',
        ];
        $out = Generic::redactSecretKeys($in);
        foreach (['passwd', 'rootpass', 'pwd', 'client_secret', 'csrf_token', 'cc_ccv2', 'cvv', 'api_key', 'apikey', 'cc', 'x_card_num'] as $key) {
            $this->assertSame('[redacted]', $out[$key], $key);
        }
        $this->assertSame('', $out['empty_password']);
        $this->assertSame('[redacted]', $out['nested']['new_password']);
        $this->assertSame('h.example.com', $out['nested']['hostname']);
        $this->assertSame('user@example.com', $out['login']);
        $this->assertSame('vps.example.com', $out['hostname']);
        $this->assertSame('12/30', $out['cc_exp']);
    }

    public function testRequestDataIsRedactedInBothPostCopies()
    {
        $_POST = ['login' => 'user@example.com', 'passwd' => 'Hunter2!'];
        $_GET = ['token' => 'resettoken123', 'page' => '2'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $db = new RecordingGeneric('db', 'u', 'p', 'localhost');
        $db->lookupThing('x', 1);
        $all = implode("\n", $db->logged);
        $this->assertStringNotContainsString('Hunter2!', $all);
        $this->assertStringNotContainsString('resettoken123', $all);
        $this->assertStringContainsString('user@example.com', $all);
        $this->assertSame(2, substr_count($all, 'POST='.json_encode(['login' => 'user@example.com', 'passwd' => '[redacted]'])) + substr_count($all, 'POST="'.json_encode(['login' => 'user@example.com', 'passwd' => '[redacted]']).'"'));
        // the superglobals themselves are not modified
        $this->assertSame('Hunter2!', $_POST['passwd']);
    }

    public function testArgumentsOfCredentialFunctionsAreHidden()
    {
        $_POST = [];
        $_GET = [];
        $db = new RecordingGeneric('db', 'u', 'p', 'localhost');
        $db->authenticate('user@example.com', 'Hunter2!');
        $all = implode("\n", $db->logged);
        $this->assertStringNotContainsString('Hunter2!', $all);
        $this->assertStringNotContainsString('user@example.com', $all);
        $this->assertStringContainsString('authenticate([arg], [arg])', $all);
    }

    public function testArgumentsOfOtherFunctionsAreStillLogged()
    {
        $_POST = [];
        $_GET = [];
        $db = new RecordingGeneric('db', 'u', 'p', 'localhost');
        $db->lookupThing('widget', 3);
        $all = implode("\n", $db->logged);
        $this->assertStringContainsString('lookupThing("widget", 3)', $all);
    }
}
