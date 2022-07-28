<?php
/**
 * PDO SQL Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2019
 * @package MyAdmin
 * @category SQL
 */

namespace MyDb\Pdo;

use MyDb\Generic;
use MyDb\Db_Interface;
use PDO;

/**
 * Db
 *
 * @access public
 */
class Db extends Generic implements Db_Interface
{
    /* public: connection parameters */
    public $driver = 'mysql';
    public $Rows = [];
    /* public: this is an api revision, not a CVS revision. */
    public $type = 'pdo';

    /**
     * changes the database we are working with.
     *
     * @param string $database the name of the database to use
     * @return void
     */
    public function selectDb($database)
    {
        $dSN = "{$this->driver}:dbname={$database};host={$this->host}";
        if ($this->characterSet != '') {
            $dSN .= ';charset='.$this->characterSet;
        }
        $this->linkId = new PDO($dSN, $this->user, $this->password);
        $this->database = $database;
    }


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

    /* public: connection management */

    /**
     * Db::connect()
     * @param string $database
     * @param string $host
     * @param string $user
     * @param string $password
     * @param string $driver
     * @return bool|int|PDO
     */
    public function connect($database = '', $host = '', $user = '', $password = '', $driver = 'mysql')
    {
        /* Handle defaults */
        if ('' == $database) {
            $database = $this->database;
        }
        if ('' == $host) {
            $host = $this->host;
        }
        if ('' == $user) {
            $user = $this->user;
        }
        if ('' == $password) {
            $password = $this->password;
        }
        if ('' == $driver) {
            $driver = $this->driver;
        }
        /* establish connection, select database */
        $dSN = "{$driver}:dbname={$database};host={$host}";
        if ($this->characterSet != '') {
            $dSN .= ';charset='.$this->characterSet;
        }
        if ($this->linkId === false) {
            try {
                $this->linkId = new PDO($dSN, $user, $password);
            } catch (\PDOException $e) {
                $this->halt('Connection Failed '.$e->getMessage());
                return 0;
            }
        }
        return $this->linkId;
    }

    /* This only affects systems not using persistent connections */

    /**
     * Db::disconnect()
     * @return void
     */
    public function disconnect()
    {
    }

    /* public: discard the query result */

    /**
     * Db::free()
     * @return void
     */
    public function free()
    {
        //			@mysql_free_result($this->queryId);
        //			$this->queryId = 0;
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
        // New query, discard previous result.
        if ($this->queryId !== false) {
            $this->free();
        }

        if ($this->Debug) {
            printf("Debug: query = %s<br>\n", $queryString);
        }
        if (isset($GLOBALS['log_queries']) && $GLOBALS['log_queries'] !== false) {
            $this->log($queryString, $line, $file);
        }

        $this->queryId = $this->linkId->prepare($queryString);
        $success = $this->queryId->execute();
        $this->Rows = $this->queryId->fetchAll();
        //$this->log("PDO Query $queryString (S:$success) - ".count($this->Rows).' Rows', __LINE__, __FILE__);
        $this->Row = -1;
        if ($success === false) {
            $this->emailError($queryString, json_encode($this->queryId->errorInfo(), JSON_PRETTY_PRINT), $line, $file);
        }

        // Will return nada if it fails. That's fine.
        return $this->queryId;
    }

    /* public: walk result set */

    /**
     * Db::next_record()
     * @param mixed $resultType
     * @return bool
     */
    public function next_record($resultType = MYSQLI_ASSOC)
    {
        // PDO result types so far seem to be +1
        ++$resultType;
        if (!$this->queryId) {
            $this->halt('next_record called with no query pending.');
            return 0;
        }

        ++$this->Row;
        $this->Record = $this->Rows[$this->Row];

        $stat = is_array($this->Record);
        if (!$stat && $this->autoFree) {
            $this->free();
        }
        return $stat;
    }

    /* public: position in result set */

    /**
     * Db::seek()
     * @param integer $pos
     * @return int
     */
    public function seek($pos = 0)
    {
        if (isset($this->Rows[$pos])) {
            $this->Row = $pos;
        } else {
            $this->halt("seek($pos) failed: result has ".count($this->Rows).' rows');
            /* half assed attempt to save the day,
            * but do not consider this documented or even
            * desirable behaviour.
            */
            return 0;
        }
        return 1;
    }

    /**
     * Initiates a transaction
     * @return bool
     */
    public function transactionBegin()
    {
        return $this->linkId->beginTransaction();
    }

    /**
     * Commits a transaction
     * @return bool
     */
    public function transactionCommit()
    {
        return $this->linkId->commit();
    }

    /**
     * Rolls back a transaction
     * @return bool
     */
    public function transactionAbort()
    {
        return $this->linkId->rollBack();
    }

    /**
     * Db::getLastInsertId()
     * @param mixed $table
     * @param mixed $field
     * @return int
     */
    public function getLastInsertId($table, $field)
    {
        if (!isset($table) || $table == '' || !isset($field) || $field == '') {
            return -1;
        }
        return $this->linkId->lastInsertId();
    }

    /* public: table locking */

    /**
     * Db::lock()
     * @param mixed  $table
     * @param string $mode
     * @return void
     */
    public function lock($table, $mode = 'write')
    {
        /*			$this->connect();

        * $query = "lock tables ";
        * if (is_array($table))
        * {
        * foreach ($table as $key => $value)
        * {
        * if ($key == "read" && $key!=0)
        * {
        * $query .= "$value read, ";
        * }
        * else
        * {
        * $query .= "$value $mode, ";
        * }
        * }
        * $query = mb_substr($query,0,-2);
        * }
        * else
        * {
        * $query .= "$table $mode";
        * }
        * $res = @mysql_query($query, $this->linkId);
        * if (!$res)
        * {
        * $this->halt("lock($table, $mode) failed.");
        * return 0;
        * }
        * return $res;
        */
    }

    /**
     * Db::unlock()
     * @return void
     */
    public function unlock()
    {
        /*			$this->connect();

        * $res = @mysql_query("unlock tables");
        * if (!$res)
        * {
        * $this->halt("unlock() failed.");
        * return 0;
        * }
        * return $res;
        */
    }

    /* public: evaluate the result (size, width) */

    /**
     * Db::affectedRows()
     *
     * @return mixed
     */
    public function affectedRows()
    {
        return @$this->queryId->rowCount();
    }

    /**
     * Db::num_rows()
     * @return int
     */
    public function num_rows()
    {
        return count($this->Rows);
    }

    /**
     * Db::num_fields()
     * @return int
     */
    public function num_fields()
    {
        $keys = array_keys($this->Rows);
        return count($this->Rows[$keys[0]]);
    }

    /**
     * @param mixed $msg
     * @param string $line
     * @param string $file
     * @return mixed|void
     */
    public function haltmsg($msg, $line = '', $file = '')
    {
        $this->log("Database error: $msg", $line, $file, 'error');
        if ($this->Errno != '0' || $this->Error != '()') {
            $this->log('PDO MySQL Error: '.json_encode($this->linkId->errorInfo()), $line, $file, 'error');
        }
        $this->logBackTrace($msg, $line, $file);
    }

    /**
     * Db::tableNames()
     *
     * @return array
     */
    public function tableNames()
    {
        $return = [];
        $this->query('SHOW TABLES');
        foreach ($this->Rows as $i => $info) {
            $return[$i]['table_name'] = $info[0];
            $return[$i]['tablespace_name'] = $this->database;
            $return[$i]['database'] = $this->database;
        }
        return $return;
    }
}
