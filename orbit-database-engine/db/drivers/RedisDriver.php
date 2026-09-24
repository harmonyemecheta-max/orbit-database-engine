<?php
namespace DB\Drivers;

/** @noinspection PhpUndefinedClassInspection */
/** @noinspection PhpUndefinedMethodInspection */

// 1. Helper to trick the IDE if the extension isn't loaded yet
// 1. Helper to trick the IDE if the extension isn't loaded yet
/*
if (!class_exists('Redis')) {
    class Redis {
        public function connect($h, $p, $t = 0): bool { return true; }
        public function auth($a): bool { return true; }
        public function ping(): string { return 'PONG'; }
        public function get($k): mixed { return ''; } // Changed to mixed/string
        public function set($k, $v): bool { return true; }
        public function select($db): bool { return true; }
        public function keys($pattern): array { return []; } // Added array type
        public function hGetAll($key): array { return []; }  // Added array type
        public function expire($k, $t): bool { return true; }
    }
}
*/
class RedisDriver implements DBDriverInterface
{
    use \DB\Traits\LoggerTrait, \DB\Traits\EncryptionTrait;

private ?\Redis $redis = null;
    public function __construct(array $config)
    {
        try {
            $this->redis = new \Redis();
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 6379;
            
            $connected = @$this->redis->connect($host, $port, 2.5);
            
            if (!$connected) {
                throw new \Exception("Could not reach Redis server.");
            }

            if (!empty($config['auth'])) {
                $this->redis->auth($config['auth']);
            }
            
            $this->log("Connected to Redis at {$host}:{$port}", []);
        } catch (\Throwable $e) {
            $this->log("Redis Error: " . $e->getMessage(), ['status' => 'offline']);
            $this->redis = null; 
        }
    }

    public function redis(): ?\Redis { return $this->redis; }

    public function connect(): void { }

    public function setDatabase(string $dbName): void {
        if ($this->redis) $this->redis->select((int)$dbName);
    }

    public function query(string $sql, array $params = []): mixed { 
        if ($sql === "SELECT 1") return $this->redis ? $this->redis->ping() : false; 
        throw new \Exception('SQL not supported on Redis.'); 
    }

    public function fetchAll(string $sql, array $params = []): array { return []; }
    public function getSchema(): array { return []; }
    public function getPDO(): \PDO { throw new \Exception("Redis does not use PDO."); }
    public function update(string $table, array $data, array $where): bool { return false; }
    public function delete(string $table, array $where): bool { return false; }


    public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string {
        if (!$this->redis) return false;

        // Force cast to array or default to empty array to satisfy the editor
        $keys = (array)($this->redis->keys('*') ?: []);
        
        $data = [];
        foreach ($keys as $k) {
            $data[$k] = $this->redis->get($k);
        }

        $json = json_encode($data);
        if ($encrypt && $key && $iv) {
            $json = $this->encryptData($json, $key, $iv);
        }

        return (bool)file_put_contents($path, $json);
    }    

    public function import(array $data): bool { 
        if (!$this->redis) return false;
        foreach ($data as $k=>$v) $this->redis->set($k, $v); 
        return true; 
    }

    public function findAll(string $collection): array { 
        return $this->redis ? $this->redis->hGetAll($collection) : []; 
    }

    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool { 
        return (bool)$this->backup($path, $encrypt, $key, $iv); 
    }

    public function listDatabases(): array { return []; }
    public function listTables(string $database): array { return []; }
    public function getTableRecords(string $database, string $table, int $limit = 50): array { return []; }
    public function createDatabase(string $dbName): bool { return true; }
    public function createTable(string $database, string $tableName, array $columns): bool { return true; }

    public function begin(): bool { return false; }
    public function commit(): bool { return false; }
    public function rollBack(): bool { return false; }
    public function execute(string $sql, array $bindings = []): int { return 0; }
    public function fetchOne(string $sql, array $bindings = []): ?array { return null; }
    public function insert(string $table, array $data): bool { return false; }
    public function lastInsertId(?string $name = null): string|int { return 0; }

    public function grammar(): \DB\ORM\Grammar {
        return new RedisGrammar(); 
    }

public function publish(string $channel, string $message): int|false
{
    if (!$this->redis) {
        return false;
    }

    return $this->redis->publish($channel, $message);
}


public function subscribe(array $channels, callable $callback): void
{
    if (!$this->redis) return;

    $this->redis->subscribe($channels, function ($redis, $channel, $message) use ($callback) {
        $callback($channel, $message);
    });
}


/**
     * Satisfies the DBDriverInterface fetch method constraint perfectly.
     * Since Redis doesn't use standard SQL row fetching, we return null or an empty array.
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        return [];
    }
} // <-- End of RedisDriver class


/**
 * Concrete implementation of Grammar for Redis stubbing
 */
class RedisGrammar extends \DB\ORM\Grammar 
{ 
    public function wrap(string $identifier): string 
    {
        return $identifier;
    }
}