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
        $this->connect();
        mysqli_select_db($this->linkId, $database);
    }

    /* public: connection management */

    /**
    * whether real_connect() has succeeded on the mysqli currently in linkId
    * @var bool
    */
    protected $linkEstablished = false;

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
    * closed". Clones share one handle (there is no __clone here) and query()'s
    * retry path below closes it, so one clone can close the connection out from
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
            // closing the connection rolls the open transaction back: a later COMMIT must be refused
            $this->markTransactionLost(2006, 'MySQL server has gone away (disconnected inside a transaction)');
        }
        $return = !is_int($this->linkId) && method_exists($this->linkId, 'close') ? $this->linkId->close() : false;
        $this->linkId = 0;
        // Closing the connection discards any open transaction server-side.
        $this->inTransaction = false;
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
        if (!$this->connect()) {
            return 0;
        }
        $haltPrev = $this->haltOnError;
        $this->haltOnError = 'no';
        $start = microtime(true);
        $this->statement = mysqli_prepare($this->linkId, $query);
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
        $start = microtime(true);
        $return = mysqli_stmt_execute($this->statement);
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
    * A statement that fails with 1213, 3101, 1180 or 2006 is retried (and reconnected) when no
    * transaction is open. Inside one it is never retried or re-run on a new connection: the server has
    * rolled the transaction back, so it fails once, through emailError()/halt(), and a later COMMIT is
    * refused (never sent) until a ROLLBACK, START TRANSACTION/BEGIN or transactionAbort() has succeeded
    * (transactionAbort() with no link at all clears it too: the caller aborted and there is nothing to
    * roll back). The refused COMMIT is a failed statement like any other: emailError(), then halt(), so
    * with haltOnError 'yes' the process ends there and the call returns false only under 'no' and
    * 'report' (transactionCommit() is the exception: it behaves like a failed mysqli_commit(), sets
    * Errno/Error, returns false, and never mails or halts, whatever haltOnError is). Errno/Error of a
    * refusal are those the transaction was lost with (a 2006 stays 2006: the outcome of what it was doing
    * is unknown, so a caller replaying on 1213 must not replay on 2006).
    * A COMMIT that fails that way counts too (after 2006 its outcome is unknown), so retrying it is
    * refused as well; every other statement keeps running, with autocommit. A plain ROLLBACK is the
    * exception to the give-up: the connection loss already discarded the writes, so it takes the retry
    * and reconnect path every statement had before (a successful ROLLBACK on the new connection is the
    * truthful answer), and only if it finally fails is the transaction recorded as lost. ROLLBACK TO
    * SAVEPOINT stays inside the transaction and is never retried. A START TRANSACTION/BEGIN with none
    * open keeps the retry and reconnect behaviour every statement had before; inTransaction is true only
    * once it has succeeded. Leading SQL comments are ignored when these statements are recognised, a
    * comment between their words is not, and COMMIT/ROLLBACK ... AND CHAIN (not AND CHAIN RELEASE) leave
    * a transaction open.
    * COMMIT/ROLLBACK AND CHAIN with no transaction open do leave inTransaction true on purpose: MySQL's
    * chain starts a new transaction even with autocommit=1 (it calls trans_begin()), so HEAD's false was
    * wrong. A give-up of any other statement inside a transaction sets the lost flag: the writes are gone
    * and a following COMMIT must not report success; only a successful ROLLBACK or START TRANSACTION/BEGIN
    * (or transactionAbort()/detach()) clears it. With completion_type=1 (server/session)
    * a plain COMMIT/ROLLBACK chains too; the tracker does not read that variable (deferred).
    * halt() runs unlock(false) even with haltOnError 'no', and after a lost connection that call throws
    * \mysqli_sql_exception under MYSQLI_REPORT_STRICT: query() then throws instead of returning false
    * (accepted, including for a COMMIT that fails with 2006).
    *
    * The transaction state (inTransaction, the lost flag) belongs to the instance: clones of a connected
    * handle share the link but not that state, so a clone cannot see a transaction another one opened or
    * lost (deferred). prepare()/execute() do not go through any of this (no give-up, no tracking); do
    * not use them inside a transaction.
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
        /*
         * Whether a transaction was open when this statement started, and what kind of statement it
         * is. The state is only updated once the statement has succeeded (see below): a COMMIT that
         * fails, or is retried after the server rolled the transaction back, must still count as
         * inside it, and a START that fails must not leave the lost-transaction flag cleared.
         */
        $transactionOpen = $this->inTransaction === true;
        $lead = static::leadingKeywordSql($queryString);
        $isStart = preg_match('/^(start\s+transaction|begin)\b/i', $lead) === 1;
        // a plain ROLLBACK is told by a whitelist on the statement with every comment removed: any other form is guarded
        $isRollback = static::isPlainRollback($queryString);
        $isCommit = preg_match('/^commit\b/i', $lead) === 1;
        /*
         * A START TRANSACTION/BEGIN with no transaction open keeps the control flow it always had: the
         * flag is raised before it runs (once connected) and every guard below reads the live flag, as before.
         * Only the bookkeeping differs: inTransaction ends up true only if the statement succeeded.
         */
        $legacyStart = $isStart && !$transactionOpen;
        /*
         * Whether the give-up rules apply: a statement inside a transaction. A plain ROLLBACK is
         * left out on purpose: when the transaction is already gone a ROLLBACK on a new connection is
         * the true answer, so it keeps the retry and reconnect path (ROLLBACK TO SAVEPOINT is not a
         * plain ROLLBACK and stays guarded).
         */
        $guarded = $transactionOpen && !$isRollback;
        /*
         * Inside a transaction a link that is no longer usable (closed by another
         * holder of this clone family's shared handle) must not be reopened here:
         * the fresh connection would have no transaction on it, so the statement
         * would autocommit on its own. The retry loop below fails it instead.
         */
        if ((!$guarded || $this->linkIsUsable()) && !$this->connect()) {
            return 0;
            /* we already complained in connect() about that. */
        }
        if ($legacyStart) {
            // raised only once there is a connection: a START that never connected opened nothing
            $this->inTransaction = true;
        }
        $haltPrev = $this->haltOnError;
        $this->haltOnError = 'no';
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
        /*
         * Set once a statement that started inside a transaction has failed in
         * a way that means the server no longer has that transaction (1213,
         * 2006, 3101, 1180, or the link is gone). Both loops stop on it, so the
         * statement is neither retried nor re-run on a new connection, and the
         * failure goes out through emailError()/halt() like any other. It is a
         * local, not inTransaction: that is cleared at the same moment, after
         * which the outer loop could not tell a give-up from a statement run
         * outside a transaction, and reconnected and re-ran it on its own.
         */
        $giveUp = false;
        // a statement error that is not retried: the statement failed, the transaction (if any) is untouched
        $leave = false;
        $linkLost = false;
        $onlyRollback = true;
        $fails = -1;
        if ($isCommit && $this->transactionLost) {
            /*
             * The server rolled the transaction back after an earlier statement
             * in it gave up, so there is nothing to commit: a COMMIT sent now
             * would succeed as a no-op and report the lost writes as saved.
             */
            $giveUp = true;
            $this->markCommitRefused();
        }
        while ((null === $this->queryId || $this->queryId === false) && $try <= $tries && !$giveUp && !$leave) {
            $try++;
            if ($try > 1) {
                /*
                 * Inside a transaction every failure ends the statement in the loop below (a give-up,
                 * or a plain statement error that leaves the transaction open), so only a statement
                 * outside one gets here, and the connection is never closed and reopened underneath
                 * a transaction. The one exception is START TRANSACTION/BEGIN, whose own flag is up
                 * while it runs: if it failed without being retried, it stops here.
                 */
                if ($legacyStart && $this->inTransaction === true) {
                    error_log('Refusing to reconnect mid-transaction; failing the query instead so the caller sees the broken transaction. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                    $this->inTransaction = false;
                    break;
                }
                @mysqli_close($this->linkId);
                $this->linkId = 0;
            }
            $start = microtime(true);
            $onlyRollback = true;
            $fails = -1;
            while ($fails < 30 && (null === $this->queryId || $this->queryId === false) && !$giveUp) {
                /*
                 * connect() below would transparently reopen a dropped
                 * connection. Inside a transaction that is exactly the silent
                 * failure we are guarding against, so stop here and let the
                 * caller see it.
                 */
                if (($legacyStart ? $this->inTransaction === true : $guarded) && !$this->linkIsUsable()) {
                    error_log('Connection lost mid-transaction; failing the query rather than silently reconnecting. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                    $this->inTransaction = false;
                    if (!$legacyStart) {
                        $this->transactionLost = true;
                        $giveUp = true;
                        $linkLost = true;
                    }
                    break;
                }
                $this->connect();
                $fails++;
                try {
                    $this->queryId = @mysqli_query($this->linkId, $queryString, MYSQLI_STORE_RESULT);
                    if (in_array((int)@mysqli_errno($this->linkId), [1213, 2006, 3101, 1180])) {
                        /*
                         * A deadlock (1213) or a Galera certification failure
                         * (3101) makes the server roll the whole transaction
                         * back, and a lost connection (2006) takes it with it.
                         * Retrying just this one statement would succeed
                         * outside the now-dead transaction, committing it on
                         * its own while the caller still believes the unit is
                         * atomic. Fail instead so the caller can replay the
                         * whole transaction, which is the only correct retry.
                         */
                        if ($legacyStart ? $this->inTransaction === true : $guarded) {
                            error_log('Got SQL error '.(int)@mysqli_errno($this->linkId).' inside a transaction; the server has rolled it back, so failing rather than retrying the statement alone. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                            $this->inTransaction = false;
                            if (!$legacyStart) {
                                $this->transactionLost = true;
                                $giveUp = true;
                            }
                            break;
                        }
                        //error_log("got ".@mysqli_errno($this->linkId)." sql error fails {$fails} on query {$queryString} from {$line}:{$file}");
                        usleep(250000); // 0.25 second
                    } else {
                        $onlyRollback = false;
                        if (in_array((int)@mysqli_errno($this->linkId), [1064])) {
                            $tries = 0;
                        }
                        $leave = $guarded;
                        break;
                    }
                } catch (\mysqli_sql_exception $e) {
                    if (in_array((int)$e->getCode(), [1213, 2006, 3101, 1180])) {
                        // Same reasoning as the non-exception branch above.
                        if ($legacyStart ? $this->inTransaction === true : $guarded) {
                            error_log('Got SQL exception '.(int)$e->getCode().' inside a transaction; the server has rolled it back, so failing rather than retrying the statement alone. Query: '.static::redactQueryForLog($queryString).' from '.$file.':'.$line);
                            $this->inTransaction = false;
                            if (!$legacyStart) {
                                $this->transactionLost = true;
                                $giveUp = true;
                            }
                            break;
                        }
                        //error_log("got ".$e->getCode()." sql error fails {$fails}");
                        usleep(250000); // 0.25 second
                    } else {
                        error_log('Got mysqli_sql_exception code '.$e->getCode().' error '.static::redactQueryForLog($e->getMessage(), $queryString).' on query '.static::redactQueryForLog($queryString).' from '.$line.':'.$file);
                        $onlyRollback = false;
                        if (in_array((int)@mysqli_errno($this->linkId), [1064])) {
                            $tries = 0;
                        }
                        $leave = $guarded;
                        break;
                    }
                }
            }
            if (!isset($GLOBALS['disable_db_queries'])) {
                $this->addLog($queryString, microtime(true) - $start, $line, $file);
            }
            $this->Row = 0;
            if ($linkLost) {
                // the handle is closed (or not a mysqli at all): reading its errno would throw
                $this->Errno = 2006;
                $this->Error = 'MySQL server has gone away (connection lost mid-transaction)';
            } else {
                $this->Errno = @mysqli_errno($this->linkId);
                $this->Error = @mysqli_error($this->linkId);
            }
            if ($giveUp) {
                // what the transaction was lost with, for the refusal of the COMMIT that follows
                $this->markTransactionLost((int) $this->Errno, (string) $this->Error);
            }
            if ($try == 1 && (null === $this->queryId || $this->queryId === false)) {
                //$this->emailError($queryString, 'Error #'.$this->Errno.': '.$this->Error, $line, $file);
            }
        }
        $this->haltOnError = $haltPrev;
        if (null === $this->queryId || $this->queryId === false) {
            if ($legacyStart) {
                // a START TRANSACTION/BEGIN that failed opened nothing
                $this->inTransaction = false;
            }
            if ($transactionOpen && $isRollback) {
                // the retries are used up: either way no transaction is open now, but the ROLLBACK did not
                // go through, so a COMMIT must not report the lost writes as saved
                $this->inTransaction = false;
                if (in_array((int) $this->Errno, [1213, 2006, 3101, 1180], true)) {
                    $this->markTransactionLost((int) $this->Errno, (string) $this->Error);
                }
            }
        } else {
            // the state only changes once the statement has worked
            if ($isStart || $isRollback) {
                $this->clearTransactionLost();
            }
            $this->trackTransactionState($queryString);
        }
        // a transactional give-up has already logged why, after a single attempt
        if ($onlyRollback === true && false === $this->queryId && !$giveUp) {
            error_log('Got MySQLi 3101 Rollback Error '.$fails.' Times, Giving Up on '.static::redactQueryForLog($queryString).' from '.$line.':'.$file.' on '.__LINE__.':'.__FILE__);
        }
        if (null === $this->queryId || $this->queryId === false) {
            $this->emailError($queryString, 'Error #'.$this->Errno.': '.$this->Error, $line, $file);
            $this->halt('', $line, $file);
        }

        // Will return nada if it fails. That's fine.
        return $this->queryId;
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
        $this->Errno = mysqli_errno($this->linkId);
        $this->Error = mysqli_error($this->linkId);

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
    * @return void
    */
    protected function markTransactionLost($errno, $error)
    {
        $this->transactionLost = true;
        $this->transactionLostErrno = (int) $errno;
        $this->transactionLostError = (string) $error;
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
    }

    /**
    * Whether a transaction is open on a link that is gone (closed by another holder of the shared
    * handle): the transaction went with it, so it is recorded as lost (2006) and no reconnect is
    * made. Outside a transaction it does nothing, so the callers keep reconnecting as before.
    *
    * @return bool true if the transaction was just found lost
    */
    protected function linkLostInTransaction()
    {
        if ($this->inTransaction !== true || $this->linkIsUsable()) {
            return false;
        }
        $this->markTransactionLost(2006, 'MySQL server has gone away (connection lost inside a transaction)');
        $this->inTransaction = false;
        $this->Errno = 2006;
        $this->Error = 'MySQL server has gone away (connection lost inside a transaction)';
        return true;
    }

    /**
    * Initiates a transaction
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
        if (!$this->connect()) {
            return 0;
        }
        $result = mysqli_begin_transaction($this->linkId);
        if ($result) {
            $this->inTransaction = true;
            $this->clearTransactionLost();
        }
        return $result;
    }

    /**
    * Commits a transaction
    *
    * @return bool
    */
    public function transactionCommit()
    {
        if ($this->transactionLost) {
            /*
             * The server already lost the transaction. This fails like a mysqli_commit() that failed
             * (Errno/Error set, false returned): no emailError(), no halt(), whatever haltOnError is,
             * unlike query('COMMIT'), which is a failed statement and halts like any other.
             */
            $this->markCommitRefused();
            return false;
        }
        if (version_compare(PHP_VERSION, '5.5.0') < 0 || $this->linkId === 0) {
            $this->inTransaction = false;
            return true;
        }
        $wasOpen = $this->inTransaction === true;
        if ($wasOpen && is_object($this->linkId) && !$this->linkIsUsable()) {
            // the link is gone (closed by another holder of the shared handle): the transaction went with it
            $this->markTransactionLost(2006, 'MySQL server has gone away (connection lost before COMMIT)');
            $this->inTransaction = false;
            $this->markCommitRefused();
            return false;
        }
        try {
            $result = mysqli_commit($this->linkId);
        } catch (\mysqli_sql_exception $e) {
            // MYSQLI_REPORT_STRICT: record the outcome before the exception goes on
            $this->inTransaction = false;
            if ($wasOpen && in_array((int) $e->getCode(), [1213, 2006, 3101, 1180], true)) {
                $this->markTransactionLost((int) $e->getCode(), $e->getMessage());
            }
            throw $e;
        }
        $this->inTransaction = false;
        if ($wasOpen && !$result && in_array((int) @mysqli_errno($this->linkId), [1213, 2006, 3101, 1180], true)) {
            // the server rolled it back (2006: outcome unknown), so a later COMMIT must not report success
            $this->markTransactionLost((int) @mysqli_errno($this->linkId), (string) @mysqli_error($this->linkId));
        }
        return $result;
    }

    /**
    * Rolls back a transaction
    *
    * @return bool
    */
    public function transactionAbort()
    {
        if (version_compare(PHP_VERSION, '5.5.0') < 0 || $this->linkId === 0) {
            $this->inTransaction = false;
            $this->clearTransactionLost();
            return true;
        }
        $result = mysqli_rollback($this->linkId);
        $this->inTransaction = false;
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
        $res = @mysqli_query($this->linkId, $query);
        // LOCK TABLES implicitly commits any open transaction.
        $this->inTransaction = false;
        if (!$res) {
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
        $this->connect();

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
