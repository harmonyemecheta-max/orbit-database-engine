<?php
namespace DB\Services;

use Exception;

class DynamicMigrationEngine 
{
    // Fallback path configured for standard platform architecture paths
    protected string $driverPath = __DIR__ . '/../drivers/';
    protected array $appConfigs = [];

    /**
     * Pass application database profiles during instantiation.
     */
    public function __construct(array $appConfigs = [])
    {
        // Fallback or explicit alignment with your platform layout paths
        $basePath = dirname(__DIR__, 3) . '/shared/db/drivers/';
        if (is_dir($basePath)) {
            $this->driverPath = $basePath;
        }
        $this->appConfigs = $appConfigs;
    }

    /**
     * Scanning filesystem to discover installed engines dynamically
     */
    public function getAvailableDrivers(): array
    {
        $drivers = [];
        if (!is_dir($this->driverPath)) {
            return $drivers;
        }

        $files = scandir($this->driverPath);
        foreach ($files as $file) {
            // 🔥 HARDENED INTERCEPTOR: Skip common files, abstract classes, or standard file helpers
            if (
                in_array($file, ['.', '..']) || 
                str_contains($file, 'Interface') || 
                $file === 'SQLDriver.php' ||
                $file === 'Driver.php' ||        // Skip generic base files
                $file === 'BaseDriver.php' ||    // Skip common abstract layers
                !str_ends_with($file, '.php')    // Ensure it's a script file
            ) {
                continue;
            }

            $className = str_replace('.php', '', $file);
            
            // Ensure we are only parsing a valid concrete class structural token
            if (!str_contains($className, 'Driver')) {
                continue; 
            }

            $token = strtolower(str_replace('Driver', '', $className));
            if ($token === 'postgresql') { $token = 'pgsql'; } 
            
            // Double-check we didn't end up with an accidental empty or invalid string token
            if (empty($token) || $token === 'php') {
                continue;
            }

            $drivers[$token] = [
                'class'        => "\\DB\\Drivers\\{$className}",
                'display_name' => str_replace('Driver', '', $className)
            ];
        }

        return $drivers;
    }


    /**
     * Resolves configuration properties, matches to an available driver, and instantiates
     */
    public function factoryInstantiate(array $config)
    {
        $availableDrivers = $this->getAvailableDrivers();
        $driverToken = strtolower($config['driver'] ?? $config['engine'] ?? '');

        if ($driverToken === 'postgresql' || $driverToken === 'postgres') {
            $driverToken = 'pgsql';
        }

        if (!isset($availableDrivers[$driverToken])) {
            throw new Exception("Driver wrapper [{$driverToken}] is not supported or missing from filesystem configuration.");
        }

        $driverClass = $availableDrivers[$driverToken]['class'];
        return new $driverClass($config);
    }

    /**
     * Executes the pipeline passing data blocks across separate driver platforms
     */
    public function executeCrossMigration(array $sourceConfig, array $targetConfig, array $tableFilter, string $strategy = 'truncate', bool $dryRun = false): array
    {
        $sourceDriver = $this->factoryInstantiate($sourceConfig);
        $targetDriver = $this->factoryInstantiate($targetConfig);

        $sourceDb = $sourceConfig['dbname'] ?? $sourceConfig['database'] ?? '';
        $targetDb = $targetConfig['dbname'] ?? $targetConfig['database'] ?? '';

        // Introspect tables if generic wildcard provided
        $tablesToProcess = $tableFilter;
        if (count($tableFilter) === 1 && $tableFilter[0] === '*') {
            // Assume your driver architecture supports a schema table inspection layer
            if (method_exists($sourceDriver, 'getTables')) {
                $tablesToProcess = $sourceDriver->getTables($sourceDb);
            } else {
                throw new Exception("Wildcard selection requires driver table introspection capability.");
            }
        }

        $telemetryLogs = [];

        foreach ($tablesToProcess as $table) {
            $table = trim($table);
            if (empty($table)) continue;

            // Fetch records using chunks or custom limit metrics defined by engine blueprints
            $records = $sourceDriver->getTableRecords($sourceDb, $table, 5000) ?? [];
            $recordCount = count($records);

            if ($dryRun) {
                $telemetryLogs[] = "[SIMULATION] Evaluated table `{$table}`: Found {$recordCount} raw records ready for transmission pipeline.";
                continue;
            }

            if ($recordCount === 0) {
                $telemetryLogs[] = "Table `{$table}` processed: 0 source rows discovered.";
                continue;
            }

            $pdoTarget = $targetDriver->getPDO();
            $pdoTarget->beginTransaction();

// 🔄 Loop through every discovered table in the source filter
foreach ($tablesToProcess as $table) {
    
    // 🎯 FIX: Wrap the entire table lifecycle so ANY read/write failure is isolated
    try {
        // 1. Attempt to stream data out of the source database engine
        // If it's a broken view, this step will fail right here and jump to the catch block safely!
        $records = $sourceDriver->table($table)->get(); 
        $recordCount = count($records);

        $pdoTarget = $targetDriver->getPDO();
        $pdoTarget->beginTransaction();

        // 2. Verify target structural footprint
        $tableExists = false;
        if (method_exists($targetDriver, 'getSchema')) {
            $targetTables = $targetDriver->getSchema();
            $tableExists = in_array($table, $targetTables);
        }

        // 3. Auto-Structural Mirror Layer
        if (!$tableExists) {
            if (method_exists($sourceDriver, 'getTableSchemaSQL')) {
                $createSQL = $sourceDriver->getTableSchemaSQL($table, 'pgsql');
                $pdoTarget->exec($createSQL);
                $telemetryLogs[] = "🛠️ Schema generated: Created structural footprint for \"{$table}\" on destination node.";
                $tableExists = true; 
            } else {
                throw new \Exception("Table structure must be manually deployed.");
            }
        }

        // 4. Clear existing data if using truncate strategy
        if (strtolower($strategy) === 'truncate' && $tableExists) {
            $pdoTarget->exec("TRUNCATE TABLE \"{$table}\" RESTART IDENTITY CASCADE");
        }

        // 5. Pipe the records across the data bridge
        if ($tableExists && $recordCount > 0) {
            foreach ($records as $row) {
                $targetDriver->insert($table, (array)$row);
            }
        }

        $pdoTarget->commit();
        $telemetryLogs[] = "✅ Pipeline success: Synced {$recordCount} records into `{$targetDb}`.`{$table}`.";

    } catch (\Throwable $e) {
        // 🎯 CRITICAL RECOVERY FLOW: Roll back any half-baked transactions for this table
        if (isset($pdoTarget) && $pdoTarget->inTransaction()) {
            $pdoTarget->rollBack();
        }

        // Log the failure as a non-blocking warning banner and keep moving!
        $telemetryLogs[] = "⚠️ SKIPPED [{$table}]: " . $e->getMessage();
        continue;
    }
}
            
        }

        return $telemetryLogs;
    }

