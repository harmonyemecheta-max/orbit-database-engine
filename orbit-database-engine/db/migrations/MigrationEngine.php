<?php
namespace DB\Migrations;

use DB\DBManager;
use Exception;

class MigrationEngine
{
    private string $path;
    private $db;

    public function __construct(string $folderPath, $connectionName = 'default')
    {
        $this->path = rtrim($folderPath, '/');
        $this->db = DBManager::connection($connectionName);

        $this->ensureMigrationsTable();
    }

    /**
     * Ensure migrations tracking table exists
     */
    private function ensureMigrationsTable(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS migrations (
                id SERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL,
                applied_at TIMESTAMP NOT NULL
            )
        ");
    }

    /**
     * Run all new migrations
     */
    public function run(): void
    {
        $files = glob("{$this->path}/*.php");
        $batch = $this->getCurrentBatch() + 1;

        foreach ($files as $file) {
            require_once $file;
            $class = basename($file, ".php");

            // If your generated migrations live under a specific namespace:
                $classNameWithNamespace = "\\DB\\Migrations\\Schema\\" . $class;

                if (class_exists($classNameWithNamespace) && !$this->hasRun($class)) {
                    $mig = new $classNameWithNamespace($this->db);
                    $mig->up();

                $this->recordMigration($class, $batch);
                echo "[MigrationEngine] Applied: $class\n";
            }
        }
    }

    /**
     * Rollback last batch
     */
    public function rollback(): void
    {
        $batch = $this->getCurrentBatch();
        if ($batch === 0) return;

        $rows = $this->db->fetchAll(
            "SELECT migration FROM migrations WHERE batch = :batch ORDER BY id DESC",
            ['batch' => $batch]
        );

        foreach ($rows as $row) {
            $class = $row['migration']; // "CreateUsersTable"
            
            // Prefix with your conventional schema namespace
            $classNameWithNamespace = "\\DB\\Migrations\\Schema\\" . $class;

            if (class_exists($classNameWithNamespace)) {
                /** @var Migration $mig */
                $mig = new $classNameWithNamespace($this->db);
                $mig->down();
                
                $this->db->delete('migrations', ['migration' => $class]);
                echo "[MigrationEngine] Rolled back: $class\n";
            } else {
                echo "[MigrationEngine] Error: Migration class $classNameWithNamespace not found.\n";
            }
        }


    }

    /**
     * Check if migration has run
     */
    private function hasRun(string $migration): bool
    {
        $row = $this->db->fetchAll(
            "SELECT id FROM migrations WHERE migration = :migration",
            ['migration' => $migration]
        );
        return !empty($row);
    }

    /**
     * Record migration run
     */
    private function recordMigration(string $migration, int $batch): void
    {
        $this->db->insert('migrations', [
            'migration'   => $migration,
            'batch'       => $batch,
            'applied_at'  => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get the current batch
     */
    private function getCurrentBatch(): int
    {
        $row = $this->db->fetchAll("SELECT MAX(batch) as batch FROM migrations");
        return $row[0]['batch'] ?? 0;
    }
}
