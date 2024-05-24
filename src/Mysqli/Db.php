<?php
/**
 * MySQL Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2019
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
        if (!is_object($this->linkId)) {
            $this->connectionAttempt++;
            if ($this->connectionAttempt >= $this->maxConnectErrors - 1) {
                error_log("MySQLi Connection Attempt #{$this->connectionAttempt}/{$this->maxConnectErrors}");
            }
            if ($this->connectionAttempt >= $this->maxConnectErrors) {
                $this->halt("connect($host, $user, \$password) failed. ".$this->linkId->connect_error);
                return 0;
            }
            $this->linkId = mysqli_init();
            $this->linkId->options(MYSQLI_INIT_COMMAND, "SET NAMES {$this->characterSet} COLLATE {$this->collation}, COLLATION_CONNECTION = {$this->collation}, COLLATION_DATABASE = {$this->collation}");
            if (!$this->linkId->real_connect($host, $user, $password, $database, $port != '' ? $port : NULL)) {
                $this->halt("connect($host, $user, \$password) failed. ".$this->linkId->connect_error);
                return 0;
            }
            $this->linkId->set_charset($this->characterSet);
            if ($this->linkId->connect_errno) {
                $this->halt("connect($host, $user, \$password) failed. ".$this->linkId->connect_error);
                return 0;
            }
        }
        return $this->linkId;
    }

    /**
     * Db::disconnect()
     * @return bool
     */
    public function disconnect()
    {
        $return = !is_int($this->linkId) && method_exists($this->linkId, 'close') ? $this->linkId->close() : false;
        $this->linkId = 0;
        return $return;
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
     * @return int|\MyDb\Mysqli\mysqli_stmt
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
        $prepare = mysqli_prepare($this->linkId, $query);
        if (!isset($GLOBALS['disable_db_queries'])) {
            $this->addLog($query, microtime(true) - $start, $line, $file);
        }
        return $prepare;
    }

    /**
     * Db::query()
     *
     *  Sends an SQL query to the database
     *
     * @param mixed $queryString
     * @param string $line
     * @param string $file
     * @return mixed 0 if no query or query id handler, safe to ignore this return
     */
    public function query($queryString, $line = '', $file = '')
    {
        /* No empty queries, please, since PHP4 chokes on them. */
        /* The empty query string is passed on from the constructor,
        * when calling the class without a query, e.g. in situations
        * like these: '$db = new db_Subclass;'
        */
        if ($queryString == '') {
            return 0;
        }
        if (!$this->connect()) {
            return 0;
            /* we already complained in connect() about that. */
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
        if (isset($GLOBALS['log_queries']) && $GLOBALS['log_queries'] !== false) {
            $this->log($queryString, $line, $file);
        }
        $tries = 2;
        $try = 0;
        $this->queryId = false;
        while ((null === $this->queryId || $this->queryId === false) && $try <= $tries) {
            $try++;
            if ($try > 1) {
                @mysqli_close($this->linkId);
                $this->linkId = 0;
                $this->connect();
            }
            $start = microtime(true);
            $onlyRollback = true;
            $fails = -1;
            while ($fails < 30 && (null === $this->queryId || $this->queryId === false)) {
                $fails++;
                try {
                    $this->queryId = @mysqli_query($this->linkId, $queryString, MYSQLI_STORE_RESULT);
                    if (in_array((int)@mysqli_errno($this->linkId), [1213, 2006, 3101, 1180])) {
                        //error_log("got ".@mysqli_errno($this->linkId)." sql error fails {$fails} on query {$queryString} from {$line}:{$file}");
                        usleep(250000); // 0.25 second
                    } else {
                        $onlyRollback = false;
                    }
                } catch (\mysqli_sql_exception $e) {
                    if (in_array((int)$e->getCode(), [1213, 2006, 3101, 1180])) {
                        //error_log("got ".$e->getCode()." sql error fails {$fails}");
                        usleep(250000); // 0.25 second
                    } else {
                        error_log('Got mysqli_sql_exception code '.$e->getCode().' error '.$e->getMessage().' on query '.$queryString.' from '.$line.':'.$file);
                        $onlyRollback = false;
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
        }
        $this->haltOnError = $haltPrev;
        if ($onlyRollback === true && false === $this->queryId) {
            error_log('Got MySQLi 3101 Rollback Error '.$fails.' Times, Giving Up on '.$queryString.' from '.$line.':'.$file.' on '.__LINE__.':'.__FILE__);
        }
        if (!isset($GLOBALS['disable_db_queries']) && (null === $this->queryId || $this->queryId === false)) {
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
     * Initiates a transaction
     *
     * @return bool
     */
    public function transactionBegin()
    {
        if (version_compare(PHP_VERSION, '5.5.0') < 0) {
            return true;
        }
        if (!$this->connect()) {
            return 0;
        }
        return mysqli_begin_transaction($this->linkId);
    }

    /**
     * Commits a transaction
     *
     * @return bool
     */
    public function transactionCommit()
    {
        if (version_compare(PHP_VERSION, '5.5.0') < 0 || $this->linkId === 0) {
            return true;
        }
        return mysqli_commit($this->linkId);
    }

    /**
     * Rolls back a transaction
     *
     * @return bool
     */
    public function transactionAbort()
    {
        if (version_compare(PHP_VERSION, '5.5.0') < 0 || $this->linkId === 0) {
            return true;
        }
        return mysqli_rollback($this->linkId);
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
