<?php
namespace DB\Drivers;

use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;
use DB\Contracts\DBDriverInterface;

/**
 * PlanetscaleDriver
 * Extends MySQLDriver but forces SSL mode and disables unsupported operations.
 */
class PlanetscaleDriver extends MySQLDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    public function __construct(array $config)
    {
        // Ensure required fields
        if (!isset($config['host'], $config['username'], $config['password'], $config['dbname'])) {
            throw new \Exception("PlanetscaleDriver requires host, username, password, and dbname.");
        }

        // Planetscale requires SSL
        $config['options'][\PDO::MYSQL_ATTR_SSL_CA] = $config['ssl_ca'] ?? true;
        $config['options'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        $config['port'] = $config['port'] ?? 3306;

        // Planetscale does not support transactions → disable in config
        $config['disable_transactions'] = true;

        parent::__construct($config);

        $this->log("Connected to Planetscale at {$config['host']}:{$config['port']} (SSL enforced).");
    }

    /**
     * Override: disable transactions (Planetscale prohibits them)
     */
    public function beginTransaction(): void
    {
        $this->log("Transactions are not supported on Planetscale.");
    }

    public function commit(): void
    {
        $this->log("Transactions are not supported on Planetscale.");
    }

    public function rollBack(): void
    {
        $this->log("Transactions are not supported on Planetscale.");
    }

    /**
     * Override table creation: Planetscale does not allow CREATE TABLE with
     * foreign keys or some MySQL features.
     */
    public function createTable(string $database, string $tableName, array $columns): bool
    {
        $safeCols = [];

        foreach ($columns as $col => $type) {
            // Remove unsupported MySQL column types
            $type = preg_replace("/\bFOREIGN KEY\b/i", "", $type);
            $type = preg_replace("/\bREFERENCES\b.+$/i", "", $type);
            $safeCols[] = "`{$col}` {$type}";
        }

        $sql = "CREATE TABLE `{$tableName}` (" . implode(", ", $safeCols) . ")";

        $this->query($sql);
        $this->log("Created table '{$tableName}' on Planetscale.");

        return true;
    }
}
