<?php
namespace DB;

use DB\Drivers\DBDriverInterface;

class DBManager
{
    private static array $instances = [];
    private static array $configs   = [];

    /**
     * Driver alias mapping (normalized internally)
     */
    private static array $driverAliases = [
        'postgresql'   => 'pgsql',
        'postgres'     => 'pgsql',
        'pg'           => 'pgsql',
        'mysql2'       => 'mysql',
        'mariadb'      => 'mysql',
        //'td_pg'        => 'tradexenter_pg', // custom driver
    ];

    public static function getConfig(string $name): array
    {
        if (!isset(self::$configs[$name])) {
            throw new \Exception("Database config '{$name}' not found.");
        }
        return self::$configs[$name];
    }

    /**
     * Register a database configuration.
     */
    public static function addConnection(string $name, array $config): void
    {
        // Normalize array keys to lowercase
        $config = array_change_key_case($config, CASE_LOWER);

        if (!isset($config['driver'])) {
            throw new \Exception("DB config '{$name}' is missing a 'driver' key.");
        }

        // Normalize driver using alias map
        $driverKey = strtolower($config['driver']);
        if (isset(self::$driverAliases[$driverKey])) {
            $config['driver'] = self::$driverAliases[$driverKey];
        }

        // Store the connection config
        self::$configs[$name] = $config;
    }

    /**
     * Retrieve a connection instance.
     */
/**
 * ==========================================================
 * GET DATABASE CONNECTION
 * ==========================================================
 *
 * RULE:
 * - Config::db() is the SINGLE SOURCE OF TRUTH
 * - Worker jobs may override runtime config
 * - Cached broken instances must be recreated
 * ==========================================================
 */
public static function connection(string $name = 'default'): DBDriverInterface
{
    /**
     * ------------------------------------------------------
     * ALWAYS refresh config from Config kernel
     * ------------------------------------------------------
     *
     * This prevents:
     * - stale worker configs
     * - stale passwords
     * - stale runtime overrides
     */
$runtimeConfig = self::$configs[$name] ?? null;

if (!$runtimeConfig) {
    throw new \Exception("No DB config registered for {$name}");
}


    /**
     * Normalize keys
     */
    $runtimeConfig = array_change_key_case(
        $runtimeConfig,
        CASE_LOWER
    );

    /**
     * Validate driver
     */
    if (!isset($runtimeConfig['driver'])) {
        throw new \Exception(
            "Database config missing driver."
        );
    }

    /**
     * Normalize aliases
     */
    $driverKey = strtolower(
        $runtimeConfig['driver']
    );

    if (isset(self::$driverAliases[$driverKey])) {
        $runtimeConfig['driver']
            = self::$driverAliases[$driverKey];
    }

    /**
     * Store latest config
     */
    self::$configs[$name] = $runtimeConfig;

    /**
     * ------------------------------------------------------
     * FORCE REBUILD CONNECTION
     * ------------------------------------------------------
     *
     * Critical for:
     * - queue workers
     * - runtime overrides
     * - orchestration
     */
    unset(self::$instances[$name]);

    /**
     * Final config
     */
    $config = self::$configs[$name];

    $driverKey = strtolower($config['driver']);

    /**
     * Driver map
     */
    $classMap = [
        'mysql'       => \DB\Drivers\MySQLDriver::class,
        'pgsql'       => \DB\Drivers\PostgreSQLDriver::class,
        'sqlite'      => \DB\Drivers\SQLiteDriver::class,
        'mongo'       => \DB\Drivers\MongoDBDriver::class,
        'redis'       => \DB\Drivers\RedisDriver::class,
        'firestore'   => \DB\Drivers\FirestoreDriver::class,
        'firebase'    => \DB\Drivers\FirebaseDriver::class,
        'mssql'       => \DB\Drivers\MSSQLDriver::class,
        'oracle'      => \DB\Drivers\OracleDriver::class,
        'couchdb'     => \DB\Drivers\CouchDBDriver::class,
        'dynamodb'    => \DB\Drivers\DynamoDBDriver::class,
        'supabase'    => \DB\Drivers\SupabaseDriver::class,
        'planetscale' => \DB\Drivers\PlanetscaleDriver::class,
    ];

    if (!isset($classMap[$driverKey])) {
        throw new \Exception(
            "Unknown database driver: {$driverKey}"
        );
    }

    /**
     * Create fresh driver instance
     */
    $driverClass = $classMap[$driverKey];

    self::$instances[$name]
        = new $driverClass($config);

    return self::$instances[$name];
}

    /**
     * Get default connection.
     */
    public static function db(): DBDriverInterface
    {
        return self::connection('default');
    }

    /**
     * Check if a connection configuration exists.
     */
    public static function hasConnection(string $name): bool
    {
        return isset(self::$configs[$name]);
    }


    public static function checkHealth(string $name): array 
    {
        try {
            $conn = self::connection($name);
            // Different drivers might need different 'ping' queries
            // This works for Postgres, MySQL, and SQLite
            $conn->query("SELECT 1"); 
            return ['status' => 'online', 'icon' => 'check-circle', 'color' => 'success'];
        } catch (\Exception $e) {
            return ['status' => 'offline', 'icon' => 'exclamation-triangle', 'color' => 'danger', 'error' => $e->getMessage()];
        }
    }


    /**
     * Remove a cached connection instance.
     * This forces the manager to recreate the driver on the next call.
     */
    public static function removeInstance(string $name): void
    {
        if (isset(self::$instances[$name])) {
            unset(self::$instances[$name]);
        }
    }



    public static function raw(string $name, string $sql)
{
    $conn = self::connection($name);

    if (!method_exists($conn, 'query')) {
        throw new \Exception("DB driver does not support query()");
    }

    return $conn->query($sql);
}


}
