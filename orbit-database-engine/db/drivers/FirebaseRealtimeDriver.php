<?php
namespace DB\Drivers;

use DB\Contracts\DBDriverInterface;
use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;

class FirebaseRealtimeDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    private string $databaseUrl;
    private ?string $authToken;

    public function __construct(array $config)
    {
        $this->databaseUrl = rtrim($config['database_url'] ?? '', '/');
        if (empty($this->databaseUrl)) {
            throw new \Exception("FirebaseRealtimeDriver requires 'database_url' in config.");
        }
        $this->authToken = $config['auth_token'] ?? null;
        $this->log("FirebaseRealtimeDriver initialized for {$this->databaseUrl}");
    }

    public function connect(): void
    {
        // REST driver has no persistent connection
    }

    private function url(string $path = ''): string
    {
        $u = $this->databaseUrl . '/' . ltrim($path, '/') . '.json';
        if ($this->authToken) {
            $u .= (strpos($u, '?') === false ? '?' : '&') . 'auth=' . urlencode($this->authToken);
        }
        return $u;
    }

    private function request(string $method, string $path = '', array|object|null $payload = null): mixed
    {
        $ch = curl_init($this->url($path));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) throw new \Exception("Firebase REST error: $err");
        $decoded = json_decode($res, true);

        if ($code >= 400) {
            $this->log("Firebase request returned HTTP $code for path: $path");
            return $decoded;
        }

        return $decoded ?? [];
    }

    // SQL methods → not supported
    public function query(string $sql, array $params = []): mixed
    {
        throw new \Exception("FirebaseRealtimeDriver does not support SQL queries.");
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        throw new \Exception("FirebaseRealtimeDriver does not support SQL queries.");
    }

    // Returns all records under a given path (collection)
    public function findAll(string $collection): array
    {
        $res = $this->request('GET', $collection);
        return is_array($res) ? $res : [];
    }

    public function getSchema(): array
    {
        $root = $this->request('GET', '');
        return is_array($root) ? array_keys($root) : [];
    }

    public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string
    {
        $data = $this->request('GET', '');
        $json = json_encode($data, JSON_PRETTY_PRINT);

        if ($encrypt && $key && $iv) {
            $json = $this->encryptData($json, $key, $iv);
        }

        file_put_contents($path, $json);
        $this->log("Firebase Realtime backup saved to $path" . ($encrypt ? " (encrypted)" : ""));
        return true;
    }

    public function import(array $data): bool
    {
        $res = $this->request('PUT', '', $data);
        $this->log("Firebase Realtime import completed");
        return $res !== null;
    }

    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool
    {
        return $this->backup($path, $encrypt, $key, $iv);
    }

    public function listDatabases(): array
    {
        return [$this->databaseUrl];
    }

    public function listTables(string $database): array
    {
        return $this->getSchema();
    }

    public function getTableRecords(string $database, string $table, int $limit = 50): array
    {
        $all = $this->findAll($table);
        if (!is_array($all)) return [];
        return array_slice($all, 0, $limit, true);
    }

    public function createDatabase(string $dbName): bool
    {
        $this->log("createDatabase called on FirebaseRealtimeDriver - no-op");
        return true;
    }

    public function createTable(string $database, string $tableName, array $columns): bool
    {
        $this->request('PUT', $tableName, (object)[]); // create empty node
        $this->log("Created node/collection '$tableName' in Firebase Realtime DB");
        return true;
    }
}
