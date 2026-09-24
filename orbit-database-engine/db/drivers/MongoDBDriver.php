<?php
namespace DB\Drivers;

use MongoDB\Client;

class MongoDBDriver implements DBDriverInterface
{
    use \DB\Traits\LoggerTrait, \DB\Traits\EncryptionTrait;

    private Client $client;
    private string $dbName;

    public function __construct(array $config)
    {
        $uri = $config['uri'] ?? 'mongodb://127.0.0.1:27017';
        $this->dbName = $config['dbname'] ?? ($config['database'] ?? 'test');
        $this->client = new Client($uri);
        $this->log("Connected to MongoDB {$this->dbName}");
    }

    public function connect(): void { /* connected in constructor */ }

    public function findAll(string $collection): array
    {
        $col = $this->client->{$this->dbName}->{$collection};
        $cursor = $col->find();
        $out = [];
        foreach ($cursor as $doc) $out[] = (array)$doc;
        return $out;
    }

    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool
    {
        $collections = $this->client->{$this->dbName}->listCollections();
        $data = [];
        foreach ($collections as $c) {
            $name = $c->getName();
            $data[$name] = $this->findAll($name);
        }
        $json = json_encode($data);
        if ($encrypt && $key && $iv) $json = $this->encryptData($json, $key, $iv);
        file_put_contents($path, $json);
        $this->log("MongoDB export saved to $path");
        return true;
    }

    public function import(array $data): bool
    {
        foreach ($data as $collection => $rows) {
            $coll = $this->client->{$this->dbName}->{$collection};
            foreach ($rows as $row) $coll->insertOne($row);
        }
        $this->log("MongoDB import completed");
        return true;
    }

    // SQL stubs
    public function query(string $sql, array $params = []): mixed { throw new \Exception('Not supported'); }
    public function fetchAll(string $sql, array $params = []): array { throw new \Exception('Not supported'); }
    public function getSchema(): array { return []; }
    public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string { return $this->export($path, $encrypt, $key, $iv); }

    public function listDatabases(): array { $dbs = $this->client->listDatabases(); $out = []; foreach ($dbs as $d) $out[] = $d->getName(); return $out; }
    public function listTables(string $database): array { $cols = $this->client->{$database}->listCollections(); $out = []; foreach ($cols as $c) $out[] = $c->getName(); return $out; }
    public function getTableRecords(string $database, string $table, int $limit = 50): array { $cursor = $this->client->{$database}->{$table}->find([], ['limit'=>$limit]); $out = []; foreach ($cursor as $d) $out[] = (array)$d; return $out; }
    public function createDatabase(string $dbName): bool { $this->client->{$dbName}->createCollection('init'); $this->client->{$dbName}->dropCollection('init'); return true; }
    public function createTable(string $database, string $tableName, array $columns): bool { $this->client->{$database}->createCollection($tableName); return true; }
}