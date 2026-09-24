<?php
namespace DB\Drivers;
use PDO;
use DB\ORM\Grammar;


interface DBDriverInterface
{
    public function lastInsertId(?string $name = null): string|int;
    public function grammar(): Grammar;
    
    public function connect(): void;
    public function setDatabase(string $dbName): void; // <--- MUST BE HERE

    // SQL methods

    public function fetchOne(string $sql, array $bindings = []): ?array;

    public function fetch(string $sql, array $params = []): ?array;

    public function execute(string $sql, array $bindings = []): int;

    public function query(string $sql, array $params = []): mixed;
    public function fetchAll(string $sql, array $params = []): array;
    public function getSchema(): array;
    public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string;
    public function import(array $data): bool;
    public function update(string $table, array $data, array $where): bool;
    public function delete(string $table, array $where): bool;
        // 🆕 ADD THIS LINE:
    public function insert(string $table, array $data): bool;

    
    // 🔑 Add this
    public function getPDO(): PDO;

    // NoSQL / Cloud
    public function findAll(string $collection): array;
    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool;

    // Admin / optional
    public function listDatabases(): array;
    public function listTables(string $database): array;
    public function getTableRecords(string $database, string $table, int $limit = 50): array;
    public function createDatabase(string $dbName): bool;
    public function createTable(string $database, string $tableName, array $columns): bool;

    public function begin(): bool;
    public function commit(): bool;
    public function rollBack(): bool;
}
