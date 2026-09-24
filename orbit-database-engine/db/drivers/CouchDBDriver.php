<?php
namespace DB\Drivers;

use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;
use DB\Drivers\DBDriverInterface;

class CouchDBDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    private string $baseUrl;
    private ?string $username;
    private ?string $password;

    public function __construct(array $config)
    {
        $this->baseUrl  = rtrim($config['url'] ?? 'http://127.0.0.1:5984', '/');
        $this->username = $config['username'] ?? null;
        $this->password = $config['password'] ?? null;

        $this->log("Connected to CouchDB at {$this->baseUrl}");
    }

    public function connect(): void
    {
        // REST driver: no persistent connection required
    }

    /**
     * Internal HTTP request wrapper
     */
    private function request(string $method, string $path, array|string|null $body = null): mixed
    {
        $url = "{$this->baseUrl}/" . ltrim($path, '/');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        if ($this->username && $this->password) {
            curl_setopt($ch, CURLOPT_USERPWD, "{$this->username}:{$this->password}");
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("CouchDB HTTP error: {$error}");
        }

        return json_decode($response, true);
    }

    // ------------------------------------
    // SQL NOT supported in CouchDB
    // ------------------------------------
    public function query(string $sql, array $params = []): mixed
    {
        throw new \Exception("CouchDB does not support SQL queries.");
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        throw new \Exception("CouchDB does not support SQL queries.");
    }

    // ------------------------------------
    // COLLECTION / DOCUMENT methods
    // ------------------------------------

    public function findAll(string $collection): array
    {
        $res = $this->request("GET", "{$collection}/_all_docs?include_docs=true");

        if (!isset($res['rows'])) {
            return [];
        }

        $docs = [];
        foreach ($res['rows'] as $row) {
            if (isset($row['doc'])) {
                $docs[] = $row['doc'];
            }
        }

        return $docs;
    }

    public function getSchema(): array
    {
        return $this->request("GET", "_all_dbs") ?? [];
    }

    public function backup(
        string $path,
        bool   $encrypt = false,
        ?string $key = null,
        ?string $iv = null
    ): bool|string
    {
        $databases = $this->getSchema();
        $dump = [];

        foreach ($databases as $db) {
            $dump[$db] = $this->findAll($db);
        }

        $json = json_encode($dump, JSON_PRETTY_PRINT);

        if ($encrypt && $key && $iv) {
            $json = $this->encryptData($json, $key, $iv);
        }

        file_put_contents($path, $json);
        $this->log("CouchDB backup saved to {$path}");

        return true;
    }

    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool
    {
        return $this->backup($path, $encrypt, $key, $iv);
    }

    public function import(array $data): bool
    {
        foreach ($data as $db => $docs) {

            // Create database if missing
            $this->request("PUT", $db);

            foreach ($docs as $doc) {
                unset($doc['_rev']); // prevent revision conflict
                $this->request("POST", $db, $doc);
            }
        }

        $this->log("CouchDB import completed");
        return true;
    }

    // ------------------------------------
    // DB info
    // ------------------------------------

    public function listDatabases(): array
    {
        return $this->getSchema();
    }

    public function listTables(string $database): array
    {
        $docs = $this->findAll($database);
        return array_map(fn($d) => $d['_id'] ?? null, $docs);
    }

    public function getTableRecords(string $database, string $table, int $limit = 50): array
    {
        $res = $this->request("GET", "{$database}/{$table}");
        return $res ? [$res] : [];
    }

    public function createDatabase(string $dbName): bool
    {
        $this->request("PUT", $dbName);
        return true;
    }

    public function createTable(string $database, string $tableName, array $columns): bool
    {
        // CouchDB is schemaless — creating a doc == creating a “table”
        $this->request("PUT", "{$database}/{$tableName}");
        return true;
    }
}
