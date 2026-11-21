<?php
/**
 * Generic SQL Driver Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2025
 * @package MyAdmin
 * @category SQL
 */

namespace MyDb;

/**
 * Class Generic
 */
abstract class Generic
{
    /* public: connection parameters */
    public $host = 'localhost';
    public $database = '';
    public $user = '';
    public $password = '';
    public $port = '';

    /* public: configuration parameters */
    public $autoStripslashes = false;
    public $Debug = 0; // Set to 1 for debugging messages.
    public $haltOnError = 'yes'; // "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)

    public $maxConnectErrors = 5;
    public $connectionAttempt = 0;
    public $maxMatches = 10000000;

    public $Type = 'mysql';

    /**
     * @var int
     */
    public $autoFree = 0; // Set to 1 for automatic mysql_free_result()

    /* public: result array and current row number */
    public $Record = [];
    public $Row;

    /* public: current error number and error text */
    public $Errno = 0;
    public $Error = '';

    /* public: this is an api revision, not a CVS revision. */
    public $type = 'generic';

    /**
     * @var int|object
     */
    public $linkId = 0;
    public $queryId = 0;

    public $characterSet = 'utf8mb4';
    public $collation = 'utf8mb4_unicode_ci';

    /**
     * Logged queries.
     * @var array
     */
    protected $log = [];

    /**
     * Whether this connection currently has an open transaction.
     *
     * Tracked so query()'s retry path can refuse to close the connection out
     * from under an open transaction. Closing it makes the server roll the
     * transaction back, and since the retry then transparently reconnects and
     * carries on, the remaining statements commit individually -- writes meant
     * to be atomic get applied piecemeal with no error raised anywhere.
     *
     * Maintained both by transactionBegin()/Commit()/Abort() and by sniffing
     * raw statements in query(), because in practice callers open transactions
     * with `$db->query('start transaction')` rather than through the API.
     *
     * @var bool
     */
    protected $inTransaction = false;

    /**
     * Set by the MySQLi driver when a statement inside a transaction gave up
     * (the server rolled the transaction back); a COMMIT is refused while set.
     *
     * @var bool
     */
    protected $transactionLost = false;

    /**
     * The error number the transaction was lost with (0 when not recorded), reported when a COMMIT is refused.
     *
     * @var int
     */
    protected $transactionLostErrno = 0;

    /**
     * The error message the transaction was lost with ('' when not recorded).
     *
     * @var string
     */
    protected $transactionLostError = '';

    /**
     * Whether a transaction conflict that could not be recovered is reported (MySQLi driver).
     *
     * A statement or COMMIT inside a transaction that the server rolled back with a deadlock (1213) or a
     * Group Replication certification failure (3101) is replayed by the MySQLi driver (see Mysqli\Db::query()).
     * When the replay cannot recover it, the statement fails through emailError() and halt() like any other.
     * With this set to false that failure, and the refusal of the COMMIT that follows it, is handed to the
     * caller instead: query() returns false with Errno/Error set and one error_log() line, and neither
     * emailError() nor halt() runs. Meant for a caller that retries the whole transaction itself and reports
     * only its final failure (MyAdmin's Session::deleteSessionRows()). Every other failure is reported as
     * always.
     *
     * @var bool
     */
    public $reportConflicts = true;

    /**
     * Whether a statement that finally fails is reported through emailError() (MySQLi driver).
     *
     * With this set to false no failure of this handle is mailed: the statement fails as always (query()
     * returns false with Errno/Error set, and halt() runs as haltOnError says, so 'yes' still ends the process),
     * and one error_log() line says the report was left to the caller. Meant for a caller that reports a failure
     * itself, once, with what it was doing, and not through a database handle: MyAdmin's Session on a connection
     * of its own, while the request's connection has a transaction open (emailError() can write a `log` row
     * through the application's handle, which would join that transaction).
     *
     * @var bool
     */
    public $reportFailures = true;

    /**
     * Set while halt() runs unlock(false), so unlock() can tell that call from one the caller made.
     *
     * @var bool
     */
    protected $haltUnlocking = false;

