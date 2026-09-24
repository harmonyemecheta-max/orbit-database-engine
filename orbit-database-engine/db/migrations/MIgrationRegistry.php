<?php

namespace DB\Migrations;

use DB\DBManager;

class MigrationRegistry
{
    protected static string $table = 'schema_migrations';

    /*
    |--------------------------------------------------------------------------
    | Ensure Registry Table Exists
    |--------------------------------------------------------------------------
    */

    public static function ensureTable(string $connection): void
    {
        $db = DBManager::connection($connection);
        $table = self::$table;

        $sql = "
            CREATE TABLE IF NOT EXISTS {$table}
            (
                id BIGSERIAL PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                checksum VARCHAR(255) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'success',

                error_message TEXT,

                execution_time INTEGER DEFAULT 0,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ";

        $db->query($sql);
        $db->query("
            ALTER TABLE {$table}
            ADD COLUMN IF NOT EXISTS error_message TEXT
        ");

        /*
        |----------------------------------------------------------------------
        | Unique Migration Constraint
        |----------------------------------------------------------------------
        */

            $db->query("
                CREATE UNIQUE INDEX IF NOT EXISTS
                {$table}_unique_idx
                ON {$table}(migration);
            ");

    }

    /*
    |--------------------------------------------------------------------------
    | Has Migration Run?
    |--------------------------------------------------------------------------
    */

    public static function hasRun(
        string $migration,
        string $connection
    ): bool
    {
        $db = DBManager::connection($connection);

        $table = self::$table;

        $sql = "
            SELECT id
            FROM {$table}
            WHERE migration = ?
            LIMIT 1
        ";


        $rows =
            $db->fetchAll($sql, [
                $migration
            ]);

        return !empty($rows);
    }

    /*
    |--------------------------------------------------------------------------
    | Mark Successful Migration
    |--------------------------------------------------------------------------
    */

    public static function markRun(
        string $migration,
        string $checksum,
        int $executionTime,
        string $connection
    ): void {
        $table = self::$table;
        $db = DBManager::connection($connection);

        $sql = "
        INSERT INTO {$table}
        (
            migration,
            checksum,
            status,
            execution_time
        )
        VALUES
        (
            ?, ?, 'success', ?
        )
        ON CONFLICT (migration)
        DO UPDATE SET
            checksum = EXCLUDED.checksum,
            status = EXCLUDED.status,
            error_message = NULL,
            execution_time = EXCLUDED.execution_time,
            executed_at = CURRENT_TIMESTAMP
            ";

        $db->query($sql, [
            $migration,
            $checksum,
            $executionTime
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Mark Failed Migration
    |--------------------------------------------------------------------------
    */

    public static function markFailed(
        string $migration,
        string $checksum,
        string $error,
        int $executionTime,
        string $connection
    ): void {
        $table = self::$table;
        $db = DBManager::connection($connection);

        $sql = "
            INSERT INTO {$table}
            (
                migration,
                checksum,
                status,
                error_message,
                execution_time
            )
            VALUES
            (
                ?, ?, 'failed', ?, ?
            )
            ON CONFLICT (migration)
            DO UPDATE SET
                checksum = EXCLUDED.checksum,
                status = EXCLUDED.status,
                error_message = EXCLUDED.error_message,
                execution_time = EXCLUDED.execution_time,
                executed_at = CURRENT_TIMESTAMP
            ";

            $db->query($sql, [

                $migration,
                $checksum,
                $error,
                $executionTime
            ]);


        error_log("[MigrationRegistry] FAILED [$migration] :: $error");
    }

    /*
    |--------------------------------------------------------------------------
    | Get Migration History
    |--------------------------------------------------------------------------
    */

    public static function getHistory(
        string $connection
    ): array {
        $table = self::$table;
        $db =
            DBManager::connection($connection);

        return $db->fetchAll("
            SELECT *
            FROM {$table}
            ORDER BY executed_at DESC
        ");
    }

    /*
    |--------------------------------------------------------------------------
    | Remove Migration Record
    |--------------------------------------------------------------------------
    */

    public static function forget(
        string $migration,
        string $connection
    ): void {

        $db = DBManager::connection($connection);

        $table = self::$table;

        $sql = "
            DELETE FROM {$table}
            WHERE migration = ?
        ";

        $db->query($sql, [
            $migration,
        ]);
    }


    public static function rollback(
        string $migration,
        string $connection
    ): void
    {
        self::forget(
            $migration,
            $connection
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Generate Checksum
    |--------------------------------------------------------------------------
    */

    public static function checksum(
        mixed $data,
    ): string {

        if (!is_string($data)) {

            $data =
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT
                );
        }

        return
            hash(
                'sha256',
                $data
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Detect Drift By Checksum
    |--------------------------------------------------------------------------
    */

    public static function hasChecksumChanged(
        string $migration,
        string $checksum,
        string $connection
    ): bool {

        $db = DBManager::connection($connection);

        $table = self::$table;

        $sql = "
            SELECT checksum
            FROM {$table}
            WHERE migration = ?
            LIMIT 1
        ";

        $rows =
            $db->fetchAll($sql, [
                $migration,
            ]);

        if (empty($rows)) {
            return true;
        }

        return
            $rows[0]['checksum']
            !==
            $checksum;
    }

    /*
    |--------------------------------------------------------------------------
    | Latest Migration
    |--------------------------------------------------------------------------
    */

    public static function latest(
        string $connection
    ): ?array {

        $history =
            self::getHistory(
                $connection
            );

        return
            $history[0]
            ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Count
    |--------------------------------------------------------------------------
    */

        public static function count(string $connection): int {
            return count(self::getHistory($connection)
            );
        }


}