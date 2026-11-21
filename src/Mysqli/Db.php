<?php
/**
* MySQL Related Functionality
* @author Joe Huss <detain@interserver.net>
* @copyright 2025
* @package MyAdmin
* @category SQL
*/

namespace MyDb\Mysqli;

use MyDb\Generic;
use MyDb\Db_Interface;

/**
* Db
*
* @access public
*/
class Db extends Generic implements Db_Interface
{
    /**
    * @var string
    */
    public $type = 'mysqli';
    public $statement;
    public $statement_query;
    public $statement_vars;

    /**
    * errors after which the server has rolled the whole transaction back and it may be replayed:
    * a deadlock, a Group Replication certification failure
    */
    const REPLAY_ERRORS = [1213, 3101];

    /**
    * errors that mean the connection is gone, and the open transaction with it (a COMMIT's outcome is then unknown)
    */
    const LINK_ERRORS = [2006, 2013];

    /**
    * errors after which the server has rolled the whole transaction back by itself
    */
    const ROLLED_BACK_ERRORS = [1213, 3101, 1180];

    /**
    * errors of a START TRANSACTION/BEGIN that the server never ran, so the transaction open before it is still
    * open: the parser's (1064 ER_PARSE_ERROR, 1065 ER_EMPTY_QUERY, 1149 ER_SYNTAX_ERROR; a statement that does
    * not parse is never executed) and the client's 2014 (commands out of sync: never sent). MySQL runs the implicit
    * commit of the open transaction first thing in trans_begin(); when that commit fails the server rolls the
    * transaction back and leaves the session in autocommit mode. Every other error of a START is taken to have
    * ended the open transaction (a ROLLBACK then makes sure of it): wrongly taking an ended transaction for an open
    * one could report its rolled-back writes as committed, the other way round only fails a transaction early.
    */
    const START_NOT_RUN_ERRORS = [1064, 1065, 1149, 2014];

    /**
    * errors of any statement that the server never ran: it did not parse (1064, 1065, 1149) or it was never sent
    * (2014). A statement counted as the end of a transaction (endsTransaction()) that fails with one of these ended
    * nothing, so the count is taken back (uncountEnd()): whatever transaction was open on the link is still open.
    */
    const NOT_RUN_ERRORS = [1064, 1065, 1149, 2014];

    /**
    * Connection errors after which query()'s retry of a statement outside a transaction closes the connection and
    * opens a new one: the connection is dead, so closing it takes nothing with it that was not gone already (an open
    * transaction of another holder of the shared link included). 2006 CR_SERVER_GONE_ERROR, 2013 CR_SERVER_LOST,
    * 2055 CR_SERVER_LOST_EXTENDED, 4031 ER_CLIENT_INTERACTION_TIMEOUT (MySQL 8.0.24+: the server closed an idle
    * connection). 2014 (commands out of sync) leaves the connection open with results pending: a new one clears it,
    * so it is closed and reopened too, but only while no other handle sharing it has a transaction open there.
    */
    const RECONNECT_ERRORS = [2006, 2013, 2055, 4031];

    /**
    * Errors of a statement outside a transaction that query() does not retry: run again on the same server it fails
    * the same way. Kept to what the statement text alone decides, plus the duplicate key: 1062 ER_DUP_ENTRY and 1586
    * ER_DUP_ENTRY_WITH_KEY_NAME, 1064 ER_PARSE_ERROR, 1065 ER_EMPTY_QUERY, 1149 ER_SYNTAX_ERROR, 1054
    * ER_BAD_FIELD_ERROR, 1146 ER_NO_SUCH_TABLE, 1049 ER_BAD_DB_ERROR, 1052 ER_NON_UNIQ_ERROR, 1136
    * ER_WRONG_VALUE_COUNT_ON_ROW, 1305 ER_SP_DOES_NOT_EXIST, and the privilege errors 1044 ER_DBACCESS_DENIED_ERROR,
    * 1142 ER_TABLEACCESS_DENIED_ERROR, 1143 ER_COLUMNACCESS_DENIED_ERROR, 1227 ER_SPECIFIC_ACCESS_DENIED_ERROR. Any
    * error not listed here or in RECONNECT_ERRORS may be transient (a lock wait timeout, a read-only member, a
    * foreign key the next try finds satisfied...) and is retried as before, on the same connection.
    */
    const NO_RETRY_ERRORS = [1044, 1049, 1052, 1054, 1062, 1064, 1065, 1136, 1142, 1143, 1146, 1149, 1227, 1305, 1586];

    /**
    * The most times a transaction the server rolled back (1213, 3101), or whose connection went, is replayed
    * before the statement gives up (0 turns replay, and its recording, off). See query(). In practice
    * $replayBudgetMs ends a replay first; this only caps a runaway.
    *
    * @var int
    */
    public $transactionReplays = 25;

    /**
    * The wall-clock budget of one replay, in milliseconds: replays go on (each after a longer, jittered wait)
    * until the next wait would pass it. In a multi-primary Group Replication cluster a replay can fail again
    * (3101) until the winning transaction has been applied on this node, so a few quick tries are not enough.
    * It bounds the waits: a replayed statement waiting for a row lock can still block for up to
    * innodb_lock_wait_timeout on its own, so a replay can end later than this.
    *
    * @var int
    */
    public $replayBudgetMs = 10000;

    /**
    * A transaction with more statements than this is not replayed (it gives up as before).
    *
    * @var int
    */
    public $replayMaxStatements = 10000;

    /**
    * A transaction whose statements and result sets come to more bytes than this is not replayed.
    *
    * @var int
    */
    public $replayMaxBytes = 16777216;

    /**
    * Statements sent on each link by any instance (\WeakMap, PHP 8): clones share a link, and a statement
    * another holder sent inside a recorded transaction makes it not replayable.
    *
    * @var \WeakMap|null
    */
    private static $linkStatements = null;

    /**
    * Statements that end a transaction (COMMIT, ROLLBACK, START TRANSACTION/BEGIN, a statement that commits
    * implicitly: see endsTransaction(); a statement whose failure made the server roll it back: see
    * countFailedEnd()) sent on each link by any instance (\WeakMap, PHP 8): one that another holder of the shared
    * link sends while this instance has a transaction open on it ended that transaction (committed or rolled it
    * back), so it is lost, not just no longer replayable.
    *
    * @var \WeakMap|null
    */
    private static $linkEnds = null;

    /**
    * The instances that have sent a statement on each link (\WeakMap link => \WeakMap instance => true, PHP 8):
    * clones share a link, and linkHasOpenTransaction() asks each one that still holds it whether it has a
    * transaction open there. An entry goes with its link or its instance.
    *
    * @var \WeakMap|null
    */
    private static $linkHolders = null;

    /**
    * The link the open transaction runs on (set when it starts, moved by a replay that reconnects): a statement or
    * COMMIT that finds another link in linkId (something reconnected underneath the transaction) finds it lost.
    *
    * @var object|null
    */
    protected $txLink = null;

    /**
    * The link's count of transaction-ending statements (self::$linkEnds) after this instance's last statement on
    * it, while a transaction is open (null: none open, or nothing to count with).
    *
    * @var int|null
    */
    protected $txEnds = null;

    /**
    * Whether another holder of the shared link ended the open transaction (seen while counting one of this
    * instance's own statements; kept until the transaction is over).
    *
    * @var bool
    */
    protected $txEndedElsewhere = false;

    /**
    * Whether statements are refused while the transaction is lost: it was lost while the caller still took it to
    * be open (a statement in it gave up, a START or transactionBegin() failed, its connection went), so a
    * statement sent now would run on its own, with autocommit. Not after a COMMIT or ROLLBACK the caller sent
    * itself (it has ended the transaction), nor after disconnect(). See query().
    *
    * @var bool
    */
    protected $txRefuse = false;

    /**
    * Set while a replay runs: the reconnects it makes are its own (linkLostInTransaction() leaves them alone).
    *
    * @var bool
    */
    protected $replaying = false;

    /**
    * Set while reportFailure() reports a failure of this instance: what the report writes through this very handle
    * (the application's error log) is the reporter's, so it is not refused.
    *
    * @var bool
    */
    protected $reportingFailure = false;

    /**
    * Links on which a statement changed the session's state (\WeakMap, PHP 8): a user variable, the selected
    * database, a temporary table, a user-level lock, LAST_INSERT_ID(expr). A new connection does not have it, so
    * a replay that has to reconnect gives up. Clones share a link, and so this.
    *
    * @var \WeakMap|null
    */
    private static $linkSessionState = null;

    /**
    * Whether a statement this instance sent changed the session state of the link it still holds (what
    * $linkSessionState says, kept here too because a lost link is let go of before a replay looks at it). Cleared
    * when a new connection is opened.
    *
    * @var bool
    */
    protected $sessionDirty = false;

    /**
    * How the recorded transaction was opened: false none, null transactionBegin(), else the START statement.
    *
    * @var false|null|string
    */
    protected $txOpener = false;

    /**
    * The statements run in the open transaction, in order, with what the caller could observe of each.
    *
    * @var array[]
    */
    protected $txSteps = [];

    /**
    * Whether the open transaction can be replayed.
    *
    * @var bool
    */
    protected $txReplayable = false;

    /**
    * Bytes of SQL and result sets recorded.
    *
    * @var int
    */
    protected $txBytes = 0;

    /**
    * The link's statement count after this instance's last statement, while a transaction is recorded.
    *
    * @var int|null
    */
    protected $txSeq = null;

    /**
    * The step the last query() recorded: affectedRows()/getLastInsertId() mark what they read of it as observed.
    *
    * @var int|null
    */
    protected $txLastStep = null;

    /**
    * Why the open transaction cannot be replayed, for the log line of a give-up.
    *
    * @var string
    */
    protected $txNotReplayableWhy = '';

    /**
    * alias function of select_db, changes the database we are working with.
    *
    * @param string $database the name of the database to use
    * @return void
    */
    public function useDb($database)
    {
        $this->selectDb($database);
    }

    /**
    * changes the database we are working with.
    *
    * @param string $database the name of the database to use
    * @return void
    */
    public function selectDb($database)
    {
        if ($this->linkLostInTransaction()) {
            // no reconnect underneath an open transaction: it is recorded as lost
            return;
        }
        $this->connect();
        $this->markSessionState();
        if ($this->inTransaction === true) {
            // the statements before it ran against the other database: a replay would run them against this one
            $this->txNotReplayable('selectDb() ran inside it');
        }
        $this->countStatement();
        mysqli_select_db($this->linkId, $database);
    }

    /* public: connection management */

    /**
    * whether real_connect() has succeeded on the mysqli currently in linkId
    * @var bool
    */
    protected $linkEstablished = false;

    /**
    * A clone shares the connection (and with it what the server keeps per connection, and the per-link counts) but
    * not the transaction bookkeeping of the handle it was cloned from: it starts with no transaction of its own,
    * nothing recorded, nothing lost and nothing refused, whatever its source was doing (get_module_db() clones the
    * application's handle in the middle of its work). A transaction its source has open on the shared link is the
    * source's: the clone's statements run inside it on the server (making it not replayable, and ending it if they
    * end a transaction, see query()), and linkHasOpenTransaction() sees it.
    *
    * @return void
    */
    public function __clone()
    {
        $this->inTransaction = false;
        $this->clearTransactionLost();
        $this->txEnd();
        $this->replaying = false;
        $this->reportingFailure = false;
        $this->haltUnlocking = false;
    }

    /**
    * Let go of the connection without closing it.
    *
    * On top of what the parent clears, the prepared statement has to go: a
    * mysqli_stmt belongs to the connection that prepared it, so it is useless,
    * and unsafe, against whatever connection this instance opens next.
    *
    * @return void
    */
    public function detach()
    {
        parent::detach();
        $this->linkEstablished = false;
        $this->txRefuse = false;
        $this->txEnd();
        $this->statement = null;
        $this->statement_query = null;
        $this->statement_vars = null;
    }