    /**
     * Report whether a transaction is currently open on this connection.
     *
     * @return bool
     */
    public function inTransaction()
    {
        return $this->inTransaction;
    }

    /**
     * Let go of the current connection without closing it.
     *
     * Leaves this instance with no link, so the next query opens one of its own.
     * Every other holder keeps using the connection we let go of -- which is the
     * difference from disconnect(), and what makes this safe to call on a clone.
     *
     * `clone $db` copies the handle, so the copy shares the original's
     * connection: cheap, and the right thing when all you want is a second
     * cursor. Detach when the copy needs a connection of its own instead:
     *
     *     $own = clone $db;
     *     $own->detach();      // stop sharing $db's connection
     *     $own->host = $other; // optional: point it somewhere else
     *     $own->query(...);    // connects on its own
     *
     * @return void
     */
    public function detach()
    {
        $this->linkId = 0;
        $this->queryId = 0;
        // an open transaction belonged to the connection we just let go of, and so did the record that it was lost
        $this->inTransaction = false;
        $this->transactionLost = false;
        $this->transactionLostErrno = 0;
        $this->transactionLostError = '';
    }

    /**
     * A copy of this handle with a connection all its own.
     *
     * The shorthand for clone-then-detach. Use it when the copy needs isolation
     * from the original rather than just a second cursor. A plain clone shares
     * the connection, and with it everything the server tracks per connection:
     * transactions, LOCK TABLES, temporary tables, user variables, session
     * settings and LAST_INSERT_ID(). This gives you none of that -- just the
     * connection settings, with the connection opened lazily on first query.
     *
     * @return static
     */
    public function newConnection()
    {
        $copy = clone $this;
        $copy->detach();
        $copy->connectionAttempt = 0;
        $copy->Record = [];
        $copy->Row = 0;
        $copy->Errno = 0;
        $copy->Error = '';
        $copy->log = [];
        return $copy;
    }

    /**
     * The statement with leading whitespace, "(" and SQL comments removed, so its first keyword can be matched.
     *
     * Strips any sequence of leading block comments, "-- " comments (MySQL needs whitespace, a control
     * character or the end of the statement after the dashes) and "#" comments. A block comment between the words of
     * the keyword (START, comment, TRANSACTION) is not recognised, and executable comments
     * (slash-star-bang) are treated as plain comments.
     *
     * @param string $queryString
     * @return string
     */
    protected static function leadingKeywordSql($queryString)
    {
        $sql = (string) $queryString;
        $length = strlen($sql);
        $offset = 0;
        while (true) {
            // MySQL's lexer treats \f and \v as space too
            $offset += strspn($sql, " \t\n\r\f\x0B(", $offset);
            if ($offset >= $length) {
                return '';
            }
            if (substr_compare($sql, '/*', $offset, 2) === 0) {
                $end = strpos($sql, '*/', $offset + 2);
                if ($end === false) {
                    return '';
                }
                $offset = $end + 2;
            } elseif ($sql[$offset] === '#' || preg_match('/--(?=[\x00-\x20\x7F]|$)/A', $sql, $m, 0, $offset) === 1) {
                $newline = strpos($sql, "\n", $offset);
                if ($newline === false) {
                    return '';
                }
                $offset = $newline + 1;
            } else {
                return $offset === 0 ? $sql : substr($sql, $offset);
            }
        }
    }

