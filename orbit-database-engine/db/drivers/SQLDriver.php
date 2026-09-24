<?php
namespace DB\Drivers;

use PDO;
use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;
use DB\ORM\QueryBuilder;

abstract class SQLDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    protected ?PDO $pdo = null;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

/**
     * Start a query on a specific table using the QueryBuilder.
     * This fixes the Fatal Error by allowing the driver to initialize the ORM.
     * To understand why this fixed the error, here is a breakdown of the relationship between your classes:
     * DBManager: Finds the right configuration and returns a PostgreSQLDriver.
     * PostgreSQLDriver: Now has a table() method (via inheritance from SQLDriver).
     * QueryBuilder: Is created and receives the driver instance to handle the actual prepare() and execute() calls later on.
     */
public function table(string $table): QueryBuilder
    {
        // We pass $this (the current driver instance) into the QueryBuilder
        $builder = new QueryBuilder($this);
        return $builder->table($table);
    }


    abstract public function connect(): void;


protected function bindAndExecute(\PDOStatement $stmt, array $bindings): void
{
    // Detect if numeric keys → positional binding
    $numericKeys = array_keys($bindings) === range(0, count($bindings) - 1);

    foreach ($bindings as $key => $value) {
        if ($numericKeys) {
            // Positional binding (already works with ? placeholders)
            $param = $key + 1;
        } else {
            // Named placeholder (must start with colon)
            $param = str_starts_with($key, ':') ? $key : ':' . $key;
        }

        if ($value === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
        } elseif (is_bool($value)) {
            $stmt->bindValue($param, $value, PDO::PARAM_BOOL);
        } elseif (is_int($value)) {
            $stmt->bindValue($param, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($param, $value, PDO::PARAM_STR);
        }
    }

    $stmt->execute();
}



public function query(string $sql, array $params = []): mixed
{
    $this->connect();

    // If we are doing a structural change (ALTER/CREATE/DROP), 
    // we should ensure it's not buried in a dead-lock transaction.
    $isStructural = preg_match('/^(ALTER|CREATE|DROP|RENAME)/i', trim($sql));

    $stmt = $this->pdo->prepare($sql);

    $this->bindAndExecute($stmt, $params);

    $this->log("Executed SQL: $sql", ['params' => $params]);

    return $stmt;
}

public function fetch(string $sql, array $params = []): ?array
{
    $stmt = $this->query($sql, $params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result ?: null;
}


public function fetchOne(string $sql, array $bindings = []): ?array
{
    $stmt = $this->query($sql, $bindings);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

public function execute(string $sql, array $bindings = []): int
{
    $stmt = $this->query($sql, $bindings);
    return $stmt->rowCount();
}


    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    abstract public function getSchema(): array;
    abstract public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string;

    // Stubs for NoSQL
    public function findAll(string $collection): array { return []; }
    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool { return false; }
    public function import(array $data): bool { return false; }
    public function listDatabases(): array { return []; }
    public function listTables(string $database): array { return []; }
    public function getTableRecords(string $database, string $table, int $limit = 50): array { return []; }
    public function createDatabase(string $dbName): bool { return false; }
    public function createTable(string $database, string $tableName, array $columns): bool { return false; }

    public function update(string $table, array $data, array $where): bool
    {
        $this->connect();
        $fields = [];
        $values = [];

        foreach ($data as $column => $value) {
            // CRITICAL: Skip 'id' in the SET clause. 
            // This prevents "Typed property App::$id must not be accessed" errors 
            // and prevents trying to update Primary Keys.
            if (strtolower($column) === 'id') continue;

            $fields[] = "\"$column\" = ?";
            // Convert empty strings or 'NULL' strings to actual PHP nulls
            $values[] = ($value === 'NULL' || $value === '') ? null : $value;
        }

        $whereClauses = [];
        foreach ($where as $column => $value) {
            $whereClauses[] = "\"$column\" = ?";
            $values[] = $value;
        }

        if (empty($fields)) return false;

        $sql = "UPDATE \"$table\" SET " . implode(', ', $fields) . " WHERE " . implode(' AND ', $whereClauses);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($values);
        } catch (\PDOException $e) {
            $this->log("Update failed on $table: " . $e->getMessage());
            return false;
        }
    }

    
    public function delete(string $table, array $where): bool
    {
        $this->connect();
        $clauses = [];
        $values = [];

        foreach ($where as $column => $value) {
            $clauses[] = "\"$column\" = ?";
            $values[] = $value;
        }

        $sql = "DELETE FROM \"$table\" WHERE " . implode(' AND ', $clauses);
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($values);
    }

    // Inside SQLDriver.php
    // Inside SQLDriver.php
    public function insert(string $table, array $data): bool 
    {
        $this->connect();

        // Map keys to escaped double quotes for Oracle/Postgres case-sensitivity
        $columns = implode(', ', array_map(fn($c) => "\"$c\"", array_keys($data)));
        
        // Create positional placeholders (?)
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO \"$table\" ($columns) VALUES ($placeholders)";
        
        // Convert 'NULL' strings back to actual PHP nulls
        $values = array_map(fn($v) => ($v === 'NULL' || $v === '') ? null : $v, array_values($data));

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($values);
        } catch (\PDOException $e) {
            // Log the error via your LoggerTrait
            $this->log("Insert failed on $table: " . $e->getMessage());
            throw $e; 
        }
    }

    public function begin(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollBack(): bool { return $this->pdo->rollBack(); }

    public function lastInsertId(?string $name = null): string|int
    {
        return $this->pdo->lastInsertId($name);
    }






    
}
