
<?php
namespace DB\Drivers;

use PDO;

class SQLiteDriver extends SQLDriver
{
    public function connect(): void
    {
        $path = $this->config['path'] ?? ':memory:';
        $dsn = "sqlite:{$path}";
        $this->pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->log("Connected to SQLite at {$path}");
    }

    public function getSchema(): array
    {
        return $this->fetchAll("SELECT name FROM sqlite_master WHERE type='table'");
    }

    public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string
    {
        $data = [];
        foreach ($this->getSchema() as $table) {
            $tbl = $table['name'];
            $data[$tbl] = $this->fetchAll("SELECT * FROM `$tbl`");
        }
        $json = json_encode($data);
        if ($encrypt && $key && $iv) $json = $this->encryptData($json, $key, $iv);
        file_put_contents($path, $json);
        $this->log("SQLite backup saved to $path", ['encrypted' => $encrypt]);
        return true;
    }
}