    /**
     * The statement with every SQL comment replaced by a space (one linear pass), for matching the words of a statement.
     *
     * Returns '' when the statement cannot be read that way: an unterminated block comment, or an
     * executable comment (slash-star-bang, slash-star-M-bang), whose content the server runs.
     * Quotes are not tracked: use it on statements that carry no string literals (COMMIT, ROLLBACK).
     *
     * @param string $queryString
     * @return string
     */
    protected static function withoutComments($queryString)
    {
        $sql = (string) $queryString;
        $length = strlen($sql);
        $out = '';
        $offset = 0;
        while ($offset < $length) {
            $special = strcspn($sql, '/#-', $offset);
            $out .= substr($sql, $offset, $special);
            $offset += $special;
            if ($offset >= $length) {
                break;
            }
            if (substr_compare($sql, '/*', $offset, 2) === 0) {
                if (substr_compare($sql, '/*!', $offset, 3) === 0 || substr_compare($sql, '/*M!', $offset, 4) === 0) {
                    return '';
                }
                $end = strpos($sql, '*/', $offset + 2);
                if ($end === false) {
                    return '';
                }
                $out .= ' ';
                $offset = $end + 2;
            } elseif ($sql[$offset] === '#' || preg_match('/--(?=[\x00-\x20\x7F]|$)/A', $sql, $m, 0, $offset) === 1) {
                $newline = strpos($sql, "\n", $offset);
                $out .= ' ';
                if ($newline === false) {
                    break;
                }
                $offset = $newline + 1;
            } else {
                $out .= $sql[$offset];
                $offset++;
            }
        }
        return $out;
    }

    /**
     * Whether the statement is a plain ROLLBACK: nothing but ROLLBACK [WORK] [AND [NO] CHAIN] [[NO] RELEASE],
     * once every comment (not only leading ones) is gone. Every other form (ROLLBACK TO SAVEPOINT, with
     * or without a comment between its words; a statement holding a quote; anything unreadable) is not.
     *
     * @param string $queryString
     * @return bool
     */
    protected static function isPlainRollback($queryString)
    {
        $sql = (string) $queryString;
        if (strpbrk($sql, '\'"`') !== false) {
            return false;
        }
        return preg_match('/^\s*(?:\(\s*)*rollback(?:\s+work)?(?:\s+and\s+(?:no\s+)?chain)?(?:\s+(?:no\s+)?release)?[\s;]*$/i', static::withoutComments($sql)) === 1;
    }

    /**
     * Update transaction state from a raw SQL statement (call it once the statement has succeeded).
     *
     * Recognises the statements callers actually use to drive transactions
     * without going through transactionBegin()/Commit()/Abort(), ignoring leading comments.
     * ROLLBACK TO [SAVEPOINT] and RELEASE SAVEPOINT leave the transaction open, and so do
     * COMMIT/ROLLBACK [WORK] AND CHAIN, which end it and start the next one at once (not AND CHAIN RELEASE,
     * which closes the session). SET
     * AUTOCOMMIT=0 (an implicit transaction) is not tracked.
     *
     * @param string $queryString
     * @return string|null what the statement did to the transaction: 'start' (START TRANSACTION/BEGIN), 'end'
     *                     (COMMIT/ROLLBACK), 'chain' (COMMIT/ROLLBACK AND CHAIN), or null (anything else)
     */
    protected function trackTransactionState($queryString)
    {
        $normalized = static::leadingKeywordSql($queryString);
        if (preg_match('/^(start\s+transaction|begin\s+work|begin)\b/i', $normalized)) {
            $this->inTransaction = true;
            return 'start';
        }
        if (preg_match('/^(commit|rollback)\b/i', $normalized)) {
            // a comment between the words (ROLLBACK, comment, TO SAVEPOINT) must not hide the form
            $clean = static::withoutComments($normalized);
            if (preg_match('/^(commit|rollback)\b(?!\s+(?:work\s+)?to\b)/i', $clean)) {
                // AND CHAIN starts the next transaction at once, unless the session is released (AND CHAIN RELEASE)
                $this->inTransaction = preg_match('/^(?:commit|rollback)\b(?:\s+work)?\s+and\s+chain\b(?!\s+release\b)/i', $clean) === 1;
                return $this->inTransaction ? 'chain' : 'end';
            }
        }
        return null;
    }

    /**
     * Constructs the db handler, can optionally specify connection parameters
     *
     * @param string $database Optional The database name
     * @param string $user Optional The username to connect with
     * @param string $password Optional The password to use
     * @param string $host Optional The hostname where the server is, or default to localhost
     * @param string $query Optional query to perform immediately
     * @param string $port optional port for the connection
     */
    public function __construct($database = '', $user = '', $password = '', $host = 'localhost', $query = '', $port = '')
    {
        $this->database = $database;
        $this->user = $user;
        $this->password = $password;
        $this->host = $host;
        $this->port = $port;
        if ($query != '') {
            $this->query($query);
        }
        $this->connectionAttempt = 0;
    }

