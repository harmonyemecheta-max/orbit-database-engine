<?php
namespace DB\Drivers;

use PDO;
use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;
use DB\Drivers\DBDriverInterface;
use DB\ORM\Grammar;
use DB\ORM\MySQLGrammar;


class MSSQLDriver extends SQLDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    /* -------------------------------------------------
     * Connection
     * ------------------------------------------------- */

    public function grammar(): Grammar
    {
        return new MySQLGrammar();
    }


    public function connect(): void
    {
        $host   = $this->config['host']     ?? '127.0.0.1';
        $port   = $this->config['port']     ?? 1433;
        $dbname = $this->config['dbname']   ?? '';
        $user   = $this->config['username'] ?? ($this->config['user'] ?? '');
        $pass   = $this->config['password'] ?? ($this->config['pass'] ?? '');

        $dsn = "sqlsrv:Server={$host},{$port};Database={$dbname}";

        $this->pdo = new PDO(
            $dsn,
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE                         => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE              => PDO::FETCH_ASSOC,
                PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE     => true,
            ]
        );

        $this->log("Connected to MSSQL: {$user}@{$host}:{$port} DB:{$dbname}");
    }

    public function getSchema(): array
    {
        $sql = "
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_TYPE = 'BASE TABLE';
        ";
        return $this->fetchAll($sql);
    }

    public function backup(
        string $path,
        bool $encrypt = false,
        ?string $key = null,
        ?string $iv = null
    ): bool|string
    {
        $tables = $this->getSchema();
        $dump   = [];

        foreach ($tables as $tbl) {
            $name = $tbl['table_name'];
            $rows = $this->fetchAll("SELECT * FROM [{$name}]");
            $dump[$name] = $rows;
        }

        $json = json_encode($dump, JSON_PRETTY_PRINT);

        if ($encrypt && $key && $iv) {
            $json = $this->encryptData($json, $key, $iv);
        }

        file_put_contents($path, $json);
        $this->log("MSSQL backup saved to {$path}" . ($encrypt ? " (encrypted)" : ""));

        return true;
    }

    public function listDatabases(): array
    {
        $rows = $this->fetchAll("SELECT name FROM sys.databases");
        return array_map(fn ($r) => $r['name'], $rows);
    }

    public function listTables(string $database): array
    {
        $sql = "
            SELECT TABLE_NAME
            FROM [{$database}].INFORMATION_SCHEMA.TABLES
        ";
        $rows = $this->fetchAll($sql);
        return array_map(fn ($r) => $r['table_name'], $rows);
    }

    public function getTableRecords(string $database, string $table, int $limit = 50): array
    {
        $sql = "SELECT TOP({$limit}) * FROM [{$table}]";
        return $this->fetchAll($sql);
    }

    public function createDatabase(string $dbName): bool
    {
        $sql = "
            IF NOT EXISTS (
                SELECT name FROM sys.databases WHERE name = '{$dbName}'
            )
            CREATE DATABASE [{$dbName}];
        ";
        $this->query($sql);
        return true;
    }

/**
 * Sets the active database name and returns the instance
 */
// Inside MySQLDriver.php
/**
 * Sets the active database name. 
 * Interface says 'void', so we cannot 'return $this'.
 */
// 1. Correct the setDatabase implementation for MSSQL syntax
public function setDatabase(string $dbName): void
{
    $this->config['dbname'] = $dbName;
    if ($this->pdo) {
        $this->pdo->exec("USE [{$dbName}]");
    }
}

// 2. Add missing insert helper using MSSQL escape tokens
public function insert(string $table, array $data): bool 
{
    $columns = implode(', ', array_map(fn($c) => "[{$c}]", array_keys($data)));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    
    $sql = "INSERT INTO [{$table}] ({$columns}) VALUES ({$placeholders})";
    return $this->getPDO()->prepare($sql)->execute(array_values($data));
}

/**
 * Returns the PDO connection instance.
 */
public function getPDO(): PDO
{
    // If PDO is null, we need to connect first to satisfy the PDO return type
    if (!$this->pdo) {
        $this->connect();
    }
    return $this->pdo;
}


    public function createTable(string $database, string $tableName, array $columns): bool
    {
        $cols = [];
        foreach ($columns as $col => $type) {
            $cols[] = "[{$col}] {$type}";
        }

        $sql = "CREATE TABLE [{$tableName}] (" . implode(',', $cols) . ")";
        $this->query($sql);

        return true;
    }
}
