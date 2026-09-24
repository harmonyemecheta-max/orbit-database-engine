<?php

namespace DB\Schema\Orchestrator;

use DB\Migrations\MigrationBuilder;

/**
 * ------------------------------------------------------------
 * SchemaExecutor
 * ------------------------------------------------------------
 * ROLE:
 * Executes a precomputed schema plan
 *
 * IMPORTANT:
 * - NO decisions here
 * - ONLY execution
 * ------------------------------------------------------------
 */

class SchemaExecutor
{
    public static function execute(array $plan, MigrationBuilder $builder): void
    {
        foreach ($plan as $step) {

            switch ($step['type']) {

                case 'table':
                    // table already handled in builder
                    break;

                case 'column':
                    $builder->addColumn(
                        $step['meta']['column'],
                        $step['meta']['definition']['type'] ?? null,
                        $step['meta']['definition']['nullable'] ?? false,
                        $step['meta']['definition']['default'] ?? null
                    );
                    break;

                case 'index':
                    $builder->addIndex(
                        [$step['meta']['column']],
                        $step['id']
                    );
                    break;

                case 'unique':
                    $builder->addUnique(
                        [$step['meta']['column']],
                        $step['id']
                    );
                    break;

                case 'foreign_key':
                    $builder->addForeignKey(
                        $step['meta']['column'],
                        $step['meta']['references']['table'],
                        $step['meta']['references']['column'],
                        'CASCADE',
                        'CASCADE'
                    );
                    break;
            }
        }
    }
}