    /**
     * @param string $message
     * @param string $line
     * @param string $file
     * @return void
     */
    public function log($message, $line = '', $file = '', $level = 'info')
    {
        error_log('SQL Query '.$line.' '.$file.' '.$message);
    }

    /**
     * @return int|object
     */
    public function linkId()
    {
        return $this->linkId;
    }

    /**
     * @return int|object
     */
    public function queryId()
    {
        return $this->queryId;
    }

    /**
     * @param $string
     * @return string
     */
    public function real_escape($string = '')
    {
        if ((!is_resource($this->linkId) || $this->linkId == 0) && !$this->connect()) {
            return $this->escape($string);
        }
        return mysqli_real_escape_string($this->linkId, $string);
    }

    /**
     * @param $string
     * @return string
     */
    public function escape($string = '')
    {
        //if (function_exists('mysql_escape_string'))
        //return mysql_escape_string($string);
        return str_replace(['\\', "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $string);
    }

    /**
     * @param mixed $str
     * @return string
     */
    public function dbAddslashes($str = '')
    {
        if (!isset($str) || $str == '') {
            return '';
        }
        return addslashes($str);
    }

    /**
     * Db::toTimestamp()
     *
     * Formats a value as the database stores timestamps. A decimal-string epoch is
     * already in that form and is returned unchanged (as any epoch is its own
     * canonical numeric timestamp); floats, ints, and every format fromTimestamp()
     * understands come back as 'Y-m-d H:i:s' in the configured timezone.
     *
     * @param mixed $epoch
     * @return bool|string
     */
    public function toTimestamp($epoch)
    {
        if (is_string($epoch) && preg_match('/^\d{10}$/', $epoch) === 1) {
            return $epoch;
        }
        $timestamp = $this->fromTimestamp(is_float($epoch) ? intval($epoch) : $epoch);
        return $timestamp === false ? false : date('Y-m-d H:i:s', (int) $timestamp);
    }

    /**
     * Db::fromTimestamp()
     * @param mixed $timestamp
     * @return bool|int|mixed
     */
    public function fromTimestamp($timestamp)
    {
        if (preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})/', $timestamp, $parts)) {
            $time = mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
        } elseif (preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})$/', $timestamp, $parts)) {
            $time = mktime($parts[4], $parts[5], $parts[6], $parts[2], $parts[3], $parts[1]);
        } elseif (preg_match('/^([0-9]{4})([0-9]{2})([0-9]{2})$/', $timestamp, $parts)) {
            $time = mktime(1, 1, 1, $parts[2], $parts[3], $parts[1]);
        } elseif (is_numeric($timestamp) && $timestamp >= 943938000) {
            $time = $timestamp;
        } else {
            $this->log('Cannot Match Timestamp from '.$timestamp, __LINE__, __FILE__);
            $time = false;
        }
        return $time;
    }

    /**
     * perform a query with limited result set
     *
     * @param string $queryString
     * @param string|int $numRows
     * @param int $offset
     * @param string|int $line
     * @param string $file
     * @return mixed
     */
    public function limitQuery($queryString, $numRows = '', $offset = 0, $line = '', $file = '')
    {
        if (!$numRows) {
            $numRows = $this->maxMatches;
        }
        if ($offset == 0) {
            $queryString .= ' LIMIT '.(int) $numRows;
        } else {
            $queryString .= ' LIMIT '.(int) $offset.','.(int) $numRows;
        }

        if ($this->Debug) {
            printf("Debug: limitQuery = %s<br>offset=%d, num_rows=%d<br>\n", $queryString, $offset, $numRows);
        }

        return $this->query($queryString, $line, $file);
    }

    /**
     * db:qr()
     *
     *  alias of queryReturn()
     *
     * @param mixed $query SQL Query to be used
     * @param string $line optionally pass __LINE__ calling the query for logging
     * @param string $file optionally pass __FILE__ calling the query for logging
     * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
     */
    public function qr($query, $line = '', $file = '')
    {
        return $this->queryReturn($query, $line, $file);
    }

    /**
     * gets a field
     *
     * @param mixed  $name
     * @param string $stripSlashes
     * @return string
     */
    public function f($name, $stripSlashes = '')
    {
        if (is_null($this->Record)) {
            return null;
        } elseif ($stripSlashes || ($this->autoStripslashes && !$stripSlashes)) {
            return stripslashes($this->Record[$name]);
        } else {
            return $this->Record[$name];
        }
    }

    /**
     * error handling
     *
     * @param mixed $msg
     * @param string $line
     * @param string $file
     * @return void
     */
    public function halt($msg, $line = '', $file = '')
    {
        /*
         * Just in case there is a table currently locked. On a connection that is gone the UNLOCK TABLES throws
         * mysqli_sql_exception under MYSQLI_REPORT_STRICT. With haltOnError 'no' or 'report' that exception is
         * swallowed here (nothing is locked on a connection that is gone), so the failed statement returns false
         * as those modes promise instead of throwing. With 'yes' it still escapes, as it always has, instead of
         * reaching the echo + die() below: callers that catch it rely on that (a cron's retry loop, a failure
         * path that must not end the request, a script's failure exit code). The \Error mysqli throws on a
         * half-open link (a connect that failed with reporting off leaves the mysqli_init() object behind, "not
         * fully initialized") escapes in every mode, as it always has (MyAdmin's opt-out StoreDb relies on it).
         */
        // put back as it was, not cleared: a halt() inside that unlock() must not end the outer one's flag early
        $unlocking = $this->haltUnlocking;
        $this->haltUnlocking = true;
        try {
            $this->unlock(false);
        } catch (\mysqli_sql_exception $e) {
            if ($this->haltOnError != 'no' && $this->haltOnError != 'report') {
                throw $e;
            }
        } finally {
            $this->haltUnlocking = $unlocking;
        }

        //$this->Error = @$this->linkId->error;
        //$this->Errno = @$this->linkId->errno;
        if ($this->haltOnError == 'no') {
            return true;
        }
        if ($msg != '') {
            $this->haltmsg($msg, $line, $file);
        }
        if ($this->haltOnError != 'report') {
            echo '<p><b>Session halted.</b>';
            //if (\MyAdmin\App::has(\MyAdmin\tf::class))
            //\MyAdmin\App::output()->terminate();
            die();
        }
        return true;
    }

    /**
     * Returns a copy of request data ($_POST/$_GET) that is safe to log:
     * every value under a secret-looking key (password, token, card data,
     * api key, ...) is replaced, recursing into nested arrays. Uses MyAdmin's
     * SecretLogRedactor when it is available, otherwise a local key match.
     *
     * @param array $data
     * @return array
     */
    public static function redactForLog(array $data)
    {
        if (class_exists(\MyAdmin\Security\SecretLogRedactor::class)) {
            return \MyAdmin\Security\SecretLogRedactor::scrubArray($data);
        }
        return static::redactSecretKeys($data);
    }

    /**
     * The standalone fallback for redactForLog(): replaces the value of every
     * secret-looking key, recursing into nested arrays. Empty values are kept
     * so "was a password supplied at all" stays visible.
     *
     * @param array $data
     * @return array
     */
    public static function redactSecretKeys(array $data)
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/pass|pwd|secret|token|cvv|ccv|api_?key|^cc$|card/i', $key) === 1) {
                if ($value !== '' && $value !== null && $value !== []) {
                    $data[$key] = '[redacted]';
                }
            } elseif (is_array($value)) {
                $data[$key] = static::redactSecretKeys($value);
            }
        }
        return $data;
    }

    /**
     * Returns $queryString safe to put in a log line or an error email. A
     * query that names a secret-looking column (password, token, api key,
     * card data, a session id: session ids are bearer tokens, so sessions,
     * appsession and access_log statements are covered too, ...) has every
     * quoted string literal replaced, so legacy
     * SQL like "account_passwd=md5('...')" or an INSERT carrying a root
     * password never leaves the database layer in clear text. Queries that
     * name no such column are returned unchanged so ordinary SQL errors stay
     * debuggable. Also used on driver error messages, which quote fragments
     * of the failing query ("near '...'", "Duplicate entry '...'").
     *
     * @param mixed $queryString
     * @param mixed $context text used to decide whether redaction applies (defaults to $queryString)
     * @return string
     */
    public static function redactQueryForLog($queryString, $context = null)
    {
        $queryString = (string) $queryString;
        $context = $context === null ? $queryString : (string) $context;
        if (preg_match('/pass|pwd|secret|token|cvv|ccv|api_?key|cc_?num|card|salt|session_?id|as_sid|access_sid|history_sid|referrer_sid|appsession/i', $context) !== 1) {
            return $queryString;
        }
        return (string) preg_replace(
            // each literal runs to its closing quote, or to the end when a driver error truncated the query
            ["/'(?:[^'\\\\]|\\\\.|'')*(?:'|\\z)/s", '/"(?:[^"\\\\]|\\\\.|"")*(?:"|\\z)/s'],
            ["'[redacted]'", '"[redacted]"'],
            $queryString
        );
    }

    /**
     * Returns the part of $_SERVER that is useful in an SQL error report and
     * safe to email: a fixed allowlist of request/host keys (never
     * PHP_AUTH_PW, Authorization, cookies, or FPM environment secrets), with
     * secret-looking query-string parameters redacted.
     *
     * @param array $server
     * @return array
     */
    public static function serverForReport(array $server)
    {
        $keep = ['HTTP_HOST', 'SERVER_NAME', 'SERVER_ADDR', 'REQUEST_METHOD', 'REQUEST_URI', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF', 'QUERY_STRING', 'HTTP_REFERER', 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'REQUEST_TIME', 'argc'];
        $out = [];
        foreach ($keep as $key) {
            if (!isset($server[$key])) {
                continue;
            }
            $value = $server[$key];
            if (is_string($value) && in_array($key, ['REQUEST_URI', 'QUERY_STRING', 'HTTP_REFERER', 'PHP_SELF'], true)) {
                $value = static::redactUrlQuery($value);
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Redacts secret-looking parameters in the query-string part of a URL,
     * or in a bare query string.
     *
     * @param string $value
     * @return string
     */
    public static function redactUrlQuery($value)
    {
        $pos = strpos($value, '?');
        $prefix = $pos === false ? '' : substr($value, 0, $pos + 1);
        $query = $pos === false ? $value : substr($value, $pos + 1);
        if ($query === '' || ($pos === false && strpos($query, '=') === false)) {
            return $value;
        }
        parse_str($query, $params);
        $redacted = static::redactForLog($params);
        if ($redacted === $params) {
            return $value;
        }
        return $prefix.http_build_query($redacted);
    }

    /**
     * @param mixed $msg
     * @param string $line
     * @param string $file
     * @return mixed|void
     */
    public function logBackTrace($msg, $line = '', $file = '')
    {
        $backtrace = (function_exists('debug_backtrace') ? debug_backtrace() : []);
        $post = (isset($_POST) && is_array($_POST)) ? static::redactForLog($_POST) : [];
        $get = (isset($_GET) && is_array($_GET)) ? static::redactForLog($_GET) : [];
        $this->log(
            ('' !== getenv('REQUEST_URI') ? ' '.getenv('REQUEST_URI') : '').
            (count($post) ? ' POST='.json_encode($post) : '').
            (count($get) ? ' GET='.json_encode($get) : '').
            ((isset($_FILES) && count($_FILES)) ? ' FILES='.json_encode($_FILES) : '').
            ('' !== getenv('HTTP_USER_AGENT') ? ' AGENT="'.getenv('HTTP_USER_AGENT').'"' : '').
            (isset($_SERVER['REQUEST_METHOD']) ? ' METHOD="'.$_SERVER['REQUEST_METHOD'].'"'.
                ($_SERVER['REQUEST_METHOD'] === 'POST' ? ' POST="'.json_encode($post).'"' : '') : ''),
            $line,
            $file,
            'error'
        );
        for ($level = 1, $levelMax = count($backtrace); $level < $levelMax; $level++) {
            $message = (isset($backtrace[$level]['file']) ? 'File: '.$backtrace[$level]['file'] : '').
                (isset($backtrace[$level]['line']) ? ' Line: '.$backtrace[$level]['line'] : '').
                ' Function: '.(isset($backtrace[$level] ['class']) ? '(class '.$backtrace[$level] ['class'].') ' : '').
                (isset($backtrace[$level] ['type']) ? $backtrace[$level] ['type'].' ' : '').
                $backtrace[$level] ['function'].'(';
            if (isset($backtrace[$level] ['args'])) {
                // never print the arguments of auth/password/login functions: they are the credentials
                $hideArgs = preg_match('/auth|pass|login/i', (string) $backtrace[$level]['function']) === 1;
                for ($argument = 0, $argumentMax = count($backtrace[$level]['args']); $argument < $argumentMax; $argument++) {
                    $message .= ($argument > 0 ? ', ' : '').
                        ($hideArgs ? '[arg]' : (is_object($backtrace[$level]['args'][$argument]) ? 'class '.get_class($backtrace[$level]['args'][$argument]) : json_encode($backtrace[$level]['args'][$argument])));
                }
            }
            $message .= ')';
            $this->log($message, $line, $file, 'error');
        }
    }

    /**
     * Re-entrancy guard for emailError().
     *
     * Reporting a failed query is itself database work: emailError() renders a
     * Smarty template and calls Mail::adminMail(), which calls myadmin_log(),
     * which writes to the `log` table through MyDbHandler. If the connection is
     * broken, that write fails too and calls emailError() again -- an unbounded
     * recursion that ends in stack exhaustion rather than a reported error.
     *
     * This is deliberately static, not per-instance: the recursion crosses
     * object boundaries, because the failing object is typically a clone while
     * MyDbHandler logs through App::db(). A per-instance flag would not see it.
     *
     * @var bool
     */
    private static $inEmailError = false;

    /**
     * Whether an SQL error is being reported right now (emailError() is running, on any instance).
     *
     * @return bool
     */
    protected static function reportingError()
    {
        return self::$inEmailError;
    }

    public function emailError($queryString, $error, $line, $file)
    {
        /*
         * Already reporting an error -- do no further database work. Degrade to
         * error_log(), which cannot re-enter, so the original failure is still
         * recorded somewhere durable.
         */
        if (self::$inEmailError === true) {
            error_log('SQL error while already reporting an SQL error (suppressed to avoid recursion): '.static::redactQueryForLog($error, $queryString).' on query '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
            return;
        }
        self::$inEmailError = true;
        try {
            $this->emailErrorInner($queryString, $error, $line, $file);
        } catch (\Throwable $e) {
            /*
             * The reporting path must never be able to throw into the caller --
             * a failed error report should not replace or mask the real error.
             */
            error_log('Failed to report SQL error ('.$e->getMessage().'). Original error: '.static::redactQueryForLog($error, $queryString).' on query '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
        } finally {
            self::$inEmailError = false;
        }
    }

    /**
     * The original emailError() body. Only ever called via emailError(), which
     * owns the re-entrancy guard and exception containment.
     *
     * @param mixed $queryString
     * @param mixed $error
     * @param mixed $line
     * @param mixed $file
     * @return void
     */
    private function emailErrorInner($queryString, $error, $line, $file)
    {
        /*
         * Nothing secret may reach the email: the subject is also logged by the
         * mailer, so it carries only the location; the query and driver error
         * are literal-redacted when they touch a secret column; request data is
         * key-redacted and the server array is an allowlist.
         */
        $error = static::redactQueryForLog(is_string($error) ? $error : json_encode($error), $queryString);
        $queryString = static::redactQueryForLog($queryString);
        $subject = php_uname('n').' MySQLi Error at '.basename((string) $file).':'.$line;
        if (class_exists('\\TFSmarty')) {
            $smarty = new \TFSmarty();
            $smarty->assign([
                'type' => $this->type,
                'queryString' => $queryString,
                'error' => $error,
                'line' => $line,
                'file' => $file,
                'request' => static::redactForLog(isset($_REQUEST) && is_array($_REQUEST) ? $_REQUEST : []),
                'server' => static::serverForReport(isset($_SERVER) && is_array($_SERVER) ? $_SERVER : []),
            ]);
            if (\MyAdmin\App::has(\MyAdmin\tf::class)) {
                $smarty->assign('account_id', \MyAdmin\App::session()->account_id);
            }
            $email = $smarty->fetch('email/admin/sql_error.tpl');
            $this->mailErrorReport($subject, $email);
        }
        $this->haltmsg('Invalid SQL: '.$queryString, $line, $file);
    }

    /**
     * Mails the report emailError() built to john@ and detain@. As a rule through \MyAdmin\Mail::adminMail(), one mail
     * each. While a transaction is open in the process (reportWithoutDatabase()) through
     * \MyAdmin\Mail::failsafeMail() instead (SMTP only, no database access; one mail to both): adminMail() can write
     * through the application's database handle (its own failure is logged by myadmin_log(), whose Monolog handler
     * INSERTs a `log` row on MyAdmin's App::db()), and on a connection with a transaction open that row would join it:
     * committed or rolled back with the caller's work, and a deadlock on it rolling the caller's transaction back.
     * haltmsg() already uses failsafeMail().
     *
     * @param string $subject
     * @param string $email
     * @return void
     */
    protected function mailErrorReport($subject, $email)
    {
        if ($this->reportWithoutDatabase()) {
            \MyAdmin\Mail::failsafeMail($subject, $email, ['john@interserver.net', 'detain@interserver.net']);
            return;
        }
        (new \MyAdmin\Mail())->adminMail($subject, $email, 'john@interserver.net', '');
        (new \MyAdmin\Mail())->adminMail($subject, $email, 'detain@interserver.net', '');
    }

    /**
     * Whether an error report must not touch any database: a transaction is open in the process, which a write of the
     * report (a log row) could join (see mailErrorReport()). This driver-neutral class tracks only its own
     * inTransaction; the MySQLi driver asks every handle it knows of.
     *
     * @return bool
     */
    protected function reportWithoutDatabase()
    {
        return $this->inTransaction === true;
    }

    /**
     * @param mixed $msg
     * @param string $line
     * @param string $file
     * @return mixed|void
     */
    public function haltmsg($msg, $line = '', $file = '')
    {
        $email = "DB Error {$msg} {$file}:{$line}";
        if (class_exists('\\MyAdmin\Mail')) {
            \MyAdmin\Mail::failsafeMail($email, $email, ['detain@interserver.net', 'john@interserver.net']);
            return;
        }
        $this->log("Database error: $msg", $line, $file, 'error');
        if ($this->Errno != '0' || !in_array($this->Error, ['', '()'])) {
            $sqlstate = mysqli_sqlstate($this->linkId);
            $this->log("MySQLi SQLState: {$sqlstate}. Error: ".$this->Errno.' ('.$this->Error.')', $line, $file, 'error');
        }
        $this->logBackTrace($msg, $line, $file);
    }

    /**
     * @return array
     */
    public function indexNames()
    {
        return [];
    }


    /**
     * Add query to logged queries.
     * @param string $statement
     * @param float $time Elapsed seconds with microseconds
     * @param string|int $line Line Number
     * @param string $file File Name
     */
    public function addLog($statement, $time, $line = '', $file = '')
    {
        $query = [
            'statement' => $statement,
            'time' => $time * 1000
        ];
        if ($line != '') {
            $query['line'] = $line;
        }
        if ($file != '') {
            $query['file'] = $file;
        }
        if (!isset($GLOBALS['db_queries'])) {
            $GLOBALS['db_queries'] = [];
        }
        $GLOBALS['db_queries'][] = $query;
        array_push($this->log, $query);
    }

    /**
     * Return logged queries.
     * @return array Logged queries
     */
    public function getLog()
    {
        return $this->log;
    }
}