    /**
     * Instantiates the source driver context and fetches available database spaces from the engine cluster
     */
/**
     * Instantiates the source driver context and fetches available database spaces from the engine cluster
     */
    public function discoverAvailableDatabases(array $config): array
    {
        try {
            // 1. Force establish a safe placeholder database name for cluster-wide exploration
            $driverToken = strtolower($config['driver'] ?? $config['engine'] ?? '');
            
            // Resolve whatever database string might already exist in the array payload
            $existingDb = !empty($config['database']) ? $config['database'] : (!empty($config['dbname']) ? $config['dbname'] : '');

            if ($driverToken === 'postgresql' || $driverToken === 'postgres' || $driverToken === 'pgsql') {
                $fallbackDb = !empty($existingDb) ? $existingDb : 'postgres';
                
                // 🎯 FIX: Populate both key shapes so the driver validation passes seamlessly
                $config['database'] = $fallbackDb;
                $config['dbname']   = $fallbackDb;
                $driverToken        = 'pgsql';
            } else {
                $fallbackDb = !empty($existingDb) ? $existingDb : 'information_schema';
                
                // 🎯 FIX: Mirror keys for MySQL / MariaDB contexts as well
                $config['database'] = $fallbackDb;
                $config['dbname']   = $fallbackDb;
            }

            // 2. Instantiate the specialized driver class instance (e.g., \DB\Drivers\MySQLDriver)
            $driverInstance = $this->factoryInstantiate($config);

            // 3. Check for your actual driver architecture methods first!
            if (method_exists($driverInstance, 'listDatabases')) {
                return $driverInstance->listDatabases();
            }

            if (method_exists($driverInstance, 'getDatabases')) {
                return $driverInstance->getDatabases();
            }

            // 4. Fallback pipeline check
            if (method_exists($driverInstance, 'getPDO')) {
                $pdo = $driverInstance->getPDO();

                if ($driverToken === 'mysql' || $driverToken === 'mariadb') {
                    return $pdo->query("SHOW DATABASES")->fetchAll(\PDO::FETCH_COLUMN);
                }

                if ($driverToken === 'pgsql') {
                    return $pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false AND datname != 'postgres'")->fetchAll(\PDO::FETCH_COLUMN);
                }
            }

            return ["{$driverToken}_cluster_connected"];

        } catch (\Throwable $e) {
            throw new \RuntimeException("Database Introspection Failure: " . $e->getMessage());
        }
    }

    
    /**
     * Ensures the target database exists on the target cluster before migration begins
     */
    public function ensureTargetDatabaseExists(array $targetConfig): void
    {
        $driver = strtolower($targetConfig['driver'] ?? 'pgsql');
        $targetDb = $targetConfig['database'] ?? '';

        if (empty($targetDb)) {
            throw new Exception("Target database name is undefined.");
        }

        // We temporarily connect to the global 'postgres' maintenance database to run the check
        $maintenanceConfig = $targetConfig;
        $maintenanceConfig['database'] = ($driver === 'pgsql') ? 'postgres' : 'information_schema';
        $maintenanceConfig['dbname'] = $maintenanceConfig['database'];

        $driverInstance = $this->factoryInstantiate($maintenanceConfig);
        $pdo = $driverInstance->getPDO();

        if ($driver === 'pgsql') {
            // Check if database exists in PostgreSQL system catalog
            $stmt = $pdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
            $stmt->execute([$targetDb]);
            if (!$stmt->fetchColumn()) {
                // Execute raw statement (CREATE DATABASE cannot run inside a transaction block)
                $pdo->exec("CREATE DATABASE " . $pdo->quote($targetDb));
            }
        }
    }
}