    /**
    * Db::linkIsUsable()
    *
    * Whether linkId is a handle we can still issue commands on.
    *
    * close() leaves the mysqli as a live PHP object, so is_object() alone could
    * not tell a closed connection apart from a working one and connect() handed
    * back the dead handle -- every later use then threw "mysqli object is already
    * closed". Clones share one handle (__clone() keeps the link and resets only
    * the transaction bookkeeping), and disconnect() or query()'s retry after a
    * connection error closes it, so one clone can close the connection out from
    * under every other holder.
    *
    * Only a handle we actually connected is probed. A mysqli_init() whose
    * real_connect() failed is still reported usable, exactly as the old
    * is_object() check did, so an unreachable server keeps failing per query
    * rather than looping back through the connect-attempt counter and tripping
    * the exit() below. Reading a property is local, so this costs no round trip.
    *
    * @return bool
    */
    public function linkIsUsable()
    {
        if (!is_object($this->linkId)) {
            return false;
        }
        if (!$this->linkEstablished) {
            return true;
        }
        try {
            return $this->linkId->thread_id > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
    * Db::connect()
    * @param string $database
    * @param string $host
    * @param string $user
    * @param string $password
    * @return int|\mysqli
    */
    public function connect($database = '', $host = '', $user = '', $password = '', $port = '')
    {
        /* Handle defaults */
        if ($database == '') {
            $database = $this->database;
        }
        if ($host == '') {
            $host = $this->host;
        }
        if ($user == '') {
            $user = $this->user;
        }
        if ($password == '') {
            $password = $this->password;
        }
        if ($port == '') {
            $port = $this->port;
        }
        /* establish connection, select database */
        if (!$this->linkIsUsable()) {
            $this->connectionAttempt++;
            if ($this->connectionAttempt >= $this->maxConnectErrors - 1) {
                error_log("MySQLi Connection Attempt #{$this->connectionAttempt}/{$this->maxConnectErrors}");
                exit;
            }
            if ($this->connectionAttempt >= $this->maxConnectErrors) {
                $this->halt("connect($host, $user, \$password) failed. ".(is_object($this->linkId) && isset( $this->linkId->connect_error) ? $this->linkId->connect_error : ''));
                return 0;
            }
            //error_log("real_connect($host, $user, $password, $database, $port)");
            $this->linkEstablished = false;
            $this->linkId = mysqli_init();
            $this->linkId->options(MYSQLI_INIT_COMMAND, "SET NAMES {$this->characterSet} COLLATE {$this->collation}, COLLATION_CONNECTION = {$this->collation}, COLLATION_DATABASE = {$this->collation}");
            if (!$this->linkId->real_connect($host, $user, $password, $database, $port != '' ? $port : NULL)) {
                $this->halt("connect($host, $user, \$password) failed. ".(is_object($this->linkId) && isset( $this->linkId->connect_error) ? $this->linkId->connect_error : ''));
                return 0;
            }
            $this->linkId->set_charset($this->characterSet);
            if ($this->linkId->connect_errno) {
                $this->halt("connect($host, $user, \$password) failed. ".(is_object($this->linkId) && isset( $this->linkId->connect_error) ? $this->linkId->connect_error : ''));
                return 0;
            }
            $this->linkEstablished = true;
            // a new connection starts with no session state of its own
            $this->sessionDirty = false;
            // Counts *consecutive* failures, so clear it once we are connected.
            // Without this it only ever grew, and since maxConnectErrors trips an
            // exit() above, a long-lived worker that legitimately reconnects a few
            // times would eventually be killed off mid-request.
            $this->connectionAttempt = 0;
        }
        return $this->linkId;
    }

    /**
    * Db::disconnect()
    * @return bool
    */
    public function disconnect()
    {
        if ($this->inTransaction === true) {
            // closing the connection rolls the open transaction back: a later COMMIT must be refused (the caller closed
            // it itself, so what it sends next is not refused)
            $this->markTransactionLost(2006, 'MySQL server has gone away (disconnected inside a transaction)', false);
        }
        $return = !is_int($this->linkId) && method_exists($this->linkId, 'close') ? $this->linkId->close() : false;
        $this->linkId = 0;
        // Closing the connection discards any open transaction server-side.
        $this->inTransaction = false;
        $this->txEnd();
        return $return;
    }

    /**
    * @param $string
    * @return mixed escaped string if input was a string; input returned as-is otherwise
    */
    public function real_escape($string = '')
    {
        if (!is_string($string)) {
            // Non-string types (null, int, float, bool, array, object) can't carry
            // SQL meta-characters through PHP string interpolation, so returning
            // them as-is preserves the caller's intent and avoids the
            // mysqli_real_escape_string(null) deprecation in PHP 8.1+.
            return $string;
        }
        if ($this->linkLostInTransaction() && !$this->linkIsUsable()) {
            // no reconnect underneath an open transaction (another holder of the shared link closed it): the
            // transaction is recorded as lost, and the string escaped without a connection
            return $this->escape($string);
        }
        // mysqli is an object rather than a resource in PHP 8, so the old
        // is_resource() test was always true and this always fell through to
        // connect(); keep calling it, but off a check that can actually pass.
        if (!$this->linkIsUsable() && !$this->connect()) {
            return $this->escape($string);
        }
        return mysqli_real_escape_string($this->linkId, $string);
    }

    /**
    * discard the query result
    * @return void
    */
    public function free()
    {
        if (is_resource($this->queryId)) {
            @mysqli_free_result($this->queryId);
        }
        $this->queryId = 0;
    }

    /**
    * Db::queryReturn()
    *
    * Sends an SQL query to the server like the normal query() command but iterates through
    * any rows and returns the row or rows immediately or FALSE on error
    *
    * @param mixed $query SQL Query to be used
    * @param string $line optionally pass __LINE__ calling the query for logging
    * @param string $file optionally pass __FILE__ calling the query for logging
    * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows, associative responses only
    */
    public function queryReturn($query, $line = '', $file = '')
    {
        $this->query($query, $line, $file);
        if ($this->num_rows() == 0) {
            return false;
        } elseif ($this->num_rows() == 1) {
            $this->next_record(MYSQLI_ASSOC);
            return $this->Record;
        } else {
            $out = [];
            while ($this->next_record(MYSQLI_ASSOC)) {
                $out[] = $this->Record;
            }
            return $out;
        }
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
    * creates a prepaired statement from query
    *
    * @param string $query sql query like INSERT INTO table (col) VALUES (?)  or  SELECT * from table WHERE col1 = ? and col2 = ?  or  UPDATE table SET col1 = ?, col2 = ? WHERE col3 = ?
    * @return false|mysqli_stmt
    * @param string $line
    * @param string $file
    */
    public function prepare($query, $line = '', $file = '')
    {
        if ($this->linkLostInTransaction()) {
            // no reconnect underneath an open transaction: it is recorded as lost
            return 0;
        }
        if (!$this->connect()) {
            return 0;
        }
        if ($this->inTransaction === true) {
            // what a prepared statement does is not recorded, so a replay could not repeat it
            $this->txNotReplayable('prepare() ran inside it');
        }
        $haltPrev = $this->haltOnError;
        $this->haltOnError = 'no';
        $start = microtime(true);
        $this->countStatement();
        try {
            $this->statement = mysqli_prepare($this->linkId, $query);
        } finally {
            $this->haltOnError = $haltPrev;
        }
        if (!isset($GLOBALS['disable_db_queries'])) {
            $this->statement_query = $query;
            $this->addLog($query, microtime(true) - $start, $line, $file);
        }
        return $this->statement;
    }

    /**
    * Binds variables to a prepared statement as parameters
    * 
    * @param string $types A string that contains one or more characters which specify the types for the corresponding bind variables: (i)nt, (d)ecimal, (s)tring, (b)inary 
    * @param mixed $vars
    * @return bool indicates success
    */
    public function bind_param($types, &...$vars) {
        $params = [$this->statement, $types];
        foreach ($vars as &$var) {
            $params[] = &$var; // Ensure they stay references
        }
        if (!isset($GLOBALS['disable_db_queries'])) {
            $this->statement_vars &= $vars;
        }
        return call_user_func_array('mysqli_stmt_bind_param', $params);
    }

    /**
    * Executes a prepared statement
    * 
    * @return bool success
    */
    public function execute($line = '', $file = '') {
        if ($this->linkLostInTransaction() || $this->refusing()) {
            // the transaction it would have run in was lost (or its link closed, replaced or ended by another holder):
            // run now it would commit on its own
            return $this->refuseStatement((string) $this->statement_query, $line, $file);
        }
        if ($this->inTransaction === true) {
            $this->txNotReplayable('execute() ran inside it');
        }
        $start = microtime(true);
        $ends = static::endsTransaction(static::leadingKeywordSql((string) $this->statement_query));
        $this->countStatement($ends);
        try {
            $return = mysqli_stmt_execute($this->statement);
        } catch (\mysqli_sql_exception $e) {
            $this->uncountEnd((int) $e->getCode(), $ends);
            $this->executeFailed((int) $e->getCode(), $e->getMessage(), $line, $file);
            throw $e;
        }
        if ($return === false) {
            $this->uncountEnd((int) @mysqli_stmt_errno($this->statement), $ends);
            $this->executeFailed((int) @mysqli_stmt_errno($this->statement), (string) @mysqli_stmt_error($this->statement), $line, $file);
        }
        if (!isset($GLOBALS['disable_db_queries'])) {
            $vars = $this->statement_vars;
            $query = $result = preg_replace_callback('/\?/', function () use (&$vars) {
                return array_shift($vars);
                }, $this->statement_query);
            $this->addLog($query, microtime(true) - $start, $line, $file);
        }
        return $return;
    }

    /**
    * A prepared statement failed. When the server rolled back the transaction it ran in (ROLLED_BACK_ERRORS), an
    * open transaction of this instance's own is given up (lost: a later COMMIT is refused, as for a statement through
    * query(); prepare()/execute() inside it already made it not replayable), and otherwise that is counted as an end
    * on the link for another holder's (countFailedEnd()). A lost connection is found by the next statement or COMMIT
    * on the dead link; any other failure fails only the statement.
    *
    * @param int $errno
    * @param string $error
    * @param string|int $line
    * @param string $file
    * @return void
    */
    protected function executeFailed($errno, $error, $line, $file)
    {
        if ($this->inTransaction === true && in_array($errno, self::ROLLED_BACK_ERRORS, true)) {
            $sql = (string) $this->statement_query;
            $this->giveUpTransaction($sql, $line, $file, false, $errno, $error, false, null, '', false, static::firstWord(static::leadingKeywordSql($sql)));
            return;
        }
        $this->countFailedEnd($errno);
        $this->endImplicitTransaction($errno);
    }

    /**
    * Db::query()
    *
    *  Sends an SQL query to the database
    *
    * @param mixed $queryString
    * @param int $line
    * @param string $file
    * @param bool $log
    * @return mixed 0 if no query or query id handler, safe to ignore this return
    *
    * Outside a transaction a statement that fails is retried as it always was, with the same results and the same
    * report and halt once it finally fails: 1213, 3101, 1180 and 2006 up to 31 times in a row 0.25 s apart, in up to
    * three rounds. What changed is the connection between rounds (statementRetry()): it is closed and a new one
    * opened only when it is dead (RECONNECT_ERRORS: 2006, 2013, 2055, 4031; a 2014 too, unless another handle
    * sharing it has a transaction open there); after any other error the next round runs on the same connection, so
    * a failure of this handle never closes a connection another handle (a clone) has a transaction open on. An
    * error the same statement would hit again (NO_RETRY_ERRORS: 1062 duplicate key, 1064 syntax, 1054 unknown
    * column, 1146 no such table, ...) is not retried at all. A 1213, 3101 or 1180 is also counted as the end of
    * whatever transaction another holder of the shared link has open on it (countFailedEnd()): that holder finds its
    * transaction lost. After a 1213 or 3101 that rolled such a transaction back, the server keeps the connection in
    * a transaction; a ROLLBACK ends it before the retry (endImplicitTransaction()), so the retry and the statements
    * after it run with autocommit instead of in a transaction nobody commits.
    *
    * Inside a transaction a statement is never retried on its own: the server has rolled the whole transaction
    * back, so the statement would run outside it, with autocommit. The transaction is replayed instead (see
    * queryInTransaction()): every statement run through query() since START TRANSACTION/BEGIN (or
    * transactionBegin(), or the last COMMIT/ROLLBACK AND CHAIN) is recorded, with what the caller could see of
    * its outcome (every row of a result set; affected rows and insert id once affectedRows()/getLastInsertId()
    * read them), and when a statement or COMMIT fails with 1213 or 3101, or a statement other than COMMIT loses
    * the connection (2006, 2013, or a handle mysqli cannot use any more), the transaction is rolled back (or a new
    * connection opened), started again, and every recorded statement re-run in order, each observed outcome
    * checked against the original, before the failed statement is run again. A replay that recovers is silent
    * (one error_log() line; no emailError(), no halt(), no exception) and query() returns the re-run
    * statement's result. Replays go on for up to $transactionReplays (25) tries, waiting 10, 20, 40 ... 640 ms
    * and then 1 s between them, each wait half fixed and half random (so two writers that collided do not collide
    * again), and stop when the next wait would take the time spent replaying past $replayBudgetMs (10 s). The
    * budget bounds the waits, not the statements: a replayed statement that waits for a row lock can block for up
    * to innodb_lock_wait_timeout on its own, so a replay can run past the budget by that much. When the replay
    * cannot recover the transaction (an outcome the caller saw changed, the budget or the tries ran out,
    * or the transaction cannot be replayed: prepare()/execute() inside it, SET (AUTOCOMMIT or any other), a
    * statement outside the replayable list, unlock(), selectDb(), another handle sharing the connection ran a
    * statement inside it, more than $replayMaxStatements statements or $replayMaxBytes bytes, or a new connection
    * is needed and the session state of the old one (a user variable, the selected database, a temporary table, a
    * GET_LOCK, LAST_INSERT_ID(expr), any SET but transaction characteristics) would be lost with it, or connect()
    * has failed so often that one more failure could make the next connect() exit the process) the statement gives
    * up: the server is told to ROLLBACK (best effort, on the transaction's own link while it is usable; a replay
    * that gave up has ended it itself, by ROLLBACK or by closing the connection), the transaction is marked lost,
    * and the statement fails through emailError()/halt() (unless $reportConflicts is false and it failed with
    * 1213/3101; with haltOnError 'no' or 'report' it returns false). A COMMIT that loses the connection is never
    * replayed: it may have committed. Reporting runs through reportFailure(), which keeps the result and
    * Errno/Error: the log write a failing mail causes through this very handle changes none of them.
    *
    * A transaction that is lost while the caller still takes it to be open (a statement in it gave up, a START or
    * transactionBegin() inside it failed, its connection went, it was ended by another holder of the shared link)
    * refuses every later statement sent through query(), execute() or lock() but ROLLBACK, START TRANSACTION/BEGIN
    * (and transactionAbort()/transactionBegin()): it is never sent (sent now, it would commit on its own while the
    * caller takes it for part of the transaction). unlock() is not refused (no refusal error, no report: UNLOCK
    * TABLES writes nothing, and halt() sends it after every failure), nor are selectDb(), prepare() and
    * real_escape(); but while a transaction is open and found lost at that moment (its link closed, replaced, or
    * ended by another holder: linkLostInTransaction()) unlock(), selectDb() and prepare() send nothing (unlock()
    * returns 0, or false for halt()'s unlock(false); prepare() returns 0), and real_escape() escapes without a
    * connection. A refused statement fails with
    * the Errno/Error the transaction was lost with ('Statement refused: ...') and one error_log() line; with
    * haltOnError 'no' or 'report' that is all (false), with 'yes' it fails like the refused COMMIT (emailError(),
    * halt(); not when $reportConflicts is off and it was lost to 1213/3101). What the error report itself writes (its
    * log row through this handle, or through it while another handle reports) is never refused. A ROLLBACK,
    * START/BEGIN or transactionAbort() that succeeds ends the refusal; so does the caller's own COMMIT (refused, see
    * below), after which statements run again (with autocommit) but a COMMIT is still refused.
    *
    * A COMMIT whose transaction was lost is refused (never sent) until a ROLLBACK, START TRANSACTION/BEGIN or
    * transactionAbort() has succeeded (transactionAbort() with no link at all clears it too). The refused COMMIT
    * is a failed statement like any other: emailError(), then halt(), so with haltOnError 'yes' the process ends
    * there and the call returns false only under 'no' and 'report' (transactionCommit() is the exception: it
    * behaves like a failed mysqli_commit(), sets Errno/Error, returns false, and never mails or halts,
    * whatever haltOnError is). Errno/Error of a refusal are those the transaction was lost with (a 2006 stays
    * 2006: the outcome of what it was doing is unknown, so a caller replaying on 1213 must not replay on 2006).
    * A COMMIT that fails with any error ends the transaction and marks it lost; a COMMIT never sent (2014) too:
    * the server still holds the transaction, and a ROLLBACK would be out of sync as well, so the connection is
    * closed, which rolls it back there (never retried: nothing was committed). A START TRANSACTION/BEGIN inside a
    * transaction commits it first and fails like a COMMIT, unless the server never ran it (START_NOT_RUN_ERRORS:
    * it did not parse, or it was never sent): then only the START failed and the transaction stays open. A plain
    * ROLLBACK keeps the retry and reconnect path every statement had before (the connection loss already
    * discarded the writes, so a successful ROLLBACK on a new connection is the truthful answer); if it finally
    * fails, with any error, the transaction is recorded as lost (a COMMIT must not commit what the caller meant to
    * discard) and a connection still open is closed, which ends the transaction on the server. A plain ROLLBACK of a
    * transaction another holder of the shared link ended is not sent at all (rollbackElsewhere(): it would end that
    * holder's transaction) and fails, the transaction lost (2006). A plain ROLLBACK from a handle with no
    * transaction of its own while another handle sharing the link has one open there is not sent either (it would
    * end that handle's transaction): it succeeds as a ROLLBACK with nothing open does (rollbackWithoutTransaction());
    * a ROLLBACK ... AND CHAIN from such a handle begins its chained transaction on a connection of its own, like a
    * START (startApart()). A ROLLBACK meant for another handle's transaction has to go through that handle. ROLLBACK TO
    * SAVEPOINT stays inside the transaction. A START TRANSACTION/BEGIN with none open keeps the retry and reconnect
    * behaviour every statement had before; inTransaction is true only once it has succeeded. Leading SQL comments
    * are ignored when these statements are recognised, a comment between their words is not, and COMMIT/ROLLBACK
    * ... AND CHAIN (not AND CHAIN RELEASE) leave a transaction open, even with none open (MySQL's chain starts one
    * even with autocommit=1). With completion_type=1 (server/session) a plain COMMIT/ROLLBACK chains too; the
    * tracker does not read that variable (deferred). With haltOnError 'no' or 'report' halt() does not throw the
    * mysqli_sql_exception of an UNLOCK TABLES on a connection that is gone; with 'yes' it does, as before, instead of
    * ending the process (the \Error of a half-open link, left by a connect that failed with reporting off, escapes in
    * every mode, as before); an \Error that escapes query() leaves haltOnError as it was.
    *
    * The transaction state (inTransaction, the lost flag and its refusal, the recording) belongs to the instance:
    * clones of a connected handle share the link but not that state. A clone starts with none of it, even one made
    * in the middle of a transaction or after a give-up (__clone()): it has no transaction of its own, refuses
    * nothing, and its statements run inside whatever is open on the shared link on the server.
    * linkHasOpenTransaction() tells whether any handle sharing the link has a transaction open (or lost and not
    * yet ended) there. A START TRANSACTION/BEGIN (or transactionBegin()) of a handle with none open of its own,
    * while another handle sharing its link has one open there, would commit that one: the handle lets go of the
    * shared link (not closed) and begins its transaction on a connection of its own, saying so in one error_log()
    * line (startApart(); not when the link's session state was changed: then the START stays on the shared link,
    * commits the other handle's transaction as MySQL does, and that handle fails its COMMIT with 2006, with one
    * error_log() line saying so; see there). A statement any other handle sends on the shared link while a
    * transaction is recorded makes it not replayable, since a replay could not re-run it; one that ends the
    * transaction (COMMIT, ROLLBACK, START/BEGIN, a statement that commits implicitly: see endsTransaction()) makes it
    * lost (2006: whether its writes were committed is not known to this instance), and the handle whose transaction
    * it was lets go of the link without closing it (the other handle's transaction stays open there), so what it
    * sends next (a ROLLBACK, a START, statements after its refused COMMIT) runs on a connection of its own. Nor is a
    * transaction carried over to another link: a link closed under it (another holder's disconnect() or retry after
    * a connection error) is not reconnected by real_escape(), selectDb(), prepare(), execute(), lock(), unlock() or
    * transactionBegin() while it is open, and a link that replaced its own (a connect() made underneath it) finds
    * it lost at the next statement or COMMIT. A
    * non-transactional table (MyISAM, MEMORY) written inside a transaction would be written twice by a replay
    * (deferred: the GR cluster is InnoDB only).
    *
    * Limits of the replay, for the caller: use ONE handle per transaction (the state is per handle, not per
    * shared link of a get_module_db() clone family); read insert ids and affected rows only through that
    * transaction's own handle (getLastInsertId(), affectedRows(); a raw $db->linkId read is invisible, so a replay
    * that hands out another id could go on); send no raw $db->linkId statements inside a transaction (they are
    * not counted); prepare()/execute() inside a transaction make it not replayable; a SET AUTOCOMMIT=0 outside one
    * opens an implicit transaction the tracker does not see. halt() no longer lets a mysqli_sql_exception from its
    * UNLOCK TABLES escape under haltOnError 'no' or 'report': on a connection that is gone a persistent 2006 now
    * returns false there (it used to throw); under 'yes' it still throws (callers catch it), as before.
    */
    public function query($queryString, $line = '', $file = '', $log = false)
    {
        /* No empty queries, please, since PHP4 chokes on them. */
        /* The empty query string is passed on from the constructor,
        * when calling the class without a query, e.g. in situations
        * like these: '$db = new db_Subclass;'
        */
        if ($queryString == '') {
            return 0;
        }
        // what affectedRows()/getLastInsertId() read from now on belongs to this statement, not a recorded one
        $this->txLastStep = null;
        /*
         * Whether a transaction was open when this statement started, and what kind of statement it
         * is. The state is only updated once the statement has succeeded (see below): a COMMIT that
         * fails must still count as inside it, and a START that fails must not leave the
         * lost-transaction flag cleared.
         */
        $transactionOpen = $this->inTransaction === true;
        $lead = static::leadingKeywordSql($queryString);
        $isStart = preg_match('/^(start\s+transaction|begin)\b/i', $lead) === 1;
        // a plain ROLLBACK is told by a whitelist on the statement with every comment removed: any other form is
        // guarded; the whole-statement scan only runs on a statement that starts with ROLLBACK
        $isRollback = strncasecmp($lead, 'rollback', 8) === 0 && static::isPlainRollback($queryString);
        $isCommit = preg_match('/^commit\b/i', $lead) === 1;
        /*
         * A statement inside a transaction (a plain ROLLBACK aside: when the transaction is already gone a
         * ROLLBACK on a new connection is the true answer, so it keeps the retry and reconnect path) is never
         * retried on its own: see queryInTransaction().
         */
        if ($transactionOpen && !$isRollback) {
            return $this->queryInTransaction($queryString, $line, $file, $log, $lead, $isStart, $isCommit);
        }
        if ($transactionOpen) {
            // a ROLLBACK of a transaction another holder of the shared link ended: sent now, it would end whatever is
            // open there (another handle's transaction), and this one's writes may have been committed
            $elsewhere = $this->transactionElsewhere();
            if ($elsewhere !== '') {
                return $this->rollbackElsewhere($queryString, $line, $file, $elsewhere);
            }
        } elseif ($isRollback && $this->anotherHolderInTransaction()) {
            // this handle has no transaction of its own, and another handle sharing the link has one open there: the
            // ROLLBACK would end that one (one transaction per connection on the server)
            if (preg_match('/\band\s+chain\b(?!\s+release\b)/i', static::withoutComments($lead)) !== 1) {
                return $this->rollbackWithoutTransaction($queryString, $line, $file);
            }
            // AND CHAIN begins a transaction: like a START, on a connection of its own
            $this->startApart();
        }
        if (!$isStart && !$isRollback && !$isCommit && $this->refusing()) {
            // the transaction the caller is still running was lost: sent now, this would commit on its own
            return $this->refuseStatement($queryString, $line, $file);
        }
        /*
         * A START TRANSACTION/BEGIN with no transaction open keeps the control flow it always had: the
         * flag is raised before it runs (once connected) and the guards below read the live flag, as before.
         * Only the bookkeeping differs: inTransaction ends up true only if the statement succeeded.
         */
        $legacyStart = $isStart && !$transactionOpen;
        if ($legacyStart) {
            // never on a link another handle has a transaction open on: the START would commit that one
            $this->startApart();
        }
        // a COMMIT whose transaction was lost is refused without a connection (it is never sent)
        $refused = $isCommit && $this->transactionLost;
        if (!$refused && !$this->connect()) {
            return 0;
            /* we already complained in connect() about that. */
        }
        if ($legacyStart) {
            // raised only once there is a connection: a START that never connected opened nothing
            $this->inTransaction = true;
        }
        $haltPrev = $this->haltOnError;
        $this->haltOnError = 'no';
        // whether the statement ends a transaction: counted per link, for another holder's open transaction
        $ends = static::endsTransaction($lead);
        $done = false;
        try {
            // New query, discard previous result.
            if (is_resource($this->queryId)) {
                $this->free();
            }
            if ($this->Debug) {
                printf("Debug: query = %s<br>\n", $queryString);
            }
            if ($log === true || (isset($GLOBALS['log_queries']) && $GLOBALS['log_queries'] !== false)) {
                $this->log($queryString, $line, $file);
            }
            $tries = 2;
            $try = 0;
            $this->queryId = false;
            $onlyRollback = true;
            $fails = -1;
            if ($refused) {
                /*
                 * The server rolled the transaction back after an earlier statement
                 * in it gave up, so there is nothing to commit: a COMMIT sent now
                 * would succeed as a no-op and report the lost writes as saved.
                 */
                $this->markCommitRefused();
            }
            // whether the next round closes the connection and opens a new one, or runs on the same one
            // (statementRetry())
            $reconnect = true;
            // whether a failure already ended the transaction the server left open after rolling back another handle's
            $implicitEnded = false;
            while ((null === $this->queryId || $this->queryId === false) && $try <= $tries && !$refused) {
                $try++;
                if ($try > 1) {
                    /*
                     * Only a statement outside a transaction gets here (and a plain ROLLBACK), so the connection is
                     * never closed and reopened underneath one. The exception is START TRANSACTION/BEGIN, whose own
                     * flag is up while it runs: if it failed without being retried, it stops here.
                     */
                    if ($legacyStart && $this->inTransaction === true) {
                        error_log('Refusing to reconnect mid-transaction; failing the query instead so the caller sees the broken transaction. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                        $this->inTransaction = false;
                        break;
                    }
                    if ($reconnect) {
                        @mysqli_close($this->linkId);
                        $this->linkId = 0;
                    }
                }
                $start = microtime(true);
                $onlyRollback = true;
                $fails = -1;
                while ($fails < 30 && (null === $this->queryId || $this->queryId === false)) {
                    /*
                     * connect() below would transparently reopen a dropped connection. For a START with its
                     * flag up that is the silent failure we are guarding against, so stop here.
                     */
                    if ($legacyStart && $this->inTransaction === true && !$this->linkIsUsable()) {
                        error_log('Connection lost mid-transaction; failing the query rather than silently reconnecting. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                        $this->inTransaction = false;
                        break;
                    }
                    $this->connect();
                    $fails++;
                    try {
                        $this->countStatement($ends);
                        $this->queryId = @mysqli_query($this->linkId, $queryString, MYSQLI_STORE_RESULT);
                        if (null === $this->queryId || false === $this->queryId) {
                            // a transaction another holder of the shared link has open on it may have gone with this
                            $this->countFailedEnd((int)@mysqli_errno($this->linkId));
                            // and an end the server never ran ended nothing
                            $this->uncountEnd((int)@mysqli_errno($this->linkId), $ends);
                        }
                        if (in_array((int)@mysqli_errno($this->linkId), [1213, 2006, 3101, 1180])) {
                            // a START whose own flag is up is not retried on the same connection (the outer loop reconnects)
                            if ($legacyStart && $this->inTransaction === true) {
                                error_log('Got SQL error '.(int)@mysqli_errno($this->linkId).' inside a transaction; the server has rolled it back, so failing rather than retrying the statement alone. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                                $this->inTransaction = false;
                                break;
                            }
                            // the retry must not run in the transaction the server left open (once per statement)
                            $implicitEnded = $implicitEnded || $this->endImplicitTransaction((int)@mysqli_errno($this->linkId));
                            //error_log("got ".@mysqli_errno($this->linkId)." sql error fails {$fails} on query {$queryString} from {$line}:{$file}");
                            usleep(250000); // 0.25 second
                        } else {
                            $onlyRollback = false;
                            if (in_array((int)@mysqli_errno($this->linkId), [1064])) {
                                $tries = 0;
                            }
                            break;
                        }
                    } catch (\mysqli_sql_exception $e) {
                        $this->countFailedEnd((int)$e->getCode());
                        $this->uncountEnd((int)$e->getCode(), $ends);
                        if (in_array((int)$e->getCode(), [1213, 2006, 3101, 1180])) {
                            // Same reasoning as the non-exception branch above.
                            if ($legacyStart && $this->inTransaction === true) {
                                error_log('Got SQL exception '.(int)$e->getCode().' inside a transaction; the server has rolled it back, so failing rather than retrying the statement alone. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                                $this->inTransaction = false;
                                break;
                            }
                            $implicitEnded = $implicitEnded || $this->endImplicitTransaction((int)$e->getCode());
                            //error_log("got ".$e->getCode()." sql error fails {$fails}");
                            usleep(250000); // 0.25 second
                        } else {
                            error_log('Got mysqli_sql_exception code '.$e->getCode().' error '.static::redactQueryForLog($e->getMessage(), $queryString).' on query '.static::redactQueryForLog($queryString).' from '.$line.':'.$file);
                            $onlyRollback = false;
                            if (in_array((int)@mysqli_errno($this->linkId), [1064])) {
                                $tries = 0;
                            }
                            break;
                        }
                    }
                }
                if (!isset($GLOBALS['disable_db_queries'])) {
                    $this->addLog($queryString, microtime(true) - $start, $line, $file);
                }
                $this->Row = 0;
                $this->Errno = @mysqli_errno($this->linkId);
                $this->Error = @mysqli_error($this->linkId);
                if ($try == 1 && (null === $this->queryId || $this->queryId === false)) {
                    //$this->emailError($queryString, 'Error #'.$this->Errno.': '.$this->Error, $line, $file);
                }
                if (null === $this->queryId || $this->queryId === false) {
                    // what the next round does: close and reconnect, run on the same connection, or nothing
                    $retry = $this->statementRetry((int) $this->Errno, $transactionOpen && $isRollback);
                    if ($retry === null) {
                        $tries = 0;
                    } else {
                        $reconnect = $retry;
                    }
                }
            }
            $done = true;
        } finally {
            // an exception out of the loop (a connect() that threw on a half-open handle) must not leave the caller's
            // haltOnError at 'no', nor a START that opened nothing marked as open
            $this->haltOnError = $haltPrev;
            if (!$done && $legacyStart) {
                $this->inTransaction = false;
            }
        }
        if (null !== $this->queryId && $this->queryId !== false && static::changesSessionState($lead)) {
            // marked once it worked, and on the connection it worked on (the loop above may have reconnected)
            $this->markSessionState();
        }
        if (null === $this->queryId || $this->queryId === false) {
            if ($legacyStart) {
                // a START TRANSACTION/BEGIN that failed opened nothing
                $this->inTransaction = false;
            }
            if ($transactionOpen && $isRollback) {
                // the retries are used up, or the error is not retried: whatever it was, the ROLLBACK did not go
                // through, so a COMMIT must not commit (or report as saved) what the caller meant to discard. The
                // caller ended the transaction itself, so what it sends next is not refused; but the server may
                // still hold the transaction (an error the loop does not retry, such as a syntax error, never
                // reconnected): closing the connection ends it there.
                $this->inTransaction = false;
                $this->markTransactionLost((int) $this->Errno > 0 ? (int) $this->Errno : 2006, (string) $this->Error, false);
                if ($this->linkIsUsable()) {
                    $this->dropLink();
                }
            }
        } else {
            // the state only changes once the statement has worked
            if ($isStart || $isRollback) {
                $this->clearTransactionLost();
            }
            $this->txAfter($this->trackTransactionState($queryString), $transactionOpen, $queryString, $line, $file, $this->queryId);
        }
        if ($this->inTransaction !== true) {
            $this->txEnd();
        }
        // a refused COMMIT has already said why
        if ($onlyRollback === true && false === $this->queryId && !$refused) {
            error_log('Got MySQLi 3101 Rollback Error '.$fails.' Times, Giving Up on '.static::redactQueryForLog($queryString).' from '.$line.':'.$file.' on '.__LINE__.':'.__FILE__);
        }
        if (null === $this->queryId || $this->queryId === false) {
            if ($refused && $this->conflictsToCaller($this->transactionLostErrno)) {
                error_log('COMMIT refused: the transaction was lost (#'.$this->transactionLostErrno.'); not reported, reportConflicts is off. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
            } else {
                $this->reportFailure($queryString, $line, $file);
            }
        }

        // Will return nada if it fails. That's fine.
        return $this->queryId;
    }

    /**
    * A statement inside an open transaction (a plain ROLLBACK aside): sent once, never retried on its own.
    *
    * When it fails because the server rolled the whole transaction back (1213, 3101), or the connection is gone
    * (2006, 2013, a handle closed under it) and it is not a COMMIT, the transaction is replayed
    * (replayTransaction()); see query() for what is recorded and when a replay gives up. A COMMIT, and a
    * START/BEGIN that commits the open transaction first, recovers the same way from 1213/3101, never from a
    * lost connection (it may have committed). Any other failure of a COMMIT ends the transaction (lost; a COMMIT
    * never sent, 2014, too: its connection is closed, which ends the transaction on the server). A START/BEGIN
    * that the server never ran (START_NOT_RUN_ERRORS) fails on its own and leaves the transaction open; any other
    * failure of one ends it. Any other failure of a statement fails only the statement and the transaction stays
    * open, as on the server. A transaction whose link was replaced underneath it (a reconnect) or that another
    * holder of the shared link ended is lost: the statement is not sent (it would run outside the transaction),
    * nothing is replayed, and the link is let go of without being closed (another handle may have a transaction
    * open on it, which closing it would roll back).
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @param bool $log
    * @param string $lead the statement without leading comments
    * @param bool $isStart
    * @param bool $isCommit
    * @return mixed the result, or false
    */
    protected function queryInTransaction($queryString, $line, $file, $log, $lead, $isStart, $isCommit)
    {
        // a START/BEGIN with a transaction open commits it first, so it fails and recovers like a COMMIT
        $commitLike = $isCommit || $isStart;
        $haltPrev = $this->haltOnError;
        $this->haltOnError = 'no';
        $result = false;
        $replay = null;
        $giveUp = false;
        try {
            if (is_resource($this->queryId)) {
                $this->free();
            }
            if ($this->Debug) {
                printf("Debug: query = %s<br>\n", $queryString);
            }
            if ($log === true || (isset($GLOBALS['log_queries']) && $GLOBALS['log_queries'] !== false)) {
                $this->log($queryString, $line, $file);
            }
            $this->queryId = false;
            $start = microtime(true);
            if ($this->txReplayable) {
                if ($this->linkStatementCount() !== $this->txSeq) {
                    $this->txNotReplayable('another handle sharing the connection ran a statement inside it');
                } elseif (!$commitLike && !static::replayableStatement($lead)) {
                    $this->txNotReplayable('it ran a statement a replay cannot repeat ('.static::firstWord($lead).')');
                }
            }
            $exception = null;
            // a handle another holder closed: the transaction went with the connection
            $linkGone = !$this->linkIsUsable();
            // a link that replaced the transaction's own, or a transaction another holder of the shared link ended
            $elsewhere = $linkGone ? '' : $this->transactionElsewhere();
            if ($linkGone) {
                $errno = 2006;
                $error = 'MySQL server has gone away (connection lost mid-transaction)';
            } elseif ($elsewhere !== '') {
                $errno = 2006;
                $error = 'Transaction lost: '.$elsewhere;
            } else {
                list($result, $errno, $error, $exception) = $this->send($queryString, '', static::endsTransaction($lead));
            }
            if ($result === false) {
                $linkLost = $linkGone || in_array($errno, self::LINK_ERRORS, true);
                if ($elsewhere !== '') {
                    // the link is not this transaction's any more, and another handle may have one open there: this
                    // instance lets go of it (what it runs next opens a connection of its own), never closes it (a
                    // quiet ROLLBACK first only while no other handle has a transaction open there:
                    // releaseEndedElsewhere())
                    $this->releaseEndedElsewhere();
                } elseif ($linkLost) {
                    // the connection is dead: whatever runs next must open a new one rather than fail on this one
                    $this->dropLink();
                }
                if ($elsewhere === '' && (in_array($errno, self::REPLAY_ERRORS, true) || ($linkLost && !$commitLike)) && $this->txReplayable) {
                    $replay = $this->replayTransaction($queryString, $commitLike, $errno, $error);
                    $errno = $replay['errno'];
                    $error = $replay['error'];
                    if ($replay['ok']) {
                        $result = $replay['result'];
                    }
                }
                if ($result === false) {
                    // a START/BEGIN the server never ran left the transaction open (its own failure only)
                    $notRun = $isStart && in_array($errno, self::START_NOT_RUN_ERRORS, true);
                    if ($replay !== null && $replay['restored']) {
                        // the transaction is back as it was, but the statement failed on its own
                        $giveUp = $commitLike && !$notRun;
                    } elseif ($linkLost || $elsewhere !== '' || $replay !== null || in_array($errno, self::ROLLED_BACK_ERRORS, true)) {
                        $giveUp = true;
                    } else {
                        // the server rolled back only the statement; a COMMIT (or the START that commits first) that failed
                        // on the server ended the transaction, and a COMMIT never sent (2014) is given up too
                        $giveUp = $commitLike && !$notRun;
                    }
                }
            }
            if (!isset($GLOBALS['disable_db_queries'])) {
                $this->addLog($queryString, microtime(true) - $start, $line, $file);
            }
            $this->Row = 0;
            if ($result !== false) {
                $this->queryId = $result;
                $this->Errno = 0;
                $this->Error = '';
                if (static::changesSessionState($lead)) {
                    $this->markSessionState();
                }
                if ($isStart) {
                    $this->clearTransactionLost();
                }
                $this->txAfter($this->trackTransactionState($queryString), true, $queryString, $line, $file, $result);
                if ($replay !== null) {
                    error_log('Replayed a transaction the server rolled back (SQL error '.$replay['first'].'; '.$replay['attempts'].' replay(s) of '.$replay['steps'].' statement(s)); the statement went through. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                }
            } else {
                $this->Errno = $errno;
                $this->Error = $error;
                if ($giveUp) {
                    // the caller's own COMMIT ends the transaction; anything else (a statement in it, a START/BEGIN or
                    // an AND CHAIN that opens the next one) leaves the caller taking it to be open
                    $endsHere = $isCommit && preg_match('/\band\s+chain\b(?!\s+release\b)/i', static::withoutComments($lead)) !== 1;
                    $this->giveUpTransaction($queryString, $line, $file, $commitLike, $errno, $error, $linkGone, $replay, $elsewhere, $endsHere, static::firstWord($lead));
                } else {
                    if ($replay !== null) {
                        error_log('Replayed a transaction the server rolled back (SQL error '.$replay['first'].'; '.$replay['attempts'].' replay(s) of '.$replay['steps'].' statement(s)); the statement then failed on its own (#'.$errno.'), the transaction is still open. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                    } elseif ($exception !== null) {
                        error_log('Got mysqli_sql_exception code '.$errno.' error '.static::redactQueryForLog($error, $queryString).' on query '.static::redactQueryForLog($queryString).' from '.$line.':'.$file);
                    }
                    if (!$commitLike) {
                        // the statement failed and the transaction stays open: a replay must see it fail the same way.
                        // A START/BEGIN that never ran is not recorded: a replay must never send one mid-transaction.
                        $this->recordError($queryString, $line, $file, $errno);
                    }
                }
            }
        } finally {
            $this->haltOnError = $haltPrev;
        }
        if ($this->inTransaction !== true) {
            $this->txEnd();
        }
        if ($result === false) {
            if (!($giveUp && $this->conflictsToCaller($errno))) {
                $this->reportFailure($queryString, $line, $file);
            }
        }
        return $this->queryId;
    }

    /**
    * A statement inside a transaction gave up: the transaction is over and a later COMMIT is refused.
    *
    * After a deadlock or a certification failure on a statement MySQL keeps the session inside a transaction
    * (trans_rollback_implicit() leaves OPTION_BEGIN set), so the statements that follow would neither
    * autocommit nor ever be committed: a ROLLBACK ends it, on every give-up while the transaction's own link is
    * usable, the server's own rollback of a COMMIT included (a replay that gave up has already ended it: its
    * ROLLBACK went through, or its connection was closed, whatever failed). Not on a link that replaced the transaction's
    * own, nor after another holder of the shared link ended it (whatever is open there now is not this
    * transaction). A COMMIT that never reached the server (2014) cannot be followed by a ROLLBACK either (it
    * would be out of sync too): its connection is closed instead, which ends the transaction on the server.
    * $commitLike is a COMMIT, or a START/BEGIN (which commits the open transaction first). Unless $endsHere (the
    * caller's own COMMIT, which ended the transaction as far as the caller is concerned), later statements are
    * refused until a ROLLBACK, START/BEGIN or transactionAbort() (see query()).
    *
    * @param string $elsewhere why the transaction is not on this instance's link any more ('' when it is)
    * @param bool $endsHere
    * @param string $what the statement's first word, for the log line
    * @return void
    */
    protected function giveUpTransaction($queryString, $line, $file, $commitLike, $errno, $error, $linkGone, $replay, $elsewhere = '', $endsHere = false, $what = 'COMMIT')
    {
        $this->inTransaction = false;
        $this->markTransactionLost((int) $errno, (string) $error, !$endsHere);
        $why = $replay !== null ? ' Not recovered: '.$replay['why'].'.' : ($this->txNotReplayableWhy !== '' ? ' Not replayed: '.$this->txNotReplayableWhy.'.' : '');
        $this->txEnd();
        if ($elsewhere === '' && is_object($this->linkId) && $this->linkIsUsable()) {
            if ($commitLike && (int) $errno === 2014) {
                $this->dropLink();
            } elseif ($replay === null || $replay['restored']) {
                // (a replay that gave up has already ended it: rolled back, or its connection closed)
                $this->sendQuietly('ROLLBACK');
            }
        }
        if ($linkGone) {
            $message = 'Connection lost mid-transaction; failing the query rather than silently reconnecting.';
        } elseif ($elsewhere !== '') {
            $message = 'The transaction is lost ('.$elsewhere.'); failing the query rather than running it outside the transaction.';
        } elseif ($commitLike && in_array((int) $errno, self::LINK_ERRORS, true)) {
            $message = $what.' lost the connection (SQL error '.(int) $errno.'); it may have committed, so it is not replayed, and a later COMMIT is refused.';
        } elseif ($commitLike && (int) $errno === 2014) {
            $message = $what.' was never sent (SQL error 2014, commands out of sync); the transaction is given up and its connection closed, so a later COMMIT is refused.';
        } elseif ($commitLike && !in_array((int) $errno, self::ROLLED_BACK_ERRORS, true)) {
            $message = $what.' failed with SQL error '.(int) $errno.($what === 'COMMIT' ? '' : ' (it commits the open transaction first)').'; the transaction is over, so a later COMMIT is refused.';
        } else {
            $message = 'Got SQL error '.(int) $errno.' inside a transaction; the server has rolled it back, so failing rather than retrying the statement alone.';
        }
        error_log($message.$why.' Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
    }

    /**
    * Reports a failed statement (emailError(), then halt()) and leaves the result and Errno/Error as they were. With
    * $reportFailures off there is no emailError(), only an error_log() line (the caller reports it); halt() still
    * runs.
    *
    * Reporting can run statements on this very handle (the mail failing, its error logged through the
    * application's database handle, which may be this one): they must not turn the failure into a result, nor
    * overwrite the error the caller reads.
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @return void
    */
    protected function reportFailure($queryString, $line, $file)
    {
        $queryId = $this->queryId;
        $errno = $this->Errno;
        $error = $this->Error;
        $reporting = $this->reportingFailure;
        $this->reportingFailure = true;
        try {
            if ($this->reportFailures === false) {
                // the caller reports it, with what it was doing (see Generic::$reportFailures)
                error_log('SQL error #'.$errno.' not reported here (reportFailures is off: the caller reports it). Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
            } else {
                $this->emailError($queryString, 'Error #'.$errno.': '.$error, $line, $file);
            }
            $this->halt('', $line, $file);
        } finally {
            $this->reportingFailure = $reporting;
            $this->queryId = $queryId;
            $this->Errno = $errno;
            $this->Error = $error;
        }
    }

    /**
    * Whether a failure with $errno goes to the caller without emailError()/halt() ($reportConflicts off).
    *
    * @param int $errno
    * @return bool
    */
    protected function conflictsToCaller($errno)
    {
        return $this->reportConflicts === false && in_array((int) $errno, self::REPLAY_ERRORS, true);
    }

    /**
    * Replays the open transaction after the server rolled it back (or the connection went), then runs $sql again.
    *
    * Each attempt waits (replayDelayUs(): 10, 20, 40 ... 640 ms, then 1 s, half of it random), ends what is left
    * on the server (ROLLBACK, or a new connection when the old one is gone or its ROLLBACK failed), starts the
    * transaction the way it was started, and re-runs every recorded statement in order, comparing what the caller
    * observed of each (every result set; affected rows and insert id once read; the error of a statement that
    * failed) with the original. A difference ends the replay: the caller decided on data that has changed. A
    * 1213/3101 or a lost connection during an attempt starts the next one, and so does a new connection that could
    * not be opened. When every statement matched, $sql runs again: if it works the recording now holds the
    * replayed outcomes and the result is returned; if it fails on its own (another error) the transaction is
    * restored and only the statement failed ('restored'). A COMMIT ($commitLike) that loses the connection ends
    * it: its outcome is unknown. The replay stops, giving up, when the next wait would pass $replayBudgetMs, after
    * $transactionReplays attempts, when it needs a new connection and the session state of the old one would be
    * lost, or when a new connection is needed and connect() has failed so often that one more failure would bring
    * connectionAttempt to where the next connect() exits the process (see replayReset()). Whatever the replay
    * leaves open when it gives up is rolled back (or its connection closed).
    *
    * @param string $sql the statement to run again ('COMMIT' with $api 'commit': mysqli_commit(); 'START TRANSACTION'
    *                    with $api 'begin': mysqli_begin_transaction())
    * @param bool $commitLike
    * @param int $errno the error the statement failed with
    * @param string $error
    * @param string $api '', 'begin' or 'commit'
    * @return array{ok: bool, restored: bool, result: mixed, errno: int, error: string, first: int, why: string, attempts: int, steps: int}
    */
    protected function replayTransaction($sql, $commitLike, $errno, $error, $api = '')
    {
        $this->replaying = true;
        try {
            return $this->replayAttempts($sql, $commitLike, $errno, $error, $api);
        } finally {
            $this->replaying = false;
        }
    }

    /**
    * The attempts of replayTransaction(), which keeps the $replaying flag up around them.
    *
    * @return array see replayTransaction()
    */
    protected function replayAttempts($sql, $commitLike, $errno, $error, $api)
    {
        $steps = $this->txSteps;
        $opener = $this->txOpener;
        $out = [
            'ok' => false,
            'restored' => false,
            'result' => false,
            'errno' => (int) $errno,
            'error' => (string) $error,
            'first' => (int) $errno,
            'why' => (int) $this->transactionReplays < 1 ? 'replays are off ($transactionReplays is '.(int) $this->transactionReplays.')' : 'it was rolled back again on each of '.(int) $this->transactionReplays.' replay(s)',
            'attempts' => 0,
            'steps' => count($steps),
        ];
        $began = $this->replayClock();
        for ($attempt = 1; $attempt <= (int) $this->transactionReplays; $attempt++) {
            $wait = $this->replayDelayUs($attempt);
            if (($this->replayClock() - $began) * 1000000 + $wait > (int) $this->replayBudgetMs * 1000) {
                // the next wait would pass the budget
                $out['why'] = $attempt === 1
                    ? 'the replay budget of '.(int) $this->replayBudgetMs.' ms is shorter than the first wait'
                    : 'it was rolled back again on each of '.($attempt - 1).' replay(s) within the '.(int) $this->replayBudgetMs.' ms budget';
                $this->replayAbandon();
                return $out;
            }
            $out['attempts'] = $attempt;
            $this->replaySleep($wait);
            $reset = $this->replayReset();
            if ($reset !== '') {
                if (strncmp($reset, 'retry:', 6) === 0) {
                    // no new connection this time: it counts as an attempt, and the next one tries again
                    $out['why'] = substr($reset, 6);
                    continue;
                }
                $out['why'] = $reset;
                return $out;
            }
            // the transaction runs on this link from now on (a new one, when the replay had to reconnect)
            $this->txLink = is_object($this->linkId) ? $this->linkId : null;
            $this->txEnds = $this->linkEndCount();
            $this->txEndedElsewhere = false;
            list($res, $e, $m) = $opener === null ? $this->send('START TRANSACTION', 'begin') : $this->send($opener, '', true);
            if ($res === false) {
                if ($this->replayRetryable($e)) {
                    $out['errno'] = $e;
                    $out['error'] = $m;
                    continue;
                }
                $out['why'] = 'the transaction could not be started again (#'.$e.')';
                break;
            }
            $fresh = [];
            $bytes = 0;
            foreach ($steps as $index => $step) {
                list($res, $e, $m) = $this->send($step['sql']);
                if ($res === false && $this->replayRetryable($e)) {
                    $out['errno'] = $e;
                    $out['error'] = $m;
                    continue 2;
                }
                $mismatch = $this->replayStep($step, $res, $e, $fresh, $bytes);
                if ($mismatch !== '') {
                    $out['why'] = $mismatch.' (statement '.($index + 1).' of '.count($steps).')';
                    break 2;
                }
            }
            list($res, $e, $m) = $this->send($sql, $api, $commitLike);
            if ($res !== false) {
                $this->txSteps = $fresh;
                $this->txBytes = $bytes;
                $out['ok'] = true;
                $out['result'] = $res;
                $out['errno'] = 0;
                $out['error'] = '';
                return $out;
            }
            $out['errno'] = $e;
            $out['error'] = $m;
            if (in_array($e, self::REPLAY_ERRORS, true)) {
                continue;
            }
            if (in_array($e, self::LINK_ERRORS, true) || !$this->linkIsUsable()) {
                if ($commitLike) {
                    // it may have committed: never replayed again, and the caller is told 2006, not a deadlock
                    $this->dropLink();
                    $out['why'] = 'the replayed '.static::firstWord(static::leadingKeywordSql($sql)).' lost the connection, so its outcome is unknown';
                    return $out;
                }
                continue;
            }
            $this->txSteps = $fresh;
            $this->txBytes = $bytes;
            $out['restored'] = true;
            return $out;
        }
        $this->replayAbandon();
        return $out;
    }

    /**
    * How long replay attempt number $attempt waits first, in microseconds: exponential (10, 20, 40 ... 640 ms,
    * then 1000 ms) with equal jitter (half fixed, half random), so two writers that collided do not collide
    * again in lockstep.
    *
    * @param int $attempt 1 for the first replay
    * @return int
    */
    protected function replayDelayUs($attempt)
    {
        $base = min(1000000, 10000 * (1 << min($attempt - 1, 7)));
        $half = intdiv($base, 2);
        return $half + $this->replayJitterUs($base - $half);
    }

    /**
    * The random part of a replay wait, 0 to $max microseconds (a seam: tests make it deterministic).
    *
    * @param int $max
    * @return int
    */
    protected function replayJitterUs($max)
    {
        try {
            return random_int(0, $max);
        } catch (\Throwable $e) {
            return mt_rand(0, $max);
        }
    }

    /**
    * The clock the replay budget is measured on, in seconds (a seam).
    *
    * @return float
    */
    protected function replayClock()
    {
        return microtime(true);
    }

    /**
    * Waits between replay attempts (a seam).
    *
    * @param int $microseconds
    * @return void
    */
    protected function replaySleep($microseconds)
    {
        usleep($microseconds);
    }

    /**
    * Re-runs one recorded statement's comparison: '' when what the caller observed is unchanged (and $fresh gets the
    * replayed step), else why not.
    *
    * @param array $step
    * @param mixed $res
    * @param int $errno
    * @param array[] $fresh
    * @param int $bytes
    * @return string
    */
    protected function replayStep(array $step, $res, $errno, array &$fresh, &$bytes)
    {
        if (isset($step['errno'])) {
            if ($res !== false) {
                return 'a statement that failed now succeeds';
            }
            if ((int) $errno !== $step['errno']) {
                return 'a statement that failed with #'.$step['errno'].' now fails with #'.(int) $errno;
            }
            $fresh[] = $step;
            $bytes += $step['bytes'];
            return '';
        }
        if ($res === false) {
            return 'a statement failed with #'.(int) $errno;
        }
        $now = $this->fingerprint($step['sql'], $step['line'], $step['file'], $res, (int) $this->replayMaxBytes - $bytes);
        if ($now === null) {
            return 'the outcome of a statement could not be read';
        }
        if (isset($now['tooLarge'])) {
            // the original fitted under $replayMaxBytes, so this one is not the same
            return 'a result set it read changed (it grew past the replay size limit)';
        }
        if (isset($step['rows']) || isset($now['rows'])) {
            if (!isset($step['rows'], $now['rows']) || $step['rows'] !== $now['rows']) {
                return 'a result set it read changed';
            }
        } else {
            if ($step['seenAffected'] && $now['affected'] !== $step['affected']) {
                return 'the affected rows it read changed ('.$step['affected'].', now '.$now['affected'].')';
            }
            if ($step['seenId'] && $now['id'] !== $step['id']) {
                return 'the insert id it read changed';
            }
            $now['seenAffected'] = $step['seenAffected'];
            $now['seenId'] = $step['seenId'];
        }
        $fresh[] = $now;
        $bytes += $now['bytes'];
        return '';
    }

    /**
    * Ends what a replay attempt finds on the server: a ROLLBACK, or a new connection when the old one is gone or its
    * ROLLBACK failed (whatever the error: closing the connection is what makes sure the server holds no transaction
    * for it any more, so the dead or doubtful link is always let go of first).
    *
    * A new connection loses the session state of the old one, so it is not opened when that state was changed
    * (the replay gives up). Nor when connect() has failed so often in a row that a failure now could bring
    * connectionAttempt to maxConnectErrors - 2: connect() exit()s the process once it reaches maxConnectErrors - 1,
    * so the replay stops one failure short of that, and the caller's own next statement still gets a real
    * connect() (with the default maxConnectErrors of 5: at most two failed reconnects per run of failures).
    *
    * @return string '' when there is a connection with no transaction on it; 'retry:' and why, when a new
    *                connection could not be opened (the next attempt may try again); else why the replay gives up
    */
    protected function replayReset()
    {
        $failed = '';
        if (is_object($this->linkId) && $this->linkEstablished && $this->linkIsUsable()) {
            list($res, $e) = $this->send('ROLLBACK', '', true);
            if ($res !== false) {
                return '';
            }
            $failed = ' (the ROLLBACK before it failed with #'.(int) $e.')';
        }
        // gone, half-open, or its ROLLBACK failed: let go of it (dropLink() keeps what was known of its session state)
        $this->dropLink();
        if ($this->sessionStateChanged()) {
            return 'it needs a new connection and the session state set on the old one (a user variable, selected database, temporary table, lock...) would be lost'.$failed;
        }
        if ((int) $this->connectionAttempt >= (int) $this->maxConnectErrors - 3) {
            return 'it needs a new connection and connect() has failed '.(int) $this->connectionAttempt.' time(s) in a row: one more failure would leave the next connect() to exit the process'.$failed;
        }
        try {
            $link = $this->connect();
        } catch (\Throwable $e) {
            $link = 0;
        }
        if (is_object($link) && $this->linkEstablished) {
            return '';
        }
        // a connect that failed leaves a half-open handle behind: nothing may touch it (the give-up included)
        $this->dropLink();
        return 'retry:no new connection could be opened for it'.$failed;
    }

    /**
    * Rolls back what an abandoned replay left open; when that ROLLBACK fails (whatever the error), or the
    * connection is gone, the connection is let go of, which ends the transaction on the server.
    *
    * @return void
    */
    protected function replayAbandon()
    {
        if (is_object($this->linkId) && $this->linkIsUsable()) {
            list($res) = $this->send('ROLLBACK', '', true);
            if ($res !== false) {
                return;
            }
        }
        $this->dropLink();
    }

    /**
    * Whether a replay attempt that failed with $errno may try again (rolled back, or the connection went).
    *
    * @param int $errno
    * @return bool
    */
    protected function replayRetryable($errno)
    {
        return in_array($errno, self::REPLAY_ERRORS, true) || in_array($errno, self::LINK_ERRORS, true);
    }

    /**
    * Sends one statement and reports its outcome instead of throwing (MYSQLI_REPORT_STRICT or not).
    *
    * @param string $sql
    * @param string $api '' (mysqli_query()), 'begin' (mysqli_begin_transaction()) or 'commit' (mysqli_commit())
    * @param bool|null $ends whether the statement ends a transaction (null: tell from $sql; see endsTransaction())
    * @return array [result or false, errno, error, the exception caught or null]
    */
    protected function send($sql, $api = '', $ends = null)
    {
        if ($ends === null) {
            $ends = $api !== '' || static::endsTransaction(static::leadingKeywordSql($sql));
        }
        $this->countStatement($ends);
        try {
            if ($api === 'begin') {
                $result = mysqli_begin_transaction($this->linkId);
            } elseif ($api === 'commit') {
                $result = mysqli_commit($this->linkId);
            } else {
                $result = @mysqli_query($this->linkId, $sql, MYSQLI_STORE_RESULT);
            }
            if ($result === false) {
                $errno = (int) @mysqli_errno($this->linkId);
                // an end the server never ran ended nothing
                $this->uncountEnd($errno, $ends);
                return [false, $errno, (string) @mysqli_error($this->linkId), null];
            }
            return [$result, 0, '', null];
        } catch (\mysqli_sql_exception $e) {
            $this->uncountEnd((int) $e->getCode(), $ends);
            return [false, (int) $e->getCode(), $e->getMessage(), $e];
        } catch (\Error $e) {
            // a closed handle, or none at all: the connection is gone
            return [false, 2006, 'MySQL server has gone away ('.$e->getMessage().')', $e];
        }
    }

    /**
    * Sends a statement whose outcome does not matter (a best-effort ROLLBACK); a connection found gone is let go of.
    *
    * @param string $sql
    * @return void
    */
    protected function sendQuietly($sql)
    {
        if (!is_object($this->linkId) || !$this->linkIsUsable()) {
            return;
        }
        list($res, $e) = $this->send($sql, '', true);
        if ($res === false && in_array($e, self::LINK_ERRORS, true)) {
            $this->dropLink();
        }
    }

    /**
    * Closes the connection (it is gone, or about to be replaced) and lets go of it, so the next call connects anew.
    *
    * @return void
    */
    protected function dropLink()
    {
        if (is_object($this->linkId)) {
            if (self::$linkSessionState !== null && isset(self::$linkSessionState[$this->linkId])) {
                // what was known of the link's session state (set by this instance or a clone sharing it) is kept:
                // once linkId is let go of, sessionStateChanged() can no longer look the link up
                $this->sessionDirty = true;
            }
            try {
                @mysqli_close($this->linkId);
            } catch (\Throwable $e) {
                // already closed by another holder of the shared handle
            }
        }
        $this->linkId = 0;
        $this->linkEstablished = false;
    }

    /**
    * Lets go of the connection without closing it (other holders of the shared link keep it, and whatever transaction
    * is open on it), so the next call of this instance connects anew. Unlike dropLink() it keeps nothing of the
    * link's session state: it is let go of only once this instance has no transaction left to replay on it, and the
    * connect() that follows starts afresh.
    *
    * @return void
    */
    protected function releaseLink()
    {
        $this->linkId = 0;
        $this->linkEstablished = false;
    }

    /**
    * Lets go of the link of an open transaction found lost to another holder of the shared link
    * (transactionElsewhere()), without closing it (releaseLink()); before that, while the transaction still runs on
    * this link and no other handle sharing it has a transaction open there, a quiet ROLLBACK.
    *
    * The count that found the transaction ended can be wrong: a statement taken for an end (endsTransaction()) that
    * ended nothing on the server (SET autocommit=1 with autocommit already on, SET autocommit=0, a DDL the server
    * refused before its implicit commit, ...) leaves the transaction open on the connection, its writes waiting for
    * whatever COMMIT or START TRANSACTION comes next there, while this instance gives the transaction up. The ROLLBACK
    * ends it; when the transaction was really ended (committed or rolled back) it rolls back nothing. It is not sent
    * while another handle has a transaction open there (it would end that one), nor on a link that replaced the
    * transaction's own (the transaction went with the old link). The transaction is lost either way: whether its
    * writes were committed by whatever ended it is not known here.
    *
    * @return void
    */
    protected function releaseEndedElsewhere()
    {
        if (is_object($this->linkId) && $this->linkId === $this->txLink && $this->linkIsUsable() && !$this->anotherHolderInTransaction()) {
            $this->sendQuietly('ROLLBACK');
        }
        $this->releaseLink();
    }

    /**
    * Takes back the end of a transaction counted for a statement of this instance's (countStatement($ends)) that the
    * server never ran (NOT_RUN_ERRORS: it did not parse, or it was never sent): whatever transaction was open on the
    * link is still open, so neither the handle whose transaction it is (it would give it up as ended elsewhere,
    * without a ROLLBACK, its writes left open on the shared connection) nor this instance (its own count is taken
    * back with it) may take it for ended.
    *
    * @param int $errno what the statement failed with
    * @param bool $ends whether it was counted as an end
    * @return void
    */
    protected function uncountEnd($errno, $ends)
    {
        if (!$ends || !in_array((int) $errno, self::NOT_RUN_ERRORS, true) || self::$linkEnds === null || !is_object($this->linkId)) {
            return;
        }
        $count = isset(self::$linkEnds[$this->linkId]) ? self::$linkEnds[$this->linkId] : 0;
        if ($count < 1) {
            return;
        }
        self::$linkEnds[$this->linkId] = $count - 1;
        if ($this->txEnds === $count && $this->linkId === $this->txLink) {
            $this->txEnds = $count - 1;
        }
    }

    /**
    * What query()'s retry of a failed statement outside a transaction does next: true closes the connection and
    * opens a new one (as every retry did before), false runs the statement again on the same connection, null does
    * not retry it.
    *
    * Closing a connection rolls back whatever transaction is open on it, and clones share it: another handle's
    * transaction would go with it. So it is closed only when it is dead anyway (RECONNECT_ERRORS), after a 2014
    * (commands out of sync: the connection is alive, a new one clears it) only while no other handle sharing it has
    * a transaction open there, and for a plain ROLLBACK of this instance's own transaction (that ROLLBACK ends the
    * transaction on the connection, which is this instance's: see query()). An error the same statement would hit
    * again (NO_RETRY_ERRORS) is not retried; any other error is retried on the same connection.
    *
    * @param int $errno
    * @param bool $ownRollback a plain ROLLBACK sent with this instance's transaction open
    * @return bool|null
    */
    protected function statementRetry($errno, $ownRollback)
    {
        if (in_array($errno, self::NO_RETRY_ERRORS, true)) {
            return null;
        }
        if ($ownRollback || in_array($errno, self::RECONNECT_ERRORS, true)) {
            return true;
        }
        if ($errno === 2014) {
            return !$this->anotherHolderInTransaction();
        }
        return false;
    }

    /**
    * The other instances that hold this instance's link now (each one that sent a statement on it).
    *
    * @return Db[]
    */
    protected function otherLinkHolders()
    {
        if (self::$linkHolders === null || !is_object($this->linkId) || !isset(self::$linkHolders[$this->linkId])) {
            return [];
        }
        $holders = [];
        foreach (self::$linkHolders[$this->linkId] as $holder => $unused) {
            if ($holder !== $this && $holder->linkId === $this->linkId) {
                $holders[] = $holder;
            }
        }
        return $holders;
    }

    /**
    * Whether another instance sharing this instance's link has a transaction open on it.
    *
    * @return bool
    */
    protected function anotherHolderInTransaction()
    {
        foreach ($this->otherLinkHolders() as $holder) {
            if ($holder->inTransaction === true) {
                return true;
            }
        }
        return false;
    }

    /**
    * Whether a transaction is open, or lost and not yet ended by its caller (lostTransactionPending()), on this
    * instance's connection: this instance's own, or that of any other handle sharing the connection (a clone, a
    * get_module_db() handle). A START TRANSACTION, COMMIT or ROLLBACK sent through this handle now would end it, and
    * a write would join it; code that must not touch such a transaction (MyAdmin's Session::deleteSessionRows()) runs
    * its work on a connection of its own instead (newConnection()). Cheap: no statement is sent, and only the
    * handles that have used this connection are asked. Without \WeakMap (PHP 7) only this instance is asked. With
    * $othersOnly only the other handles are asked (this instance's own transaction is left out: a caller that ends
    * that one itself, as Session does at shutdown, still must not end another handle's).
    *
    * @param bool $othersOnly
    * @return bool
    */
    public function linkHasOpenTransaction($othersOnly = false)
    {
        if (!$othersOnly && ($this->inTransaction === true || $this->lostTransactionPending())) {
            return true;
        }
        foreach ($this->otherLinkHolders() as $holder) {
            if ($holder->inTransaction === true || $holder->lostTransactionPending()) {
                return true;
            }
        }
        return false;
    }

    /**
    * Before a START TRANSACTION/BEGIN of this instance's own (it has none open): when another handle sharing the link
    * has a transaction open there, the START would commit that transaction (MySQL commits the open one first, then
    * starts the new one), so this instance lets go of the shared link (it is not closed: the other handle keeps it
    * and its transaction) and its transaction runs on a connection of its own, opened by the connect() that follows.
    * Not when the session state of the shared link was changed (a user variable, a temporary table, a SET...): a new
    * connection would not have it, so the START goes out on the shared link as it always did. That is MySQL's own
    * semantics (one transaction per connection: the START commits the open one first): the other handle's writes so
    * far are committed by it, that handle finds its transaction lost (transactionElsewhere()), refuses its next
    * statements and fails its COMMIT with 2006 (outcome unknown to it), honestly, rather than reporting the rest as
    * part of a transaction that is gone. No request path of MyAdmin does this (it would take two handles of one clone
    * family each running a transaction, one of them after changing the session state). Either way one error_log()
    * line says so, with no SQL and no values. A connection of its own keeps the server's innodb_lock_wait_timeout:
    * a moved START whose statements wait for row locks the other handle holds in the same request waits that long
    * (by default 50 s), then fails with 1205, instead of silently committing the other handle's work.
    *
    * @return void
    */
    protected function startApart()
    {
        if (!is_object($this->linkId) || !$this->anotherHolderInTransaction()) {
            return;
        }
        if ($this->sessionStateChanged()) {
            error_log('START TRANSACTION on a connection another handle has a transaction open on: it commits that transaction (the session state of the connection was changed, so the START is not moved to a connection of its own)');
            return;
        }
        error_log('START TRANSACTION on a connection another handle has a transaction open on: opening a connection of its own for it, so as not to commit that transaction');
        $this->releaseLink();
    }

    /**
    * A plain ROLLBACK (query()) of a transaction another holder of the shared link ended, or whose link was replaced
    * underneath it (transactionElsewhere()): it is not sent as asked, and it does not report success, since the
    * writes may have been committed by whatever ended the transaction. The transaction is over and marked lost (2006:
    * its outcome is not known to this instance; the caller ended it, so what it sends next is not refused, but a
    * COMMIT is until a ROLLBACK, START or transactionAbort() succeeds), this instance lets go of the link without
    * closing it (a quiet ROLLBACK first only while no other handle has a transaction open there, in case the end was
    * miscounted: releaseEndedElsewhere()), and the ROLLBACK fails like any failed statement (emailError(), halt()).
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @param string $elsewhere transactionElsewhere()
    * @return false
    */
    protected function rollbackElsewhere($queryString, $line, $file, $elsewhere)
    {
        if (is_resource($this->queryId)) {
            $this->free();
        }
        $this->inTransaction = false;
        $this->markTransactionLost(2006, 'Transaction lost: '.$elsewhere, false);
        $this->releaseEndedElsewhere();
        $this->txEnd();
        $this->queryId = false;
        $this->Row = 0;
        $this->Errno = 2006;
        $this->Error = 'ROLLBACK not sent: the transaction is lost ('.$elsewhere.')';
        error_log('ROLLBACK not sent: the transaction is lost ('.$elsewhere.'); sent now it would end whatever is open on the shared connection. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
        $this->reportFailure($queryString, $line, $file);
        return false;
    }

    /**
    * A plain ROLLBACK (query(), not AND CHAIN) of a handle that has no transaction of its own, while another handle
    * sharing the link has one open there (a cleanup after a give-up, a ROLLBACK "just in case"): it is not sent.
    * The server keeps one transaction per connection, so it would end the other handle's, whose writes would be gone
    * and whose COMMIT would then fail. This handle has nothing to roll back, so it is answered as MySQL answers a
    * ROLLBACK with nothing open: it succeeds (true, Errno 0), and a lost record this handle kept (a transaction it
    * gave up, already rolled back or let go of) is cleared, as by any successful ROLLBACK. One error_log() line says
    * so. A ROLLBACK ... RELEASE is not sent either (it would also close the connection under that handle). A ROLLBACK
    * meant for another handle's transaction has to be sent through that handle (or transactionAbort() on it).
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @return true
    */
    protected function rollbackWithoutTransaction($queryString, $line, $file)
    {
        if (is_resource($this->queryId)) {
            $this->free();
        }
        $this->clearTransactionLost();
        $this->txEnd();
        $this->queryId = true;
        $this->Row = 0;
        $this->Errno = 0;
        $this->Error = '';
        error_log('ROLLBACK not sent: this handle has no transaction open, and another handle sharing the connection has one open there, which it would end. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
        return true;
    }

    /**
    * Counts a statement this instance sends on its link (clones share the link, and with it the count), and, when it
    * ends a transaction, the link's count of those too.
    *
    * While this instance has a transaction open on the link, a count of transaction-ending statements that moved
    * since its own last statement means another holder ended the transaction: that is remembered
    * ($txEndedElsewhere) before this statement is added, so no statement of this instance can hide it.
    *
    * @param bool $ends whether the statement ends a transaction (see endsTransaction())
    * @return void
    */
    protected function countStatement($ends = false)
    {
        if (!is_object($this->linkId)) {
            return;
        }
        if (self::$linkStatements === null || self::$linkEnds === null) {
            if (!class_exists('WeakMap')) {
                return;
            }
            self::$linkStatements = self::$linkStatements === null ? new \WeakMap() : self::$linkStatements;
            self::$linkEnds = new \WeakMap();
        }
        // this instance holds the link (linkHasOpenTransaction() asks every holder)
        if (self::$linkHolders === null) {
            self::$linkHolders = new \WeakMap();
        }
        if (!isset(self::$linkHolders[$this->linkId])) {
            self::$linkHolders[$this->linkId] = new \WeakMap();
        }
        $holders = self::$linkHolders[$this->linkId];
        $holders[$this] = true;
        $count = (isset(self::$linkStatements[$this->linkId]) ? self::$linkStatements[$this->linkId] : 0) + 1;
        self::$linkStatements[$this->linkId] = $count;
        if ($this->txSeq !== null) {
            $this->txSeq = $count;
        }
        $tracked = $this->txEnds !== null && $this->linkId === $this->txLink;
        $endCount = isset(self::$linkEnds[$this->linkId]) ? self::$linkEnds[$this->linkId] : 0;
        if ($tracked && $endCount !== $this->txEnds) {
            $this->txEndedElsewhere = true;
        }
        if ($ends) {
            self::$linkEnds[$this->linkId] = ++$endCount;
        }
        if ($tracked) {
            $this->txEnds = $endCount;
        }
    }

    /**
    * Counts, after a statement this instance sent failed with one of ROLLED_BACK_ERRORS (the server rolled the
    * connection's transaction back), the end of whatever transaction was open on its link. A statement sent outside
    * a transaction of this instance's own can still run inside one another holder of the shared link has open on it
    * (its deadlock rolls that transaction back); counted like a ROLLBACK, the other holder finds its transaction lost
    * (transactionElsewhere()) instead of sending its next statements outside it and committing what is left as if
    * nothing had gone. A lost connection (LINK_ERRORS) needs no count: the other holder's next statement or COMMIT
    * fails on the same dead or closed link. Any other failure leaves the transaction open on the server.
    *
    * @param int $errno
    * @return void
    */
    protected function countFailedEnd($errno)
    {
        // (only a plain ROLLBACK of this instance's own transaction gets here with one open: it ends it either way)
        if (!is_object($this->linkId) || self::$linkEnds === null || !in_array((int) $errno, self::ROLLED_BACK_ERRORS, true)) {
            return;
        }
        self::$linkEnds[$this->linkId] = (isset(self::$linkEnds[$this->linkId]) ? self::$linkEnds[$this->linkId] : 0) + 1;
    }

    /**
    * After a statement of this instance's, sent outside a transaction of its own, failed with 1213 or 3101 while
    * another handle sharing the link had a transaction open there: the server rolled that transaction back and, as
    * after any deadlock inside START TRANSACTION, left the connection in a transaction (MySQL's
    * trans_rollback_implicit() keeps OPTION_BEGIN set). The retry of the statement, and every later statement on the
    * connection, would then run inside a transaction nobody commits (lost when the connection closes, or committed
    * by whatever COMMIT or START comes next). A ROLLBACK ends it while it is still empty (nothing ran on the
    * connection since); the other handle's transaction is gone either way, and that handle finds it lost
    * (countFailedEnd()). query() calls it before the wait of its retry (a START TRANSACTION/BEGIN that fails is
    * retried as a whole instead, which ends it too), execute() after its failure.
    *
    * @param int $errno
    * @return bool whether a ROLLBACK was sent
    */
    protected function endImplicitTransaction($errno)
    {
        if (!in_array((int) $errno, self::REPLAY_ERRORS, true) || !$this->anotherHolderInTransaction()) {
            return false;
        }
        $this->sendQuietly('ROLLBACK');
        return true;
    }

    /**
    * How many transaction-ending statements any handle has sent on this instance's link (null: not counted).
    *
    * @return int|null
    */
    protected function linkEndCount()
    {
        if (self::$linkEnds === null) {
            if (!is_object($this->linkId) || !class_exists('WeakMap')) {
                return null;
            }
            return 0;
        }
        if (!is_object($this->linkId)) {
            return null;
        }
        return isset(self::$linkEnds[$this->linkId]) ? self::$linkEnds[$this->linkId] : 0;
    }

    /**
    * '' while the open transaction runs, as far as this instance can tell, on its own link; else why not: the link
    * in linkId replaced the one the transaction started on (something reconnected underneath it), or another
    * holder of the shared link ended the transaction (COMMIT, ROLLBACK, START TRANSACTION/BEGIN, a statement that
    * commits implicitly, a statement whose failure made the server roll it back: countFailedEnd()), so its writes
    * were committed or rolled back without this instance.
    *
    * @return string
    */
    protected function transactionElsewhere()
    {
        if (is_object($this->txLink) && $this->linkId !== $this->txLink) {
            return 'the connection it ran on was replaced underneath it (a reconnect), and it went with the old one';
        }
        if ($this->txEndedElsewhere || ($this->txEnds !== null && $this->linkEndCount() !== $this->txEnds)) {
            return 'another handle sharing the connection ended it (COMMIT, ROLLBACK, START TRANSACTION, a statement that commits implicitly, or a statement whose failure made the server roll it back), so whether its writes were committed is unknown';
        }
        return '';
    }

    /**
    * Whether the statement ends an open transaction on the server: COMMIT and ROLLBACK (not ROLLBACK TO
    * SAVEPOINT), START TRANSACTION/BEGIN, and the statements MySQL commits implicitly before running (DDL other than
    * on temporary tables, account management, LOCK TABLES, SET autocommit, table maintenance, FLUSH, RESET...).
    * UNLOCK TABLES is not counted (it commits only after LOCK TABLES, which already did; halt() sends it after
    * every failed statement). Matched on the leading keywords only, so it costs nothing on a long statement; a
    * doubtful form counts as an end. The count is made before the statement is sent: one the server never ran
    * (NOT_RUN_ERRORS) is taken back (uncountEnd()). Any other miscount (SET autocommit=1 with autocommit already on,
    * SET autocommit=0, a DDL refused before its implicit commit) makes the holder of the open transaction give it
    * up although it is still open on the server: that holder is told it is lost (2006), and rolls it back with a
    * quiet ROLLBACK when no other handle has a transaction open there (releaseEndedElsewhere()), so its writes are
    * not left for the next COMMIT or START on the connection to commit.
    *
    * @param string $lead the statement without leading comments
    * @return bool
    */
    protected static function endsTransaction($lead)
    {
        if (preg_match('/^(?:start\s+transaction|begin|commit|rollback|alter|create|drop|rename|truncate|grant|revoke|lock\s+tables?|install|uninstall|analyze|check\s+table|optimize|repair|flush|reset|cache\s+index|load\s+index|set\s+password|set\b[^;]*?\bautocommit\b)/i', $lead, $match) !== 1) {
            return false;
        }
        $word = strtolower(substr($match[0], 0, 4));
        if ($word === 'roll') {
            // ROLLBACK TO [SAVEPOINT] stays inside the transaction (a comment between its words must not hide that)
            return preg_match('/^rollback\s+(?:work\s+)?to\b/i', static::withoutComments($lead)) !== 1;
        }
        if ($word === 'crea' || $word === 'drop') {
            // CREATE/DROP TEMPORARY TABLE does not commit
            return preg_match('/^(?:create|drop)\s+temporary\b/i', $lead) !== 1;
        }
        return true;
    }

    /**
    * How many statements any handle has sent on this instance's link (null: not counted).
    *
    * @return int|null
    */
    protected function linkStatementCount()
    {
        if (self::$linkStatements === null || !is_object($this->linkId)) {
            return null;
        }
        return isset(self::$linkStatements[$this->linkId]) ? self::$linkStatements[$this->linkId] : 0;
    }

    /**
    * Whether the statement changes the state of the session a new connection would not have: SET of a
    * session or user variable (transaction characteristics aside), USE, CREATE/DROP TEMPORARY TABLE,
    * a user variable assignment or SELECT ... INTO @var, GET_LOCK/RELEASE_LOCK, LAST_INSERT_ID(expr).
    *
    * @param string $lead the statement without leading comments
    * @return bool
    */
    protected static function changesSessionState($lead)
    {
        if (preg_match('/^set\b/i', $lead) === 1) {
            return preg_match('/^set\s+(?:(?:session|global|local)\s+)?transaction\b/i', $lead) !== 1;
        }
        if (preg_match('/^(?:use\b|(?:create|drop)\s+temporary\s+table\b)/i', $lead) === 1) {
            return true;
        }
        // the pattern below needs one of these: plain substring scans rule out (almost) every statement before the
        // regex walks a long one (an INSERT of a few MB runs through query() after every successful statement)
        if (strpos($lead, '@') === false && strpos($lead, ':=') === false && stripos($lead, 'lock') === false && stripos($lead, 'last_insert_id') === false) {
            return false;
        }
        return preg_match('/:=|\binto\s+@|\b(?:get_lock|release_lock|release_all_locks)\s*\(|\blast_insert_id\s*\(\s*[^)\s]/i', $lead) === 1;
    }

    /**
    * Records that the session state of this instance's link changed.
    *
    * @return void
    */
    protected function markSessionState()
    {
        $this->sessionDirty = true;
        if (!is_object($this->linkId)) {
            return;
        }
        if (self::$linkSessionState === null) {
            if (!class_exists('WeakMap')) {
                return;
            }
            self::$linkSessionState = new \WeakMap();
        }
        self::$linkSessionState[$this->linkId] = true;
    }

    /**
    * Whether the session state of the link this instance holds (or held) was changed, by it or by a clone.
    *
    * @return bool
    */
    protected function sessionStateChanged()
    {
        return $this->sessionDirty || (self::$linkSessionState !== null && is_object($this->linkId) && isset(self::$linkSessionState[$this->linkId]));
    }

    /**
    * Whether re-running the statement repeats what it did: reads and row writes, savepoints; not a statement
    * that assigns a user variable or takes a user-level lock, nor anything else (SET, DDL, LOCK, CALL, DO...).
    *
    * @param string $lead the statement without leading comments
    * @return bool
    */
    protected static function replayableStatement($lead)
    {
        if (preg_match('/^(?:select|insert|update|delete|replace|with|savepoint|release\s+savepoint|rollback\s+(?:work\s+)?to|show|explain|describe|desc)\b/i', $lead) !== 1) {
            return false;
        }
        if (strpos($lead, ':=') !== false) {
            return false;
        }
        // the pattern needs an @ or a lock function: skip the regex walk of a long statement that has neither
        return (strpos($lead, '@') === false && stripos($lead, 'lock') === false) || preg_match('/\binto\s+@|\b(?:get_lock|release_lock|release_all_locks)\s*\(/i', $lead) !== 1;
    }

    /**
    * The statement's first word, for a log line.
    *
    * @param string $lead
    * @return string
    */
    protected static function firstWord($lead)
    {
        return preg_match('/^[A-Za-z_]+/', $lead, $match) === 1 ? strtoupper($match[0]) : '?';
    }

    /**
    * What the caller could observe of a statement's outcome, as a recorded step.
    *
    * A result set is read to the end and rewound (it is buffered), so the caller still sees every row.
    *
    * @param string $sql
    * @param string|int $line
    * @param string $file
    * @param mixed $result
    * @param int|null $limit stop reading once the step passes this many bytes ('tooLarge' set; null: no limit)
    * @return array|null null when it could not be read
    */
    protected function fingerprint($sql, $line, $file, $result, $limit = null)
    {
        $step = ['sql' => $sql, 'line' => $line, 'file' => $file, 'bytes' => strlen((string) $sql)];
        try {
            if (is_object($result)) {
                $hash = hash_init('md5');
                $rows = 0;
                while (is_array($row = mysqli_fetch_row($result))) {
                    $data = serialize($row);
                    $step['bytes'] += strlen($data);
                    $rows++;
                    if ($limit !== null && $step['bytes'] > $limit) {
                        // too large to replay: no point hashing (and serializing) the rest of it
                        $step['tooLarge'] = true;
                        break;
                    }
                    hash_update($hash, $data);
                }
                if ($rows > 0) {
                    mysqli_data_seek($result, 0);
                }
                if (isset($step['tooLarge'])) {
                    return $step;
                }
                $step['rows'] = $rows.':'.mysqli_num_fields($result).':'.hash_final($hash);
            } else {
                $step['affected'] = (int) mysqli_affected_rows($this->linkId);
                $step['id'] = (string) mysqli_insert_id($this->linkId);
                $step['seenAffected'] = false;
                $step['seenId'] = false;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return $step;
    }

    /**
    * Updates the recording after a statement worked: a START opens a new one, AND CHAIN starts over with the
    * same opener, COMMIT/ROLLBACK ends it, and any other statement inside a transaction is recorded.
    *
    * @param string|null $change what trackTransactionState() reported
    * @param bool $wasOpen whether a transaction was open when the statement started
    * @return void
    */
    protected function txAfter($change, $wasOpen, $queryString, $line, $file, $result)
    {
        if ($change === 'start') {
            $this->txStart($queryString);
        } elseif ($change === 'chain') {
            $this->txStart($wasOpen && $this->txOpener !== false ? $this->txOpener : 'START TRANSACTION');
        } elseif ($this->inTransaction !== true) {
            $this->txEnd();
        } elseif ($wasOpen && $this->txReplayable) {
            $step = $this->fingerprint($queryString, $line, $file, $result, (int) $this->replayMaxBytes - $this->txBytes);
            if ($step === null) {
                $this->txNotReplayable('the outcome of a statement could not be read');
                return;
            }
            if (isset($step['tooLarge'])) {
                $this->txNotReplayable('it is too large to replay');
                return;
            }
            $this->txAppend($step);
        }
    }

    /**
    * Records a statement that failed inside the transaction (only the statement was rolled back).
    *
    * @return void
    */
    protected function recordError($sql, $line, $file, $errno)
    {
        if ($this->inTransaction === true && $this->txReplayable) {
            $this->txAppend(['sql' => $sql, 'line' => $line, 'file' => $file, 'errno' => (int) $errno, 'bytes' => strlen((string) $sql)]);
        }
    }

    /**
    * @param array $step
    * @return void
    */
    protected function txAppend(array $step)
    {
        $this->txBytes += $step['bytes'];
        if (count($this->txSteps) >= (int) $this->replayMaxStatements || $this->txBytes > (int) $this->replayMaxBytes) {
            $this->txNotReplayable('it is too large to replay');
            return;
        }
        $this->txSteps[] = $step;
        $this->txLastStep = count($this->txSteps) - 1;
    }

    /**
    * Starts recording a transaction that has just been opened.
    *
    * @param string|null $opener the START statement, or null for mysqli_begin_transaction()
    * @return void
    */
    protected function txStart($opener)
    {
        $this->txOpener = $opener;
        $this->txSteps = [];
        $this->txBytes = 0;
        $this->txLastStep = null;
        $this->txNotReplayableWhy = '';
        $this->txSeq = $this->linkStatementCount();
        $this->txReplayable = $this->txSeq !== null && (int) $this->transactionReplays > 0;
        // where the transaction runs, and how many transaction-ending statements its link has seen so far
        $this->txLink = is_object($this->linkId) ? $this->linkId : null;
        $this->txEnds = $this->linkEndCount();
        $this->txEndedElsewhere = false;
        if ($this->txSeq === null) {
            $this->txNotReplayableWhy = 'statements cannot be counted per connection (needs WeakMap)';
        }
    }

    /**
    * Stops recording: the transaction is over.
    *
    * @return void
    */
    protected function txEnd()
    {
        $this->txOpener = false;
        $this->txSteps = [];
        $this->txBytes = 0;
        $this->txReplayable = false;
        $this->txSeq = null;
        $this->txLastStep = null;
        $this->txNotReplayableWhy = '';
        $this->txLink = null;
        $this->txEnds = null;
        $this->txEndedElsewhere = false;
    }

    /**
    * The open transaction can no longer be replayed; what was recorded is let go of.
    *
    * @param string $why
    * @return void
    */
    protected function txNotReplayable($why)
    {
        if (!$this->txReplayable) {
            return;
        }
        $this->txReplayable = false;
        $this->txSteps = [];
        $this->txBytes = 0;
        $this->txSeq = null;
        $this->txLastStep = null;
        $this->txNotReplayableWhy = $why;
    }

    /**
    * @return array|null|object
    */
    public function fetchObject()
    {
        $this->Record = @mysqli_fetch_object($this->queryId);
        return $this->Record;
    }

    /* public: walk result set */

    /**
    * Db::next_record()
    *
    * @param mixed $resultType
    * @return bool
    */
    public function next_record($resultType = MYSQLI_BOTH)
    {
        if ($this->queryId === false) {
            $this->haltmsg('next_record called with no query pending.');
            return 0;
        }

        $this->Record = @mysqli_fetch_array($this->queryId, $resultType);
        ++$this->Row;
        if (is_object($this->linkId)) {
            // a buffered result outlives a connection that was let go of: then the errors stay as they were
            $this->Errno = mysqli_errno($this->linkId);
            $this->Error = mysqli_error($this->linkId);
        }

        $stat = is_array($this->Record);
        if (!$stat && $this->autoFree && is_resource($this->queryId)) {
            $this->free();
        }
        return $stat;
    }

    /**
    * switch to position in result set
    *
    * @param integer $pos the row numbe starting at 0 to switch to
    * @return bool whetherit was successfu or not.
    */
    public function seek($pos = 0)
    {
        $status = @mysqli_data_seek($this->queryId, $pos);
        if ($status) {
            $this->Row = $pos;
        } else {
            $this->haltmsg("seek({$pos}) failed: result has ".$this->num_rows().' rows', __LINE__, __FILE__);
            /* half assed attempt to save the day, but do not consider this documented or even desirable behaviour. */
            $rows = $this->num_rows();
            @mysqli_data_seek($this->queryId, $rows);
            $this->Row = $rows;
            return false;
        }
        return true;
    }

    /**
    * Records the refusal of a COMMIT after the transaction was lost (query() and transactionCommit()).
    *
    * @return void
    */
    protected function markCommitRefused()
    {
        $this->queryId = false;
        // the caller has ended the transaction (its COMMIT failed): what it sends next is its own, not refused
        $this->txRefuse = false;
        if ($this->transactionLostErrno > 0) {
            // the error the transaction was lost with: a 2006 must not look like a deadlock (a caller that
            // replays on 1213 would replay a COMMIT that may have landed)
            $this->Errno = $this->transactionLostErrno;
            $this->Error = 'COMMIT refused: the transaction was lost (#'.$this->transactionLostErrno.' '.$this->transactionLostError.')';
        } else {
            $this->Errno = 1213;
            $this->Error = 'COMMIT refused: the transaction was rolled back by the server';
        }
    }

    /**
    * Records that the transaction was lost, and with which error.
    *
    * @param int $errno
    * @param string $error
    * @param bool $refuse whether the caller may still take the transaction to be open, so statements are refused
    *                     until it is ended (see $txRefuse)
    * @return void
    */
    protected function markTransactionLost($errno, $error, $refuse = true)
    {
        $this->transactionLost = true;
        $this->transactionLostErrno = (int) $errno;
        $this->transactionLostError = (string) $error;
        $this->txRefuse = (bool) $refuse;
    }

    /**
    * Forgets that the transaction was lost.
    *
    * @return void
    */
    protected function clearTransactionLost()
    {
        $this->transactionLost = false;
        $this->transactionLostErrno = 0;
        $this->transactionLostError = '';
        $this->txRefuse = false;
    }

    /**
    * Whether a transaction was lost while the caller still took it to be open, and the caller has not ended it yet:
    * until a ROLLBACK, START TRANSACTION/BEGIN or transactionAbort() its statements are refused (see query()).
    * Code that must not end a transaction its caller is still running (a START would end this one too, and clear
    * the refusal) checks this next to inTransaction().
    *
    * @return bool
    */
    public function lostTransactionPending()
    {
        return $this->txRefuse && $this->transactionLost && $this->inTransaction !== true;
    }

    /**
    * Whether a statement is refused now: the transaction was lost while the caller still took it to be open, and
    * no ROLLBACK, START/BEGIN or transactionAbort() has ended it since. Never while an error is being reported
    * (this instance's reportFailure(), or emailError() on any instance): what the report writes (its log line
    * through the application's handle) is the reporter's, not the caller's.
    *
    * @return bool
    */
    protected function refusing()
    {
        return $this->txRefuse && $this->transactionLost && $this->inTransaction !== true && !$this->reportingFailure && !static::reportingError();
    }

    /**
    * Refuses a statement (it is never sent): the transaction it belongs to was lost, so run now it would commit on
    * its own (autocommit), and the caller would take it for part of a transaction that is gone. Errno/Error are
    * those the transaction was lost with, as for a refused COMMIT, and one error_log() line says so; with
    * haltOnError 'no' or 'report' that is all (it returns false), with 'yes' it fails like the refused COMMIT
    * (emailError(), then halt(), which ends the process; not when reportConflicts is off and the transaction was
    * lost to 1213/3101).
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @return false
    */
    protected function refuseStatement($queryString, $line, $file)
    {
        if (is_resource($this->queryId)) {
            $this->free();
        }
        $this->queryId = false;
        $this->Row = 0;
        $errno = $this->transactionLostErrno > 0 ? $this->transactionLostErrno : 1213;
        $this->Errno = $errno;
        $this->Error = 'Statement refused: the transaction was lost (#'.$errno.' '.$this->transactionLostError.'); a ROLLBACK, START TRANSACTION or transactionAbort() ends it';
        error_log('Statement refused: the transaction it belongs to was lost (#'.$errno.'), and sent now it would commit on its own. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
        if ($this->haltOnError != 'no' && $this->haltOnError != 'report' && !$this->conflictsToCaller($errno)) {
            $this->reportFailure($queryString, $line, $file);
        }
        return false;
    }

    /**
    * Whether a transaction is open on a link that is gone (closed by another holder of the shared handle), on a
    * link that replaced its own (a reconnect underneath it), or was ended by another holder of the shared link
    * (transactionElsewhere()): it is recorded as lost (2006: whether it was committed is not known to this
    * instance) and no reconnect is made. A link that is still usable (ended elsewhere, or a replacement) is let go of
    * without being closed: another handle may have a transaction open on it. Statements are refused from then on
    * (see query()); the ROLLBACK or START that ends the refusal runs on a connection of this instance's own. Outside a
    * transaction, and during a replay (whose reconnects are its own), it does nothing, so the callers keep
    * reconnecting as before.
    *
    * @return bool true if the transaction was just found lost
    */
    protected function linkLostInTransaction()
    {
        if ($this->inTransaction !== true || $this->replaying) {
            return false;
        }
        if (!$this->linkIsUsable()) {
            $why = 'MySQL server has gone away (connection lost inside a transaction)';
        } else {
            $why = $this->transactionElsewhere();
            if ($why === '') {
                return false;
            }
            $why = 'Transaction lost: '.$why;
            // another handle may have a transaction open on the link: let go of it, never close it (nor end that
            // transaction with what this instance sends next: that opens a connection of its own); a quiet ROLLBACK
            // first only while no other handle has one open there (releaseEndedElsewhere())
            $this->releaseEndedElsewhere();
        }
        $this->markTransactionLost(2006, $why, true);
        $this->inTransaction = false;
        $this->txEnd();
        $this->Errno = 2006;
        $this->Error = $why;
        return true;
    }

    /**
    * Initiates a transaction
    *
    * With a transaction already open, the START TRANSACTION it sends commits that one first. When that commit is
    * rolled back by the server (1213, 3101) the open transaction is replayed like query() does and the START run
    * again; when it cannot be recovered, or fails with any error but one the server never ran the START with
    * (START_NOT_RUN_ERRORS), the open transaction is over: it is marked lost (a later COMMIT is refused, and so are
    * statements until a ROLLBACK, START/BEGIN or transactionAbort()), a ROLLBACK makes sure of it, and this returns
    * false (or rethrows, under MYSQLI_REPORT_STRICT). It never mails or halts. With none open of its own, and another
    * handle sharing the connection with a transaction open on it, the transaction is begun on a connection of this
    * instance's own (see startApart()), as by query('START TRANSACTION').
    *
    * @return bool
    */
    public function transactionBegin()
    {
        if (version_compare(PHP_VERSION, '5.5.0') < 0) {
            return true;
        }
        if ($this->linkLostInTransaction()) {
            return false;
        }
        if ($this->inTransaction !== true) {
            // never on a link another handle has a transaction open on: the START would commit that one
            $this->startApart();
        }
        if (!$this->connect()) {
            return 0;
        }
        $this->txLastStep = null;
        $wasOpen = $this->inTransaction === true;
        if ($wasOpen && $this->txReplayable && $this->linkStatementCount() !== $this->txSeq) {
            $this->txNotReplayable('another handle sharing the connection ran a statement inside it');
        }
        list($result, $errno, $error, $exception) = $this->send('START TRANSACTION', 'begin');
        if ($result) {
            $this->inTransaction = true;
            $this->clearTransactionLost();
            $this->txStart(null);
            return $result;
        }
        if (!$wasOpen || ($exception === null || !$exception instanceof \Error) && in_array($errno, self::START_NOT_RUN_ERRORS, true)) {
            // nothing was open, or the server never ran it: whatever was open is still open
            if ($exception instanceof \Error) {
                // (nothing was open) a handle mysqli cannot use (half-open, or closed): nothing began, and the handle
                // is let go of so the next call connects anew, instead of an \Error to the caller
                $this->releaseLink();
                $this->Errno = 2006;
                $this->Error = (string) $error;
                return false;
            }
            if ($exception !== null) {
                throw $exception;
            }
            return $result;
        }
        $replay = null;
        $linkLost = $exception instanceof \Error || in_array($errno, self::LINK_ERRORS, true);
        if ($linkLost) {
            if (!$exception instanceof \Error) {
                $this->dropLink();
            }
        } elseif (in_array($errno, self::REPLAY_ERRORS, true) && $this->txReplayable) {
            $haltPrev = $this->haltOnError;
            $this->haltOnError = 'no';
            try {
                $replay = $this->replayTransaction('START TRANSACTION', true, $errno, $error, 'begin');
            } finally {
                $this->haltOnError = $haltPrev;
            }
            if ($replay['ok']) {
                $this->inTransaction = true;
                $this->clearTransactionLost();
                $this->txStart(null);
                $this->Errno = 0;
                $this->Error = '';
                error_log('Replayed a transaction the server rolled back at START TRANSACTION (SQL error '.$replay['first'].'; '.$replay['attempts'].' replay(s) of '.$replay['steps'].' statement(s)); it committed and a new one began.');
                return true;
            }
            $errno = $replay['errno'];
            $error = $replay['error'];
            if ($replay['restored'] && in_array($errno, self::START_NOT_RUN_ERRORS, true)) {
                // the transaction is back as it was and the START never ran: it is still open
                $this->Errno = $errno;
                $this->Error = $error;
                if ($exception !== null) {
                    throw new \mysqli_sql_exception($error, $errno);
                }
                return false;
            }
        }
        $this->Errno = $errno;
        $this->Error = $error;
        // the implicit commit of the open transaction failed: it is over, and the caller, asking for a new one,
        // must not have its next statements autocommit
        $this->inTransaction = false;
        $this->markTransactionLost($errno, $error, true);
        $why = $replay !== null ? ' Not recovered: '.$replay['why'].'.' : ($this->txNotReplayableWhy !== '' ? ' Not replayed: '.$this->txNotReplayableWhy.'.' : '');
        $this->txEnd();
        if (!$linkLost && is_object($this->linkId) && $this->linkIsUsable()) {
            $this->sendQuietly('ROLLBACK');
        }
        error_log('transactionBegin(): the transaction that was open could not be committed first (SQL error '.(int) $errno.'); it is lost, a later COMMIT is refused, and so are statements until a ROLLBACK or a new START.'.$why);
        if ($exception !== null) {
            throw $exception instanceof \Error || $replay === null || $exception->getCode() === $errno ? $exception : new \mysqli_sql_exception($error, $errno);
        }
        return false;
    }

    /**
    * Commits a transaction
    *
    * A COMMIT the server rolls back (1213, 3101) replays the transaction like query() does (see query()) and
    * returns true once the replayed COMMIT went through: no exception, no mail, no halt. Otherwise it fails like
    * mysqli_commit(): false (or, under MYSQLI_REPORT_STRICT, the exception of the final failure) with
    * Errno/Error set, and the transaction is over and marked lost, so a later COMMIT is refused. A COMMIT that
    * loses the connection (2006, 2013) is never replayed: it may have committed, so a caller must not run the
    * transaction again on those (the outcome is unknown); on 1213/3101 nothing was committed. A COMMIT never
    * sent (2014) is given up too: the connection is closed, which ends the transaction on the server, so nothing
    * was committed. A transaction whose connection was closed, replaced (a reconnect underneath it) or ended by
    * another holder of the shared link is refused (2006: whether its writes were committed is not known here).
    * With no link at all and nothing open it returns true, as it always did. It never mails or halts, and the
    * statements after it are not refused (the caller has ended the transaction).
    *
    * @return bool
    */
    public function transactionCommit()
    {
        $this->txLastStep = null;
        if ($this->transactionLost) {
            /*
             * The server already lost the transaction. This fails like a mysqli_commit() that failed
             * (Errno/Error set, false returned): no emailError(), no halt(), whatever haltOnError is,
             * unlike query('COMMIT'), which is a failed statement and halts like any other.
             */
            $this->markCommitRefused();
            return false;
        }
        if (version_compare(PHP_VERSION, '5.5.0') < 0) {
            $this->inTransaction = false;
            $this->txEnd();
            return true;
        }
        $wasOpen = $this->inTransaction === true;
        if ($wasOpen) {
            $gone = !$this->linkIsUsable() ? 'MySQL server has gone away (connection lost before COMMIT)' : $this->transactionElsewhere();
            if ($gone !== '') {
                // the link is gone (closed by another holder of the shared handle) or not the transaction's any more,
                // or another holder ended the transaction: it went without this instance
                $this->markTransactionLost(2006, $gone, false);
                $this->inTransaction = false;
                if ($this->linkIsUsable()) {
                    // another handle may have a transaction open on it: let go of it, never close it (the statements
                    // the caller sends next run on a connection of this instance's own, not inside that transaction);
                    // a quiet ROLLBACK first only while no other handle has one open there (releaseEndedElsewhere())
                    $this->releaseEndedElsewhere();
                }
                $this->txEnd();
                $this->markCommitRefused();
                return false;
            }
        }
        if ($this->linkId === 0) {
            // no link and nothing open: nothing to commit
            $this->inTransaction = false;
            $this->txEnd();
            return true;
        }
        if ($wasOpen && $this->txReplayable && $this->linkStatementCount() !== $this->txSeq) {
            $this->txNotReplayable('another handle sharing the connection ran a statement inside it');
        }
        list($result, $errno, $error, $exception) = $this->send('COMMIT', 'commit');
        if ($exception instanceof \Error && !$wasOpen) {
            // nothing open, on a handle mysqli cannot use (closed by another holder of the shared link, or half-open):
            // the connection is gone, so this fails like a COMMIT on a dead link instead of throwing, and the handle
            // is let go of so the next call connects anew
            $this->inTransaction = false;
            $this->txEnd();
            $this->releaseLink();
            $this->Errno = 2006;
            $this->Error = (string) $error;
            return false;
        }
        if ($exception instanceof \Error) {
            // not a server error (no usable mysqli at all): it goes on unchanged, but a transaction that was open is
            // over (its connection is unusable), so a later COMMIT must not report it as saved
            if ($wasOpen) {
                $this->markTransactionLost(2006, (string) $error, false);
            }
            $this->inTransaction = false;
            $this->txEnd();
            throw $exception;
        }
        if ($result) {
            $this->inTransaction = false;
            $this->txEnd();
            return true;
        }
        if (!$wasOpen) {
            // nothing was open, so nothing was lost
            $this->inTransaction = false;
            $this->txEnd();
            if ($exception !== null) {
                throw $exception;
            }
            return false;
        }
        $replay = null;
        $linkLost = in_array($errno, self::LINK_ERRORS, true);
        if ($linkLost) {
            // it may have committed: never replayed; the dead connection is let go of so the next call reconnects
            $this->dropLink();
        } elseif (in_array($errno, self::REPLAY_ERRORS, true) && $this->txReplayable) {
            $haltPrev = $this->haltOnError;
            $this->haltOnError = 'no';
            try {
                $replay = $this->replayTransaction('COMMIT', true, $errno, $error, 'commit');
            } finally {
                $this->haltOnError = $haltPrev;
            }
            if ($replay['ok']) {
                $this->inTransaction = false;
                $this->txEnd();
                $this->Errno = 0;
                $this->Error = '';
                error_log('Replayed a transaction the server rolled back at COMMIT (SQL error '.$replay['first'].'; '.$replay['attempts'].' replay(s) of '.$replay['steps'].' statement(s)); it committed.');
                return true;
            }
            $errno = $replay['errno'];
            $error = $replay['error'];
        }
        $this->Errno = $errno;
        $this->Error = $error;
        // the server rolled it back, refused it, or its outcome is unknown; or it was never sent (2014): a later COMMIT
        // must not report success
        $this->inTransaction = false;
        $this->markTransactionLost($errno, $error, false);
        $this->txEnd();
        if (!$linkLost && is_object($this->linkId) && $this->linkIsUsable()) {
            if ((int) $errno === 2014) {
                // never sent: the server still holds the transaction, and a ROLLBACK would be out of sync too, so the
                // connection is closed, which ends it there (nothing was committed)
                $this->dropLink();
            } elseif ($replay === null || $replay['restored']) {
                // a COMMIT that failed without the server saying it ended the transaction: make sure it is over
                $this->sendQuietly('ROLLBACK');
            }
        }
        if ($exception !== null) {
            throw $replay === null || $exception->getCode() === $errno ? $exception : new \mysqli_sql_exception($error, $errno);
        }
        return false;
    }

    /**
    * Rolls back a transaction
    *
    * Returns false, never throws an \Error, when the handle is closed (another holder of the shared link closed it)
    * or half-open (a connect that failed): the connection is gone, and its transaction with it; the handle is let go
    * of so the next call connects anew, and a lost record stays (as after a ROLLBACK on a dead link). A transaction of
    * this instance's own that another holder of the shared link ended (transactionElsewhere()) is not rolled back as
    * asked: its writes may have been committed by whatever ended it, so it is marked lost (2006), the link let go of
    * without closing it, and false returned (no mail, no halt); a quiet ROLLBACK goes out first only while no other
    * handle has a transaction open there (releaseEndedElsewhere()). With no transaction of its own while another
    * handle sharing the link has one open there, nothing is sent (the ROLLBACK would end that handle's transaction)
    * and it returns true, as query('ROLLBACK') does (rollbackWithoutTransaction()). Under MYSQLI_REPORT_STRICT a
    * server error still throws its mysqli_sql_exception.
    *
    * @return bool
    */
    public function transactionAbort()
    {
        if (version_compare(PHP_VERSION, '5.5.0') < 0 || $this->linkId === 0) {
            $this->inTransaction = false;
            $this->txEnd();
            $this->clearTransactionLost();
            return true;
        }
        if ($this->inTransaction === true) {
            $elsewhere = $this->transactionElsewhere();
            if ($elsewhere !== '') {
                $this->inTransaction = false;
                $this->markTransactionLost(2006, 'Transaction lost: '.$elsewhere, false);
                // (a quiet ROLLBACK first when no other handle has a transaction open there: releaseEndedElsewhere())
                $this->releaseEndedElsewhere();
                $this->txEnd();
                $this->Errno = 2006;
                $this->Error = 'ROLLBACK not sent: the transaction is lost ('.$elsewhere.')';
                error_log('transactionAbort(): ROLLBACK not sent: the transaction is lost ('.$elsewhere.'); sent now it would end whatever is open on the shared connection.');
                return false;
            }
        } elseif ($this->anotherHolderInTransaction()) {
            // no transaction of its own, and another handle sharing the link has one open there, which the ROLLBACK
            // would end: nothing to roll back here (as query('ROLLBACK'), see rollbackWithoutTransaction())
            $this->txEnd();
            $this->clearTransactionLost();
            error_log('transactionAbort(): ROLLBACK not sent: this handle has no transaction open, and another handle sharing the connection has one open there, which it would end.');
            return true;
        }
        $this->countStatement(true);
        $this->inTransaction = false;
        $this->txEnd();
        try {
            $result = mysqli_rollback($this->linkId);
        } catch (\Error $e) {
            // closed or half-open: the connection is gone, and any transaction with it (the lost record is kept)
            $this->releaseLink();
            $this->Errno = 2006;
            $this->Error = 'MySQL server has gone away ('.$e->getMessage().')';
            return false;
        }
        if ($result) {
            // a failed ROLLBACK (dead link) leaves the lost record in place
            $this->clearTransactionLost();
        }
        return $result;
    }

    /**
    * This will get the last insert ID created on the current connection.  Should only be called after an insert query is
    * run on a table that has an auto incrementing field.  $table and $field are required, but unused here since it's
    * unnecessary for mysql.  For compatibility with pgsql, the params must be supplied.
    *
    * @param string $table
    * @param string $field
    * @return int|string
    */
    public function getLastInsertId($table, $field)
    {
        if (!isset($table) || $table == '' || !isset($field) || $field == '') {
            return -1;
        }
        if ($this->txLastStep !== null && isset($this->txSteps[$this->txLastStep]['id'])) {
            // the caller now depends on this id: a replay that hands out another one must not go on
            $this->txSteps[$this->txLastStep]['seenId'] = true;
        }
        if (!is_object($this->linkId)) {
            // the connection was let go of (lost, or closed to end a transaction): no insert id, as on a dead link
            return 0;
        }

        return @mysqli_insert_id($this->linkId);
    }

    /* public: table locking */

    /**
    * Db::lock()
    * @param mixed  $table
    * @param string $mode
    * @return bool|int|\mysqli_result
    */
    public function lock($table, $mode = 'write')
    {
        if ($this->linkLostInTransaction()) {
            // no reconnect underneath an open transaction: it is recorded as lost
            return 0;
        }
        if ($this->refusing()) {
            // the transaction the caller is still running was lost: LOCK TABLES would end it on its own terms
            $this->refuseStatement('lock tables', __LINE__, __FILE__);
            return 0;
        }
        if ($this->inTransaction === true) {
            // LOCK TABLES commits the open transaction: there is nothing left to replay
            $this->txEnd();
        }
        $this->connect();
        $query = 'lock tables ';
        if (is_array($table)) {
            foreach ($table as $key => $value) {
                if ($key == 'read' && $key != 0) {
                    $query .= "$value read, ";
                } else {
                    $query .= "$value $mode, ";
                }
            }
            $query = mb_substr($query, 0, -2);
        } else {
            $query .= "$table $mode";
        }
        $this->countStatement(true);
        try {
            $res = @mysqli_query($this->linkId, $query);
        } catch (\mysqli_sql_exception $e) {
            // an end the server never ran ended nothing
            $this->uncountEnd((int) $e->getCode(), true);
            throw $e;
        }
        // LOCK TABLES implicitly commits any open transaction.
        $this->inTransaction = false;
        if (!$res) {
            $this->uncountEnd((int) @mysqli_errno($this->linkId), true);
            $this->halt("lock($table, $mode) failed.");
            return 0;
        }
        return $res;
    }

    /**
    * Db::unlock()
    * @param bool $haltOnError optional, defaults to TRUE, whether or not to halt on error
    * @return bool|int|\mysqli_result
    */
    public function unlock($haltOnError = true)
    {
        if ($this->linkLostInTransaction()) {
            // no reconnect, and no halt() (which calls unlock(false) again): the transaction is recorded as lost
            return $haltOnError === true ? 0 : false;
        }
        if ($this->haltUnlocking && !$this->linkIsUsable()) {
            // halt() after the connection was let go of: a new connection would hold no table locks
            return false;
        }
        if ($this->inTransaction === true && !$this->haltUnlocking) {
            $this->txNotReplayable('unlock() ran inside it');
        }
        $this->connect();

        $this->countStatement();
        $res = @mysqli_query($this->linkId, 'unlock tables');
        if ($haltOnError === true && !$res) {
            $this->halt('unlock() failed.');
            return 0;
        }
        return $res;
    }

    /* public: evaluate the result (size, width) */

    /**
    * Db::affectedRows()
    * @return int
    */
    public function affectedRows()
    {
        if ($this->txLastStep !== null && isset($this->txSteps[$this->txLastStep]['affected'])) {
            // the caller now depends on this count: a replay that changes it must not go on
            $this->txSteps[$this->txLastStep]['seenAffected'] = true;
        }
        if (!is_object($this->linkId)) {
            // the connection was let go of (lost, or closed to end a transaction): -1, as on a dead link
            return -1;
        }
        return @mysqli_affected_rows($this->linkId);
    }

    /**
    * Db::num_rows()
    * @return int
    */
    public function num_rows()
    {
        return @mysqli_num_rows($this->queryId);
    }

    /**
    * Db::num_fields()
    * @return int
    */
    public function num_fields()
    {
        return @mysqli_num_fields($this->queryId);
    }

    /**
    * gets an array of the table names in teh current datase
    *
    * @return array
    */
    public function tableNames()
    {
        $return = [];
        $this->query('SHOW TABLES');
        $i = 0;
        while ($info = $this->queryId->fetch_row()) {
            $return[$i]['table_name'] = $info[0];
            $return[$i]['tablespace_name'] = $this->database;
            $return[$i]['database'] = $this->database;
            ++$i;
        }
        return $return;
    }
}

/**
* @param $result
* @param $row
* @param int|string $field
* @return bool
*/
/*
function mysqli_result($result, $row, $field = 0) {
if ($result === false) return false;
if ($row >= mysqli_num_rows($result)) return false;
if (is_string($field) && !(mb_strpos($field, '.') === false)) {
$tField = explode('.', $field);
$field = -1;
$tFields = mysqli_fetch_fields($result);
for ($id = 0, $idMax = mysqli_num_fields($result); $id < $idMax; $id++) {
if ($tFields[$id]->table == $tField[0] && $tFields[$id]->name == $tField[1]) {
$field = $id;
break;
}
}
if ($field == -1) return false;
}
mysqli_data_seek($result, $row);
$line = mysqli_fetch_array($result);
return isset($line[$field]) ? $line[$field] : false;
}
*/
