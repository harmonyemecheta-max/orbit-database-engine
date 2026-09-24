<?php

namespace DB\Schema\Orchestrator\Transaction;

use DB\Migrations\MigrationBuilder;

/**
 * ------------------------------------------------------------
 * SchemaTransaction
 * ------------------------------------------------------------
 * ROLE:
 * Ensures schema changes are atomic and recoverable
 *
 * FUTURE:
 * - rollback journal
 * - partial failure recovery
 * - schema checkpoints
 * ------------------------------------------------------------
 */

class SchemaTransaction
{
    public static function run(callable $callback, MigrationBuilder $builder): void
    {
        try {

            $builder->begin();

            $callback($builder);

            $builder->commit();

        } catch (\Throwable $e) {

            $builder->rollback();

            error_log("[SCHEMA TRANSACTION FAILED] " . $e->getMessage());

            throw $e;
        }
    }
}