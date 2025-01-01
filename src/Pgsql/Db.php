<?php
/**
 * PostgreSQL Related Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2025
 * @package MyAdmin
 * @category SQL
 */

namespace MyDb\Pgsql;

use MyDb\Generic;
use MyDb\Db_Interface;

/**
 * Db
 *
 * @access public
 */
class Db extends Generic implements Db_Interface
{
    /* public: this is an api revision, not a CVS revision. */
    public $type = 'pgsql';
    public $port = '';
    public $defaultPort = '5432';


    /**
     * adds if not blank
     *
     * @param string $add the value to set
     * @param string $me the key/field to set the value for
     * @param false|string $quote optional indicate the value needs quoted
     * @return string
     */
    public function ifadd($add, $me, $quote = false)
    {
        if ('' != $add) {
            return ' '.$me.($quote === false ? '' : $quote).$add.($quote === false ? '' : $quote);
        }
        return '';
    }

    /**
     * @param $string
     * @return string
     */
    public function real_escape($string = '')
    {
        return $this->escape($string);
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

    /**
     * changes the database we are working with.
     *
     * @param string $database the name of the database to use
     * @return void
     */
    public function selectDb($database)
    {
        /*if ($database != $this->database) {
            $this->database = $database;
            $this->linkId = null;
            $this->connect();
        }*/
    }

    /**
     * Db::connect()
     * @return void
     */
    public function connect()
    {
        if (0 == $this->linkId) {
            $connectString = trim($this->ifadd($this->host, 'host=').
                             $this->ifadd($this->port, 'port=').
                             $this->ifadd($this->database, 'dbname=').
                             $this->ifadd($this->user, 'user=').
                             $this->ifadd($this->password, 'password=', "'"));
            $this->linkId = pg_connect($connectString);
            if (!$this->linkId) {
                $this->halt('Link-ID == FALSE, connect failed');
            }
        }
    }

    /* This only affects systems not using persistent connections */

    /**
     * Db::disconnect()
     * @return bool
     */
    public function disconnect()
    {
        return @pg_close($this->linkId);
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
            $this->next_record(MYSQL_ASSOC);
            return $this->Record;
        } else {
            $out = [];
            while ($this->next_record(MYSQL_ASSOC)) {
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

        $this->connect();

        /* printf("<br>Debug: query = %s<br>\n", $queryString); */

        $this->queryId = @pg_exec($this->linkId, $queryString);
        $this->Row = 0;

        $this->Error = pg_errormessage($this->linkId);
        $this->Errno = ($this->Error == '') ? 0 : 1;
        if (!$this->queryId) {
            $this->halt('Invalid SQL: '.$queryString, $line, $file);
        }

        return $this->queryId;
    }

    /**
     * Db::free()
     *
     * @return void
     */
    public function free()
    {
        @pg_freeresult($this->queryId);
        $this->queryId = 0;
    }

    /**
     * Db::next_record()
     * @param mixed $resultType
     * @return bool
     */
    public function next_record($resultType = PGSQL_BOTH)
    {
        $this->Record = @pg_fetch_array($this->queryId, $this->Row++, $resultType);

        $this->Error = pg_errormessage($this->linkId);
        $this->Errno = ($this->Error == '') ? 0 : 1;

        $stat = is_array($this->Record);
        if (!$stat && $this->autoFree) {
            pg_freeresult($this->queryId);
            $this->queryId = 0;
        }
        return $stat;
    }

    /**
     * Db::seek()
     *
     * @param mixed $pos
     * @return void
     */
    public function seek($pos)
    {
        $this->Row = $pos;
    }

    /**
     * Db::transactionBegin()
     *
     * @return mixed
     */
    public function transactionBegin()
    {
        return $this->query('begin');
    }

    /**
     * Db::transactionCommit()
     * @return bool|mixed
     */
    public function transactionCommit()
    {
        if (!$this->Errno) {
            return pg_exec($this->linkId, 'commit');
        } else {
            return false;
        }
    }

    /**
     * Db::transactionAbort()
     * @return mixed
     */
    public function transactionAbort()
    {
        return pg_exec($this->linkId, 'rollback');
    }

    /**
     * Db::getLastInsertId()
     * @param mixed $table
     * @param mixed $field
     * @return int
     */
    public function getLastInsertId($table, $field)
    {
        /* This will get the last insert ID created on the current connection.  Should only be called
        * after an insert query is run on a table that has an auto incrementing field.  Of note, table
        * and field are required because pgsql returns the last inserted OID, which is unique across
        * an entire installation.  These params allow us to retrieve the sequenced field without adding
        * conditional code to the apps.
        */
        if (!isset($table) || $table == '' || !isset($field) || $field == '') {
            return -1;
        }

        $oid = pg_getlastoid($this->queryId);
        if ($oid == -1) {
            return -1;
        }

        $result = @pg_exec($this->linkId, "select $field from $table where oid=$oid");
        if (!$result) {
            return -1;
        }

        $Record = @pg_fetch_array($result, 0);
        @pg_freeresult($result);
        if (!is_array($Record)) /* OID not found? */
        {
            return -1;
        }

        return $Record[0];
    }

    /**
     * Db::lock()
     * @param mixed  $table
     * @param string $mode
     * @return int|mixed
     */
    public function lock($table, $mode = 'write')
    {
        $result = $this->transactionBegin();

        if ($mode == 'write') {
            if (is_array($table)) {
                foreach ($table as $t) {
                    $result = pg_exec($this->linkId, 'lock table '.$t[1].' in share mode');
                }
            } else {
                $result = pg_exec($this->linkId, 'lock table '.$table.' in share mode');
            }
        } else {
            $result = 1;
        }

        return $result;
    }

    /**
     * Db::unlock()
     * @return bool|mixed
     */
    public function unlock()
    {
        return $this->transactionCommit();
    }

    /**
     * Db::affectedRows()
     * @return void
     */
    public function affectedRows()
    {
        return pg_cmdtuples($this->queryId);
    }

    /**
     * Db::num_rows()
     * @return int
     */
    public function num_rows()
    {
        return pg_numrows($this->queryId);
    }

    /**
     * Db::num_fields()
     * @return int
     */
    public function num_fields()
    {
        return pg_numfields($this->queryId);
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
            $this->log('PostgreSQL Error: '.pg_last_error($this->linkId), $line, $file, 'error');
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
        $this->query("select relname from pg_class where relkind = 'r' and not relname like 'pg_%'");
        $i = 0;
        while ($this->next_record()) {
            $return[$i]['table_name'] = $this->f(0);
            $return[$i]['tablespace_name'] = $this->database;
            $return[$i]['database'] = $this->database;
            ++$i;
        }
        return $return;
    }

    /**
     * Db::indexNames()
     *
     * @return array
     */
    public function indexNames()
    {
        $return = [];
        $this->query("SELECT relname FROM pg_class WHERE NOT relname ~ 'pg_.*' AND relkind ='i' ORDER BY relname");
        $i = 0;
        while ($this->next_record()) {
            $return[$i]['index_name'] = $this->f(0);
            $return[$i]['tablespace_name'] = $this->database;
            $return[$i]['database'] = $this->database;
            ++$i;
        }
        return $return;
    }
}
