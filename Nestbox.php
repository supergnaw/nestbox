<?php
declare(strict_types=1);

namespace app\Nestbox;

// https://phpdelusions.net/pdo

use PDO;
use PDOException;
use app\Nestbox\Exception\CannotBindArrayException;
use app\Nestbox\Exception\EmptyQueryException;
use app\Nestbox\Exception\FailedToBindValueException;
use app\Nestbox\Exception\InvalidColumnException;
use app\Nestbox\Exception\InvalidTableException;
use app\Nestbox\Exception\MissingDatabaseHostException;
use app\Nestbox\Exception\MissingDatabaseNameException;
use app\Nestbox\Exception\MissingDatabasePassException;
use app\Nestbox\Exception\MissingDatabaseUserException;
use app\Nestbox\Exception\NestboxException;
use app\Nestbox\Exception\QueryErrorException;
use app\Nestbox\Exception\TransactionBeginFailedException;
use app\Nestbox\Exception\TransactionCommitFailedException;
use app\Nestbox\Exception\TransactionException;
use app\Nestbox\Exception\TransactionInProgressException;
use app\Nestbox\Exception\TransactionRollbackFailedException;

class Nestbox
{
    /*
        1.0 Structs & Vars
    */

    // connection properties
    protected $host;
    protected $user;
    protected $pass;
    protected $name;

    // handler properties
    protected $pdo;
    protected $stmt;

    // database properties
    protected $tableSchema = [];

    // query information
    protected $results = [];
    protected $rowCount = null;
    protected $lastInsertId = null;


    /**
     * Default constructor
     *
     * @param string|null $host
     * @param string|null $user
     * @param string|null $pass
     * @param string|null $name
     */
    public function __construct(string $host = null, string $user = null, string $pass = null, string $name = null)
    {
        $this->set_db_host($host);
        $this->set_db_user($user);
        $this->set_db_pass($pass);
        $this->set_db_name($name);
    }

    /**
     * Default destructor
     */
    public function __destruct()
    {
        // close any existing database connection
        $this->close();
    }

    /**
     * Magic method to reset pdo connection details
     *
     * @param string|null $host
     * @param string|null $user
     * @param string|null $pass
     * @param string|null $name
     * @return void
     */
    public function __invoke(string $host = null, string $user = null, string $pass = null, string $name = null)
    {
        $this->set_db_host($host);
        $this->set_db_user($user);
        $this->set_db_pass($pass);
        $this->set_db_name($name);
    }

    /**
     * Set database connection host
     *
     * @param string|null $host
     * @return void
     */
    public function set_db_host(string $host = null): void
    {
        $this->close();
        if (!empty($host)) {
            $this->host = $host;
        } else {
            if (defined('NESTBOX_DB_HOST')) {
                $this->host = NESTBOX_DB_HOST;
            } else {
                throw new MissingDatabaseHostException("Missing database hostname.");
            }
        }
    }

    /**
     * Set database connection user
     *
     * @param string|null $user
     * @return void
     */
    public function set_db_user(string $user = null): void
    {
        $this->close();
        if (!empty($user)) {
            $this->user = $user;
        } else {
            if (defined('NESTBOX_DB_USER')) {
                $this->user = NESTBOX_DB_USER;
            } else {
                throw new MissingDatabaseUserException("Missing database username.");
            }
        }
    }

    /**
     * Set database connection password
     *
     * @param string|null $pass
     * @return void
     */
    public function set_db_pass(string $pass = null): void
    {
        $this->close();
        if (!empty($pass)) {
            $this->pass = $pass;
        } else {
            if (defined('NESTBOX_DB_PASS')) {
                $this->pass = NESTBOX_DB_PASS;
            } else {
                throw new MissingDatabasePassException("Missing database password.");
            }
        }
    }

    /**
     * Set database connection name
     *
     * @param string|null $name
     * @return void
     */
    public function set_db_name(string $name = null): void
    {
        $this->close();
        if (!empty($name)) {
            $this->name = $name;
        } else {
            if (defined('NESTBOX_DB_NAME')) {
                $this->name = NESTBOX_DB_NAME;
            } else {
                throw new MissingDatabaseNameException("Missing database name.");
            }
        }
    }

