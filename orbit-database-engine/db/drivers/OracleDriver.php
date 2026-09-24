<?php
namespace DB\Drivers;

use PDO;
use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;
use DB\ORM\Grammar;
use DB\ORM\OracleGrammar;   // ← THIS LINE FIXES EVERYTHING



class OracleDriver extends SQLDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    public function grammar(): Grammar
    {
        return new OracleGrammar();
    }

    public function connect(): void
    {
        $host   = $this->config['host'] ?? '127.0.0.1';
        $port   = $this->config['port'] ?? 1521;
        $sid    = $this->config['sid'] ?? null;
        $service = $this->config['service_name'] ?? null;

        // Build TNS string
        if ($service) {
            $tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=$host)(PORT=$port))(CONNECT_DATA=(SERVICE_NAME=$service)))";
        } else {
            $tns = "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST=$host)(PORT=$port))(CONNECT_DATA=(SID=$sid)))";
        }

        $dsn = "oci:dbname={$tns}";

        $this->pdo = new PDO(
            $dsn,
            $this->config['username'],
            $this->config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_CASE => PDO::CASE_LOWER,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );

        $this->log("Connected to Oracle Database at {$host}:{$port}");
    }

    public function getSchema(): array
    {
        $sql = "SELECT table_name FROM user_tables ORDER BY table_name";
        return $this->fetchAll($sql);
    }

    public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string
    {
        $schema = $this->getSchema();
        $data = [];

        foreach ($schema as $tbl) {
            $table = $tbl['table_name'];
            $data[$table] = $this->fetchAll("SELECT * FROM {$table}");
        }

        $json = json_encode($data, JSON_PRETTY_PRINT);

        if ($encrypt && $key && $iv) {
            $json = $this->encryptData($json, $key, $iv);
        }

        file_put_contents($path, $json);
        $this->log("Oracle backup saved to {$path}");

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
// 1. Adjust setDatabase to accurately reflect Oracle's session context changing 
public function setDatabase(string $dbName): void
{
    $this->config['dbname'] = $dbName;
    if ($this->pdo) {
        $this->pdo->exec("ALTER SESSION SET CURRENT_SCHEMA = " . strtoupper($dbName));
    }
}

// 2. Add missing insert helper formatted for standard Oracle identifiers
public function insert(string $table, array $data): bool 
{
    // Oracle columns are safer normalized or wrapped cleanly in double quotes if case-sensitive
    $columns = implode(', ', array_map(fn($c) => "\"".strtoupper($c)."\"", array_keys($data)));
    $placeholders = implode(', ', array_map(fn($k) => ":{$k}", array_keys($data)));
    
    $sql = "INSERT INTO \"".strtoupper($table)."\" ({$columns}) VALUES ({$placeholders})";
    
    $stmt = $this->getPDO()->prepare($sql);
    // Combine parameters safely since Oracle relies heavily on named parameters via PDO Statement bindings
    $binds = [];
    foreach ($data as $key => $value) {
        $binds[":{$key}"] = $value;
    }
    return $stmt->execute($binds);
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

    public function listDatabases(): array
    {
        return [$this->config['username']];
    }

    public function listTables(string $database): array
    {
        return array_map(fn($t) => $t['table_name'], $this->getSchema());
    }

    public function getTableRecords(string $database, string $table, int $limit = 50): array
    {
        $sql = "SELECT * FROM {$table} WHERE ROWNUM <= :lim";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':lim' => $limit]);

        return $stmt->fetchAll();
    }

    public function createDatabase(string $dbName): bool
    {
        // Oracle doesn't support CREATE DATABASE at user level
        return true;
    }

    public function createTable(string $database, string $tableName, array $columns): bool
    {
        $cols = [];
        foreach ($columns as $col => $type) {
            $cols[] = "{$col} {$type}";
        }

        $sql = "CREATE TABLE {$tableName} (" . implode(', ', $cols) . ")";
        $this->query($sql);

        return true;
    }
}
