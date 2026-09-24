<?php
namespace DB\Drivers;

use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;
use DB\Drivers\DBDriverInterface;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\DatabaseException;

class FirebaseDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    private $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        if (!isset($config['credentials'])) {
            throw new \Exception("FirebaseDriver requires 'credentials' (path to JSON service account).");
        }

        $factory = (new Factory())->withServiceAccount($config['credentials']);
        $this->db = $factory->createDatabase();

        $this->log("Connected to Firebase Realtime Database");
    }

    public function connect(): void
    {
        // Firebase SDK handles connections automatically
    }

    // ---------------------------------------------------------------------
    // SQL NOT SUPPORTED
    // ---------------------------------------------------------------------
    public function query(string $sql, array $params = []): mixed
    {
        throw new \Exception("Firebase does not support SQL queries.");
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        throw new \Exception("Firebase does not support SQL queries.");
    }

    // ---------------------------------------------------------------------
    // BASIC METHODS
    // ---------------------------------------------------------------------

    public function findAll(string $collection): array
    {
        try {
            $data = $this->db->getReference($collection)->getValue();
            return $data ?: [];
        } catch (DatabaseException $e) {
            throw new \Exception("Firebase error: " . $e->getMessage());
        }
    }

    public function getSchema(): array
    {
        // Firebase has no schema — treat top-level nodes as "tables"
        $root = $this->db->getReference('/')->getValue();
        return $root ? array_keys($root) : [];
    }

    // ---------------------------------------------------------------------
    // EXPORT / BACKUP
    // ---------------------------------------------------------------------

    public function backup(
        string $path,
        bool $encrypt = false,
        ?string $key = null,
        ?string $iv = null
    ): bool|string
    {
        $data = $this->db->getReference('/')->getValue() ?: [];
        $json = json_encode($data, JSON_PRETTY_PRINT);

        if ($encrypt && $key && $iv) {
            $json = $this->encryptData($json, $key, $iv);
        }

        file_put_contents($path, $json);
        $this->log("Firebase backup saved to {$path}");
        return true;
    }

    public function export(
        string $path,
        bool $encrypt = false,
        ?string $key = null,
        ?string $iv = null
    ): bool {
        return $this->backup($path, $encrypt, $key, $iv);
    }

    // ---------------------------------------------------------------------
    // IMPORT
    // ---------------------------------------------------------------------

    public function import(array $data): bool
    {
        $this->db->getReference('/')->set($data);
        $this->log("Firebase import completed.");
        return true;
    }

    // ---------------------------------------------------------------------
    // DATABASE / TABLE MANAGEMENT
    // ---------------------------------------------------------------------

    public function listDatabases(): array
    {
        // Firebase has only one DB root
        return ['/'];
    }

    public function listTables(string $database): array
    {
        return $this->getSchema();
    }

    public function getTableRecords(string $database, string $table, int $limit = 50): array
    {
        $data = $this->findAll($table);
        if (!is_array($data)) {
            return [];
        }
        return array_slice($data, 0, $limit, true);
    }

    public function createDatabase(string $dbName): bool
    {
        // Cannot create new Firebase databases dynamically
        return true;
    }

    public function createTable(string $database, string $tableName, array $columns): bool
    {
        // Schemaless → create an empty node
        $this->db->getReference($tableName)->set([]);
        return true;
    }
}