    /*
        2.0 Connections
    */
    /**
     * Connect to the database, returns true on success, false on fail
     *
     * @return bool
     */
    protected function connect(): bool
    {
        // existing connection
        if ($this->check_connection()) {
            return true;
        }

        // MySQL Database
        try {
            $this->pdo = new PDO(
                "mysql:host=" . $this->host .
                ";dbname=" . $this->name . "",
                $this->user,
                $this->pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT => true,
                    PDO::ATTR_EMULATE_PREPARES => true, // off for :named placeholders
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new NestboxException($e->getMessage());
        }

        // successful connection
        return true;
    }

    /**
     * Check if a connection exists
     *
     * @return bool
     */
    protected function check_connection(): bool
    {
        if (empty($this->pdo)) {
            // no connection
            return false;
        } else {
            // test existing connection
            $this->prep("SELECT 1");
            $this->execute();
            $rows = $this->results();

            // check test results
            if (1 === $rows[0]['1']) {
                // good connection
                return true;
            } else {
                // kill dead connection
                $this->close();
                return false;
            }
        }
    }

    /**
     * Close an existing connection
     *
     * @return bool
     */
    protected function close(): bool
    {
        // https://www.php.net/manual/en/pdo.connections.php
        // "To close the connection, you need to destroy the object"
        $this->pdo = null;
        unset($this->pdo);
        if (empty($this->pdo)) {
            return true;
        } else {
            return false;
        }
    }

    /*
        3.0 Queries
    */

    /**
     * Execute a query against the database
     *
     * @param string $query
     * @param array $params
     * @param bool $close
     * @return bool
     */
    public function query_execute(string $query, array $params = [], bool $close = false): bool
    {
        // verify query isn't empty
        if (empty(trim($query))) throw new EmptyQueryException("Empty MySQL query provided.");

        // verify parameters
        $params = (!empty($params)) ? $this->verify_parameters($query, $params) : null;

        // connect to database
        $this->connect();

        // prepare statement
        $this->prep($query, $params);

        // bind parameters
        if (!empty($params)) {
            foreach ($params as $var => $val) {
                $this->bind($var, $val);
            }
        }

        // execute
        if ($this->execute()) {
            if ($close) $this->close();
            return true;
        } else {
            if ($close) $this->close();
            return false;
        }
    }

    /**
     * Verify/sanitize the parameters passed for a given query
     *
     * @param string $query
     * @param array $params
     * @return array
     */
    protected function verify_parameters(string $query, array $params): array
    {
        foreach ($params as $var => $val) {
            // remove unwanted parameters
            if (!strpos($query, ":{$var}")) {
                unset($params[$var]);
            }
        }
        return $params;
    }

    /**
     * Prepare a query into a statement
     *
     * @param string $query
     * @param array|null $params
     * @return bool
     */
    protected function prep(string $query, array $params = null): bool
    {
        if (empty($params) || null == $params) {
            // prepare a statement
            $this->stmt = $this->pdo->prepare($query);
        } else {
            // prepare a statement with parameters
            $this->stmt = $this->pdo->prepare($query, $params);
        }
        return true;
    }

    /**
     * Bind a variable to a paramters
     *
     * @param $variable
     * @param $value
     * @return bool
     */
    protected function bind($variable, $value): bool
    {
        // set binding type
        if (is_int($value)) { // int
            $type = PDO::PARAM_INT;
        } elseif (is_bool($value)) { // boolean
            $type = PDO::PARAM_BOOL;
        } elseif (is_null($value)) { // null
            $type = PDO::PARAM_NULL;
        } elseif (is_array($value)) { // array
            throw new CannotBindArrayException("Cannot bind array type to :{$variable}");
        } else { // string
            $type = PDO::PARAM_STR;
        }

        // backwards compatibility; older versions require colon prefix where newer versions do not
        if (':' != substr($variable, 0, 1)) {
            $variable = ":{$variable}";
        }

        // bind value to parameter
        if (true === $this->stmt->bindValue($variable, $value, $type)) {
            return true;
        } else {
            throw new FailedToBindValueException("Failed to bind '{$value}' to :{$variable} ({$type})");
        }
    }

    /**
     * Execute a statement
     *
     * @return bool
     */
    protected function execute(): bool
    {
        // execute query
        try {
            if (!$this->stmt->execute()) {
                $error = $this->stmt->errorInfo();
                throw new QueryErrorException("MySQL error {$error[1]}: {$error[2]} ({$error[0]})");
            }
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            throw new PDOException("PDO Exception: {$msg}");
        }
        return true;
    }

    /*
        4.0 Results
    */

    /**
     * Return resulting rows from a query; optionally, return only the first
     * row of _depricated_data when only one row is expected
     *
     * @param bool $firstResultOnly
     * @return array
     */
    public function results(bool $firstResultOnly = false): array
    {
        // get result set
        $rows = $this->stmt->fetchAll();
        return true === $firstResultOnly && !empty($rows) ? $rows[0] : $rows;
    }

    /**
     * Return the row count from the most recent query
     * @return int
     */
    public function row_count(): int
    {
        // get row count
        return $this->stmt->rowCount();
    }

    /**
     * Get the row ID of the last insert of the active connection
     *
     * @return string
     */
    public function last_insert_id(): string
    {
//			 $sql = "SELECT LAST_INSERT_ID();";
//			 $this->query_execute( $sql );
//			 return $this->results();
//			return $this->lastInsertId;
        // get last id
        return $this->pdo->lastInsertId();
    }

    /**
     * Return resulting rows as key pairs
     *
     * @return array
     */
    public function key_pair(): array
    {
        return $this->stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /*
        5.0 Transactions
    */
    /**
     * Pass an array of SQL queries and perform a transaction with them
     *
     * @param array $queries
     * @return array
     */
    public function transaction_execute(array $queries): array
    {
        try {
            // connect to database
            $this->connect();

            // start transaction if not already in progress
            if ($this->pdo->inTransaction()) {
                throw new TransactionInProgressException("Unable to start new transaction while one is already in progress.");
            } else {
                $this->pdo->beginTransaction();
            }

            // perform transaction
            $results = [];
            foreach ($queries as $query => $params) {
                // prepare query
                $this->prep($query, $params);

                // bind parameters
                if (!empty($params)) {
                    foreach ($params as $var => $val) {
                        $this->bind($var, $val);
                    }
                }

                if ($this->execute()) {
                    $results[] = [
                        'rows' => $this->results(),
                        'row_count' => $this->row_count(),
                        'last_id' => $this->last_id(),
                    ];
                }
            }

            // commit the transaction and return any results
            if ($this->pdo->commit()) {
                return $results;
            } else {
                throw new TransactionCommitFailedException("Failed to commit transaction.");
            }
        } catch (\Exception $e) {
            // Oh no! Roll back database and re-throw the error
            $this->pdo->rollback();
            throw new TransactionException($e->getMessage());
        }
    }

    /**
     * Use a single query to perform an incremental transaction
     *
     * @param string $query
     * @param array $params
     * @param bool $commit
     * @param bool $close
     * @return mixed
     */
    public function transaction(string $query, array $params, bool $commit = false, bool $close = false): mixed
    {
        try {
            // start transaction if not already in progress
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                if (!$this->pdo->inTransaction()) {
                    // couldn't start a transaction
                    throw new TransactionBeginFailedException("Failed to begin new transaction.");
                }
            }

            // perform single query for the transaction
            if ($this->execute($query, $params)) {
                $results = [
                    'rows' => $this->results(),
                    'row_count' => $this->row_count(),
                    'last_id' => $this->last_id(),
                ];
            }

            // commit the transaction and return any results
            if (true === $commit) {
                if ($this->pdo->commit()) {
                    // commit the transaction and return the results
                    return $results;
                } else {
                    throw new TransactionCommitFailedException("Failed to commit transaction.");
                }
            } else {
                // return the query results but leave transaction in progress
                return $results;
            }
        } catch (\Exception $e) {
            // oh no! roll back database and re-throw whatever fun error was encountered
            if (!$this->rollback()) {
                // we're really not having a good day today are we...
                throw new TransactionRollbackFailedException($e->getMessage() . " -- AND -- Failed to rollback database transaction.");
            }
            throw new TransactionException($e->getMessage());
        }
    }

    public function rollback(): bool
    {
        if ($this->pdo->inTransaction()) {
            if ($this->pdo->rollback()) {
                return true;
            }
        }
        return false;
    }

    /*
        6.0 Quick Queries
    */
    public function insert(string $table, array $params, bool $update = true): int
    {
        // verify table
        if (!$this->valid_schema($table)) {
            throw new InvalidTableException("Cannot insert into invalid table: {$table}");
        }

        // verify columns
        if (!empty($params)) {
            foreach ($params as $col => $val) {
                if (!is_array($val)) {
                    // inserting a single row
                    if (!$this->valid_schema($table, $col)) {
                        throw new InvalidColumnException("Cannot insert into invalid column: {$table}.{$col}");
                    }
                    $insertMode = "single";
                } else {
                    // inserting multiple rows
                    foreach ($val as $c => $v) {
                        if (!$this->valid_schema($table, $c)) {
                            throw new InvalidColumnException("Cannot insert into invalid column: {$table}.{$col}");
                        }
                    }
                    $insertMode = "many";
                }
            }
        }

        if (!isset($insertMode)) {
            var_dump($params);
            die;
        }

        // prepare variables
        if ("single" == $insertMode) {
            $columns = array_keys($params);
            $cols = (count($params) > 1) ? implode("`,`", $columns) : current($columns);
            $vars = (count($params) > 1) ? implode(", :", $columns) : current($columns);
        } elseif ("many" == $insertMode) {
            $vars = [];
            $p = [];
            foreach ($params as $i => $row) {
                $columns = array_keys($row);
                $cols = (count($row) > 1) ? implode("`,`", $columns) : current($columns);
                $vars[] = (count($row) > 1) ? implode("_{$i}, :", $columns) . "_{$i}" : current($columns);
                foreach ($row as $c => $v) {
                    $p["{$c}_{$i}"] = $v;
                }
            }
            $vars = implode("),(:", $vars);
        }

        // create base query
        $query = "INSERT INTO `{$table}` (`{$cols}`) VALUES (:{$vars})";

        // add update
        if (true === $update) {
            $updates = [];
            $priKey = $this->table_primary_key($table);
            if ("single" == $insertMode) {
                foreach ($params as $col => $val) {
                    if ($col != $priKey) {
                        $updates[] = "`{$col}` = :{$col}";
                    }
                }
                $query .= " ON DUPLICATE KEY UPDATE " . implode(",", $updates);
            } elseif ("many" == $insertMode) {
                $updates = [];
                foreach ($params[0] as $col => $val) {
                    if ($col != $priKey) {
                        $updates[] = "`{$table}`.`{$col}` = `new`.`{$col}`";
                    }
                }
                $query .= " AS `new` ON DUPLICATE KEY UPDATE " . implode(",", $updates);
            }
        }

        $params = ("many" == $insertMode) ? $p : $params;

        // execute query
        if ($this->query_execute($query, $params)) {
            return $this->row_count();
        } else {
            return 0;
        }
    }

    public function update(string $table, array $params, array $where, string $conjunction = "AND"): int
    {
        // verify table
        if (!$this->valid_schema($table)) {
            throw new NestboxException\InvalidTable("Cannot update invalid table: {$table}");
        }

        // parse updates
        $updates = [];
        foreach ($params as $col => $val) {
            if (!$this->valid_schema($table, $col)) {
                throw new InvalidColumnException("Cannot update invalid column: {$table}.{$col}");
            } else {
                $updates[] = "`{$col}` = :{$col}";
            }
        }
        $updates = implode(",", $updates);

        // define where keys
        $wheres = [];
        foreach ($where as $col => $val) {
            if ($this->valid_schema($table, $col)) {
                $wheres[] = "`{$col}` = :{$col}";
            }
        }
        $conjunction = (in_array($conjunction, ["AND", "OR"])) ? $conjunction : "AND";
        $wheres = implode(" {$conjunction} ", $wheres);

        // compile query
        $query = "UPDATE `{$table}` SET {$updates} WHERE {$wheres};";
        var_dump($query);

        // execute
        $this->query_execute($query, $params);
        return $this->row_count();
    }

    public function delete(string $table, array $where, $conjunction = "AND"): int
    {
        // verify table
        if (!$this->valid_schema($table)) {
            throw new NestboxException\InvalidTable("Cannot delete from invalid table: {$table}");
        }

        // parse columns
        $wheres = [];
        $params = [];
        foreach ($where as $col => $val) {
            if (!$this->valid_schema($table, $col)) {
                throw new InvalidColumnException("Cannot delete from invalid column: {$table}.{$col}");
            } else {
                $where[] = "`{$col}` = :{$col}";
                $params[$col] = $val;
            }
        }
        $conjunction = (in_array($conjunction, ["AND", "OR"])) ? $conjunction : "AND";
        $wheres = implode(" {$conjunction} ", $wheres);

        // compile query
        $query = "DELETE FROM `{$table}` WHERE {$wheres};";

        // execute
        $this->query_execute($query, $params);
        return $this->row_count();
    }

    public function select(string $table, array $where, string $conjunction = "AND"): array
    {
        if (!$this->valid_schema($table)) {
            throw new InvalidTableException();
        }

        $wheres = [];
        $params = [];
        foreach ($where as $col => $val) {
            if (!$this->valid_schema($table, $col)) {
                throw new InvalidColumnException();
            } else {
                $wheres[] = "`{$col}` = {$col}";
                $params[$col] = $val;
            }
        }
        $wheres = implode($conjunction, $wheres);

        $sql = "SELECT * FROM `{$table}` WHERE {$wheres}";
        $this->query_execute($sql, $params);
        return $this->results();
    }

    /*
        7.0 Schema
    */

    public function load_table_schema(): bool
    {
        $params = array();

        $sql = "SELECT `TABLE_NAME`,`COLUMN_NAME`,`DATA_TYPE`
					FROM `INFORMATION_SCHEMA`.`COLUMNS`
					WHERE `TABLE_SCHEMA` = :database_name;";

        if (!$this->query_execute($sql, ['database_name' => $this->name])) {
            return false;
        }

        foreach ($this->results() as $row) {
            $this->tableSchema[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row['DATA_TYPE'];
        }

        return true;
    }

    /**
     * Determine if a given table/column combination exists
     * within the database schema
     *
     * @param string $tbl
     * @param string|null $col
     * @return bool
     */
    public function valid_schema(string $tbl, string $col = null): bool
    {
        // load database schema:
        // 		SELECT `TABLE_NAME`,`COLUMN_NAME`,`DATA_TYPE`
        // 		FROM `INFORMATION_SCHEMA`.`COLUMNS`
        // 		WHERE `TABLE_SCHEMA` = :database_name;
        $this->load_table_schema();

        $tbl = (!empty($tbl)) ? trim($tbl) : $tbl;
        $col = (!empty($col)) ? trim($col) : $col;

        // check table
        if (!array_key_exists($tbl, $this->tableSchema)) {
            return false;
        } elseif (is_null($col)) {
            return true;
        }

        // check column
        if (!array_key_exists($col, $this->tableSchema[$tbl])) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Determine if a table exists within the database
     *
     * @param string $table
     * @return bool
     */
    public function valid_table(string $table): bool
    {
        if (empty($this->tableSchema)) {
            $this->load_table_schema();
        }

        if (array_key_exists($table, $this->tableSchema)) {
            return true;
        } else {
            // reload in case schema has changed since last load
            $this->load_table_schema();
            if (array_key_exists($table, $this->tableSchema)) {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Determine if a column exists within a given table
     *
     * @param string $table
     * @param string $column
     * @return bool
     */
    public function valid_column(string $table, string $column): bool
    {
        if (!$this->valid_schema($table)) {
            return false;
        }

        if (array_key_exists($column, $this->tableSchema[$table])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get primary key for a given table within the database
     *
     * @param string $table
     * @return string
     */
    public function table_primary_key(string $table): string
    {
        if (!$this->valid_schema($table)) {
            throw new NestboxException\InvalidTable("Cannot get primary key of invalid table: {$table}");
        }

        $sql = "SHOW KEYS FROM `{$table}` WHERE `Key_name` = 'PRIMARY';";
        if ($this->query_execute($sql)) {
            $rows = $this->results(true);
            return $rows["Column_name"];
        } else {
            return "";
        }
    }

    /*
        Other
    */
    /**
     * Generate HTML code for a two-dimensional array
     *
     * @param string $table
     * @param string $tblClass
     * @param array $colClass
     * @return string
     */
    public static function html_table(array $table, string $tblClass = "", array $colClass = []): string
    {
        // table start
        $code = "";
        $code .= "<table class='{$tblClass}'>";

        // add headers
        $hdrs = "";
        foreach ($table[0] as $col => $data) {
            $class = (array_key_exists($col, $colClass)) ? "class='{$colClass[$col]}'" : "";
            $hdrs .= "<th {$class}>{$col}</th>";
        }
        $code .= "<tr>{$hdrs}</tr>";

        // add _depricated_data
        foreach ($table as $tblRow) {
            $row = "";
            foreach ($tblRow as $col => $val) {
                $class = (array_key_exists($col, $colClass)) ? "class='{$colClass[$col]}'" : "";
                $row .= "<td {$class}>{$val}</td>";
            }
            $code .= "<tr>{$row}</tr>";
        }

        // table end
        $code .= "</table>";
        return $code;
    }
}
