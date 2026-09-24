<?php
namespace DB\Drivers;

use DB\Contracts\DBDriverInterface;
use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;

class SupabaseDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    private string $url;
    private string $apikey;

    public function __construct(array $config)
    {
        $this->url = rtrim($config['url'] ?? '', '/');
        $this->apikey = $config['apikey'] ?? '';
        $this->log("Connected to Supabase API at {$this->url}");
    }

    public function connect(): void {}

    private function request(string $method, string $endpoint, array $data = []): mixed
    {
        $ch = curl_init("{$this->url}/{$endpoint}");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "apikey: {$this->apikey}",
            "Content-Type: application/json"
        ]);

        if (!empty($data))
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        curl_close($ch);

        return json_decode($res, true);
    }

    // Supabase does NOT support raw SQL
    public function query(string $sql, array $params = []): mixed
    {
        throw new \Exception("Supabase does not support direct SQL queries.");
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        throw new \Exception("Supabase does not support direct SQL queries.");
    }

    public function findAll(string $collection): array
    {
        return $this->request('GET', $collection);
    }

    public function getSchema(): array
    {
        return array_keys($this->findAll(''));
    }

    public function backup(
        string $path,
        bool   $encrypt = false,
        ?string $key = null,
        ?string $iv = null
    ): bool|string
    {
        $data = $this->findAll('');
        $json = json_encode($data);

        if ($encrypt && $key && $iv)
            $json = $this->encryptData($json, $key, $iv);

        file_put_contents($path, $json);
        $this->log("Supabase backup saved to $path");
        return true;
    }

    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool
    {
        return (bool) $this->backup($path, $encrypt, $key, $iv);
    }

    public function import(array $data): bool
    {
        foreach ($data as $table => $rows) {
            foreach ($rows as $row)
                $this->request('POST', $table, $row);
        }
        $this->log("Supabase import completed");
        return true;
    }

    public function listDatabases(): array { return [$this->url]; }
    public function listTables(string $database): array { return $this->getSchema(); }

    public function getTableRecords(string $database, string $table, int $limit = 50): array
    {
        return array_slice($this->findAll($table), 0, $limit);
    }

    public function createDatabase(string $dbName): bool { return true; }

    public function createTable(string $database, string $tableName, array $columns): bool
    {
        // Supabase is schemaless via API
        $this->request('POST', $tableName, []);
        return true;
    }
}
