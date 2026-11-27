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
    * errors that mean the connection is gone, and the open transaction with it (a COMMIT's outcome is then unknown):
    * 2006 CR_SERVER_GONE_ERROR, 2013 CR_SERVER_LOST, 2055 CR_SERVER_LOST_EXTENDED, 4031 ER_CLIENT_INTERACTION_TIMEOUT
    * (the server closed an idle connection), the same as RECONNECT_ERRORS
    */
    const LINK_ERRORS = [2006, 2013, 2055, 4031];

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
    * errors of a CREATE or ALTER ... START TRANSACTION that the server refuses before it begins a transaction for the
    * statement (trans_begin(), sql_parse.cc), so it leaves none open (endStrayDdlTransaction() sends no ROLLBACK): 1290
    * ER_OPTION_PREVENTS_STATEMENT (read_only or super_read_only: deny_updates_if_read_only_option() refuses before the
    * implicit commit, and trans_begin()'s check_readonly() after its commit of the open transaction but before it
    * begins one, transaction.cc) and 1792 ER_CANT_EXECUTE_IN_READ_ONLY_TRANSACTION (checked after trans_begin(), whose
    * READ WRITE clears tx_read_only: raised only when none ran). The refusal of the clause itself, 3979
    * ER_NOT_ALLOWED_WITH_START_TRANSACTION (sql_table.cc), and any other error come after trans_begin() or may.
    */
    const NO_DDL_TRANSACTION_ERRORS = [1290, 1792];

    /**
    * What may sit between two keywords in the leading-word checks (endsTransaction(), implicitCommitOutcome(),
    * changesSessionState()): whitespace or a plain comment (a block comment, "-- " or # to the end of the line), never
    * an executable one (slash-star-bang, slash-star-M-bang: the server runs its content). Only the leading words are
    * matched with it, so nothing further on is read (a string literal holding a comment marker changes nothing). The M
    * is an uppercase one, as in leadingKeywordSql(): MariaDB's lexer runs slash-star-M-bang only with an uppercase M,
    * and MySQL's runs none, so a slash-star-m-bang is a plain comment on both.
    */
    const WORD_GAP = '(?:\s|/\*(?!!|(?-i:M)!)(?:(?!\*/).)*+\*/|(?:\#|--(?=[\x00-\x20\x7F]))[^\n]*+(?:\n|$))++';

    /**
    * The newest version comment (slash-star-bang and five digits, MMmmdd) whose content runs on every server this driver
    * meets (MySQL 5.7 and later, MariaDB): leadingKeywordSql() reads its content as statement text. MySQL's lexer runs
    * one only when its version is at most the server's own (sql_lex.cc, MY_LEX_LONG_COMMENT: version <=
    * MYSQL_VERSION_ID), so what a later one holds depends on the server and is not read here.
    */
    const EXECUTED_COMMENT_VERSION = 50700;

    /**
    * The bytes of a word in leadingKeywordSql(): keywords and unquoted identifiers (ASCII).
    */
    const WORD_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$';

    /**
    * leadingKeywordSql()'s common case, matched at the first byte that is neither space nor "(": a word, then words and
    * space (the bytes MySQL's lexer takes for space) only, ending at the end or at a byte that cannot open a comment.
    */
    const PLAIN_LEAD_PATTERN = '~[A-Za-z0-9_$][A-Za-z0-9_$ \t\n\r\f\x0B]*+(?![/#-])~A';

    /**
    * leadingKeywordSql(), Generic::withoutComments(): an executable comment (slash-star-bang or slash-star-M-bang, with
    * no version or a five-digit one) that holds nothing but whitespace: run or skipped, it runs nothing on any server,
    * so it is read as a space wherever it is (a sixth digit is not a version on MySQL, which runs it).
    */
    const EMPTY_EXECUTABLE_PATTERN = Generic::EMPTY_EXECUTABLE_PATTERN;

    /**
    * endsTransaction(): the leading words of a statement that may end an open transaction (built once).
    */
    const ENDS_PATTERN = '~^(?:start'.self::WORD_GAP.'transaction|begin|commit|rollback|alter|create|drop|rename|truncate|grant|revoke|lock'.self::WORD_GAP.'tables?|install|uninstall|analyze|check'.self::WORD_GAP.'table|optimize|repair|flush|reset|cache'.self::WORD_GAP.'index|load'.self::WORD_GAP.'index|import'.self::WORD_GAP.'table|clone\b|(?:start|stop)'.self::WORD_GAP.'(?:replica|slave|group_replication)\b|change'.self::WORD_GAP.'(?:master|replication)\b|set'.self::WORD_GAP.'password|set'.self::WORD_GAP.'default'.self::WORD_GAP.'role\b)~is';

    /**
    * endsTransaction(): the forms of CREATE, DROP and RESET that commit nothing.
    */
    const NO_END_PATTERN = '~^(?:(?:create|drop)'.self::WORD_GAP.'temporary\b|drop'.self::WORD_GAP.'prepare\b|reset'.self::WORD_GAP.'persist\b)~is';

    /**
    * implicitCommitOutcome(): the forms MySQL 8.0 commits the open transaction on (CF_AUTO_COMMIT_TRANS).
    */
    const END_PATTERN = '~^(?:alter|create|drop|rename'.self::WORD_GAP.'(?:tables?|user)|truncate|grant|revoke|install|uninstall|lock'.self::WORD_GAP.'tables?|(?:analyze|optimize|repair)'.self::WORD_GAP.'(?:(?:no_write_to_binlog|local)'.self::WORD_GAP.')?tables?|check'.self::WORD_GAP.'tables?|flush|reset|cache'.self::WORD_GAP.'index|load'.self::WORD_GAP.'index|import'.self::WORD_GAP.'table|clone|(?:start|stop)'.self::WORD_GAP.'(?:replica|slave)|change'.self::WORD_GAP.'(?:master|replication'.self::WORD_GAP.'(?:source|filter)))\b~is';

    /**
    * executableAmongLeadingWords(): an executable comment before the first word, or after leading words.
    */
    const EXECUTABLE_AMONG_PATTERN = '~^(?:\w++(?:'.self::WORD_GAP.'\w++)*+(?:'.self::WORD_GAP.')?)?/\*(?-i:M)?!~is';

    /**
    * changesSessionState(), replayableStatement(): INTO followed by a user variable (SELECT ... INTO @x), with
    * whitespace and comments between them, the content of a plain or executable one whatever it holds (SELECT a, b
    * INTO /*!99999 x *\/ @x: skipped by MySQL 8.0, which assigns @x), and the user variable inside an executable one
    * too (INTO /*!80000 @x *\/, where it runs); a "*\/" between them ends an executable comment INTO sat in. A comment
    * that is an executable one beginning with the user variable is not skipped as a gap (the last alternative reads
    * it). Its M is of either case (under the caller's i flag): a slash-star-m-bang is a plain comment on every server,
    * so taking an @ behind one for a user variable only takes a statement for one that changes the session, the safe
    * side (START_TRANSACTION_ANYWHERE reads an uppercase M only, WORD_GAP). What it matches is read by wordGapReaches()
    * (intoUserVariable()); the pattern itself is never run over a statement: it is linear from each INTO, but it walks
    * the gap again from every INTO, string literals and comment bodies included, so a literal holding "@" and many
    * "into /*" cost the length squared (a 512 KB one about 80 s).
    */
    const INTO_USER_VARIABLE = '\binto(?:\s|/\*(?!M?!\d*+\s*+@)(?:(?!\*/).)*+\*/|\*/|(?:\#|--(?=[\x00-\x20\x7F]))[^\n]*+(?:\n|$))*+(?:@|/\*M?!\d*+\s*+@)';

    /**
    * implicitCommitOutcome(): START and TRANSACTION next to each other anywhere in the statement (WORD_GAP between
    * them: a plain comment, slash-star-m-bang included, is a gap, an executable one is not). Read by wordGapReaches()
    * (startTransactionAdjacent()), never run: like INTO_USER_VARIABLE it walks the gap again from every START.
    */
    const START_TRANSACTION_ANYWHERE = '\bstart'.self::WORD_GAP.'transaction\b';

    /**
    * setAutocommitOutcome(): an assignment that may be to the session's autocommit, its target:
    * autocommit followed by = or :=, not after a word character, $, . or @ (a user variable @autocommit, a column
    * t.autocommit and @@GLOBAL.autocommit are not), alone, quoted, or as @@autocommit, @@SESSION.autocommit or
    * @@LOCAL.autocommit. Its scope keyword and value are read by setAutocommitOutcome().
    */
    const AUTOCOMMIT_TARGET = '(?<![\w$.@])(?:@@(?:`?(?:session|local)`?\.)?)?`?autocommit`?\s*:?=';

    /**
    * changesSessionState(): CREATE/DROP TEMPORARY TABLE.
    */
    const TEMPORARY_TABLE_PATTERN = '~^(?:create|drop)'.self::WORD_GAP.'temporary'.self::WORD_GAP.'tables?\b~is';

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
    * The work wordGapReaches() has done in this process (places read, bytes looked at), for the tests that check it
    * stays linear in the statement's length whatever the statement holds; the library itself never reads it.
    *
    * @var int
    */
    protected static $sweepWork = 0;

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
    * Whether the lost record ($transactionLost) still waits for the caller's cleanup of the transaction it was lost
    * from: set when the transaction is lost, cleared with the record, and cleared once the caller has moved on without
    * a ROLLBACK (it sent a statement of its own after the transaction was over: anything but a ROLLBACK, START/BEGIN or
    * COMMIT, through query(), execute() or lock(); not what an error report writes). Only while it is set is a
    * ROLLBACK (or transactionAbort()) that would end another handle's transaction answered as this handle's cleanup
    * (rollbackWithoutTransaction()): a stale record, one the caller moved on from, must not make a ROLLBACK meant for
    * that other transaction report success.
    *
    * @var bool
    */
    protected $lostCleanupPending = false;

    /**
    * While the open transaction's end is not known ('' otherwise): the first word of the statement that may have
    * committed it implicitly or left it open (keepUnknownEnd()). Cleared when the transaction is over (txEnd()) or a
    * new one starts.
    *
    * @var string
    */
    protected $txEndUnknown = '';

    /**
    * Whether the transaction was lost with 2006 in place of the server's 1213/3101 (unknownEndLoss()): for
    * conflictsToCaller() that loss is still a conflict, handed to the caller as one (no mail, no halt) with
    * reportConflicts off.
    *
    * @var bool
    */
    protected $transactionLostConflict = false;

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
    * innodb_lock_wait_timeout, in seconds, set on every connection this instance opens while the transaction it moved
    * off a shared link (startApart()) runs: the handle that keeps the shared link has a transaction open there, and in
    * the same request it may hold row locks this instance's statements wait for, which nothing releases while they
    * wait. A lock wait then fails with 1205 after this long instead of the server's innodb_lock_wait_timeout (by
    * default 50 s). Only for that transaction: once it is over the connection gets the server's value back
    * (endApartLockWait()). 0 leaves the server's own.
    *
    * @var int
    */
    public $apartLockWaitSeconds = 5;

    /**
    * Whether a START TRANSACTION this instance moved off a shared link (startApart()) is under way: from the move until
    * that transaction is over (committed, rolled back, given up or lost; or it never began), every connection this
    * instance opens gets $apartLockWaitSeconds (connect()), and at its end the connection it holds is set back to the
    * server's value and this is cleared (endApartLockWait()). A clone, and a detach()/newConnection() copy, start
    * without it.
    *
    * @var bool
    */
    protected $movedApart = false;

    /**
    * The connection the prepared statement in $statement was prepared on (prepare()): execute() runs it only while
    * this instance still holds that connection (see execute()).
    *
    * @var object|null
    */
    protected $statementLink = null;

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
    * end a transaction, see query()), and linkHasOpenTransaction() sees it. Nor does it inherit the shorter lock wait
    * of a handle that moved a START off a shared link ($movedApart): it holds its source's connection as it is (made
    * while that moved transaction runs, it shares the shorter wait on it until the transaction ends; made after, it
    * gets the server's), and it never sets the connection back itself (cleared first, before anything here could).
    *
    * @return void
    */
    public function __clone()
    {
        $this->movedApart = false;
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
    * and unsafe, against whatever connection this instance opens next. A handle
    * whose moved START is under way ($movedApart) sets the connection it lets go
    * of back to the server's lock wait first (endApartLockWait()): whoever keeps
    * it must not inherit the shorter one.
    *
    * @return void
    */
    public function detach()
    {
        $this->endApartLockWait(false);
        parent::detach();
        $this->linkEstablished = false;
        $this->txRefuse = false;
        $this->lostCleanupPending = false;
        $this->txEnd();
        $this->statement = null;
        $this->statement_query = null;
        $this->statement_vars = null;
        $this->statementLink = null;
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
                // B180 (S53): there were two guards here, an exit at maxConnectErrors - 1 and a
                // halt + return 0 at maxConnectErrors, but whatever passed the first never reached
                // the second: the halt was dead. The exit was also silent - no mail, no message,
                // status 0 - so cron and queue supervisors recorded a DB-outage death as success.
                // The trip point stays deliberately one failure before the documented
                // maxConnectErrors (with the default of 5: on the 4th consecutive failure), the
                // timing every outage path has lived with; what changes is that it is now a real
                // halt: log, halt() (mail under 'report', message under 'yes', caller-visible
                // false under 'no'), then leave with a status that says failure. The replay stays
                // inside this limit without adjustment (see replayReset()).
                error_log("MySQLi Connection Attempt #{$this->connectionAttempt}/{$this->maxConnectErrors}");
                $this->halt("connect($host, $user, \$password) failed. ".(is_object($this->linkId) && isset( $this->linkId->connect_error) ? $this->linkId->connect_error : ''));
                exit(1);
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
            // Without this it only ever grew, and since the consecutive-failure limit trips a
            // halt + exit(1) above, a long-lived worker that legitimately reconnects a few
            // times would eventually be killed off mid-request.
            $this->connectionAttempt = 0;
            if ($this->movedApart) {
                // a connection of its own beside a handle that has a transaction open (startApart())
                $this->setApartLockWait();
            }
        }
        return $this->linkId;
    }

    /**
    * Sets $apartLockWaitSeconds as innodb_lock_wait_timeout on the connection connect() has just opened for an instance
    * whose START TRANSACTION moved off a shared link (startApart()) is under way. Sent once per connection, straight to
    * the server: not counted and not recorded as session state, since connect() sets it again on any new connection
    * this instance opens while that transaction runs (a replay that reconnects included), and endApartLockWait() sets
    * it back when the transaction is over. A failure is logged in one line and changes nothing else: the connection
    * then waits for row locks as long as the server says.
    *
    * @return void
    */
    protected function setApartLockWait()
    {
        $seconds = (int) $this->apartLockWaitSeconds;
        if ($seconds < 1) {
            return;
        }
        try {
            $ok = @mysqli_query($this->linkId, 'SET SESSION innodb_lock_wait_timeout='.$seconds);
            $errno = $ok === false ? (int) @mysqli_errno($this->linkId) : 0;
        } catch (\Throwable $e) {
            $ok = false;
            $errno = (int) $e->getCode();
        }
        if ($ok === false) {
            error_log('Could not cut innodb_lock_wait_timeout to '.$seconds.' s on the connection of its own of a START TRANSACTION moved off a shared connection (SQL error '.$errno.'): its row lock waits last as long as the server says');
        }
    }

    /**
    * The transaction of a START this instance moved off a shared link (startApart(), $movedApart) is over: it committed,
    * rolled back, was given up or lost, the START never began one, or the instance lets go of the connection. The
    * shorter lock wait was for that transaction only, so $movedApart is cleared (a connection opened from now on, a
    * reconnect outside any transaction included, gets the server's value) and the connection this instance still holds,
    * if usable, gets `SET SESSION innodb_lock_wait_timeout=DEFAULT` (the server's global value, what a new connection
    * gets): its later autocommit statements, and a clone made from it later (get_module_db() clones share the
    * connection), wait as long as the server says. Sent straight to the server like setApartLockWait() (not counted,
    * not session state); it ends nothing on the server and touches no transaction (a session variable is not
    * transactional). A failure (other than a connection that is gone or out of sync, where there is nothing to set
    * back) is logged in one line and changes nothing else.
    *
    * @param bool $whenOver true: only once this instance has no transaction open (inTransaction false); false: now (the
    *                       transaction is lost, or the connection is about to be let go of)
    * @return void
    */
    protected function endApartLockWait($whenOver = true)
    {
        if (!$this->movedApart || ($whenOver && $this->inTransaction === true)) {
            return;
        }
        $this->movedApart = false;
        if ((int) $this->apartLockWaitSeconds < 1 || !is_object($this->linkId) || !$this->linkEstablished || !$this->linkIsUsable()) {
            return;
        }
        try {
            $ok = @mysqli_query($this->linkId, 'SET SESSION innodb_lock_wait_timeout=DEFAULT');
            $errno = $ok === false ? (int) @mysqli_errno($this->linkId) : 0;
        } catch (\Throwable $e) {
            $ok = false;
            $errno = (int) $e->getCode();
        }
        if ($ok === false && $errno !== 2014 && !in_array($errno, self::LINK_ERRORS, true)) {
            error_log('Could not set innodb_lock_wait_timeout back to the server\'s value after the transaction of a START TRANSACTION moved off a shared connection (SQL error '.$errno.'): this connection keeps the shorter wait');
        }
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
            // B013 (S52, 2026-11-22): str_replace escaping without a live utf8mb4 link is not a
            // security control, so surface the outage instead of degrading silently: halt() sets
            // Errno/Error and logs (mailing only in 'report' mode); haltOnError='yes' dies inside
            // halt() before ever handing back a worse escape, and under PHP 8.3's default
            // MYSQLI_REPORT_STRICT real_connect() throws before connect() returns 0 at all — this
            // leg runs only when the driver is deliberately running with mysqli error reporting
            // off and halting off. escape() stays the documented worst case, and it is now loud.
            $this->halt('real_escape() called with no usable connection', __LINE__, __FILE__);
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
    * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows,
    * associative responses only
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
    * @return mixed FALSE if no rows, if a single row it returns that, if multiple it returns an array of rows,
    * associative responses only
    */
    public function qr($query, $line = '', $file = '')
    {
        return $this->queryReturn($query, $line, $file);
    }

    /**
    * creates a prepaired statement from query
    *
    * A START TRANSACTION, BEGIN, COMMIT or ROLLBACK (preparedTransactionStatement()) is not prepared: 0, Errno 1295,
    * nothing sent and nothing changed, as execute() refuses one (see there): the mysqli_stmt handed back could be run
    * with its own execute(), past this instance's bookkeeping. The statement execute() reads ($statement_query) is the
    * one prepared here whether or not it is logged (disable_db_queries), and after a refusal the refused one, so the
    * statement prepared before is never run in its place.
    *
    * @param string $query sql query like INSERT INTO table (col) VALUES (?)  or  SELECT * from table WHERE col1 = ? and
    * col2 = ?  or  UPDATE table SET col1 = ?, col2 = ? WHERE col3 = ?
    * @return false|mysqli_stmt
    * @param string $line
    * @param string $file
    */
    public function prepare($query, $line = '', $file = '')
    {
        $unread = false;
        $lead = static::leadingKeywordSql((string) $query, $unread);
        if (static::preparedTransactionStatement($lead, $unread, $this->inTransaction === true)) {
            // (execute() reads it, and refuses it too)
            $this->statement_query = $query;
            $this->refusePreparedTransactionStatement('prepare', $line, $file);
            return 0;
        }
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
            // a mysqli_stmt runs on the connection that prepared it: execute() checks that this instance still holds it
            $this->statementLink = is_object($this->linkId) ? $this->linkId : null;
            // what execute() reads, logged or not: kept with the statement it belongs to
            $this->statement_query = $query;
        } finally {
            $this->haltOnError = $haltPrev;
        }
        if (!isset($GLOBALS['disable_db_queries'])) {
            $this->addLog($query, microtime(true) - $start, $line, $file);
        }
        return $this->statement;
    }

    /**
    * Binds variables to a prepared statement as parameters
    * 
    * @param string $types A string that contains one or more characters which specify the types for the corresponding
    * bind variables: (i)nt, (d)ecimal, (s)tring, (b)inary
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
    * A mysqli_stmt runs on the connection that prepared it, whatever this instance holds now. When this instance has
    * let go of that connection since prepare() (a START TRANSACTION moved to a connection of its own beside another
    * handle's open transaction, startApart(); a transaction found ended by another holder; a dead link closed and a
    * new one opened), the statement is not sent: run there it would land inside whatever another handle has open on
    * that connection, or fail on a closed one. It fails like a lost connection instead (false, Errno 2006, one
    * error_log() line; no mail, no halt, as for any failed execute()): prepare() it again. A statement that commits
    * implicitly (DDL, LOCK TABLES...) run inside a transaction of this instance's own ends it when it works (when
    * whether it committed cannot be told it goes on with its end not known, or is given up, 2006, when what it ran is
    * not known at all: implicitCommitOutcome()) and gives it up when it fails after the server ran it, as through
    * query() (see executeFailed(), queryInTransaction()).
    *
    * A START TRANSACTION, BEGIN, COMMIT or ROLLBACK (preparedTransactionStatement(): every form query() tracks, and
    * one that may be any of them) is never sent here, nor prepared (prepare()): false, Errno 1295 (ER_UNSUPPORTED_PS,
    * the server's own "not supported in the prepared statement protocol": nothing ran, not a conflict errno), one
    * error_log() line, no mail, no halt, no exception, whatever haltOnError and the report mode are (as for a statement
    * prepared on a connection let go of), and nothing changes: an open transaction stays open, a lost one stays lost.
    * Run untracked it would end, open or commit a transaction behind the bookkeeping (a prepared COMMIT left the
    * transaction tracked as open, so a 1213 on the next statement was taken for its rollback and a retrying caller wrote
    * it twice) and past query()'s guards (the refusal after a lost transaction, another handle's transaction on the
    * shared link). Refused rather than tracked: MySQL takes only COMMIT of them as a prepared statement (the rest fail
    * 1295 at prepare), no caller sends one, and tracking it would repeat every guard of query() here. Send them through
    * query() or transactionBegin()/transactionCommit()/transactionAbort().
    *
    * @return bool success
    */
    public function execute($line = '', $file = '') {
        $unread = false;
        $lead = static::leadingKeywordSql((string) $this->statement_query, $unread);
        if (static::preparedTransactionStatement($lead, $unread, $this->inTransaction === true)) {
            $this->refusePreparedTransactionStatement('execute', $line, $file);
            return false;
        }
        if ($this->linkLostInTransaction() || $this->refusing()) {
            // the transaction it would have run in was lost (or its link closed, replaced or ended by another holder):
            // run now it would commit on its own
            return $this->refuseStatement((string) $this->statement_query, $line, $file);
        }
        if (is_object($this->statement) && is_object($this->statementLink) && $this->statementLink !== $this->linkId) {
            // prepared on a connection this instance has let go of
            return $this->executeOnAnotherLink($line, $file);
        }
        $this->movedOnFromLost();
        if ($this->inTransaction === true) {
            $this->txNotReplayable('execute() ran inside it');
        }
        $start = microtime(true);
        $ends = static::endsTransaction($lead, (string) $this->statement_query);
        // a statement that commits the open transaction of this instance's own implicitly before it runs (see
        // queryInTransaction())
        $implicitCommit = $ends && $this->inTransaction === true && preg_match('/^(?:start\s+transaction|begin|commit|rollback)\b/i', $lead) !== 1;
        $this->countStatement($ends);
        try {
            $return = mysqli_stmt_execute($this->statement);
        } catch (\mysqli_sql_exception $e) {
            $errno = (int) $e->getCode();
            $this->uncountEnd($errno, $ends);
            $lostWith = $this->executeFailed($errno, $e->getMessage(), $line, $file, $implicitCommit);
            if ($lostWith !== null && $lostWith !== $errno) {
                // lost with 2006 (outcome unknown), never the server's 1213 (as lock())
                throw new \mysqli_sql_exception($this->transactionLostError, $lostWith, $e);
            }
            throw $e;
        }
        if ($return === false) {
            $this->uncountEnd((int) @mysqli_stmt_errno($this->statement), $ends);
            $this->executeFailed((int) @mysqli_stmt_errno($this->statement), (string) @mysqli_stmt_error($this->statement), $line, $file, $implicitCommit);
        } elseif ($implicitCommit) {
            // what it did to the transaction, told strictly as in queryInTransaction() (implicitCommitOutcome())
            $outcome = static::implicitCommitOutcome($lead, (string) $this->statement_query);
            if ($outcome === 'end') {
                // it committed the open transaction first: the transaction is over, and not lost (clearTransactionLost())
                $this->inTransaction = false;
                $this->txEnd();
                $this->clearTransactionLost();
            } elseif ($outcome === 'doubt' || $outcome === 'chain') {
                $this->keepUnknownEnd((string) $this->statement_query, $line, $file, static::firstWord($lead), $outcome === 'chain');
            } elseif ($outcome === 'unknown') {
                $this->giveUpUnknownEnd((string) $this->statement_query, $line, $file, static::firstWord($lead));
            }
        } elseif ($ends && static::workedEndsNothing($lead)) {
            // it worked, so it ended nothing: another holder's transaction on the shared link goes on
            $this->takeBackEnd();
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
    * Whether a statement is one prepare() and execute() refuse (see execute()): a START TRANSACTION or BEGIN (query()'s
    * isStart, trackTransactionState()'s 'start'), any COMMIT (every form ends or chains the transaction), and any
    * ROLLBACK but a ROLLBACK [WORK] TO [SAVEPOINT] read as one with no executable comment among its words that is not
    * read (trackTransactionState() reads the rest as 'end' or 'chain'; one with such a comment, queryInTransaction()
    * gives up, and one whose comments cannot be read, it leaves untracked: refused here, as either may end the
    * transaction on the server); and, with no transaction of this instance's own open, one whose executable comment is
    * not read and that may be any of them (unreadLeadMay(): query() guards it as each of them there). Inside one such a
    * statement is told after it worked, by execute() as by queryInTransaction() (implicitCommitOutcome()), so it is not
    * refused.
    *
    * @param string $lead the statement from its first keyword on (leadingKeywordSql())
    * @param bool $unread whether leadingKeywordSql() found an executable comment it could not read
    * @param bool $open whether a transaction of this instance's own is open
    * @return bool
    */
    protected static function preparedTransactionStatement($lead, $unread, $open)
    {
        if (preg_match('/^(?:start\s+transaction|begin|commit)\b/i', $lead) === 1) {
            return true;
        }
        if (preg_match('/^rollback\b/i', $lead) === 1) {
            return static::executableAmongLeadingWords($lead)
                || preg_match('/^rollback(?:\s+work)?\s+to\b/i', static::withoutComments($lead)) !== 1;
        }
        if ($unread && !$open) {
            $may = static::unreadLeadMay($lead);
            return $may['commit'] || $may['rollback'] || $may['open'];
        }
        return false;
    }

    /**
    * prepare() or execute() of a START TRANSACTION, BEGIN, COMMIT or ROLLBACK: not sent (see execute()). Sets
    * Errno/Error and writes one error_log() line.
    *
    * @param string $what 'prepare' or 'execute'
    * @param string|int $line
    * @param string $file
    * @return void
    */
    protected function refusePreparedTransactionStatement($what, $line, $file)
    {
        $this->Errno = 1295;
        $this->Error = 'This command is not supported in the prepared statement protocol here: a START TRANSACTION, BEGIN, COMMIT or ROLLBACK goes through query() or transactionBegin()/transactionCommit()/transactionAbort() (not sent)';
        error_log($what.'() not sent: a START TRANSACTION, BEGIN, COMMIT or ROLLBACK (or a statement that may be one) is never run as a prepared statement, where the transaction it ends or opens would not be tracked; it fails (1295), nothing changed: send it through query() or transactionBegin()/transactionCommit()/transactionAbort(). Query: '.static::redactQueryForLog((string) $this->statement_query).' from '.$file.':'.$line);
    }

    /**
    * execute() of a statement prepared on a connection this instance has let go of: not sent (see execute()).
    *
    * @param string|int $line
    * @param string $file
    * @return false
    */
    protected function executeOnAnotherLink($line, $file)
    {
        $this->Errno = 2006;
        $this->Error = 'MySQL server has gone away (the prepared statement belongs to a connection this handle has let go of: prepare it again)';
        error_log('execute() not sent: the prepared statement belongs to a connection this handle has let go of (run there it would land inside whatever another handle has open on it); prepare() it again. Query: '.static::redactQueryForLog((string) $this->statement_query).' from '.$file.':'.$line);
        return false;
    }

    /**
    * A prepared statement failed. When the server rolled back the transaction it ran in (ROLLED_BACK_ERRORS), an
    * open transaction of this instance's own is given up (lost: a later COMMIT is refused, as for a statement through
    * query(); prepare()/execute() inside it already made it not replayable), and otherwise that is counted as an end
    * on the link for another holder's (countFailedEnd()). A lost connection is found by the next statement or COMMIT
    * on the dead link; any other failure fails only the statement. A statement that commits the open transaction
    * implicitly before it runs ($implicitCommit: DDL, LOCK TABLES...) and that the server ran is given up as through
    * query() (queryInTransaction(), lockFailed()): rolled back for 3101/1180, the connection's errno for a lost
    * connection, lost with 2006 (outcome unknown) for anything else, 1213 included; Errno/Error are set to what it
    * was lost with, and execute() throws a 2006 exception in place of the server's under MYSQLI_REPORT_STRICT.
    *
    * @param int $errno
    * @param string $error
    * @param string|int $line
    * @param string $file
    * @param bool $implicitCommit
    * @return int|null the errno the transaction was lost with (null: not given up here)
    */
    protected function executeFailed($errno, $error, $line, $file, $implicitCommit = false)
    {
        $sql = (string) $this->statement_query;
        if ($this->inTransaction !== true) {
            $this->endStrayDdlTransaction($errno, static::leadingKeywordSql($sql));
        }
        if ($implicitCommit && $this->inTransaction === true && !in_array($errno, self::NOT_RUN_ERRORS, true)) {
            $what = static::firstWord(static::leadingKeywordSql($sql));
            $lostWith = $errno;
            if ((!in_array($errno, [3101, 1180], true) || $this->txEndUnknown !== '') && !in_array($errno, self::LINK_ERRORS, true)) {
                // (3101/1180 rolled back only what the implicit commit committed: while the transaction's end is not
                // known, the writes before the statement that may have committed it may be committed)
                $lostWith = 2006;
                $error = 'Transaction lost: '.$what.' failed (SQL error '.$errno.') after the implicit commit of the open transaction may have run, so whether its writes were committed is not known';
            }
            $this->giveUpTransaction($sql, $line, $file, false, $lostWith, $error, false, null, '', false, $what, $errno);
            $this->Errno = $lostWith;
            $this->Error = $error;
            return $lostWith;
        }
        if ($this->inTransaction === true && (in_array($errno, self::ROLLED_BACK_ERRORS, true) || ($this->txEndUnknown !== '' && !in_array($errno, self::LINK_ERRORS, true)))) {
            // a transaction whose end is not known is lost with 2006 (unknownEndLoss()), and execute() throws that; so
            // is one that fails only its statement there (its errno would say "not committed": see queryInTransaction())
            list($lostWith, $lostError) = $this->unknownEndLoss($errno, $error);
            $this->giveUpTransaction($sql, $line, $file, false, $lostWith, $lostError, false, null, '', false, static::firstWord(static::leadingKeywordSql($sql)), null, $errno);
            if ($lostWith !== $errno) {
                $this->Errno = $lostWith;
                $this->Error = $lostError;
                return $lostWith;
            }
            return null;
        }
        $this->countFailedEnd($errno);
        $this->endImplicitTransaction($errno);
        return null;
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
    * the connection (LINK_ERRORS: 2006, 2013, 2055, 4031; or a handle mysqli cannot use any more), the transaction
    * is rolled back (or a new connection opened), started again, and every recorded statement re-run in order, each
    * observed outcome checked against the original, before the failed statement is run again. A replay that recovers
    * is silent
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
    * Errno/Error: the log write a failing mail causes through this very handle changes none of them. While any
    * handle in the process has a transaction open, the report's mail goes out through \MyAdmin\Mail::failsafeMail()
    * (no database access) instead of adminMail(), so the report never writes a log row into an open transaction
    * (Generic::mailErrorReport(), reportWithoutDatabase()).
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
    * end that handle's transaction): right after a give-up of its own (a lost record it has not moved on from:
    * $lostCleanupPending) it is the cleanup and succeeds as a ROLLBACK with nothing open does; otherwise it FAILS,
    * nothing rolled back (2006, "the open transaction belongs to another handle"; never mailed, never halts:
    * rollbackWithoutTransaction()), since true would tell a caller that meant the other transaction its writes were
    * discarded while they stay open for that handle's next COMMIT or START to commit; a ROLLBACK ... AND CHAIN from
    * such a handle begins its chained transaction on a connection of its own, like a START (startApart()). Roll back
    * through the handle that began the transaction. A COMMIT
    * (COMMIT ... RELEASE and COMMIT ... AND CHAIN too) from a handle with no transaction of its own (and no lost one,
    * whose COMMIT is refused), while another handle sharing the link has one open there, is not sent either (it would
    * commit that handle's transaction half done, behind its back), and it FAILS (2006, "the open transaction belongs
    * to another handle"; commitWithoutTransaction(), and transactionCommit() too, without mail or halt there): a
    * caller sending it most likely meant that other transaction, and a COMMIT answered as one with nothing open would
    * tell it those writes were saved while they are still uncommitted (rolled back if that handle never commits them).
    * Commit through the handle that began the transaction. ROLLBACK TO SAVEPOINT stays inside the transaction. A START
    * TRANSACTION/BEGIN with none open keeps the retry and reconnect behaviour every statement had before;
    * inTransaction is true only once it has succeeded. These statements are recognised on their leading words as the
    * server reads them (leadingKeywordSql(): a plain comment before or among them is a space, and so is an executable
    * one that holds nothing but whitespace; one whose content runs on every server is read as that content). One with
    * an executable comment there that is not read (a version above 50700, slash-star-M-bang) is, outside a transaction of
    * this instance's own, taken for each COMMIT, ROLLBACK or opener its readings may be (unreadLeadMay()): guarded as a
    * COMMIT or ROLLBACK from a handle with no transaction of its own (above), refused as a COMMIT after a lost
    * transaction, run as a START when it may open one (apart beside another handle's transaction; once it worked,
    * tracked as a transaction whose start is not known: openUnknown()), but while a lost transaction is refused it is
    * refused as what it is read as, a statement that is none of them (its readings never end the refusal); inside one,
    * see implicitCommitOutcome(). COMMIT/ROLLBACK
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
    * shared link (not closed) and begins its transaction on a connection of its own (row lock waits cut to
    * $apartLockWaitSeconds there), saying so in one error_log() line (startApart(); not when the link's session
    * state was changed: then the START stays on the shared link,
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
    * not counted); prepare()/execute() inside a transaction make it not replayable (and execute() refuses a statement
    * prepared on a connection this handle has let go of); lock() inside a transaction commits it, and so does a DDL,
    * LOCK TABLES or any other statement that commits implicitly sent through query() (it ends the transaction; one
    * that worked but may or may not have committed it, SET autocommit=1 say, lets it go on with its end not known, so
    * any failure in it afterwards loses it with 2006, and one whose content is not known, an executable comment that
    * is not read before its first keyword, gives it up, 2006: implicitCommitOutcome(); a failed one gives it up, lost
    * with 2006 unless the server says it rolled back: see
    * lock() and queryInTransaction(); a 1213 from such a statement never reads as "rolled back, retry"; a CALL or
    * EXECUTE of something that commits is not seen); COMMIT and ROLLBACK
    * through the handle that began the transaction (from another handle of the clone family they fail, 2006, and are
    * not sent: see above); a SET AUTOCOMMIT=0 outside one opens an implicit transaction the
    * tracker does not see. halt() no longer lets a mysqli_sql_exception from its UNLOCK TABLES escape under haltOnError
    * 'no' or 'report': on a connection that is gone a persistent 2006 now returns false there (it used to throw);
    * under 'yes' it still throws (callers catch it), as before.
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
        $unread = false;
        $lead = static::leadingKeywordSql($queryString, $unread);
        $isStart = preg_match('/^(start\s+transaction|begin)\b/i', $lead) === 1;
        // a plain ROLLBACK is told by a whitelist on the statement with every comment removed: any other form is
        // guarded; the whole-statement scan only runs on a statement that starts with ROLLBACK
        $isRollback = strncasecmp($lead, 'rollback', 8) === 0 && static::isPlainRollback($queryString);
        $isCommit = preg_match('/^commit\b/i', $lead) === 1;
        // whether it may open a transaction that it is not read as opening (see below)
        $mayOpen = false;
        // whether it is read as one of those on its own, before the readings it may have (below) are added: only then may
        // it get past the refusal after a lost transaction (and so end it, clear the lost record or count as the
        // caller's COMMIT of it)
        $known = $isStart || $isRollback || $isCommit;
        // a START or ROLLBACK read as one: only such a statement that worked clears the lost record (one that only may
        // be one, below, leaves it: a later COMMIT stays refused, the safe side; but for the COMMIT of a transaction it
        // may have opened, which is tracked as open: that one is sent, and it, like any end of that transaction that
        // works, clears the record: clearTransactionLost())
        $knownClears = $isStart || $isRollback;
        if ($unread && !$transactionOpen) {
            /*
             * An executable comment the lexer could not read sits before its first keyword or among its leading words:
             * what the statement is depends on the server (unreadLeadMay()). Outside a transaction of this instance's
             * own it is taken for each of the COMMIT, ROLLBACK and opener (START, BEGIN, AND CHAIN) it may be: guarded
             * as a COMMIT or ROLLBACK below (not sent while another handle sharing the link has a transaction open there;
             * a COMMIT refused after a lost transaction), and run as a START when it may open one (moved apart beside
             * another handle's open transaction, startApart(); once it worked, tracked as an open transaction whose
             * start is not known, openUnknown()). While a lost transaction is refused (refusing()), one read as none of
             * them on its own is refused like any other statement: on a server that skips the comment it is the statement
             * read here (MySQL 8.0 runs /*!99999 ROLLBACK; *\/ INSERT ... as the INSERT), and taken for the ROLLBACK,
             * START or COMMIT it may be, it would end the refusal and run on its own, the lost writes then reported as
             * saved by the next COMMIT. Inside one, what it did is told after it worked (queryInTransaction():
             * implicitCommitOutcome()).
             */
            $may = static::unreadLeadMay($lead);
            $isCommit = $isCommit || $may['commit'];
            $isRollback = $isRollback || $may['rollback'];
            $mayOpen = !$isStart && $may['open'];
            $isStart = $isStart || $may['open'];
        }
        if (!$known && $this->refusing()) {
            // the transaction the caller is still running was lost: sent now, this would commit on its own. One that may
            // be a ROLLBACK, START or COMMIT too (an executable comment that is not read) is refused the same way, before
            // the guards below can take it for the caller's ROLLBACK (the cleanup that clears the lost record) or COMMIT
            return $this->refuseStatement($queryString, $line, $file);
        }
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
        } elseif ($isCommit && !$this->transactionLost && $this->anotherHolderInTransaction()) {
            // this handle has no transaction of its own, and another handle sharing the link has one open there: the
            // COMMIT (AND CHAIN and RELEASE too) would commit that one, half done, behind its back, and answered as a
            // COMMIT with nothing open it would report that transaction's writes as saved (a refused COMMIT of a lost
            // one is never sent either)
            return $this->commitWithoutTransaction($queryString, $line, $file);
        }
        if (!$isStart && !$isRollback && !$isCommit) {
            // after a lost transaction, the caller goes on without a ROLLBACK
            $this->movedOnFromLost();
        }
        // a COMMIT whose transaction was lost is refused without a connection (it is never sent)
        $refused = $isCommit && $this->transactionLost;
        /*
         * A START TRANSACTION/BEGIN with no transaction open keeps the control flow it always had: the
         * flag is raised before it runs (once connected) and the guards below read the live flag, as before.
         * Only the bookkeeping differs: inTransaction ends up true only if the statement succeeded. (A statement
         * that may be a COMMIT as well as open one, refused here, is no START.)
         */
        $legacyStart = $isStart && !$transactionOpen && !$refused;
        if ($legacyStart) {
            // never on a link another handle has a transaction open on: the START would commit that one
            $this->startApart();
        }
        $connected = false;
        try {
            $connected = $refused || $this->connect();
        } finally {
            if (!$connected) {
                // a START (or ROLLBACK ... AND CHAIN) moved off a shared link that could not connect (connect() returned
                // 0, or threw under MYSQLI_REPORT_STRICT) began nothing
                $this->endApartLockWait();
            }
        }
        if (!$connected) {
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
        $ends = static::endsTransaction($lead, $queryString);
        // the link this statement's end is counted on and not taken back (null: none). It is counted once, however
        // many times the statement is sent there (a retry after 1205, 1213...): one end is all another holder needs to
        // find its transaction ended, and the one take-back of a CREATE TEMPORARY TABLE that worked (it ended nothing,
        // workedEndsNothing()) must leave no end of this statement's behind. A new link (a reconnect) counts it anew
        $endCountedOn = null;
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
                    // whether this try counts the end (none counted on this link yet)
                    $countEnd = $ends && is_object($this->linkId) && $endCountedOn !== $this->linkId;
                    try {
                        $this->countStatement($countEnd);
                        if ($countEnd) {
                            $endCountedOn = $this->linkId;
                        }
                        $this->queryId = @mysqli_query($this->linkId, $queryString, MYSQLI_STORE_RESULT);
                        if (null === $this->queryId || false === $this->queryId) {
                            // a transaction another holder of the shared link has open on it may have gone with this
                            $this->countFailedEnd((int)@mysqli_errno($this->linkId));
                            // and an end the server never ran ended nothing (the one this try counted: an earlier try
                            // that ran keeps its own)
                            if ($countEnd && in_array((int)@mysqli_errno($this->linkId), self::NOT_RUN_ERRORS, true)) {
                                $this->uncountEnd((int)@mysqli_errno($this->linkId), true);
                                $endCountedOn = null;
                            }
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
                        if ($countEnd && in_array((int)$e->getCode(), self::NOT_RUN_ERRORS, true)) {
                            $this->uncountEnd((int)$e->getCode(), true);
                            $endCountedOn = null;
                        }
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
            if (!$done) {
                // nor did a START (or ROLLBACK ... AND CHAIN) moved off a shared link that threw here
                $this->endApartLockWait();
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
            if (!$transactionOpen) {
                // (inside a transaction of this instance's own only a plain ROLLBACK gets here)
                $this->endStrayDdlTransaction($this->Errno, $lead);
            }
            if ($transactionOpen && $isRollback) {
                // the retries are used up, or the error is not retried: whatever it was, the ROLLBACK did not go
                // through, so a COMMIT must not commit (or report as saved) what the caller meant to discard. The
                // caller ended the transaction itself, so what it sends next is not refused; but the server may
                // still hold the transaction (an error the loop does not retry, such as a syntax error, never
                // reconnected): closing the connection ends it there.
                $this->inTransaction = false;
                // (2006 while its end is not known, unknownEndLoss(): what it held may be committed)
                list($lostErrno, $lostError) = $this->unknownEndLoss((int) $this->Errno > 0 ? (int) $this->Errno : 2006, (string) $this->Error);
                $this->markTransactionLost($lostErrno, $lostError, false);
                if ($this->linkIsUsable()) {
                    $this->dropLink();
                }
            }
        } else {
            // the state only changes once the statement has worked; the lost record only for a START or ROLLBACK read as
            // one (MySQL 8.0 runs /*!99999 ROLLBACK; *\/ INSERT ... as the INSERT: taken for the ROLLBACK it may be, it
            // would let the next COMMIT report a lost transaction's writes as saved)
            if ($knownClears) {
                $this->clearTransactionLost();
            }
            if ($endCountedOn !== null && $endCountedOn === $this->linkId && static::workedEndsNothing($lead)) {
                // it worked, so it ended nothing: another holder's transaction on the shared link goes on (counted once on
                // this link when it was sent, countStatement(), however many tries it took; a statement inside this
                // instance's own transaction never gets here, but a plain ROLLBACK)
                $this->takeBackEnd();
            }
            $change = $this->trackTransactionState($queryString);
            $this->txAfter($change, $transactionOpen, $queryString, $line, $file, $this->queryId);
            if ($mayOpen && $change === null && $this->inTransaction === true) {
                // it may have opened a transaction (its flag is up, as a START's): one whose start is not known
                $this->openUnknown($queryString, $line, $file);
            }
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
    * failure of one ends it. A statement MySQL runs only after committing the open transaction implicitly (DDL other
    * than on temporary tables, LOCK TABLES, SET autocommit, GRANT, TRUNCATE...: endsTransaction(), ROLLBACK aside) is
    * never replayed. When it works, what it did is told strictly (implicitCommitOutcome()): one MySQL commits on ends the
    * transaction, as after lock() (the writes before it were committed and what runs next autocommits); one that
    * commits nothing (SET autocommit=0 and SET GLOBAL/PERSIST autocommit; CREATE/DROP TEMPORARY TABLE, CREATE
    * /*!32312 TEMPORARY *\/ TABLE, DROP PREPARE and RESET PERSIST are not taken for one at all, a comment between their
    * words or not) leaves it open; one that committed it or left it open, which cannot be told, but rolled nothing back
    * (SET autocommit=1, which commits only when the session's autocommit was off; an executable comment that is not read
    * after its first word) lets it go on with its end not known (keepUnknownEnd(): not replayable, nothing refused or
    * halted, and any failure in it afterwards, one that fails only its statement too, loses it with 2006,
    * unknownEndLoss()); so does a CREATE TABLE ... START TRANSACTION, which committed it (its trans_begin() commits the
    * open transaction) and keeps one of its own open (keepUnknownEnd() $chain); one whose content is not known at all
    * (an executable comment that is not read before its first keyword; a COMMIT or ROLLBACK with one among its words)
    * gives it up, lost with 2006 (giveUpUnknownEnd()), and its result stands. When it fails, unless the server never
    * ran it (NOT_RUN_ERRORS: the transaction stays open), the transaction is given up like a lock() that failed
    * (lockFailed()): rolled back at the commit for 3101/1180 (that errno), the connection's own errno for a lost
    * connection, and for anything else, 1213 included (a deadlock on the metadata locks comes after the commit went
    * through), lost with 2006, outcome unknown, so a caller that retries on 1213/3101 does not apply writes that were
    * committed twice. Any other failure of a statement fails only the statement and the transaction stays open, as on
    * the server. A transaction whose link was replaced underneath it (a reconnect) or that another holder of the shared
    * link ended is lost: the statement is not sent (it would run outside the transaction), nothing is replayed, and the
    * link is let go of without being closed (another handle may have a transaction open on it, which closing it would
    * roll back).
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
        $ends = static::endsTransaction($lead, $queryString);
        // a statement MySQL runs only after committing the open transaction (DDL, LOCK TABLES, SET autocommit...: a
        // ROLLBACK ends it without a commit)
        $implicitCommit = $ends && !$commitLike && strncasecmp($lead, 'rollback', 8) !== 0;
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
                list($result, $errno, $error, $exception) = $this->send($queryString, '', $ends);
            }
            // the error of a statement that commits implicitly, when the commit may have gone through before it failed
            $implicitErrno = null;
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
                if ($result === false && $implicitCommit && !$linkLost && $elsewhere === '' && !in_array($errno, self::NOT_RUN_ERRORS, true) && !in_array($errno, [3101, 1180], true)) {
                    // the server ran it, so it committed the open transaction first; it failed after that commit or in
                    // it (a deadlock, 1213, on the metadata locks comes after the commit went through), so whether the
                    // writes were committed is not known: lost with 2006, never 1213 ("rolled back, retry": a caller
                    // retrying on 1213/3101 would apply writes that did commit twice), as lock() does (lockFailed()).
                    // 3101 and 1180 come from the commit only (rolled back), a connection error says unknown itself;
                    // a statement the server never ran (NOT_RUN_ERRORS) left the transaction open.
                    $implicitErrno = $errno;
                    $errno = 2006;
                    $error = 'Transaction lost: '.static::firstWord($lead).' failed (SQL error '.$implicitErrno.') after the implicit commit of the open transaction may have run, so whether its writes were committed is not known';
                }
                if ($result === false) {
                    // a START/BEGIN the server never ran left the transaction open (its own failure only)
                    $notRun = $isStart && in_array($errno, self::START_NOT_RUN_ERRORS, true);
                    if ($replay !== null && $replay['restored']) {
                        // the transaction is back as it was, but the statement failed on its own
                        $giveUp = $commitLike && !$notRun;
                    } elseif ($linkLost || $elsewhere !== '' || $replay !== null || $implicitErrno !== null || in_array($errno, self::ROLLED_BACK_ERRORS, true)) {
                        $giveUp = true;
                    } else {
                        // the server rolled back only the statement; a COMMIT (or the START that commits first) that failed
                        // on the server ended the transaction, and a COMMIT never sent (2014) is given up too
                        $giveUp = $commitLike && !$notRun;
                    }
                    if (!$giveUp && $this->txEndUnknown !== '') {
                        // a failure that fails only its statement (1062...) says "not committed", while the writes before
                        // whatever made its end (or start) not known may be committed: given up, lost with 2006 like any
                        // other failure in it (unknownEndLoss())
                        $giveUp = true;
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
                $change = $this->trackTransactionState($queryString);
                if ($change !== null) {
                    // a START, COMMIT or ROLLBACK (any form that ends it: one that is not plain gets here) that worked
                    // ended the transaction without losing it (clearTransactionLost())
                    $this->clearTransactionLost();
                }
                $this->txAfter($change, true, $queryString, $line, $file, $result);
                if ($implicitCommit) {
                    // what it did to the transaction is told strictly (implicitCommitOutcome())
                    $outcome = static::implicitCommitOutcome($lead, $queryString);
                    if ($outcome === 'end') {
                        // it committed the open transaction before it ran (MySQL's implicit commit: the writes so far are
                        // committed), and the connection is back in autocommit: the transaction is over, as after lock(),
                        // so a later 1213 is never taken for the rollback of a transaction that is gone; and it is not lost
                        // (clearTransactionLost())
                        $this->inTransaction = false;
                        $this->txEnd();
                        $this->clearTransactionLost();
                    } elseif ($outcome === 'doubt' || $outcome === 'chain') {
                        // it committed it or left it open, which is not known ('chain': it committed it, and may have
                        // begun a transaction of its own): it goes on, its end not known to the caller's errno
                        $this->keepUnknownEnd($queryString, $line, $file, static::firstWord($lead), $outcome === 'chain');
                    } elseif ($outcome === 'unknown') {
                        // what it ran is not known (a ROLLBACK among what it may be): given up (lost, 2006)
                        $this->giveUpUnknownEnd($queryString, $line, $file, static::firstWord($lead));
                    }
                    // 'none' (SET autocommit=0, a SET of no session autocommit): it commits nothing, the transaction goes on
                } elseif (preg_match('/^(?:commit|rollback)\b/i', $lead) === 1 && static::executableAmongLeadingWords($lead)) {
                    // COMMIT or ROLLBACK with an executable comment among its words that is not read (ROLLBACK
                    // /*!99999 TO SAVEPOINT s1 *\/ is a ROLLBACK of the whole transaction on a server older than that,
                    // COMMIT /*!99999 AND CHAIN *\/ a COMMIT that chains nothing): whether the transaction was committed,
                    // rolled back or goes on is not known, so it is given up (lost, 2006), as for 'unknown'
                    $this->giveUpUnknownEnd($queryString, $line, $file, static::firstWord($lead));
                }
                if ($replay !== null) {
                    error_log('Replayed a transaction the server rolled back (SQL error '.$replay['first'].'; '.$replay['attempts'].' replay(s) of '.$replay['steps'].' statement(s)); the statement went through. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                }
            } else {
                $serverErrno = (int) $errno;
                if ($giveUp) {
                    // a transaction whose end is not known is lost with 2006 (unknownEndLoss())
                    list($errno, $error) = $this->unknownEndLoss($errno, $error);
                }
                $this->Errno = $errno;
                $this->Error = $error;
                if ($giveUp) {
                    // the caller's own COMMIT ends the transaction; anything else (a statement in it, a START/BEGIN or
                    // an AND CHAIN that opens the next one) leaves the caller taking it to be open
                    $endsHere = $isCommit && preg_match('/\band\s+chain\b(?!\s+release\b)/i', static::withoutComments($lead)) !== 1;
                    $this->giveUpTransaction($queryString, $line, $file, $commitLike, $errno, $error, $linkGone, $replay, $elsewhere, $endsHere, static::firstWord($lead), $implicitCommit ? ($implicitErrno !== null ? $implicitErrno : $serverErrno) : null, $serverErrno);
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
    * $implicit is the server's error of a statement that commits the open transaction implicitly before it runs (DDL,
    * LOCK TABLES...: see queryInTransaction()), for the log line: unless that error came from the commit (3101, 1180:
    * rolled back) the commit may have gone through, so the line never says the server rolled the transaction back.
    *
    * @param string $elsewhere why the transaction is not on this instance's link any more ('' when it is)
    * @param bool $endsHere
    * @param string $what the statement's first word, for the log line
    * @param int|null $implicit the statement's own error when it commits implicitly (null: it does not)
    * @param int|null $serverErrno the server's error, when $errno is what the transaction is lost with instead (a
    *                              transaction whose end was not known: unknownEndLoss())
    * @return void
    */
    protected function giveUpTransaction($queryString, $line, $file, $commitLike, $errno, $error, $linkGone, $replay, $elsewhere = '', $endsHere = false, $what = 'COMMIT', $implicit = null, $serverErrno = null)
    {
        $unknownEnd = $this->txEndUnknown;
        $sent = $serverErrno !== null ? (int) $serverErrno : (int) $errno;
        $this->inTransaction = false;
        $this->markTransactionLost((int) $errno, (string) $error, !$endsHere);
        // lost with 2006 in place of a conflict (unknownEndLoss()): still one for conflictsToCaller()
        $this->transactionLostConflict = $sent !== (int) $errno && in_array($sent, self::REPLAY_ERRORS, true);
        $why = $replay !== null ? ' Not recovered: '.$replay['why'].'.' : ($this->txNotReplayableWhy !== '' ? ' Not replayed: '.$this->txNotReplayableWhy.'.' : '');
        $this->txEnd();
        if ($elsewhere === '' && is_object($this->linkId) && $this->linkIsUsable()) {
            if ($commitLike && $sent === 2014) {
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
        } elseif ($unknownEnd !== '' && $serverErrno !== null && (int) $serverErrno !== (int) $errno) {
            $message = $what.' inside a transaction failed (SQL error '.(int) $serverErrno.') after '.$unknownEnd.' (not to be retried): the transaction is given up (lost, #'.(int) $errno.'), a later COMMIT is refused, and so are statements until a ROLLBACK or a new START.';
        } elseif ($implicit !== null) {
            $message = $what.' inside a transaction failed (SQL error '.(int) $implicit.'); it commits the open transaction first, '.(in_array((int) $implicit, [3101, 1180], true) ? 'and that commit was rolled back' : 'so whether its writes were committed is not known (not to be retried)').': the transaction is given up (lost, #'.(int) $errno.'), a later COMMIT is refused, and so are statements until a ROLLBACK or a new START.';
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
    * A statement that may commit the open transaction implicitly WORKED inside a transaction of this instance's own,
    * and what it ran cannot be told at all (implicitCommitOutcome() 'unknown': an executable comment before its first
    * keyword that leadingKeywordSql() could not read, a version newer than EXECUTED_COMMENT_VERSION or a MariaDB one:
    * it may have committed the transaction, left it open, or rolled it back). Neither ending the transaction nor keeping
    * it is safe: ended while the server kept it open, a 1213 on a later statement would roll the writes so far back,
    * that statement would be retried on its own (autocommit) and the COMMIT would report the writes as saved; kept
    * while the server committed it, a 1213 later would be handed out as "rolled back, retry", and a caller retrying
    * would apply the committed writes twice; kept while it rolled it back, the COMMIT would report the writes as saved.
    * So it is given up as after a failure of such a statement (queryInTransaction()): lost with 2006 (outcome unknown,
    * the errno a caller must not retry its transaction on), a quiet ROLLBACK on its usable link so nothing of it is left
    * open (writes still open there are discarded), the statements the caller sends next refused, and its COMMIT refused,
    * until a ROLLBACK, START or transactionAbort(). On the transaction's own link (it is: transactionElsewhere() came
    * back empty) the ROLLBACK goes out whatever another holder of the link says, as in giveUpTransaction(): the
    * transaction open there, if one is, is this instance's; another holder still flagged as inside a
    * transaction is one whose transaction the START of this one committed (a START that stays on the shared link
    * because its session state changed, startApart()). Skipped for that stale flag, the transaction would stay open
    * while the caller's cleanup ROLLBACK is answered without being sent ($lostCleanupPending), and the next statement
    * the caller takes for an autocommit would join it and be rolled back later. The statement's own result stands (it
    * ran: query()/execute() return it). One error_log() line says so; nothing is mailed and nothing halts here (a
    * refused statement or COMMIT reports as any does: under haltOnError 'yes', mailed, and halt() ends the request).
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @param string $what the statement's first word, for the log line
    * @return void
    */
    protected function giveUpUnknownEnd($queryString, $line, $file, $what)
    {
        $this->inTransaction = false;
        $this->markTransactionLost(2006, 'Transaction lost: '.$what.' ran inside it, and what it ran is not known (an executable comment), so whether its writes were committed is not known', true);
        // read before txEnd() forgets the transaction's link
        $own = is_object($this->txLink) && $this->linkId === $this->txLink;
        $this->txEnd();
        if (is_object($this->linkId) && $this->linkIsUsable() && ($own || !$this->anotherHolderInTransaction())) {
            $this->sendQuietly('ROLLBACK');
        }
        error_log($what.' inside a transaction worked, but what it ran is not known (an executable comment that is not read here), so whether it committed or rolled back the open transaction is not known: the transaction is given up (lost, #2006, not to be retried), a later COMMIT is refused, and so are statements until a ROLLBACK or a new START. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
    }

    /**
    * A statement that may commit the open transaction implicitly WORKED inside a transaction of this instance's own,
    * and it either committed the transaction or left it open, which cannot be told (implicitCommitOutcome() 'doubt':
    * SET autocommit=1, which commits only when the session's autocommit was off; STOP GROUP_REPLICATION...); it rolled
    * nothing back. The transaction goes on ($txEndUnknown), and neither guess is acted on:
    *  - statements go on as before (none is refused, nothing halts): left open, they run inside the transaction; ended,
    *    each one commits on its own;
    *  - a COMMIT that works reports success: every write is committed either way (by that COMMIT, or by the implicit
    *    commit and autocommit), and so does a START TRANSACTION that works (it commits whatever is open first);
    *  - it is not replayed any more, and anything that fails in it from here on and would end it (a 1213 or 3101 on a
    *    statement, a failed COMMIT or START, lock(), execute()) gives it up lost with 2006 (unknownEndLoss(): outcome
    *    unknown, the errno a caller must not retry its transaction on; a connection error keeps its own, which says
    *    the same), never with the server's 1213/3101 ("rolled back, retry": the writes before the statement may be
    *    committed, and a retry would apply them twice) nor with an errno that says "not committed": a failure that
    *    fails only its statement (1062, a statement the server never ran...) gives it up with 2006 too (the caller's
    *    rule reads any other errno as "not committed", while the writes before may be; the server kept the transaction
    *    open or had committed it, and the quiet ROLLBACK ends what is still open);
    *  - a ROLLBACK is sent as always: it discards what is still open; the writes before the statement stay committed
    *    if it committed them (as after any statement that commits implicitly).
    * One error_log() line says so. The statement's own result stands.
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * $chain (implicitCommitOutcome() 'chain': a CREATE TABLE that may hold START TRANSACTION) is one that DID commit the
    * transaction, and may have begun one of its own for the CREATE (trans_begin(): only COMMIT and ROLLBACK run in it,
    * any other statement fails, ER_STATEMENT_NOT_ALLOWED_AFTER_START_TRANSACTION): handled the same way (the caller's
    * transaction goes on as far as the library is concerned, a COMMIT commits the CREATE and reports success, a
    * ROLLBACK discards it, and any failure from here on is a 2006, never that statement's "not committed" errno), only
    * the log line and the error text say the writes before it are committed.
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @param string $what the statement's first word, for the log line
    * @param bool $chain
    * @return void
    */
    protected function keepUnknownEnd($queryString, $line, $file, $what, $chain = false)
    {
        $this->txNotReplayable($chain ? $what.' committed it implicitly' : 'whether '.$what.' committed it implicitly is not known');
        if ($this->txEndUnknown === '') {
            if ($chain) {
                error_log($what.' inside a transaction worked: MySQL committed the open transaction before it ran (a CREATE TABLE ... START TRANSACTION begins a transaction of its own for the CREATE, which only a COMMIT or ROLLBACK may follow), so the writes before it are committed: the transaction goes on (a COMMIT that works commits the CREATE too), it is not replayed, and any failure in it from here on loses it with 2006 (not to be retried). Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                $this->txEndUnknown = $what.' ran inside it, which committed the writes before it (MySQL commits the open transaction before a CREATE TABLE, one with START TRANSACTION too), so those are committed and what ran after it may be';
            } else {
                error_log($what.' inside a transaction worked; it commits the open transaction only in some sessions (SET autocommit=1: when autocommit was off), and which is not known: the transaction goes on (its statements run, a COMMIT that works has saved every write either way), it is not replayed, and any failure in it from here on loses it with 2006 (outcome unknown, not to be retried). Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                $this->txEndUnknown = $what.' ran inside it, which may have committed it implicitly, so whether its writes were committed is not known';
            }
        }
    }

    /**
    * A statement outside a transaction that may have opened one WORKED, and whether it did is not known: an executable
    * comment leadingKeywordSql() could not read makes it an opener on one server and not on another (unreadLeadMay()
    * 'open': /*!99999 x *\/ START TRANSACTION opens one where the comment is skipped, COMMIT /*!80000 AND CHAIN *\/
    * chains on MySQL 8.0, not on 5.7). Taken for an open transaction (query() raised the flag, as for a START), so a
    * 1213 in it is never retried on its own (where it did open one, the server rolled back what ran before) and other
    * handles sharing the link see it, with its start not known, as a transaction whose end is not known
    * (keepUnknownEnd()): it is not replayed (where it opened nothing, what ran after it committed statement by
    * statement, and a replay would write that twice), and any failure in it from here on loses it with 2006
    * (unknownEndLoss(): outcome unknown, the errno a caller must not retry its transaction on, never "rolled back,
    * retry" nor "not committed"). A COMMIT that works has saved every write either way; a ROLLBACK discards what is
    * still open. One error_log() line says so.
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @return void
    */
    protected function openUnknown($queryString, $line, $file)
    {
        $this->txStart($queryString);
        $this->txNotReplayable('whether the statement that began it opened a transaction is not known (an executable comment that is not read here)');
        $this->txEndUnknown = 'the statement that began it, held by an executable comment that is not read here, may have opened none (then what ran after it committed statement by statement), so whether its writes were committed is not known';
        error_log('A statement that may open a transaction worked, and an executable comment in it is not read here, so whether it opened one is not known: it is taken for an open transaction that is not replayed, and any failure in it loses it with 2006 (outcome unknown, not to be retried); a COMMIT that works has saved every write either way. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
    }

    /**
    * The errno and error the open transaction is lost with when something fails in it: unchanged, unless its end is
    * not known ($txEndUnknown, keepUnknownEnd()), where anything but a connection error (LINK_ERRORS, which say the
    * outcome is unknown themselves) becomes 2006: the writes before the statement whose end is not known may be
    * committed, so neither "rolled back, retry" (1213, 3101) nor "not committed" may be told.
    *
    * @param int $errno
    * @param string $error
    * @param string|null $endUnknown the transaction's $txEndUnknown when txEnd() has cleared it already
    *                                (transactionAbort()); null: read it now
    * @return array{0: int, 1: string}
    */
    protected function unknownEndLoss($errno, $error, $endUnknown = null)
    {
        $endUnknown = $endUnknown === null ? $this->txEndUnknown : (string) $endUnknown;
        if ($endUnknown === '' || in_array((int) $errno, self::LINK_ERRORS, true)) {
            return [(int) $errno, (string) $error];
        }
        return [2006, 'Transaction lost: SQL error '.(int) $errno.' after '.$endUnknown];
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
    * Whether an error report must not touch any database (Generic::mailErrorReport()): this instance, or any other
    * instance of the driver in the process that has sent a statement on a link it still holds, has a transaction open.
    * Not only this link's holders: the report's log row goes through the application's handle (MyAdmin's App::db()),
    * which may hold another connection. Without \WeakMap (PHP 7) only this instance is asked. Cheap: it runs only
    * while a failure is reported.
    *
    * @return bool
    */
    protected function reportWithoutDatabase()
    {
        if ($this->inTransaction === true) {
            return true;
        }
        if (self::$linkHolders === null) {
            return false;
        }
        foreach (self::$linkHolders as $holders) {
            foreach ($holders as $holder => $unused) {
                if ($holder->inTransaction === true) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
    * Whether a failure with $errno goes to the caller without emailError()/halt() ($reportConflicts off): 1213 and
    * 3101, and the 2006 a transaction whose end was not known was lost with in their place ($transactionLostConflict).
    *
    * @param int $errno
    * @return bool
    */
    protected function conflictsToCaller($errno)
    {
        if ($this->reportConflicts !== false) {
            return false;
        }
        // a conflict the transaction was lost to with 2006, its end not known (unknownEndLoss())
        return in_array((int) $errno, self::REPLAY_ERRORS, true) || ((int) $errno === 2006 && $this->transactionLostConflict && $this->transactionLostErrno === 2006);
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
    * @return array{ok: bool, restored: bool, result: mixed, errno: int, error: string, first: int, why: string,
    * attempts: int, steps: int}
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
            $ends = $api !== '' || static::endsTransaction(static::leadingKeywordSql($sql), $sql);
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
    * ended nothing on the server (SET autocommit=1 with autocommit already on, a DDL the server refused before its
    * implicit commit, ...) leaves the transaction open on the connection, its writes waiting for
    * whatever COMMIT or START TRANSACTION comes next there, while this instance gives the transaction up. The ROLLBACK
    * ends it; when the transaction was really ended (committed or rolled back) it rolls back nothing. It is not sent
    * while another handle has a transaction open there (it would end that one: anotherLiveHolderInTransaction()), nor
    * on a link that replaced the transaction's own (the transaction went with the old link). A holder still flagged as
    * inside a transaction that its own count shows ended on the link (a START of another handle that stayed on the
    * shared link committed it, startApart()) has none open there: skipped for that stale flag, the ROLLBACK would leave
    * this transaction open on the server, and the next writes of any handle on the link would join it and be rolled
    * back when the connection closes (or committed by the next COMMIT or START there). The transaction is lost either
    * way: whether its writes were committed by whatever ended it is not known here.
    *
    * @return void
    */
    protected function releaseEndedElsewhere()
    {
        if (is_object($this->linkId) && $this->linkId === $this->txLink && $this->linkIsUsable() && !$this->anotherLiveHolderInTransaction()) {
            $this->sendQuietly('ROLLBACK');
        }
        // the transaction is lost: a connection it moved off a shared link (startApart()) gets the server's lock wait
        // back before it is let go of, so whoever keeps it does not inherit the shorter one
        $this->endApartLockWait(false);
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
        if (!$ends || !in_array((int) $errno, self::NOT_RUN_ERRORS, true)) {
            return;
        }
        $this->takeBackEnd();
    }

    /**
    * Takes back one end of a transaction counted on this instance's link (countStatement(true)): for a statement the
    * server never ran (uncountEnd()), and for one that worked and so ended nothing (workedEndsNothing()).
    *
    * @return void
    */
    protected function takeBackEnd()
    {
        if (self::$linkEnds === null || !is_object($this->linkId)) {
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
    * Whether another instance sharing this instance's link has a transaction open on it that, as far as that instance
    * can tell, is still there: flagged in a transaction, and not ended by another holder or carried off by a
    * reconnect (its transactionElsewhere() is ''). releaseEndedElsewhere() asks this one; the callers of
    * anotherHolderInTransaction() that refuse a statement or move a START apart read a stale flag on the safe side.
    *
    * @return bool
    */
    protected function anotherLiveHolderInTransaction()
    {
        foreach ($this->otherLinkHolders() as $holder) {
            if ($holder->inTransaction === true && $holder->transactionElsewhere() === '') {
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
    * line says so, with no SQL and no values. The other handle may hold row locks the moved transaction's statements
    * wait for, and nothing releases them while this request waits: every connection this instance opens while the
    * moved transaction runs (the one connect() opens next, and any later one, a replay's reconnect included:
    * $movedApart) gets innodb_lock_wait_timeout = $apartLockWaitSeconds (5 s; setApartLockWait()), so such a wait
    * costs at most that, then fails with 1205 as any lock wait timeout does (with innodb_rollback_on_timeout OFF only
    * the statement is rolled back and the transaction stays open), instead of the server's 50 s, and instead of
    * silently committing the other handle's work. The shorter wait is the moved transaction's only: when it is over
    * (committed, rolled back, given up or lost, or the START began nothing) the connection is set back to the
    * server's value and later connections are not cut (endApartLockWait()). While it runs, a clone made from this
    * instance shares the connection and with it the shorter wait.
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
        $this->movedApart = true;
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
    * sharing the link has one open there: it is not sent. The server keeps one transaction per connection, so it would
    * end the other handle's, whose writes would be gone and whose COMMIT would then fail. A ROLLBACK ... RELEASE is not
    * sent either (it would also close the connection under that handle).
    *  - The handle kept a lost record (a transaction of its own it gave up, already rolled back or let go of) that
    *    still waits for its cleanup ($lostCleanupPending: the caller has sent nothing of its own since, a refused COMMIT
    *    aside): this is that cleanup, with nothing left to roll back, so it is answered as MySQL answers a ROLLBACK with
    *    nothing open: it succeeds (true, Errno 0) and the record is cleared, as by any successful ROLLBACK.
    *  - Otherwise (no lost record, or a stale one the caller moved on from) the caller most likely meant the other
    *    handle's transaction (begun through another handle of the clone family: App::db() and a get_module_db()
    *    clone, or two get_module_db() calls), and true would tell it those writes were discarded while they stay open,
    *    for that handle's next COMMIT or START to commit (a retry would then apply them twice). So it fails, nothing
    *    rolled back: false, Errno 2006 (not a conflict errno: a caller must not retry its transaction on it), Error
    *    saying the open transaction belongs to another handle. It is never mailed and never halts, whatever
    *    haltOnError is (a ROLLBACK sent "just in case" stays harmless): the caller reads false. Nothing changes for
    *    either handle.
    * One error_log() line says so either way. A ROLLBACK meant for another handle's transaction has to be sent
    * through the handle that began it (or transactionAbort() on it).
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @return bool
    */
    protected function rollbackWithoutTransaction($queryString, $line, $file)
    {
        if (is_resource($this->queryId)) {
            $this->free();
        }
        $this->txEnd();
        $this->Row = 0;
        if ($this->transactionLost && $this->lostCleanupPending) {
            // the cleanup after this handle's own give-up: nothing of it is left to roll back
            $this->clearTransactionLost();
            $this->queryId = true;
            $this->Errno = 0;
            $this->Error = '';
            error_log('ROLLBACK not sent: this handle has no transaction open (its own was given up), and another handle sharing the connection has one open there, which it would end. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
            return true;
        }
        $this->queryId = false;
        $this->Errno = 2006;
        $this->Error = 'ROLLBACK not sent: the open transaction belongs to another handle: roll it back through the handle that began it';
        error_log('ROLLBACK not sent: this handle has no transaction open, and the transaction open on the shared connection belongs to another handle; it fails (2006), nothing was rolled back: roll it back through the handle that began it. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
        return false;
    }

    /**
    * A COMMIT (query(); COMMIT ... RELEASE and COMMIT ... AND CHAIN included) of a handle that has no transaction of its
    * own and no lost one to refuse, while another handle sharing the link has one open there: it is not sent, and it
    * fails. The server keeps one transaction per connection, so it would commit the other handle's transaction half
    * done, behind its back (that handle would then find it ended elsewhere: its writes so far committed, its next
    * statements refused and its own COMMIT failing with 2006). Nor may it succeed as a COMMIT with nothing open: the
    * caller most likely meant that other transaction (begun through another handle of the clone family, App::db() and
    * a get_module_db() clone say), and true would tell it those writes were saved while they are still uncommitted, and
    * rolled back when the connection closes unless that handle commits them. So it fails like a statement that never
    * reached the server: false, Errno 2006 (the errno a caller must not retry a transaction on; nothing was sent, so
    * nothing was committed), Error saying the open transaction belongs to another handle, one error_log() line (no SQL),
    * then emailError()/halt() as for any failed statement (reportFailure()). Nothing changes for either handle: the
    * other handle's transaction goes on, and this one still has none. Commit through the handle that began the
    * transaction. transactionCommit() fails the same way, without mail or halt.
    *
    * @param string $queryString
    * @param string|int $line
    * @param string $file
    * @return false
    */
    protected function commitWithoutTransaction($queryString, $line, $file)
    {
        if (is_resource($this->queryId)) {
            $this->free();
        }
        $this->txEnd();
        $this->queryId = false;
        $this->Row = 0;
        $this->Errno = 2006;
        $this->Error = 'COMMIT not sent: the open transaction belongs to another handle (commit it through the handle that began it)';
        error_log('COMMIT not sent: this handle has no transaction open, and the transaction open on the shared connection belongs to another handle; it fails (2006), nothing was committed: commit through the handle that began it. From '.$file.':'.$line);
        $this->reportFailure($queryString, $line, $file);
        return false;
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
    * The statement from its first keyword on, with its leading words read as MySQL's lexer reads them (MySQL 8.0
    * sql_lex.cc, MY_LEX_LONG_COMMENT, MY_LEX_COMMENT). Every check of what a statement is (query()'s START, COMMIT and
    * ROLLBACK, trackTransactionState(), endsTransaction(), implicitCommitOutcome(), changesSessionState(),
    * replayableStatement()) reads this.
    *  - Before the first keyword: whitespace, "(" and plain comments (a block comment; "--" followed by whitespace, a
    *    control character or the end; "#" to the end of the line) are skipped, as Generic::leadingKeywordSql() does.
    *  - Among the leading words (the first keyword and the words that follow it up to the first character that is
    *    neither a word byte, whitespace nor a comment: "(", "=", "@", a quote...): a plain comment becomes one space, so
    *    START /* c *\/ TRANSACTION reads as the START TRANSACTION the server runs.
    *  - An executable comment there or before the first keyword is unwrapped: the server runs its content, so its
    *    markers (slash-star-bang with its five-digit version, and the star-slash that closes it) become a space and its
    *    content is read as the statement's words (CREATE /*!32312 TEMPORARY *\/ TABLE is a CREATE TEMPORARY TABLE,
    *    /*!40101 SET NAMES utf8mb4 *\/ a SET NAMES, LOCK /*!50000 TABLES *\/ t WRITE a LOCK TABLES). A plain comment
    *    inside it is a space too. Only one whose content runs on every server is unwrapped: no version (MySQL runs
    *    slash-star-bang without five digits as code) or a version up to EXECUTED_COMMENT_VERSION.
    *  - One that cannot be read that way is left where it is, with everything after it, for the checks to find
    *    (executableAmongLeadingWords(): an executable comment still in the lead is one whose content is not known): a
    *    later version (it runs only on a server at least that new) or a six-digit one (MariaDB's: MySQL reads five
    *    digits and runs the sixth as a word), slash-star-M-bang (MariaDB runs it, MySQL takes it
    *    for a plain comment), an executable comment inside another (MySQL's lexer ends both at the inner one's end), a
    *    "--" or "#" comment inside one (it runs to the end of the line, past the star-slash), or one never closed
    *    (refused by the server). When it opened among the leading words, the lead keeps the words before it.
    * Nothing after the leading words is read (a string literal holding a comment marker changes nothing) and the rest of
    * the statement is returned as it is. Without a comment among the leading words the result is the statement from its
    * first keyword on, as from Generic::leadingKeywordSql(). Linear in the length of what it skips. An executable comment
    * that holds nothing but whitespace (EMPTY_EXECUTABLE_PATTERN: /*!80000 *\/, /*M!50000 *\/) runs nothing whether
    * the server runs or skips it, so it is a space like a plain comment, whatever its version.
    *
    * @param string $queryString
    * @param bool|null $unread set to whether the lead was left at an executable comment that is not read, before the
    *                          first keyword or among the leading words (the words read so far, then that comment on)
    * @return string
    */
    protected static function leadingKeywordSql($queryString, &$unread = null)
    {
        $unread = false;
        $sql = (string) $queryString;
        $length = strlen($sql);
        // the common case, read without the walk below: the statement starts with its first keyword (after whitespace
        // or "(" only), and its leading words end at the end or at a character that cannot open a comment
        // (one anchored, possessive PCRE match: strspn() with a long mask is a nested loop, twice as slow here)
        $offset = strspn($sql, " \t\n\r\f\x0B(");
        if (preg_match(self::PLAIN_LEAD_PATTERN, $sql, $plain, 0, $offset) === 1) {
            return $offset === 0 ? $sql : substr($sql, $offset);
        }
        $offset = 0;
        // the lead built so far, and where the text not copied into it yet begins (null: no word yet); the same two as
        // they were when the open executable comment began ($exec: its offset), to go back to if it cannot be read
        // ($execLead: the length the lead had then, not a copy of it: a copy would share the string, so every later
        // append to the lead would copy all of it, and a run of executable comments would cost its length squared)
        $lead = '';
        $copy = null;
        $exec = null;
        $execLead = 0;
        $execCopy = null;
        $stopped = false;
        while ($offset < $length) {
            // whitespace (MySQL's lexer takes \f and \v for space too), and "(" before the first word
            $offset += strspn($sql, $copy === null ? " \t\n\r\f\x0B(" : " \t\n\r\f\x0B", $offset);
            if ($offset >= $length) {
                break;
            }
            $words = strspn($sql, self::WORD_BYTES, $offset);
            if ($words > 0) {
                if ($copy === null) {
                    $copy = $offset;
                }
                $offset += $words;
                continue;
            }
            $char = $sql[$offset];
            $next = $offset + 1 < $length ? $sql[$offset + 1] : '';
            if ($char === '/' && $next === '*') {
                $third = $offset + 2 < $length ? $sql[$offset + 2] : '';
                if ($third === '!' || ($third === 'M' && $offset + 3 < $length && $sql[$offset + 3] === '!')) {
                    $skip = 3;
                    $readable = $third === '!' && $exec === null;
                    if ($readable && strspn($sql, '0123456789', $offset + 3, 5) === 5) {
                        // a version comment: MySQL runs its content only on a server at least that new (a sixth digit:
                        // a MariaDB version, which MySQL reads as five digits and a word)
                        $skip = 8;
                        $readable = (int) substr($sql, $offset + 3, 5) <= self::EXECUTED_COMMENT_VERSION && strspn($sql, '0123456789', $offset + 8, 1) === 0;
                    }
                    if (!$readable && $exec === null && preg_match(self::EMPTY_EXECUTABLE_PATTERN, $sql, $empty, 0, $offset) === 1) {
                        // one that holds nothing but whitespace runs nothing on any server, run or skipped: a space,
                        // like a plain comment
                        static::leadSkip($sql, $lead, $copy, $offset, $offset + strlen($empty[0]));
                        $offset += strlen($empty[0]);
                        continue;
                    }
                    if (!$readable) {
                        // its content is not known here: left in the lead from where it (or the executable comment it
                        // sits in) begins
                        $unread = true;
                        return $exec !== null ? static::leadFrom($sql, substr($lead, 0, $execLead), $execCopy, $exec) : static::leadFrom($sql, $lead, $copy, $offset);
                    }
                    $exec = $offset;
                    $execLead = strlen($lead);
                    $execCopy = $copy;
                    static::leadSkip($sql, $lead, $copy, $offset, $offset + $skip);
                    $offset += $skip;
                    continue;
                }
                $end = strpos($sql, '*/', $offset + 2);
                if ($end === false) {
                    // never closed: the server refuses the statement
                    if ($exec !== null) {
                        $unread = true;
                        return static::leadFrom($sql, substr($lead, 0, $execLead), $execCopy, $exec);
                    }
                    return $copy === null ? '' : $lead.substr($sql, $copy);
                }
                static::leadSkip($sql, $lead, $copy, $offset, $end + 2);
                $offset = $end + 2;
                continue;
            }
            if ($exec !== null && $char === '*' && $next === '/') {
                // the end of the executable comment
                static::leadSkip($sql, $lead, $copy, $offset, $offset + 2);
                $offset += 2;
                $exec = null;
                continue;
            }
            if ($char === '#' || ($char === '-' && $next === '-' && ($offset + 2 >= $length || preg_match('/[\x00-\x20\x7F]/', $sql[$offset + 2]) === 1))) {
                if ($exec !== null) {
                    // it runs to the end of the line, past the end of the executable comment it sits in
                    $unread = true;
                    return static::leadFrom($sql, substr($lead, 0, $execLead), $execCopy, $exec);
                }
                $newline = strpos($sql, "\n", $offset);
                if ($newline === false) {
                    // it runs to the end of the statement
                    return $copy === null ? '' : $lead.substr($sql, $copy, $offset - $copy);
                }
                static::leadSkip($sql, $lead, $copy, $offset, $newline + 1);
                $offset = $newline + 1;
                continue;
            }
            // the leading words end here
            $stopped = true;
            break;
        }
        if ($exec !== null && !$stopped) {
            // an executable comment never closed: the server refuses the statement
            $unread = true;
            return static::leadFrom($sql, substr($lead, 0, $execLead), $execCopy, $exec);
        }
        if ($copy === null) {
            return $offset < $length ? substr($sql, $offset) : '';
        }
        return $lead.substr($sql, $copy);
    }

    /**
    * leadingKeywordSql(): a comment (or an executable comment's marker) from $from to $to is skipped: before the first
    * word it is dropped, among the words it becomes one space.
    *
    * @param string $sql
    * @param string $lead
    * @param int|null $copy
    * @param int $from
    * @param int $to
    * @return void
    */
    protected static function leadSkip($sql, &$lead, &$copy, $from, $to)
    {
        if ($copy === null) {
            return;
        }
        $lead .= substr($sql, $copy, $from - $copy).' ';
        $copy = $to;
    }

    /**
    * leadingKeywordSql(): the lead built so far, then the statement as it is from $at on ($copy null: no word yet, the
    * lead begins at $at).
    *
    * @param string $sql
    * @param string $lead
    * @param int|null $copy
    * @param int $at
    * @return string
    */
    protected static function leadFrom($sql, $lead, $copy, $at)
    {
        return $copy === null ? substr($sql, $at) : $lead.substr($sql, $copy);
    }

    /**
    * Whether the statement may end an open transaction on the server: COMMIT and ROLLBACK (not ROLLBACK TO
    * SAVEPOINT), START TRANSACTION/BEGIN, and the statements MySQL commits implicitly before running (MySQL 8.0
    * "Statements That Cause an Implicit Commit": DDL other than CREATE/DROP TEMPORARY TABLE, account management, LOCK
    * TABLES, SET autocommit, table maintenance, FLUSH, RESET but RESET PERSIST, IMPORT TABLE, CLONE, the replication
    * statements...). UNLOCK TABLES is not counted (it commits only after LOCK TABLES, which already did; halt() sends it
    * after every failed statement), nor DROP/DEALLOCATE PREPARE or RESET PERSIST (they commit nothing), nor a CALL or
    * EXECUTE (what the routine or prepared statement runs is not seen here). Matched on the leading keywords of $lead,
    * as leadingKeywordSql() reads them (a plain comment among them is a space there, an executable comment whose
    * content runs everywhere is unwrapped), so it costs nothing on a long statement; nothing past those words is read,
    * so a string literal later on changes nothing. A doubtful form counts as an end: an executable comment
    * leadingKeywordSql() could not read before the first keyword (its content is not known: DDL, SET autocommit, a
    * COMMIT...), or right after the first word of a lead that takes two words (LOCK /*!99999 TABLES *\/ t WRITE: on a
    * server that new it is a LOCK TABLES). This over-approximation is what another holder's count and a failure are
    * judged by; whether one that WORKED ended this instance's own transaction is told strictly
    * (implicitCommitOutcome()). A SET counts when it holds a comment or may turn the session's autocommit on
    * (setAutocommitOutcome() is not 'none': SET autocommit=0, a GLOBAL one or a user variable @autocommit_count commit
    * nothing), a CREATE TEMPORARY TABLE only for START and TRANSACTION next to each other in it
    * (startTransactionAdjacent(), or both with an executable comment). The count is made before the statement is sent:
    * one the server never ran (NOT_RUN_ERRORS) is taken back (uncountEnd()), and so is a CREATE TEMPORARY TABLE that
    * worked (workedEndsNothing(): the server refuses one that holds START TRANSACTION). Any other miscount (SET
    * autocommit=1 with autocommit already on, a DDL refused before its implicit commit) makes the holder of the open
    * transaction give it up although it is still open on the server: that holder is told it is lost (2006), and rolls
    * it back with a quiet ROLLBACK when no other handle has a transaction open there (releaseEndedElsewhere()), so its
    * writes are not left for the next COMMIT or START on the connection to commit.
    *
    * @param string $lead the statement from its first keyword on (leadingKeywordSql())
    * @param string|null $sql the whole statement (not needed any more: leadingKeywordSql() keeps an executable comment
    *                         before the first keyword in $lead when it cannot read it)
    * @return bool
    */
    protected static function endsTransaction($lead, $sql = null)
    {
        if (isset($lead[0]) && $lead[0] === '/' && (strncmp($lead, '/*!', 3) === 0 || strncmp($lead, '/*M!', 4) === 0)) {
            // an executable comment before the first keyword leadingKeywordSql() could not read: its content is not known
            return true;
        }
        $matched = preg_match(self::ENDS_PATTERN, $lead, $match);
        if ($matched === false) {
            // the pattern could not run to its end (a PCRE limit): an end, on the safe side
            return true;
        }
        if ($matched !== 1) {
            // (a look below that could not run, a PCRE limit: an end, on the safe side)
            if (preg_match('~^set\b~i', $lead) !== 0 && stripos($lead, 'autocommit') !== false) {
                // a SET that names autocommit: an end when it holds a comment, which may hide an assignment
                // (SET autocommit /* c *\/ = 1), or when an assignment in it may turn the session's autocommit on
                // (setAutocommitOutcome(), as implicitCommitOutcome() reads it once it worked: a ';' or any length of
                // text before it included, one in a string literal too). One that commits nothing is none: a name that
                // only holds the word (SET @autocommit_count = 1), autocommit set off, a GLOBAL or PERSIST one
                if (strpos($lead, '/*') !== false || strpos($lead, '#') !== false || strpos($lead, '--') !== false) {
                    return true;
                }
                return static::setAutocommitOutcome($lead) !== 'none';
            }
            // an executable comment it could not read right after the first word of a lead that takes two words
            return preg_match('~^(?:start|lock|check|cache|load|import|stop|change|set)\b~i', $lead) !== 0 && static::executableAmongLeadingWords($lead);
        }
        $word = strtolower(substr($match[0], 0, 4));
        if ($word === 'roll') {
            // ROLLBACK TO [SAVEPOINT] stays inside the transaction (a comment between its words must not hide that)
            return preg_match('/^rollback\s+(?:work\s+)?to\b/i', static::withoutComments($lead)) !== 1;
        }
        if ($word === 'crea' || $word === 'drop' || $word === 'rese') {
            // CREATE/DROP TEMPORARY TABLE, DROP PREPARE and RESET PERSIST commit nothing; an executable comment between
            // the words that leadingKeywordSql() could not read is doubtful: an end
            if (preg_match(self::NO_END_PATTERN, $lead) !== 1) {
                return true;
            }
            // but CREATE TEMPORARY TABLE ... START TRANSACTION commits the open transaction (trans_begin(), sql_parse.cc)
            // before the server refuses it (temporary tables take no START TRANSACTION, sql_table.cc): the two words next
            // to each other (whitespace or a plain comment between them, slash-star-m-bang included; in a literal too, on
            // the safe side), or both
            // anywhere in one with an executable comment (which may join them), as implicitCommitOutcome()'s 'chain'. One
            // that worked held none: its end is taken back then (takeBackEnd(), workedEndsNothing())
            return $word === 'crea' && stripos($lead, 'start') !== false && stripos($lead, 'transaction') !== false
                && (strpos($lead, '/*!') !== false || strpos($lead, '/*M!') !== false || static::startTransactionAdjacent($lead));
        }
        return true;
    }

    /**
    * Whether a statement endsTransaction() counted as an end ended nothing when it worked: a CREATE TEMPORARY TABLE,
    * counted only for what may be a START TRANSACTION in it, which the server refuses for a temporary table (after its
    * trans_begin() committed what was open), so one that worked held none. Its end is taken back then (takeBackEnd()):
    * counted, it would make another holder of the shared link give up a transaction that is still open, and roll it back.
    *
    * @param string $lead the statement from its first keyword on (leadingKeywordSql())
    * @return bool
    */
    protected static function workedEndsNothing($lead)
    {
        // (a pattern that could not run, a PCRE limit: the end is kept)
        return preg_match('~^create'.self::WORD_GAP.'temporary\b~is', $lead) === 1;
    }

    /**
    * After a statement that failed outside a transaction of this instance's own (query(), execute()): when the server
    * ran it (its errno is not one of NOT_RUN_ERRORS) past the point where it begins a transaction for it (not one of
    * NO_DDL_TRANSACTION_ERRORS: refused as read-only before that) and it may have left the session inside that
    * transaction (mayLeaveDdlTransaction(): a refused CREATE TEMPORARY TABLE or ALTER TABLE ... START TRANSACTION),
    * that transaction is ended with a quiet ROLLBACK. Left open, the caller's autocommit writes after it would wait in
    * it, each answered true, and be lost when the connection closes. Nothing of this instance's is open there, and
    * whatever another handle had open on the link was committed by the server's trans_begin() and is counted as ended
    * by this statement (endsTransaction()); a holder whose transaction still looks live there is left alone, as in
    * releaseEndedElsewhere(). The caller's Errno/Error are those of the failed statement.
    *
    * @param int $errno the failed statement's
    * @param string $lead the statement from its first keyword on (leadingKeywordSql())
    * @return void
    */
    protected function endStrayDdlTransaction($errno, $lead)
    {
        if (in_array((int) $errno, self::NOT_RUN_ERRORS, true) || in_array((int) $errno, self::NO_DDL_TRANSACTION_ERRORS, true)) {
            // it never ran, or the server refused it before it began a transaction for it (read-only): nothing to end,
            // and a ROLLBACK would end whatever else is open there (an implicit one of SET autocommit=0, not tracked)
            return;
        }
        if (static::mayLeaveDdlTransaction($lead) && !$this->anotherLiveHolderInTransaction()) {
            $this->sendQuietly('ROLLBACK');
        }
    }

    /**
    * Whether a statement that failed may have left the session inside a transaction of its own: a CREATE or ALTER with
    * START and TRANSACTION next to each other in it (a plain comment between them, slash-star-m-bang included: WORD_GAP;
    * or both with an executable comment), as endsTransaction() counts a CREATE TEMPORARY TABLE, or one held by an
    * executable comment before its first keyword that is not read and holds START, TRANSACTION and CREATE or ALTER
    * anywhere (endsTransaction() counts any such statement). The server begins that transaction (trans_begin(),
    * sql_parse.cc) before it runs the statement, and some refusals end the statement without rolling it back (a
    * temporary table, ALTER TABLE, an engine without atomic DDL: 3979 ER_NOT_ALLOWED_WITH_START_TRANSACTION,
    * sql_table.cc).
    *
    * @param string $lead the statement from its first keyword on (leadingKeywordSql())
    * @return bool
    */
    protected static function mayLeaveDdlTransaction($lead)
    {
        if (stripos($lead, 'start') === false || stripos($lead, 'transaction') === false) {
            return false;
        }
        if (strncmp($lead, '/*!', 3) === 0 || strncmp($lead, '/*M!', 4) === 0) {
            // held by an executable comment before its first keyword that is not read (/*!80000 CREATE TEMPORARY TABLE
            // ... START TRANSACTION *\/): what it runs is not known, so one that may be a CREATE or ALTER may be that one
            return stripos($lead, 'create') !== false || stripos($lead, 'alter') !== false;
        }
        return preg_match('~^(?:create|alter)\b~i', $lead) === 1
            && (strpos($lead, '/*!') !== false || strpos($lead, '/*M!') !== false || static::startTransactionAdjacent($lead));
    }

    /**
    * Whether an executable comment (slash-star-bang, slash-star-M-bang) that leadingKeywordSql() could not read sits
    * before the first keyword or among the leading words (after nothing but words, whitespace and plain comments):
    * its content is not known (a version newer than EXECUTED_COMMENT_VERSION, a MariaDB one...), so the words read
    * here may not be what the server ran. One it could read is unwrapped there, and is not in the lead any more.
    *
    * @param string $lead the statement from its first keyword on (leadingKeywordSql())
    * @param string|null $sql the whole statement (not needed any more)
    * @return bool
    */
    protected static function executableAmongLeadingWords($lead, $sql = null)
    {
        if (strpos($lead, '/*!') === false && strpos($lead, '/*M!') === false) {
            return false;
        }
        // (a pattern that could not run, a PCRE limit: one, on the safe side)
        return preg_match(self::EXECUTABLE_AMONG_PATTERN, $lead) !== 0;
    }

    /**
    * unreadLeadMay(): the states of the COMMIT/ROLLBACK ... AND CHAIN grammar a reading can be in (bits of a set, one
    * set over every reading): COMMIT [WORK] AND [NO] CHAIN [[NO] RELEASE] (sql_yacc.yy commit/rollback: opt_work
    * opt_chain opt_release, AND CHAIN RELEASE refused; MariaDB's the same). CHAIN_START: no word yet (the next one may be
    * the first keyword); CHAIN_END: COMMIT or ROLLBACK; CHAIN_WORK: then WORK; CHAIN_AND: then AND; CHAIN_CHAIN: AND
    * CHAIN, which opens the next transaction; CHAIN_CHAIN_NO: AND CHAIN NO; CHAIN_RELEASE: AND CHAIN NO RELEASE, which
    * opens it too; CHAIN_ENDED: the leading words ended at a character in a reading that had opened it (as before, a
    * character keeps an opener). A reading whose word the grammar does not take there (AND NO: what follows opens
    * nothing; any word after CHAIN_RELEASE or CHAIN_ENDED) opens nothing: the set loses it.
    */
    const CHAIN_START = 1;
    const CHAIN_END = 2;
    const CHAIN_WORK = 4;
    const CHAIN_AND = 8;
    const CHAIN_CHAIN = 16;
    const CHAIN_CHAIN_NO = 32;
    const CHAIN_RELEASE = 64;
    const CHAIN_ENDED = 128;

    /**
    * unreadLeadMay(): the states in which a reading has opened a transaction with AND CHAIN.
    */
    const CHAIN_OPENS = self::CHAIN_CHAIN | self::CHAIN_RELEASE | self::CHAIN_ENDED;

    /**
    * What a statement may be whose lead leadingKeywordSql() left at an executable comment it could not read, before the
    * first keyword or among the leading words ($unread): the server runs that comment's content or skips it (a version
    * newer than its own, slash-star-M-bang on MySQL), so the statement it runs is one of several readings. Every
    * reading holds the words outside such comments (and the content of one every server runs, EXECUTED_COMMENT_VERSION
    * and below); the words of each such comment are in it or not, all of them together. Over-approximated, each comment
    * run or skipped on its own:
    * a word with no word every reading holds before it may be the first keyword, and
    *  - 'commit': COMMIT may be;
    *  - 'rollback': ROLLBACK may be;
    *  - 'open': BEGIN may be, or START with TRANSACTION after it and no word every reading holds between them, and no
    *    word every reading holds comes after that opener that no form of it takes there (BEGIN takes WORK; START
    *    TRANSACTION READ, ONLY, WRITE, WITH, CONSISTENT, SNAPSHOT, and Percona's FROM SESSION and its number): every
    *    reading holding the opener holds that word after it, and the server refuses each of them, so the statement
    *    opened nothing once it worked (/*!99999 BEGIN *\/ SELECT 1 is BEGIN SELECT 1 where the comment runs, a SELECT
    *    where it is skipped); or some reading is COMMIT or ROLLBACK [WORK] AND CHAIN [NO RELEASE], read word by word
    *    on the set of the grammar's states every reading can be in (CHAIN_START ... CHAIN_ENDED: the words of a comment
    *    move the readings that run it, those that skip it stay): AND NO CHAIN, a NO before the AND, AND CHAIN RELEASE,
    *    a second chain clause, a CHAIN after RELEASE or after TO SAVEPOINT open nothing in any reading (COMMIT
    *    /*!80000 AND NO *\/ CHAIN is COMMIT AND NO CHAIN where the comment runs, COMMIT CHAIN, refused, where it is
    *    skipped). Only words are read so: a character that ends the leading words after an opener keeps it.
    * Only the leading words are read: the first character outside a comment that is neither a word byte nor whitespace
    * ends them (a plain comment among them is a space, as in leadingKeywordSql()), and so does one inside an executable
    * comment every server runs. One inside a comment that is not read ends them only in the readings that run it: the
    * rest of that comment is skipped (no word after it there can be a leading one), and the words after the comment are
    * read as in the readings that skip it; a quote inside one is such a character (no keyword follows a literal, which
    * may hold a star-slash where the comment runs). Where it is skipped, the comment ends as MySQL's lexer ends it
    * (versionCommentEnd()): a slash-star-bang one at the first star-slash that does not close a comment nested in it
    * (one level, consume_comment()), a slash-star-M-bang one (a plain comment on MySQL: no nesting) at its first
    * star-slash. A plain comment inside a slash-star-bang one is a space too: it ends at its first star-slash whether
    * the comment it sits in runs or is skipped. One that ends the leading words at such a character and is never closed
    * where it is skipped (a comment nested in it never closed included) leaves only the readings that run it. What
    * cannot be read that way (an executable comment inside one, a plain comment inside a slash-star-M-bang one, or a
    * slash-star before the first star-slash of one at such a character: where the server ends them is not read here;
    * "--" and "#" may run past its end; one never closed at its end) is told by the words alone, wherever they are in
    * the statement (a keyword the server runs is spelt out in it): COMMIT, ROLLBACK, and BEGIN, START with TRANSACTION,
    * or CHAIN. A version's digits are skipped, a sixth one too (MariaDB's: dropping a word only adds readings).
    *
    * @param string $lead the statement from its first keyword or that comment on (leadingKeywordSql())
    * @return array{commit: bool, rollback: bool, open: bool}
    */
    protected static function unreadLeadMay($lead)
    {
        // what is not read: told by the words anywhere in it
        $any = [
            'commit' => stripos($lead, 'commit') !== false,
            'rollback' => stripos($lead, 'rollback') !== false,
            'open' => stripos($lead, 'begin') !== false || stripos($lead, 'chain') !== false || (stripos($lead, 'start') !== false && stripos($lead, 'transaction') !== false),
        ];
        $may = ['commit' => false, 'rollback' => false, 'open' => false];
        // the openers some reading may hold so far (BEGIN, START TRANSACTION; AND CHAIN is told by $chain)
        $opener = ['begin' => false, 'start' => false];
        // the states of the COMMIT ... AND CHAIN grammar some reading is in (CHAIN_*), and those of the readings that skip
        // the executable comment not read that is open (null: none is open)
        $chain = self::CHAIN_START;
        $chainSkip = null;
        $length = strlen($lead);
        $offset = 0;
        // inside an executable comment: whether every server runs its content (null: outside one)
        $inside = null;
        // inside one that is not read: whether it is a slash-star-M-bang one (a plain comment on MySQL)
        $mariadb = false;
        // whether a word every reading holds came already: no word after it can be the first keyword
        $held = false;
        // a START that may be the first keyword, and no word every reading holds after it yet
        $start = false;
        while ($offset < $length) {
            $offset += strspn($lead, " \t\n\r\f\x0B", $offset);
            if ($offset >= $length) {
                break;
            }
            $words = strspn($lead, self::WORD_BYTES, $offset);
            if ($words > 0) {
                $word = strtolower(substr($lead, $offset, $words));
                $offset += $words;
                $first = false;
                // the opener this word makes ('': none)
                $opened = '';
                if (!$held) {
                    if ($word === 'commit' || $word === 'rollback') {
                        $may[$word] = true;
                    } elseif ($word === 'begin') {
                        $opener['begin'] = true;
                        $opened = 'begin';
                    } elseif ($word === 'start') {
                        $start = true;
                        $first = true;
                    }
                }
                if ($start && !$first) {
                    if ($word === 'transaction') {
                        $opener['start'] = true;
                        $opened = 'start';
                    }
                    if ($inside !== false) {
                        // a word every reading holds, and not TRANSACTION: no START TRANSACTION from that START
                        $start = false;
                    }
                }
                // the readings that hold this word: those that run the comment it is in, or all of them
                $chain = static::chainStep($chain, $word);
                if ($inside !== false) {
                    $held = true;
                    // a word every reading holds that no form of an opener before it takes: each reading with that
                    // opener is refused by the server (BEGIN SELECT 1), so it is none
                    if ($opened !== 'begin' && $word !== 'work') {
                        $opener['begin'] = false;
                    }
                    if ($opened !== 'start' && !in_array($word, ['read', 'only', 'write', 'with', 'consistent', 'snapshot', 'from', 'session'], true) && !ctype_digit($word)) {
                        $opener['start'] = false;
                    }
                }
                continue;
            }
            $char = $lead[$offset];
            $next = $offset + 1 < $length ? $lead[$offset + 1] : '';
            if ($char === '/' && $next === '*') {
                $third = $offset + 2 < $length ? $lead[$offset + 2] : '';
                $executable = $third === '!' || ($third === 'M' && $offset + 3 < $length && $lead[$offset + 3] === '!');
                if ($inside !== null) {
                    if ($executable || ($inside === false && $mariadb)) {
                        // an executable comment inside one, or a comment inside a slash-star-M-bang one (MySQL ends that
                        // plain comment at the inner one's end): where the server ends them is not read here
                        return $any;
                    }
                    // a plain comment inside a slash-star-bang one: a space whether that one runs (MySQL's lexer reads it
                    // as a comment there) or is skipped (consume_comment() takes it as the one level of nesting), and it
                    // ends at its first star-slash either way
                    $end = strpos($lead, '*/', $offset + 2);
                    if ($end === false) {
                        return $any;
                    }
                    $offset = $end + 2;
                    continue;
                }
                if ($executable) {
                    $marker = $third === '!' ? 3 : 4;
                    $digits = strspn($lead, '0123456789', $offset + $marker);
                    // run by every server: no version, or one up to EXECUTED_COMMENT_VERSION (as leadingKeywordSql())
                    $inside = $third === '!' && ($digits === 0 || ($digits === 5 && (int) substr($lead, $offset + 3, 5) <= self::EXECUTED_COMMENT_VERSION));
                    $mariadb = $third === 'M';
                    // the readings that skip it keep the states they are in now
                    $chainSkip = $inside ? null : $chain;
                    $offset += $marker + $digits;
                    continue;
                }
                $end = strpos($lead, '*/', $offset + 2);
                if ($end === false) {
                    return $any;
                }
                $offset = $end + 2;
                continue;
            }
            if ($inside !== null && $char === '*' && $next === '/') {
                if ($chainSkip !== null) {
                    // the readings that ran it and those that skipped it
                    $chain |= $chainSkip;
                    $chainSkip = null;
                }
                $inside = null;
                $offset += 2;
                continue;
            }
            if ($inside !== null) {
                if ($char === '#' || ($char === '-' && $next === '-')) {
                    // a comment that may run past its end ("--" without a space is two minus signs, read the same way
                    // here)
                    return $any;
                }
                // (a quote is read as any other character here: no keyword comes after a literal or a quoted name, so
                // the leading words end at it where the comment runs, even when the literal holds a star-slash)
                if ($inside) {
                    // run by every server: the leading words end here
                    $may['open'] = $opener['begin'] || $opener['start'] || ($chain & self::CHAIN_OPENS) !== 0;
                    return $may;
                }
                // ends the leading words where the server runs it: the rest of it is no leading word. Where it is
                // skipped, it ends where MySQL's lexer ends it (a comment nested in it, one level, may hold a star-slash)
                $end = $mariadb ? static::plainCommentEnd($lead, $offset, $length) : static::versionCommentEnd($lead, $offset, $length);
                if ($end === null) {
                    return $any;
                }
                $chain = (($chain & self::CHAIN_OPENS) !== 0 ? self::CHAIN_ENDED : 0) | ($end === false ? 0 : (int) $chainSkip);
                if ($end === false) {
                    // never closed where it is skipped (the server refuses the statement there): only the readings that
                    // run it are left, and their leading words end here
                    $may['open'] = $opener['begin'] || $opener['start'] || ($chain & self::CHAIN_OPENS) !== 0;
                    return $may;
                }
                $chainSkip = null;
                $inside = null;
                $offset = $end;
                continue;
            }
            if ($char === '#' || ($char === '-' && $next === '-' && ($offset + 2 >= $length || preg_match('/[\x00-\x20\x7F]/', $lead[$offset + 2]) === 1))) {
                $newline = strpos($lead, "\n", $offset);
                if ($newline === false) {
                    break;
                }
                $offset = $newline + 1;
                continue;
            }
            // the leading words end here
            $may['open'] = $opener['begin'] || $opener['start'] || ($chain & self::CHAIN_OPENS) !== 0;
            return $may;
        }
        if ($inside !== null) {
            // (an executable comment still open at the end was never closed: the server refuses the statement)
            return $any;
        }
        $may['open'] = $opener['begin'] || $opener['start'] || ($chain & self::CHAIN_OPENS) !== 0;
        return $may;
    }

    /**
    * unreadLeadMay(): the states of the COMMIT ... AND CHAIN grammar (CHAIN_*) the readings in $states are in after
    * $word (lowercase); a reading whose next word the grammar does not take there is dropped (it opens nothing with
    * AND CHAIN, refused or not).
    *
    * @param int $states
    * @param string $word
    * @return int
    */
    protected static function chainStep($states, $word)
    {
        $next = 0;
        if (($states & self::CHAIN_START) !== 0 && ($word === 'commit' || $word === 'rollback')) {
            $next |= self::CHAIN_END;
        }
        if (($states & self::CHAIN_END) !== 0 && $word === 'work') {
            $next |= self::CHAIN_WORK;
        }
        if (($states & (self::CHAIN_END | self::CHAIN_WORK)) !== 0 && $word === 'and') {
            $next |= self::CHAIN_AND;
        }
        // (AND NO CHAIN opens nothing: a reading at AND NO is dropped)
        if (($states & self::CHAIN_AND) !== 0 && $word === 'chain') {
            $next |= self::CHAIN_CHAIN;
        }
        if (($states & self::CHAIN_CHAIN) !== 0 && $word === 'no') {
            $next |= self::CHAIN_CHAIN_NO;
        }
        if (($states & self::CHAIN_CHAIN_NO) !== 0 && $word === 'release') {
            $next |= self::CHAIN_RELEASE;
        }
        return $next;
    }

    /**
    * Where a version comment (slash-star-bang and digits) that the server skips ends, read from $at inside it: after the
    * first star-slash that does not close a comment nested in it. MySQL's lexer (sql_lex.cc consume_comment(), called
    * with one nesting level for a version comment it skips; MariaDB's the same) takes a slash-star inside it for a
    * nested comment, which ends at its own first star-slash (nothing nests in it).
    *
    * @param string $sql
    * @param int $at
    * @param int $length
    * @return int|false the offset after the star-slash; false: never closed, or a comment nested in it never closed (the
    *                   server refuses the statement where it skips the comment)
    */
    protected static function versionCommentEnd($sql, $at, $length)
    {
        while ($at < $length) {
            $close = strpos($sql, '*/', $at);
            if ($close === false) {
                return false;
            }
            $open = strpos($sql, '/*', $at);
            if ($open === false || $open > $close) {
                return $close + 2;
            }
            // a comment nested in it, to its own first star-slash after its slash-star (one whose star is that
            // star-slash's, "/*/", is one too: the lexer takes the star with the slash before it)
            $inner = strpos($sql, '*/', $open + 2);
            if ($inner === false) {
                return false;
            }
            $at = $inner + 2;
        }
        return false;
    }

    /**
    * Where a slash-star-M-bang comment the server skips ends, read from $at inside it: at its first star-slash, when no
    * slash-star comes before that (MySQL reads it as a plain comment, which ends at its first star-slash with no
    * nesting; MariaDB nests one level in one it skips: where a slash-star comes first, the two may differ).
    *
    * @param string $sql
    * @param int $at
    * @param int $length
    * @return int|false|null the offset after the star-slash; false: never closed (refused where it is skipped); null:
    *                        not known (a slash-star before its first star-slash)
    */
    protected static function plainCommentEnd($sql, $at, $length)
    {
        $close = strpos($sql, '*/', $at);
        if ($close === false) {
            return false;
        }
        $open = strpos($sql, '/*', $at);
        if ($open !== false && $open < $close) {
            return null;
        }
        return $close + 2;
    }

    /**
    * What a statement endsTransaction() counts as an implicit commit (START/BEGIN, COMMIT and ROLLBACK aside) did to
    * the transaction of this instance's own that it ran in, once it WORKED. Strict, unlike endsTransaction(): matched
    * on the leading words as leadingKeywordSql() reads them, nothing past them read but where said below.
    *  - 'end': MySQL committed the open transaction before it ran, and it is over. Only the forms MySQL 8.0 lists as
    *    committing and the server source flags so (CF_AUTO_COMMIT_TRANS): ALTER, CREATE, DROP (any object: database,
    *    table, index, view, trigger, event, routine, server, tablespace, user, role, resource group, spatial reference
    *    system), RENAME TABLE/USER, TRUNCATE, GRANT, REVOKE, INSTALL/UNINSTALL, LOCK TABLES, ANALYZE/CHECK/OPTIMIZE/REPAIR
    *    TABLE, FLUSH, RESET (but PERSIST), CACHE INDEX, LOAD INDEX, IMPORT TABLE, CLONE, START/STOP REPLICA (SLAVE),
    *    CHANGE MASTER/REPLICATION SOURCE/REPLICATION FILTER, SET PASSWORD, SET DEFAULT ROLE; also when they came out of an
    *    executable comment leadingKeywordSql() unwrapped (/*!40000 ALTER TABLE t DISABLE KEYS *\/); an executable
    *    comment past the leading words (mysqldump's /*!50100 PARTITION BY ... *\/) changes nothing.
    *  - 'chain': a CREATE TABLE that may hold START TRANSACTION: the two words next to each other (whitespace or a
    *    plain comment between them, a literal holding them too), or both anywhere in one with an executable comment.
    * CREATE TABLE ... START TRANSACTION (atomic DDL) skips the implicit commit    (sql_parse.cc
    * stmt_causes_implicit_commit()), but the server begins a transaction of its own for it with    trans_begin()
    * (sql_parse.cc), which commits the open one first (transaction.cc), before the CREATE runs; after    it only COMMIT
    * and ROLLBACK run (any other statement fails, ER_STATEMENT_NOT_ALLOWED_AFTER_START_TRANSACTION).    The writes
    * before it are committed, and the session is in a transaction again: the transaction goes on as far as    the
    * library is concerned, its end not known to the caller's errno (queryInTransaction(): keepUnknownEnd(), its
    * $chain case). Taken for one where they are only in a literal or a comment, it is a CREATE that ended the
    * transaction and what runs after it autocommits, as in the 'doubt' case below.
    *  - 'none': it commits nothing, the transaction goes on: a SET that assigns no session autocommit, or only 0, OFF or
    *    FALSE to it (setAutocommitOutcome(): disabling autocommit commits nothing, and MySQL's pre_autocommit() leaves
    *    a GLOBAL, PERSIST or PERSIST_ONLY assignment alone); a CREATE TEMPORARY TABLE, counted only for what may be a
    *    START TRANSACTION in it (endsTransaction()): the server refuses START TRANSACTION for a temporary table (after
    *    its trans_begin() committed), so one that worked held none. DROP TEMPORARY TABLE, DROP PREPARE, RESET PERSIST,
    *    DEALLOCATE PREPARE and any other CREATE TEMPORARY TABLE never get here: endsTransaction() does not count them.
    *  - 'doubt': it either committed the open transaction or left it open, and which cannot be told here; it rolled
    *    nothing back. The first keyword is known: SET of the session's autocommit to anything but 0/OFF/FALSE (it
    *    commits only when the session's autocommit was off, and that is not tracked), or a SET of autocommit with a
    *    comment in it; STOP/START GROUP_REPLICATION; an executable comment leadingKeywordSql() could not read after the
    *    first keyword (CREATE /*M! TEMPORARY *\/ TABLE: MariaDB creates a temporary table, MySQL a table); anything else
    *    not listed here, and anything a pattern could not be run on (a PCRE limit). The transaction goes on with its end
    *    not known (queryInTransaction(): keepUnknownEnd()).
    *  - 'unknown': an executable comment leadingKeywordSql() could not read before the first keyword: the statement it
    *    holds is not known at all (a ROLLBACK may have rolled the transaction back), so the transaction is given up
    *    (giveUpUnknownEnd()).
    *
    * @param string $lead the statement from its first keyword on (leadingKeywordSql())
    * @param string|null $sql the whole statement (not needed any more)
    * @return string 'end', 'chain', 'none', 'doubt' or 'unknown'
    */
    protected static function implicitCommitOutcome($lead, $sql = null)
    {
        $gap = self::WORD_GAP;
        if (strncmp($lead, '/*!', 3) === 0 || strncmp($lead, '/*M!', 4) === 0) {
            return 'unknown';
        }
        if (static::executableAmongLeadingWords($lead)) {
            return 'doubt';
        }
        if (preg_match('~^set\b~i', $lead) === 1) {
            $ends = preg_match('~^set'.$gap.'(?:password|default'.$gap.'role)\b~is', $lead);
            if ($ends !== 0) {
                // (a pattern that could not run: doubt, never none)
                return $ends === 1 ? 'end' : 'doubt';
            }
            if (strpos($lead, '/*') !== false || strpos($lead, '#') !== false || strpos($lead, '--') !== false) {
                // a comment (or what may be one) past the leading words: an executable one runs its content, a plain one
                // may hide an assignment from the look below
                return 'doubt';
            }
            return static::setAutocommitOutcome($lead);
        }
        $temporary = preg_match('~^create'.$gap.'temporary\b~is', $lead);
        if ($temporary !== 0) {
            // counted only for what may be a START TRANSACTION in it (endsTransaction()): the server refuses that for a
            // temporary table, so one that worked held none, and committed nothing (a pattern that could not run: doubt)
            return $temporary === 1 ? 'none' : 'doubt';
        }
        if (preg_match(self::END_PATTERN, $lead) !== 1) {
            return 'doubt';
        }
        // CREATE TABLE ... START TRANSACTION commits too (trans_begin(), sql_parse.cc, before the CREATE runs), and then
        // keeps a transaction of its own open: the two words next to each other (whitespace or a plain comment between
        // them; in a literal too, on the safe side), or anywhere in one with an executable comment (whose content the
        // server runs or skips, which may join them)
        if (stripos($lead, 'start') !== false && stripos($lead, 'transaction') !== false && preg_match('~^create'.$gap.'table\b~is', $lead) !== 0
            && (strpos($lead, '/*!') !== false || strpos($lead, '/*M!') !== false || static::startTransactionAdjacent($lead))) {
            return 'chain';
        }
        return 'end';
    }

    /**
    * implicitCommitOutcome() of a SET that endsTransaction() counted (it names autocommit) and that holds no comment:
    * 'none' when every assignment to the session's autocommit sets it to 0, OFF or FALSE (or there is none), else
    * 'doubt'. The assignments are those MySQL makes (sql_yacc.yy option_value, parse_tree_nodes.cc): a plain name takes
    * the scope keyword in force, the latest GLOBAL, PERSIST, PERSIST_ONLY, SESSION or LOCAL before it in the statement
    * (PT_option_value_type sets lex->option_type, PT_set_variable reads it; PT_set starts it at SESSION); @@name and
    * @@SESSION.name (@@LOCAL.name) are the session's whatever keyword is in force (PT_set_system_variable takes its own
    * scope), @@GLOBAL.name (PERSIST, PERSIST_ONLY) is not, @name is a user variable. pre_autocommit() (sys_vars.cc)
    * commits only for an assignment that is neither GLOBAL nor PERSIST nor PERSIST_ONLY and turns autocommit on.
    * Every target the plain look below finds ($assigned: any autocommit followed by = or :=, not after a word, $, ., or
    * @, nor @@GLOBAL. ...) must be one the assignments were read with (sessionAutocommitValues()), or each of them is
    * taken for the session's (as before: none only when all set it off).
    *
    * @param string $lead
    * @return string 'none' or 'doubt'
    */
    protected static function setAutocommitOutcome($lead)
    {
        // the assignments to the session's autocommit (a user variable @autocommit, @@GLOBAL.autocommit and a read of
        // @@autocommit are not), and those that set it off
        $assigned = self::AUTOCOMMIT_TARGET;
        $targets = preg_match_all('~'.$assigned.'~i', $lead);
        if ($targets === false) {
            // the pattern could not run (a PCRE limit): never none on that
            return 'doubt';
        }
        if ($targets === 0) {
            return 'none';
        }
        $values = static::sessionAutocommitValues($lead, $targets);
        if ($values === null) {
            // not read with their scopes: every target is taken for the session's
            $off = preg_match_all('~'.$assigned.'\s*([\'"]?)(?:0|off|false)\1\s*(?=,|;|$)~i', rtrim($lead));
            return $off === $targets ? 'none' : 'doubt';
        }
        foreach ($values as $value) {
            // (every quote in it closes: sessionAutocommitValues() reads them, so '0 or 0' cannot be a value here)
            if (preg_match('~^\'?(?:0|off|false)\'?$~i', $value) !== 1) {
                return 'doubt';
            }
        }
        return 'none';
    }

    /**
    * The values a SET assigns to the session's autocommit, its assignments read with the scope MySQL gives each (see
    * setAutocommitOutcome()); null when it cannot be read that way for certain: a double quote (an identifier under
    * ANSI_QUOTES, a string otherwise) or a backslash (an escape, or not, under NO_BACKSLASH_ESCAPES), quotes or
    * parentheses that do not close, an assignment naming autocommit that is not read as one, or a count of targets that
    * is not $targets.
    *
    * @param string $lead a SET without comments
    * @param int $targets how many autocommit targets the plain look found
    * @return string[]|null
    */
    protected static function sessionAutocommitValues($lead, $targets)
    {
        if (strpbrk($lead, '"\\') !== false || preg_match('~^set[ \t\n\r\f\x0B]++~i', $lead, $head) !== 1) {
            return null;
        }
        // the assignments: the commas outside quotes and parentheses
        $items = [];
        $length = strlen($lead);
        $start = strlen($head[0]);
        $depth = 0;
        for ($i = $start; $i < $length; $i++) {
            $char = $lead[$i];
            if ($char === "'" || $char === '`') {
                // to its closing quote (a doubled one is part of it)
                $close = $i;
                do {
                    $close = strpos($lead, $char, $close + 1);
                    if ($close === false) {
                        return null;
                    }
                    if ($close + 1 < $length && $lead[$close + 1] === $char) {
                        $close++;
                        continue;
                    }
                    break;
                } while (true);
                $i = $close;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($char === ',' && $depth === 0) {
                $items[] = substr($lead, $start, $i - $start);
                $start = $i + 1;
            }
        }
        if ($depth !== 0) {
            return null;
        }
        $items[] = substr($lead, $start);
        $session = true;
        $found = 0;
        $values = [];
        foreach ($items as $item) {
            $item = rtrim(trim($item, " \t\n\r\f\x0B"), " \t\n\r\f\x0B;");
            if (preg_match('~^(global|persist_only|persist|session|local)[ \t\n\r\f\x0B]++~i', $item, $keyword) === 1) {
                // the scope of this assignment and of the plain names after it
                $session = in_array(strtolower($keyword[1]), ['session', 'local'], true);
                $item = substr($item, strlen($keyword[0]));
            }
            if (preg_match('~^(@@(?:`?(global|persist_only|persist|session|local)`?\.)?)?(`?)autocommit\3[ \t\n\r\f\x0B]*+:?=[ \t\n\r\f\x0B]*+(.*)$~is', $item, $target) === 1) {
                $scope = strtolower($target[2]);
                if ($target[1] === '') {
                    // a plain name: the scope in force
                    $itsSession = $session;
                    $found++;
                } elseif ($scope === '' || $scope === 'session' || $scope === 'local') {
                    // @@autocommit, @@SESSION.autocommit: the session's
                    $itsSession = true;
                    $found++;
                } else {
                    // @@GLOBAL.autocommit and the like: not the session's (nor counted by the plain look)
                    $itsSession = false;
                }
                if ($itsSession) {
                    $values[] = $target[4];
                }
            } elseif (preg_match('~^(?:@@(?:`?\w++`?\.)?)?`?autocommit\b~i', $item) === 1) {
                // a target named autocommit that is not read above
                return null;
            }
        }
        return $found === $targets ? $values : null;
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
        if (isset($lead[0]) && $lead[0] === '/' && (strncmp($lead, '/*!', 3) === 0 || strncmp($lead, '/*M!', 4) === 0)) {
            // an executable comment before the first keyword leadingKeywordSql() could not read: what it ran is not known
            return true;
        }
        // (each look below: a pattern that could not run, a PCRE limit, reads as changed, on the safe side)
        $set = preg_match('/^set\b/i', $lead);
        if ($set !== 0) {
            return $set === false || preg_match('/^set\s+(?:(?:session|global|local)\s+)?transaction\b/i', $lead) !== 1;
        }
        if (preg_match('/^use\b/i', $lead) !== 0) {
            return true;
        }
        if (preg_match('/^(?:create|drop)\b/i', $lead) !== 0 && (preg_match(self::TEMPORARY_TABLE_PATTERN, $lead) !== 0 || static::executableAmongLeadingWords($lead))) {
            // a temporary table (whitespace or a comment between the words; CREATE /*!32312 TEMPORARY */ TABLE reads as
            // one, leadingKeywordSql()), or what may be one (an executable comment it could not read among them)
            return true;
        }
        // the pattern below needs one of these: plain substring scans rule out (almost) every statement before the
        // regex walks a long one (an INSERT of a few MB runs through query() after every successful statement)
        if (strpos($lead, '@') === false && strpos($lead, ':=') === false && stripos($lead, 'lock') === false && stripos($lead, 'last_insert_id') === false) {
            return false;
        }
        if (strpos($lead, ':=') !== false || (strpos($lead, '@') !== false && static::intoUserVariable($lead))) {
            return true;
        }
        // (a pattern that could not run, a PCRE limit: changed, on the safe side; from each function name it walks only
        // the whitespace after it, so it is linear)
        return (stripos($lead, 'lock') !== false || stripos($lead, 'last_insert_id') !== false)
            && preg_match('~\b(?:get_lock|release_lock|release_all_locks)\s*\(|\blast_insert_id\s*\(\s*[^)\s]~is', $lead) !== 0;
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
        // INTO @x needs an @, a lock function its name: skip the walk of a long statement that has neither (a pattern
        // that could not run, a PCRE limit: not replayable)
        if (strpos($lead, '@') !== false && static::intoUserVariable($lead)) {
            return false;
        }
        return stripos($lead, 'lock') === false || preg_match('~\b(?:get_lock|release_lock|release_all_locks)\s*\(~is', $lead) === 0;
    }

    /**
    * Whether the statement holds what INTO_USER_VARIABLE matches (SELECT ... INTO @x, with comments between), read in
    * one linear sweep (wordGapReaches()).
    *
    * @param string $lead
    * @return bool true too when a pattern could not run (a PCRE limit): changed, not replayable
    */
    protected static function intoUserVariable($lead)
    {
        return static::wordGapReaches($lead, 'into', true);
    }

    /**
    * Whether the statement holds what START_TRANSACTION_ANYWHERE matches (START and TRANSACTION with whitespace or
    * plain comments between them, anywhere), read in one linear sweep (wordGapReaches()).
    *
    * @param string $lead
    * @return bool true too when a pattern could not run (a PCRE limit): the safe side ('chain')
    */
    protected static function startTransactionAdjacent($lead)
    {
        return static::wordGapReaches($lead, 'start', false);
    }

    /**
    * Whether $sql holds $word (any case, not after a word character: PCRE's \b) followed by a gap and then a target,
    * exactly where INTO_USER_VARIABLE ($into true) or START_TRANSACTION_ANYWHERE ($into false) matches:
    *  - $into: the gap is any number of: whitespace (\s), a block comment that is not an executable one beginning with
    *    a user variable (slash-star, optional M, bang, digits, whitespace, @; to the first star-slash after its
    *    slash-star: the content of a plain or executable comment whatever it holds), a star-slash, a "#" or "--"
    *    comment (the "--" followed by a control character or a space) to the end of its line; the target is "@", or
    *    an executable comment beginning with a user variable;
    *  - otherwise WORD_GAP, at least one of: whitespace, a block comment that is not an executable one (no bang nor
    *    uppercase M-bang after its slash-star), a "#" or "--" comment to the end of its line; the target is the word
    *    TRANSACTION (\b after it).
    * Each step of a gap begins with a different character and runs to a fixed end, so the gap after a word is the
    * same whoever reaches a place in it, and what follows that place is told by the place alone. The patterns walked
    * that gap again from each word, string literals and comment bodies included ("@ into /* into /* ..." in a literal:
    * from every INTO to the end of the statement, the length squared). Here every walk that can still reach a target
    * is advanced in the order of its position, and a place already waiting is not queued twice, so each place is read
    * once whichever word's gap reaches it; the end of a block comment and of a line is looked up forward only (the
    * places are read in increasing order, so one lookup serves every place before what it found). Linear in the length
    * of $sql whatever it holds, in time and in memory: one lowercase copy of the statement (for INTO none when it holds
    * no comment opener), and the places waiting, at most one for each place.
    *
    * @param string $sql
    * @param string $word 'into' or 'start' (lowercase)
    * @param bool $into
    * @return bool true too when the word could not be looked for (a PCRE limit)
    */
    protected static function wordGapReaches($sql, $word, $into)
    {
        $sql = (string) $sql;
        // the words are looked for in a lowercase copy with strpos(), each from where the last one ended: what is read
        // of the statement to find them is read once (a caseless search from each word, PCRE's without its JIT or
        // stripos()'s, looks for both cases of the first letter to the end of the statement each time when one of them
        // is not there: the length times the number of words). For START it is made first, for the plain look too
        $lower = null;
        // whitespace (and a star-slash) holds no word, so walks over nothing but those never meet and a pattern walks
        // each place of them once: first, a target after nothing but those (a match), then the words with a comment
        // after nothing but those (none: no walk ever takes a comment, and there is no other match). One search over
        // the statement, run once. For INTO it is caseless on the statement: the letter it must hold last, the @, has
        // no case. For START it is caseful on the lowercase copy (the same match in the C ctype the app keeps, where
        // PCRE's tables and strtolower() fold ASCII letters only and \w is [A-Za-z0-9_]: \b is a word character on one
        // side only, \s the six bytes in the class): without PCRE's JIT, a caseless search checks before each try that
        // the last letter it must hold (TRANSACTION's n) still follows, with memchr() for each case, and a case that is
        // not there is looked for to the end of the statement every time (an uppercase N after each START and no
        // lowercase n: the length squared)
        if ($into) {
            $plain = preg_match('~\binto(?:\s|\*/)*+(?:@|/\*M?!\d*+\s*+@)~i', $sql);
            // a walk at a place after the last @ reaches no target
            $last = strrpos($sql, '@');
        } else {
            $lower = strtolower($sql);
            $plain = preg_match('~(?<![a-z0-9_])start[ \t\n\r\x0B\f]++transaction(?![a-z0-9_])~', $lower);
            $last = strrpos($lower, 'transaction');
        }
        if ($plain !== 0) {
            // (a pattern that could not run, a PCRE limit: true, the safe side)
            return true;
        }
        if ($last === false) {
            return false;
        }
        // no comment opener anywhere: every gap is whitespace (and, for INTO, star-slashes) and every target is a plain
        // one (an executable comment holding the user variable begins with a slash-star too), which the plain look read
        if (strpos($sql, '/*') === false && strpos($sql, '#') === false && strpos($sql, '--') === false) {
            return false;
        }
        $length = strlen($sql);
        if ($lower === null) {
            $lower = strtolower($sql);
        }
        $wordLength = strlen($word);
        // the work done (places read, bytes looked at), added to $sweepWork at the end
        $work = 0;
        $from = 0;
        $next = static::wordGapEntry($sql, $lower, $word, $wordLength, $into, $from, $last, $length, $work);
        if ($next === false) {
            // no word has a comment after it at or before the last target: no walk
            self::$sweepWork += $work;
            return false;
        }
        // places some walk reached and has not read yet, the smallest first, and the same as a set; the smallest of
        // them (null: none)
        $waiting = new \SplMinHeap();
        $queued = [];
        $top = null;
        // the last lookups of a star-slash and of a newline: from where, and what they found (false: none from there on)
        $closeFrom = -1;
        $closeAt = false;
        $lineFrom = -1;
        $lineAt = false;
        // whether some walk took a step to the end of the star-slash and of the newline last looked up: another walk
        // that takes a step there would skip what follows it again and reach the same place, so it ends there (k walks
        // whose comments close at one star-slash would each skip the run after it: k times its length). A step goes to
        // the end of the star-slash or newline last looked up, and a lookup is made again only once a place is past
        // what it found (the places are read in increasing order), so a step end that is not the current one is never
        // stepped to again, and one flag for each answers what a set of every step end would (whose memory grew with
        // the statement: 16 bytes for each byte of a chain of empty comments, 31 of "#" lines). A comment's end and a
        // line's meet only at the end of the statement, where every walk ends
        $closeStepped = false;
        $lineStepped = false;
        $at = null;
        $result = false;
        while (true) {
            if ($next === null) {
                $next = static::wordGapEntry($sql, $lower, $word, $wordLength, $into, $from, $last, $length, $work);
            }
            if ($at === null) {
                // the next place to read: the smallest of the next word's and the waiting ones
                if ($next !== false && ($top === null || $next <= $top)) {
                    $at = $next;
                    $next = null;
                    if ($at === $top) {
                        // a walk reached it too: read once
                        $waiting->extract();
                        unset($queued[$at]);
                        $top = $waiting->isEmpty() ? null : $waiting->top();
                    }
                    continue;
                }
                if ($top === null) {
                    break;
                }
                $at = $waiting->extract();
                unset($queued[$at]);
                $top = $waiting->isEmpty() ? null : $waiting->top();
            }
            $work++;
            // (no pattern is run at a place: with PCRE's JIT, a match anchored there first looks for the pattern's last
            // fixed character through the rest of the statement, which would cost its length at every place)
            $char = $sql[$at];
            $comment = $char === '/' && $at + 1 < $length && $sql[$at + 1] === '*';
            // an executable comment: slash-star, M or not, bang; $bang where its version would begin. For START the M is
            // an uppercase one (WORD_GAP: MariaDB runs only that one, MySQL none, so START /*m! x *\/ TRANSACTION is the
            // START TRANSACTION both run); for INTO of either case (INTO_USER_VARIABLE: an @ behind a slash-star-m-bang
            // taken for a user variable, the safe side)
            $bang = 0;
            if ($comment) {
                $mark = $at + 2 < $length ? $sql[$at + 2] : '';
                if ($mark === '!') {
                    $bang = $at + 3;
                } elseif (($mark === 'M' || ($into && $mark === 'm')) && $at + 3 < $length && $sql[$at + 3] === '!') {
                    $bang = $at + 4;
                }
            }
            // a target (not where a word's walk takes its first comment: the plain look above found none there)
            if ($into) {
                if ($char === '@') {
                    $result = true;
                    break;
                }
                if ($bang > 0) {
                    // its digits, its whitespace, then the user variable (a place is read once, and what this reads
                    // belongs to its comment alone)
                    $after = $bang + strspn($sql, '0123456789', $bang);
                    $after += strspn($sql, " \t\n\r\x0B\f", $after);
                    $work += $after - $bang;
                    if ($after < $length && $sql[$after] === '@') {
                        $result = true;
                        break;
                    }
                }
            } elseif (($char === 't' || $char === 'T') && $at + 11 <= $length && substr_compare($sql, 'transaction', $at, 11, true) === 0
                && strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_', $at + 11, 1) === 0) {
                $result = true;
                break;
            }
            // one step of the gap, a comment (a place is either one, whitespace or a star-slash, or where a gap ends)
            if ($comment) {
                if (!$into && $bang > 0) {
                    // an executable comment is no WORD_GAP
                    $at = null;
                    continue;
                }
                // (for INTO, an executable one beginning with a user variable is the target, read above)
                if ($closeFrom < 0 || $at + 2 < $closeFrom || ($closeAt !== false && $at + 2 > $closeAt)) {
                    $closeFrom = $at + 2;
                    $closeAt = strpos($sql, '*/', $closeFrom);
                    $work += ($closeAt === false ? $length : $closeAt) - $closeFrom;
                    $closeStepped = false;
                }
                if ($closeAt === false) {
                    // never closed: no step
                    $at = null;
                    continue;
                }
                if ($closeStepped) {
                    // another walk's step ended here: it skipped what follows and went on from where that ends (queued
                    // it, read it or ended there), and so would this one
                    $at = null;
                    continue;
                }
                $closeStepped = true;
                $to = $closeAt + 2;
            } elseif ($char === '#' || ($char === '-' && $at + 2 < $length && $sql[$at + 1] === '-' && (ord($sql[$at + 2]) <= 0x20 || $sql[$at + 2] === "\x7F"))) {
                if ($lineFrom < 0 || $at + 1 < $lineFrom || ($lineAt !== false && $at + 1 > $lineAt)) {
                    $lineFrom = $at + 1;
                    $lineAt = strpos($sql, "\n", $lineFrom);
                    $work += ($lineAt === false ? $length : $lineAt) - $lineFrom;
                    $lineStepped = false;
                }
                if ($lineStepped) {
                    // (as for a comment)
                    $at = null;
                    continue;
                }
                $lineStepped = true;
                $to = $lineAt === false ? $length : $lineAt + 1;
            } else {
                $to = $at;
            }
            // then whitespace and (for INTO) star-slashes: steps that hold no word. A run of them is skipped from at
            // most three step ends: its start, the end of its first star-slash (a comment opened before the run closes
            // there at the latest, and none opens inside it) and its first newline (the end of a line comment begun
            // before it); no walk is at a place inside it
            do {
                $was = $to;
                $to += strspn($sql, " \t\n\r\x0B\f", $to);
                if ($into && $to + 1 < $length && $sql[$to] === '*' && $sql[$to + 1] === '/') {
                    $to += 2;
                }
                $work += $to - $was + 1;
            } while ($to !== $was);
            if ($to === $at || $to > $last || isset($queued[$to]) || strpos($into ? '@/#-' : 'tT/#-', $sql[$to]) === false) {
                // no step (the gap ends here, short of a target), past the last target, a walk waits there already, or
                // what is there is neither a target nor a comment: that walk ends
                $at = null;
            } elseif ($next !== null && ($next === false || $to < $next) && ($top === null || $to < $top)) {
                // still the smallest place: read on from it at once
                $at = $to;
            } else {
                $queued[$to] = true;
                $waiting->insert($to);
                if ($top === null || $to < $top) {
                    $top = $to;
                }
                $at = null;
            }
        }
        self::$sweepWork += $work;
        return $result;
    }

    /**
    * wordGapReaches(): where the gap after the next $word from $from on begins with a comment ("/*", "#", or "--" with a
    * control character or a space after it; for START not an executable "/*!" or "/*M!", an uppercase M), exactly where
    * its entry pattern matched: $word not after a word character (PCRE's \w: letters, digits, underscore; \b), then any
    * run of whitespace (\s) and, for INTO, star-slashes, then the comment. $from moves past what was read; each byte of
    * $lower is looked at once over all the calls of one sweep, and the runs after different words do not overlap.
    *
    * @param string $sql
    * @param string $lower strtolower($sql)
    * @param string $word
    * @param int $wordLength
    * @param bool $into
    * @param int $from where to look from (moved on)
    * @param int $last no entry is wanted after it
    * @param int $length
    * @param int $work the work done (added to)
    * @return int|false where that comment begins; false: none at or before $last
    */
    protected static function wordGapEntry($sql, $lower, $word, $wordLength, $into, &$from, $last, $length, &$work)
    {
        while ($from <= $last) {
            $p = strpos($lower, $word, $from);
            if ($p === false || $p > $last) {
                $work += $length - $from;
                $from = $length;
                return false;
            }
            $work += $p - $from + 1;
            $from = $p + 1;
            if ($p > 0 && strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_', $p - 1, 1) === 1) {
                // after a word character: no \b
                continue;
            }
            $end = $p + $wordLength;
            do {
                $was = $end;
                $end += strspn($sql, " \t\n\r\x0B\f", $end);
                if ($into && $end + 1 < $length && $sql[$end] === '*' && $sql[$end + 1] === '/') {
                    $end += 2;
                }
            } while ($end !== $was);
            $work += $end - $p - $wordLength;
            if ($end >= $length) {
                continue;
            }
            $char = $sql[$end];
            $second = $end + 1 < $length ? $sql[$end + 1] : '';
            if ($char === '#'
                || ($char === '-' && $second === '-' && $end + 2 < $length && (ord($sql[$end + 2]) <= 0x20 || $sql[$end + 2] === "\x7F"))
                || ($char === '/' && $second === '*' && ($into || !static::executableAt($sql, $end, $length)))) {
                // what was read up to $end holds no other word's entry (the run after this word has no letters)
                $from = $end;
                return $end <= $last ? $end : false;
            }
        }
        return false;
    }

    /**
    * Whether the block comment at $at (slash-star) is an executable one: a bang after it, or an uppercase M and a bang
    * (MariaDB's lexer tests an uppercase M, MySQL's none: a slash-star-m-bang is a plain comment on both). Only START's
    * entries read it (wordGapEntry()).
    *
    * @param string $sql
    * @param int $at
    * @param int $length
    * @return bool
    */
    protected static function executableAt($sql, $at, $length)
    {
        if ($at + 2 >= $length) {
            return false;
        }
        $mark = $sql[$at + 2];
        return $mark === '!' || ($mark === 'M' && $at + 3 < $length && $sql[$at + 3] === '!');
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
    * Cost: inside a replayable transaction every result set is fetched and hashed here once more on top of the
    * caller's own reads (serialize() + MD5 of each row: about 1 to 4 microseconds a row for rows of 10 to 30 short
    * columns, the fetch itself aside; outside a transaction, and in one that can no longer be replayed, nothing is
    * done). It is capped: a step stops reading once the transaction's recording passes $replayMaxBytes (16 MB, the
    * transaction is then not replayable and nothing more is hashed), so a recording costs at most about one pass
    * over 16 MB (some tens of ms). It is not deferred to the replay or to the caller's first read: the replay must
    * compare against the rows the caller could have seen, and the result set is a public object ($queryId, queryId())
    * that a caller can read, rewind or keep past its next statement without this instance knowing, so reading it
    * any later could neither tell which rows were seen nor promise they were not.
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
        $this->txEndUnknown = '';
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
        // a transaction a START moved off a shared link ran is over: its connection gets the server's lock wait back
        $this->endApartLockWait();
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
        $this->txEndUnknown = '';
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
        $this->lostCleanupPending = true;
        $this->transactionLostConflict = false;
    }

    /**
    * Forgets that the transaction was lost.
    *
    * Besides a START or ROLLBACK read as one (query()), transactionBegin() or transactionAbort() that worked, and the
    * cleanup ROLLBACK answered without being sent, every end of a transaction tracked as open that worked without
    * losing it calls this: a COMMIT (query(): queryInTransaction(), a replayed one too; transactionCommit()), a
    * ROLLBACK of any form (a plain one through query(), any other through queryInTransaction()), and a statement that
    * committed it implicitly (DDL, LOCK TABLES, TRUNCATE...: queryInTransaction() and execute() 'end', lock()). Only
    * openUnknown() leaves a lost record beside a tracked transaction (a statement that may be a START, after the lost
    * one's refused COMMIT: query() keeps the record for what only may be a START or ROLLBACK). Once that transaction is
    * over the caller has ended a transaction of its own since: kept, the record would refuse a later COMMIT with the
    * lost one's "rolled back" while the writes are committed (a caller retrying on it writes them twice), and answer a
    * later ROLLBACK beside another handle's transaction as its cleanup. (transactionCommit()'s replay never runs with a
    * lost record left: openUnknown() makes the transaction not replayable.)
    *
    * @return void
    */
    protected function clearTransactionLost()
    {
        $this->transactionLost = false;
        $this->transactionLostErrno = 0;
        $this->transactionLostError = '';
        $this->txRefuse = false;
        $this->lostCleanupPending = false;
        $this->transactionLostConflict = false;
    }

    /**
    * The caller sent a statement of its own (not a ROLLBACK, START/BEGIN or COMMIT) after its transaction was over: a
    * lost record left from that transaction no longer waits for its cleanup ($lostCleanupPending). Not while an error
    * is being reported (what the report writes is the reporter's, see refusing()).
    *
    * @return void
    */
    protected function movedOnFromLost()
    {
        if ($this->transactionLost && $this->inTransaction !== true && !$this->reportingFailure && !static::reportingError()) {
            $this->lostCleanupPending = false;
        }
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
    * false (or rethrows, under MYSQLI_REPORT_STRICT). It never mails or halts. Whenever it fails, Errno/Error are those
    * of its own failure (with none open too). With none open of its own, and another handle sharing the connection
    * with a transaction open on it, the transaction is begun on a connection of this instance's own (see
    * startApart()), as by query('START TRANSACTION').
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
        $connected = false;
        try {
            $connected = (bool) $this->connect();
        } finally {
            if (!$connected) {
                // a START moved off a shared link that could not connect (connect() returned 0, or threw under
                // MYSQLI_REPORT_STRICT) began nothing
                $this->endApartLockWait();
            }
        }
        if (!$connected) {
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
            // nothing was open, or the server never ran it: whatever was open is still open (a START moved off a shared
            // link that began nothing sets its connection back to the server's lock wait)
            $this->endApartLockWait();
            if ($exception instanceof \Error) {
                // (nothing was open) a handle mysqli cannot use (half-open, or closed): nothing began, and the handle
                // is let go of so the next call connects anew, instead of an \Error to the caller
                $this->releaseLink();
                $this->Errno = 2006;
                $this->Error = (string) $error;
                return false;
            }
            // the caller reads why it failed here, not the error of whatever ran before it
            $this->Errno = (int) $errno;
            $this->Error = (string) $error;
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
        // a transaction whose end was not known is lost with 2006 (unknownEndLoss())
        $serverErrno = (int) $errno;
        list($errno, $error) = $this->unknownEndLoss($errno, $error);
        $this->Errno = $errno;
        $this->Error = $error;
        // the implicit commit of the open transaction failed: it is over, and the caller, asking for a new one,
        // must not have its next statements autocommit
        $this->inTransaction = false;
        $this->markTransactionLost($errno, $error, true);
        $this->transactionLostConflict = $serverErrno !== (int) $errno && in_array($serverErrno, self::REPLAY_ERRORS, true);
        $why = $replay !== null ? ' Not recovered: '.$replay['why'].'.' : ($this->txNotReplayableWhy !== '' ? ' Not replayed: '.$this->txNotReplayableWhy.'.' : '');
        $this->txEnd();
        if (!$linkLost && is_object($this->linkId) && $this->linkIsUsable()) {
            $this->sendQuietly('ROLLBACK');
        }
        error_log('transactionBegin(): the transaction that was open could not be committed first (SQL error '.$serverErrno.')'.($serverErrno !== (int) $errno ? ', and a statement that may have committed it implicitly ran in it, so whether its writes were committed is not known (lost, #'.(int) $errno.', not to be retried)' : '').'; it is lost, a later COMMIT is refused, and so are statements until a ROLLBACK or a new START.'.$why);
        if ($exception !== null) {
            if ($serverErrno !== (int) $errno && !$exception instanceof \Error) {
                throw new \mysqli_sql_exception($error, $errno, $exception);
            }
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
    * With no link at all and nothing open it returns true, as it always did. With nothing open of its own while another
    * handle sharing the link has a transaction open there, nothing is sent (the COMMIT would commit that handle's
    * transaction behind its back) and it fails: false, Errno 2006, Error "the open transaction belongs to another
    * handle", as query('COMMIT') does (commitWithoutTransaction()), and that other transaction goes on; commit it
    * through the handle that began it. It never mails or halts, and the statements after it are not refused (the
    * caller has ended the transaction).
    *
    * After a lost transaction it is refused (nothing is sent: false with the errno the transaction was lost with,
    * markCommitRefused()) while no transaction is open, as query('COMMIT') is. A transaction tracked as open since then
    * is sent its COMMIT, as by query('COMMIT') (queryInTransaction()): only a statement that may be a START (an
    * executable comment that is not read, openUnknown()) opens one and leaves the lost record, and refused, the COMMIT
    * of that new transaction would answer the stale "rolled back" of the lost one while its writes stay open or are
    * saved (a caller that retries on it writes them twice). Once such a COMMIT works the lost record is cleared: the
    * caller has committed a transaction of its own since, so no later COMMIT is the lost one's (so is it by every other
    * end of that transaction that worked: clearTransactionLost()).
    *
    * @return bool
    */
    public function transactionCommit()
    {
        $this->txLastStep = null;
        if ($this->transactionLost && $this->inTransaction !== true) {
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
        if (!$wasOpen && $this->anotherHolderInTransaction()) {
            // nothing open of its own, and another handle sharing the link has a transaction open there, which the
            // COMMIT would commit behind its back; answered true it would report that transaction's writes as saved
            // (as query('COMMIT'), commitWithoutTransaction(); no mail, no halt here)
            $this->txEnd();
            $this->Errno = 2006;
            $this->Error = 'COMMIT not sent: the open transaction belongs to another handle (commit it through the handle that began it)';
            error_log('transactionCommit(): COMMIT not sent: this handle has no transaction open, and the transaction open on the shared connection belongs to another handle; it fails (2006), nothing was committed: commit through the handle that began it.');
            return false;
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
            // (the COMMIT of a transaction opened after a lost one: see above, and clearTransactionLost())
            $this->clearTransactionLost();
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
        // a transaction whose end was not known is lost with 2006 (unknownEndLoss()): the writes before the statement
        // that may have committed it implicitly may be committed, whatever the COMMIT's own error says
        $serverErrno = (int) $errno;
        list($errno, $error) = $this->unknownEndLoss($errno, $error);
        $this->Errno = $errno;
        $this->Error = $error;
        // the server rolled it back, refused it, or its outcome is unknown; or it was never sent (2014): a later COMMIT
        // must not report success
        $this->inTransaction = false;
        $this->markTransactionLost($errno, $error, false);
        $this->transactionLostConflict = $serverErrno !== (int) $errno && in_array($serverErrno, self::REPLAY_ERRORS, true);
        if ($serverErrno !== (int) $errno) {
            error_log('transactionCommit(): COMMIT failed (SQL error '.$serverErrno.') after a statement that may have committed the transaction implicitly ran in it, so whether its writes were committed is not known: lost, #'.(int) $errno.', not to be retried.');
        }
        $this->txEnd();
        if (!$linkLost && is_object($this->linkId) && $this->linkIsUsable()) {
            if ($serverErrno === 2014) {
                // never sent: the server still holds the transaction, and a ROLLBACK would be out of sync too, so the
                // connection is closed, which ends it there (nothing was committed)
                $this->dropLink();
            } elseif ($replay === null || $replay['restored']) {
                // a COMMIT that failed without the server saying it ended the transaction: make sure it is over
                $this->sendQuietly('ROLLBACK');
            }
        }
        if ($exception !== null) {
            if ($serverErrno !== (int) $errno) {
                throw new \mysqli_sql_exception($error, $errno, $exception);
            }
            throw $replay === null || $exception->getCode() === $errno ? $exception : new \mysqli_sql_exception($error, $errno);
        }
        return false;
    }

    /**
    * Rolls back a transaction
    *
    * Returns false, never throws an \Error, when the handle is closed (another holder of the shared link closed it)
    * or half-open (a connect that failed): the connection is gone, and its transaction with it; the handle is let go
    * of so the next call connects anew. A ROLLBACK that fails (there or on the server) is recorded as query('ROLLBACK')
    * records one (abortFailed(): Errno/Error, and a transaction of its own that was open lost with that errno, its
    * connection closed when still usable), never mailed and never halting; with nothing of its own open a lost record
    * stays. A transaction of this instance's own that another holder of the shared link ended (transactionElsewhere())
    * is not rolled back as asked: its writes may have been committed by whatever ended it, so it is marked lost (2006),
    * the link let go of without closing it, and false returned (no mail, no halt); a quiet ROLLBACK goes out first only
    * while no other handle has a transaction open there (releaseEndedElsewhere()). With no transaction of its own while
    * another handle sharing the link has one open there, nothing is sent (the ROLLBACK would end that handle's
    * transaction), as query('ROLLBACK') (rollbackWithoutTransaction()): true when the handle kept a lost record of its
    * own that still waits for its cleanup (right after its own give-up, $lostCleanupPending; the record is cleared),
    * else false with Errno 2006, "the open transaction belongs to another handle" (roll it back through the handle that
    * began it; no mail, no halt, no exception). Under MYSQLI_REPORT_STRICT a server error still throws its
    * mysqli_sql_exception, once it is recorded.
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
            // would end (as query('ROLLBACK'), see rollbackWithoutTransaction())
            $this->txEnd();
            if ($this->transactionLost && $this->lostCleanupPending) {
                // the cleanup after this handle's own give-up: nothing of it is left to roll back
                $this->clearTransactionLost();
                error_log('transactionAbort(): ROLLBACK not sent: this handle has no transaction open (its own was given up), and another handle sharing the connection has one open there, which it would end.');
                return true;
            }
            // most likely meant for that other transaction: true would report its writes as discarded while they stay
            // open (no mail, no halt)
            $this->Errno = 2006;
            $this->Error = 'ROLLBACK not sent: the open transaction belongs to another handle: roll it back through the handle that began it';
            error_log('transactionAbort(): ROLLBACK not sent: this handle has no transaction open, and the transaction open on the shared connection belongs to another handle; it fails (2006), nothing was rolled back: roll it back through the handle that began it.');
            return false;
        }
        // read before txEnd() forgets them: a failure is recorded against the transaction that was open (abortFailed())
        $wasOpen = $this->inTransaction === true;
        $endUnknown = $this->txEndUnknown;
        $this->countStatement(true);
        $this->inTransaction = false;
        $this->txEnd();
        try {
            $result = mysqli_rollback($this->linkId);
        } catch (\Error $e) {
            // closed or half-open: the connection is gone, and any transaction with it
            $this->releaseLink();
            $this->abortFailed($wasOpen, $endUnknown, 2006, 'MySQL server has gone away ('.$e->getMessage().')');
            return false;
        } catch (\mysqli_sql_exception $e) {
            // (MYSQLI_REPORT_STRICT) recorded as a failure that returns, then thrown as always
            $this->abortFailed($wasOpen, $endUnknown, (int) $e->getCode(), $e->getMessage());
            throw $e;
        }
        if ($result) {
            $this->clearTransactionLost();
            return true;
        }
        $this->abortFailed($wasOpen, $endUnknown, (int) @mysqli_errno($this->linkId), (string) @mysqli_error($this->linkId));
        return false;
    }

    /**
    * transactionAbort()'s ROLLBACK failed: recorded as query('ROLLBACK') records one that failed (query(): a plain
    * ROLLBACK inside a transaction that did not go through). Errno/Error are the failure's (the server's, as query()
    * leaves them; 2006 for a handle mysqli cannot use). A transaction of this instance's own that was open is lost with
    * that errno (2006 while its end was not known: unknownEndLoss()), so a later COMMIT is refused rather than answered
    * for writes whose fate the caller was not told (an older lost record gives way to it: a COMMIT is not answered with
    * that one's "rolled back"), and the caller's statements are not refused (it ended the transaction itself); a
    * connection still usable is closed, which ends the transaction there (the server may still hold it, and what the
    * caller sends next must not run inside it). One error_log() line says so. With none open nothing is recorded, as
    * for query('ROLLBACK'). Unlike query() it never mails or halts, whatever haltOnError is (an API call, as
    * transactionCommit() and transactionBegin()), and it does not reconnect to send the ROLLBACK again (query() does for
    * a plain ROLLBACK, and answers true once that one works: the transaction went with the old connection).
    *
    * @param bool $wasOpen whether a transaction of this instance's own was open
    * @param string $endUnknown its $txEndUnknown
    * @param int $errno
    * @param string $error
    * @return void
    */
    protected function abortFailed($wasOpen, $endUnknown, $errno, $error)
    {
        $this->Errno = (int) $errno;
        $this->Error = (string) $error;
        if (!$wasOpen) {
            return;
        }
        list($lostErrno, $lostError) = $this->unknownEndLoss($this->Errno > 0 ? $this->Errno : 2006, $this->Error, $endUnknown);
        $this->markTransactionLost($lostErrno, $lostError, false);
        $closed = $this->linkIsUsable();
        if ($closed) {
            $this->dropLink();
        }
        error_log('transactionAbort(): the ROLLBACK failed (SQL error '.$this->Errno.'), so the transaction is recorded as lost (#'.$lostErrno.'): a later COMMIT is refused'.($closed ? '; its connection is closed, which ends it on the server' : '').'.');
    }

    /**
    * This will get the last insert ID created on the current connection.  Should only be called after an insert query is
    * run on a table that has an auto incrementing field.  $table and $field are required, but unused here since it's
    * unnecessary for mysql.  For compatibility with pgsql, the params must be supplied.
    *
    * Inside a recorded transaction the read is an observation (see query()): when this instance's last statement was
    * an INSERT/REPLACE it recorded, that step's insert id is marked as seen, so a replay that would hand out another
    * id gives up instead of going on. Only this instance's own recording is marked. The id lives on the connection, so
    * a clone sharing it (or any other handle holding the same link) reads the same id, but its read marks nothing in
    * the recording of the instance that ran the INSERT: when that instance's transaction is replayed later (a
    * 1213/3101 on a later statement or its COMMIT), the replayed INSERT can get a different id, the replay goes on
    * and the COMMIT succeeds, while the clone's caller keeps the id it read, which now names no row (or another one).
    * The same goes for a raw mysqli_insert_id() on $db->linkId. Read the id through the handle that ran the INSERT,
    * inside its transaction; or, as MyAdmin's CcMeta does, look the row up by a key of its own after COMMIT.
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
    *
    * LOCK TABLES commits the transaction open on the connection first (MySQL runs that implicit commit before it looks
    * at the tables). Inside a transaction of this instance's own: once it worked, the transaction is over (committed).
    * When it failed, either the server never ran it (NOT_RUN_ERRORS: it did not parse, or it was never sent), and the
    * transaction is still open (but no longer replayable), or it did, and the transaction is over, committed or rolled
    * back (see lockFailed(): only 3101/1180 say it was rolled back; anything else, 1213 included, leaves that unknown,
    * lost with 2006): it is given up like a statement in it, marked lost, so a later COMMIT is refused instead of
    * reporting writes whose fate is not known as saved, statements are refused until a ROLLBACK, START or
    * transactionAbort(), and a quiet ROLLBACK makes sure nothing of it is left open. The failure itself is answered as
    * always (halt(), or the exception under MYSQLI_REPORT_STRICT), with the errno the transaction was lost with: Errno,
    * and the exception's code (a lost one with 2006 throws a new 2006 exception whose previous is the server's 1213,
    * never the 1213 itself, which a caller retrying on 1213/3101 would take for "rolled back, retry"). Errno/Error are
    * set on every failure. LOCK TABLES sent through query() inside a transaction is answered the same way (see
    * queryInTransaction()).
    *
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
        $this->movedOnFromLost();
        $wasOpen = $this->inTransaction === true;
        if ($wasOpen) {
            // LOCK TABLES commits the open transaction: a replay could not repeat that (the transaction is still
            // tracked: it stays open if the server never runs the LOCK)
            $this->txNotReplayable('lock() ran inside it');
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
            $errno = (int) $e->getCode();
            // an end the server never ran ended nothing
            $this->uncountEnd($errno, true);
            $lostWith = $this->lockFailed($wasOpen, $errno, $e->getMessage());
            $this->Errno = $lostWith !== null ? $lostWith : $errno;
            $this->Error = $lostWith !== null ? $this->transactionLostError : $e->getMessage();
            if ($lostWith !== null && $lostWith !== $errno) {
                // the transaction was lost with 2006 (outcome unknown): the caller must not read the server's 1213 as
                // "rolled back, retry" (a retry would apply writes that did commit twice); the server's error stays
                // reachable as the previous exception
                throw new \mysqli_sql_exception($this->transactionLostError, $lostWith, $e);
            }
            throw $e;
        }
        if (!$res) {
            $errno = (int) @mysqli_errno($this->linkId);
            $error = (string) @mysqli_error($this->linkId);
            $this->uncountEnd($errno, true);
            $lostWith = $this->lockFailed($wasOpen, $errno, $error);
            $this->Errno = $lostWith !== null ? $lostWith : $errno;
            $this->Error = $lostWith !== null ? $this->transactionLostError : $error;
            $this->halt("lock($table, $mode) failed.");
            return 0;
        }
        // LOCK TABLES implicitly commits any open transaction.
        $this->inTransaction = false;
        $this->txEnd();
        if ($wasOpen) {
            // the transaction it committed was this instance's own, and is not lost (clearTransactionLost()); with none
            // open a lost record stays (it commits nothing of the caller's)
            $this->clearTransactionLost();
        }
        return $res;
    }

    /**
    * LOCK TABLES failed (lock()). MySQL runs it in two steps: first the implicit commit of the open transaction, then
    * the table (and metadata) locks. With a transaction of this instance's own open ($wasOpen):
    *  - the server never ran it (NOT_RUN_ERRORS): the transaction is still open, and stays so (not replayable);
    *  - the implicit commit was rolled back (3101, a Group Replication certification failure, or 1180, an error during
    *    COMMIT: both come from the commit step only): lost with that error, nothing was committed (lost with 2006 while
    *    the transaction's end is not known, keepUnknownEnd(): what it held before may be committed);
    *  - anything else, 1213 included (a deadlock can come from the commit, rolled back, or from the metadata lock
    *    acquisition after the commit went through), and the other errors that may come before or after the implicit
    *    commit (a Group Replication refusal of that commit such as 3100 rolled it back, 1146 on a missing table left it
    *    committed): whether its writes were committed is not known here, so it is lost with 2006, the errno a caller
    *    must not retry the transaction on (a caller retrying on 1213/3101 would apply writes that did commit twice);
    *    a connection error (LINK_ERRORS) keeps its own errno, which says the same.
    * A lost one refuses a later COMMIT (never reported as saved) and statements until a ROLLBACK, START or
    * transactionAbort() (the caller still takes it to be open), and a quiet ROLLBACK on its usable link makes sure
    * nothing of it is left open on the server: sent whatever another holder of the link says, as in giveUpUnknownEnd()
    * (lock() runs only on the transaction's own link, so what is open there is this instance's; another holder still
    * flagged as inside a transaction is one whose transaction this one's START committed). One error_log()
    * line says so. lock() hands the caller the errno it was lost with (Errno, and under MYSQLI_REPORT_STRICT the code
    * of the exception it throws: a 2006 one, with the server's exception as its previous, in place of a 1213).
    *
    * @param bool $wasOpen whether this instance had a transaction open when lock() was called
    * @param int $errno
    * @param string $error
    * @return int|null the errno the transaction was lost with (null: nothing was open, or it is still open)
    */
    protected function lockFailed($wasOpen, $errno, $error)
    {
        $errno = (int) $errno;
        if (!$wasOpen || in_array($errno, self::NOT_RUN_ERRORS, true)) {
            // nothing was open, or the server never ran it: whatever was open is still open
            return null;
        }
        if ((in_array($errno, [3101, 1180], true) && $this->txEndUnknown === '') || in_array($errno, self::LINK_ERRORS, true)) {
            // rolled back at the implicit commit (3101, 1180; not while the transaction's end is not known: the writes
            // before the statement that may have committed it implicitly may be committed), or the connection went
            // (outcome unknown, so it says)
            $this->markTransactionLost($errno, (string) $error, true);
        } else {
            // 1213 among them: it may come after the implicit commit went through
            $this->markTransactionLost(2006, 'Transaction lost: LOCK TABLES failed (SQL error '.$errno.') after the implicit commit of the open transaction may have run, so whether its writes were committed is not known', true);
        }
        $this->inTransaction = false;
        // whatever another holder of the link says while it is the transaction's own link (lock() found it so:
        // linkLostInTransaction()), as in giveUpUnknownEnd(); read before txEnd() forgets that link
        $own = is_object($this->txLink) && $this->linkId === $this->txLink;
        $this->txEnd();
        if (is_object($this->linkId) && $this->linkIsUsable() && ($own || !$this->anotherHolderInTransaction())) {
            $this->sendQuietly('ROLLBACK');
        }
        error_log('LOCK TABLES inside a transaction failed (SQL error '.$errno.'); it commits the open transaction first, so the transaction is given up (lost, #'.$this->transactionLostErrno.'): a later COMMIT is refused, and so are statements until a ROLLBACK or a new START.');
        return $this->transactionLostErrno;
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
