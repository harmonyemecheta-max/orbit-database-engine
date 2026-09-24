<?php
namespace DB\Schema\Repair;

use DB\Migrations\MigrationBuilder;
use DB\Schema\Diff\SchemaDiff;
use DB\Schema\Orchestrator\transaction\SchemaTransaction;

class SchemaRepair
{
    public static function repair(
        MigrationBuilder $builder,
        array $definitions
    ): void {

        SchemaTransaction::run(function ($builder) use ($definitions) {

            foreach ($definitions as $definition) {

                SchemaDiff::reconcile($builder, $definition);
            }

        }, $builder);
    }
}