<?php
namespace DB\Drivers;

use PDO;
use PDOException;
use Exception;
use DB\ORM\Grammar;
use DB\ORM\PostgreSQLGrammar;

class PostgreSQLDriver extends SQLDriver
{
    /* -------------------------------------------------
     * Connection
     * ------------------------------------------------- */

    public function grammar(): Grammar
    {
        return new PostgreSQLGrammar();
    }

    public function connect(): void 
    {
        if (isset($this->pdo) && $this->pdo !== null) {
            return;
        }

        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 5432;
        $db   = $this->config['database'] ?? null;
        $user = $this->config['user'] ?? null;
        $pass = $this->config['password'] ?? null;

        if (!$db || !$user) {
            throw new Exception('PostgreSQL config requires database and user');
        }

        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

        try {
            $this->pdo = new PDO(
                $dsn,
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            $this->log("Connected to PostgreSQL {$db}@{$host}:{$port}");
        } catch (PDOException $e) {
            throw new Exception(
                "PostgreSQL connection failed ({$user}@{$host}:{$port}/{$db}): " . $e->getMessage()
            );
        }
    }

    public function setDatabase(string $dbName): void 
    {
        $this->config['database'] = $dbName;
        $this->pdo = null; 
    }

    /* -------------------------------------------------
     * Schema & Introspection
     * ------------------------------------------------- */

    /**
     * Introspects a PostgreSQL database instance and lists all available tables
     */
    public function getTables(string $database = ''): array
    {
        $pdo = $this->getPDO(); // Safely triggers connect() internally
        
        try {
            $stmt = $pdo->prepare("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = 'public' 
                  AND table_type = 'BASE TABLE'
                ORDER BY table_name
            ");
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            if (method_exists($this, 'log')) {
                $this->log("Postgres introspection engine failure: " . $e->getMessage());
            }
            return [];
        }
    }

    /**
     * Reconfigured clean schema access contract
     */
    public function getSchema(): array
    {
        return $this->getTables();
    }

    /**
     * 🎯 FIX: Added PostgreSQL DDL Schema generator for Postgres-to-Postgres mirroring
     */
    public function getTableSchemaSQL(string $table, string $targetDialect = 'pgsql'): string
    {
        $pdo = $this->getPDO();

        // Query the information_schema columns data for this specific table
        $stmt = $pdo->prepare("
            SELECT column_name, data_type, character_maximum_length, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = :table
            ORDER BY ordinal_position
        ");
        $stmt->execute(['table' => $table]);
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($columns)) {
            throw new Exception("Failed to extract schema metadata for table [{$table}].");
        }

        $lines = [];
        foreach ($columns as $col) {
            $name = $col['column_name'];
            $type = strtoupper($col['data_type']);
            $null = $col['is_nullable'] === 'NO' ? 'NOT NULL' : 'NULL';
            $default = '';

            // Map data length constraints if present
            if ($col['character_maximum_length']) {
                $type .= "(" . $col['character_maximum_length'] . ")";
            }

            // Clean up sequence mappings to use SERIAL cleanly on target migrations
            if ($col['column_default'] && str_contains($col['column_default'], 'nextval')) {
                $type = str_contains($type, 'BIGINT') ? 'BIGSERIAL' : 'SERIAL';
                $null = ''; // SERIAL implicitly manages constraints
            } elseif ($col['column_default']) {
                $default = "DEFAULT " . $col['column_default'];
            }

            $lines[] = "    \"{$name}\" {$type} {$null} {$default}";
        }

        // Fetch Primary Key Constraints
        $pkStmt = $pdo->prepare("
            SELECT c.column_name
            FROM information_schema.table_constraints tc 
            JOIN information_schema.key_column_usage c ON tc.constraint_name = c.constraint_name
            WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_name = :table
        ");
        $pkStmt->execute(['table' => $table]);
        $pks = $pkStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($pks)) {
            $quotedPks = array_map(fn($col) => "\"{$col}\"", $pks);
            $lines[] = "    PRIMARY KEY (" . implode(', ', $quotedPks) . ")";
        }

        return "CREATE TABLE \"{$table}\" (\n" . implode(",\n", $lines) . "\n);";
    }

    public function backup(
        string $path,
        bool $encrypt = false,
        ?string $key = null,
        ?string $iv = null
    ): bool|string {
        $data = [];

        foreach ($this->getSchema() as $table) {
            $data[$table] = $this->getPDO()->query("SELECT * FROM \"{$table}\"")->fetchAll(PDO::FETCH_ASSOC);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT);

        if ($encrypt) {
            if (!$key || !$iv) {
                throw new Exception('Encryption key and IV required for encrypted backup');
            }
            $json = $this->encryptData($json, $key, $iv);
        }

        if (file_put_contents($path, $json) === false) {
            throw new Exception("Failed to write PostgreSQL backup to {$path}");
        }

        $this->log("PostgreSQL backup written to {$path}" . ($encrypt ? ' (encrypted)' : ''));

        return true;
    }

    public function getAttribute($attribute)
    {
        if (!$this->pdo) {
            $this->connect();
        }
        return $this->pdo->getAttribute($attribute);
    }

    /* -------------------------------------------------
     * Structural Drivers
     * ------------------------------------------------- */

    public function query(string $sql, array $params = []): mixed
    {
        return parent::query($sql, $params);
    }

    public function listTables(string $database): array
    {
        return $this->getSchema();
    }

    public function listDatabases(): array
    {
        // 🎯 FIX: Changed standard custom fetchAll calls to clean PDO statements to avoid abstract dependencies
        $this->connect();
        $stmt = $this->pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false");
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'datname');
    }

    /* -------------------------------------------------
     * Transactions
     * ------------------------------------------------- */

    public function begin(): bool
    {
        $this->connect(); 
        if (!$this->pdo->inTransaction()) {
            return $this->pdo->beginTransaction();
        }
        return true; 
    }

    public function rollback(): bool
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }
        return false;
    }

    public function commit(): bool
    {
        if ($this->pdo && $this->pdo->inTransaction()) {
            return $this->pdo->commit();
        }
        return false;
    }

    public function lastInsertId($name = null): string|int 
    {
        return $this->pdo->lastInsertId($name);
    }

    public function inTransaction(): bool
    {
        if (!$this->pdo) {
            return false;
        }
        return $this->pdo->inTransaction();
    }

    /* -------------------------------------------------
     * Core Pipeline Utilities
     * ------------------------------------------------- */

    public function getPDO(): PDO
    {
        $this->connect();
        return $this->pdo;
    }

    public function prepare(string $sql): \PDOStatement
    {
        $this->connect();
        return $this->pdo->prepare($sql);
    }

    public function getTableRecords(string $database, string $table, int $limit = 50): array 
    {
        $this->connect(); 
        $sql = "SELECT * FROM \"$table\" LIMIT :limit";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->log("Error fetching records from $table: " . $e->getMessage());
            return [];
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
        return $this->pdo->prepare($sql)->execute($values);
    }

    public function insert(string $table, array $data): bool 
    {
        $this->connect();
        $quotedColumns = array_map(fn($col) => "\"$col\"", array_keys($data));
        $columns = implode(', ', $quotedColumns);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO \"$table\" ($columns) VALUES ($placeholders)";
        
        try {
            return $this->pdo->prepare($sql)->execute(array_values($data));
        } catch (PDOException $e) {
            $this->log("Insert failed into $table: " . $e->getMessage());
            return false;
        }
    }
}