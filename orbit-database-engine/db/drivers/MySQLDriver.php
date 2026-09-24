<?php
namespace DB\Drivers;

use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;
use PDO;
use Exception;
use DB\ORM\Grammar;
use DB\ORM\MySQLGrammar;



class MySQLDriver extends SQLDriver
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
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 3306;
        $dbname = $this->config['dbname'] ?? '';
        $user = $this->config['user'] ?? $this->config['username'] ?? 'root';
        $pass = $this->config['password'] ?? $this->config['pass'] ?? '';

        $charset = $this->config['charset'] ?? 'utf8mb4';
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Optional SSL parameters for Planetscale/Supabase
        if (!empty($this->config['ssl_ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $this->config['ssl_ca'];
        }
        if (!empty($this->config['ssl_cert'])) {
            $options[PDO::MYSQL_ATTR_SSL_CERT] = $this->config['ssl_cert'];
        }
        if (!empty($this->config['ssl_key'])) {
            $options[PDO::MYSQL_ATTR_SSL_KEY] = $this->config['ssl_key'];
        }

        $this->pdo = new PDO($dsn, $user, $pass, $options);
        $this->log("Connected to MySQL-compatible DB '{$dbname}' at {$host}:{$port}");
    }

    public function getSchema(): array
    {
        $tables = $this->fetchAll("SHOW TABLES");
        $result = [];
        foreach ($tables as $tblArr) {
            $result[] = array_values($tblArr)[0];
        }
        return $result;
    }

    public function listDatabases(): array
    {
        $rows = $this->fetchAll("SHOW DATABASES");
        return array_map(fn($r) => array_values($r)[0], $rows);
    }

    public function listTables(string $database): array
    {
        $this->pdo->exec("USE `$database`");
        return $this->getSchema();
    }

/**
     * Introspects the database cluster instance and lists all available tables
     */
    public function getTables(string $database): array
    {
        // Ensure we are connected to the instance
        if (method_exists($this, 'connect')) {
            $this->connect();
        }

        $pdo = $this->getPDO();
        
        try {
            // Using raw database quoting to prevent SQL injection during introspection
            $stmt = $pdo->prepare("SHOW TABLES FROM `" . str_replace('`', '``', $database) . "`");
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            if (method_exists($this, 'log')) {
                $this->log("Error introspecting tables for database {$database}: " . $e->getMessage());
            }
            return [];
        }
    }    

    public function getTableRecords(string $database, string $table, int $limit = 50): array
    {
        $this->pdo->exec("USE `$database`");
        return $this->fetchAll("SELECT * FROM `$table` LIMIT $limit");
    }

    public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string
    {
        $data = [];
        foreach ($this->getSchema() as $tbl) {
            $data[$tbl] = $this->fetchAll("SELECT * FROM `$tbl`");
        }

        $json = json_encode($data, JSON_PRETTY_PRINT);

        if ($encrypt && $key && $iv) {
            $json = $this->encryptData($json, $key, $iv);
        }

        if (file_put_contents($path, $json) === false) {
            $this->log("Failed to write backup to $path");
            return false;
        }

        $this->log("Backup saved to $path", ['encrypted' => $encrypt]);
        return true;
    }

    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool
    {
        return $this->backup($path, $encrypt, $key, $iv);
    }

    public function import(array $data): bool
    {
        foreach ($data as $table => $rows) {
            foreach ($rows as $row) {
                $columns = implode(',', array_map(fn($c) => "`$c`", array_keys($row)));
                $placeholders = implode(',', array_fill(0, count($row), '?'));
                $stmt = $this->pdo->prepare("INSERT INTO `$table` ($columns) VALUES ($placeholders)");
                $stmt->execute(array_values($row));
            }
        }
        $this->log("Data imported successfully");
        return true;
    }

    public function createDatabase(string $dbName): bool
    {
        $this->query("CREATE DATABASE IF NOT EXISTS `$dbName`");
        return true;
    }

    public function createTable(string $database, string $tableName, array $columns): bool
    {
        $this->pdo->exec("USE `$database`");
        $cols = [];
        foreach ($columns as $col => $type) {
            $cols[] = "`$col` $type";
        }
        $this->query("CREATE TABLE IF NOT EXISTS `$tableName` (" . implode(',', $cols) . ")");
        return true;
    }

// Replace your bottom methods with these:

/**
 * Sets the active database name and returns the instance
 */
// Inside MySQLDriver.php
/**
 * Sets the active database name. 
 * Interface says 'void', so we cannot 'return $this'.
 */
public function setDatabase(string $dbName): void
{
    $this->config['dbname'] = $dbName;
    if ($this->pdo) {
        $this->pdo->exec("USE `$dbName`");
    }
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

// Inside your MySQLDriver or similar class
public function insert(string $table, array $data): bool 
{
    $columns = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    
    $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
    return $this->getPDO()->prepare($sql)->execute(array_values($data));
}


/**
     * Introspects a MySQL table structure and converts it to a PostgreSQL DDL string
     */
    public function getTableSchemaSQL(string $table, string $targetDialect = 'pgsql'): string
    {
        if (method_exists($this, 'connect')) { $this->connect(); }
        $pdo = $this->getPDO();

        // 1. Fetch raw MySQL table structure
        $stmt = $pdo->prepare("SHOW CREATE TABLE `" . str_replace('`', '``', $table) . "`");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row || !isset($row['Create Table'])) {
            throw new Exception("Failed to extract schema metadata for table [{$table}].");
        }

        $createSQL = $row['Create Table'];

        if (strtolower($targetDialect) !== 'pgsql') {
            return $createSQL; // Return raw if no translation needed
        }

        // 2. ⚡ TRANSLATION ENGINE: Convert MySQL dialect into clean PostgreSQL syntax
        // Extract the interior column definitions block
        if (preg_match('/CREATE TABLE\s+`[^`]+`\s*\((.*)\)\s*ENGINE=/is', $createSQL, $matches)) {
            $innerContent = $matches[1];
        } else {
            return $createSQL; 
        }

        $lines = explode("\n", $innerContent);
        $pgColumns = [];
        $primaryKeyCols = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Handle Primary Key constraints listed at the bottom
            if (str_starts_with(strtoupper($line), 'PRIMARY KEY')) {
                if (preg_match_all('/`([^`]+)`/', $line, $pkMatches)) {
                    $quotedPks = array_map(fn($col) => "\"{$col}\"", $pkMatches[1]);
                    $primaryKeyCols[] = "PRIMARY KEY (" . implode(', ', $quotedPks) . ")";
                }
                continue;
            }

            // Skip other MySQL-specific indexes/keys for baseline structural copy
            if (
                str_starts_with(strtoupper($line), 'KEY') || 
                str_starts_with(strtoupper($line), 'UNIQUE KEY') || 
                str_starts_with(strtoupper($line), 'CONSTRAINT')
            ) {
                continue;
            }

            // Parse out the column name inside backticks
            if (preg_match('/^`([^`]+)`\s+(.*)$/', $line, $colMatches)) {
                $colName = $colMatches[1];
                $colDef  = rtrim($colMatches[2], ',');

                // Normalize structural types to PostgreSQL equivalents
// 🎯 FIX: Match and convert explicit tinyint/bigint/int types completely, preserving casing safely
                $colDef = preg_replace('/\btinyint(\(\d+\))?/i', 'SMALLINT', $colDef);
                $colDef = preg_replace('/\bbigint(\(\d+\))?/i', 'BIGINT', $colDef);
                $colDef = preg_replace('/\bmediumint(\(\d+\))?/i', 'INTEGER', $colDef);
                $colDef = preg_replace('/\bint(\(\d+\))?/i', 'INTEGER', $colDef);
                
                // Convert auto-increment features safely to SERIAL counters
                if (str_contains(strtoupper($colDef), 'AUTO_INCREMENT')) {
                    $colDef = str_ireplace('AUTO_INCREMENT', '', $colDef);
                    $colDef = str_ireplace('INTEGER', 'SERIAL', $colDef);
                    $colDef = str_ireplace('BIGINT', 'BIGSERIAL', $colDef);
                    $colDef = str_ireplace('SMALLINT', 'SERIAL', $colDef); // Fallback for tinyint auto-increments
                    $colDef = str_ireplace('NOT NULL', '', $colDef); 
                }
                
                // 🎯 HARDENED BINARY TRANSLATION BLOCK (Regex forces case-insensitive matching)
// 🎯 HARDENED BINARY TRANSLATION BLOCK
                $colDef = preg_replace('/(longblob|mediumblob|tinyblob|blob)/i', 'BYTEA', $colDef);

                // 🎯 FIX: Convert inline MySQL ENUMs into VARCHAR with a PostgreSQL CHECK constraint
                if (preg_match('/enum\(([^)]+)\)/i', $colDef, $enumMatches)) {
                    $enumValues = $enumMatches[1]; // e.g., "'N','Y'"
                    // Replace the enum definition with a standard VARCHAR of appropriate size
                    $colDef = preg_replace('/enum\([^)]+\)/i', 'VARCHAR(50)', $colDef);
                    // Append a valid inline PostgreSQL CHECK constraint to validate values
                    $colDef .= " CHECK (\"{$colName}\" IN ({$enumValues}))";
                }

                // Strip out MySQL-specific tracking constraints and function signatures
                if (str_contains(strtoupper($colDef), 'ON UPDATE')) {
                    $colDef = preg_replace('/ON UPDATE\s+[a-z0-9_().:\']+/i', '', $colDef);
                }
                
                // Normalize default timestamp functions and texts
                $colDef = str_ireplace('CURRENT_TIMESTAMP()', 'CURRENT_TIMESTAMP', $colDef);
                $colDef = preg_replace('/(datetime)/i', 'TIMESTAMP', $colDef);
                $colDef = preg_replace('/(longtext|mediumtext)/i', 'TEXT', $colDef);
                
                // Strip out MySQL-specific character parameters or collations
                $colDef = preg_replace('/CHARACTER SET \w+/i', '', $colDef);
                $colDef = preg_replace('/COLLATE \w+/i', '', $colDef);
                
                $pgColumns[] = "    \"{$colName}\" " . trim($colDef);
            }
        }

        // Combine columns and primary keys together safely
        $body = implode(",\n", array_merge($pgColumns, $primaryKeyCols));
        
        return "CREATE TABLE \"{$table}\" (\n{$body}\n);";
    }


}
