<?php

namespace DB\Schema\Provisioner;

use DB\Migrations\MigrationBuilder;
use DB\Schema\Compiler\SchemaCompiler;

class SchemaProvisioner
{
    public static function provision(
        MigrationBuilder $builder,
        array $definitions
    ): void {

        foreach ($definitions as $definition) {

            self::provisionTable(
                $builder,
                $definition
            );
        }
    }

    private static function provisionTable(
        MigrationBuilder $builder,
        array $definition
    ): void {

        if (empty($definition['table'])) {
            return;
        }

        $table = $definition['table'];

        $builder->table($table);

        /*
        |--------------------------------------------------------------------------
        | TABLE ALREADY EXISTS
        |--------------------------------------------------------------------------
        */

        if ($builder->tableExists($table)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | COLUMNS
        |--------------------------------------------------------------------------
        */

        foreach (
            $definition['columns']
            as $name => $meta
        ) {

            $compiled =
                SchemaCompiler::compileColumn(
                    $name,
                    $meta
                );

            $builder->addColumn(
                $compiled['name'],
                $compiled['type'],
                $compiled['nullable'],
                $compiled['default']
            );

            /*
            |--------------------------------------------------------------------------
            | PRIMARY KEY
            |--------------------------------------------------------------------------
            */

            if ($compiled['primary']) {

                $builder->primary(
                    $compiled['name']
                );
            }

            /*
            |--------------------------------------------------------------------------
            | INDEX (QUEUE)
            |--------------------------------------------------------------------------
            */

            if (!empty($meta['index'])) {

                $builder->index(
                    [$name],
                    $table . '_' . $name . '_idx'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | UNIQUE (QUEUE)
            |--------------------------------------------------------------------------
            */

            if (!empty($meta['unique'])) {

                $builder->unique(
                    [$name],
                    $table . '_' . $name . '_unique'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | TABLE LEVEL UNIQUES
        |--------------------------------------------------------------------------
        */

        if (!empty($definition['unique'])) {

            foreach (
                $definition['unique']
                as $unique
            ) {

                $cols =
                    $unique['columns'];

                $builder->unique(
                    $cols,
                    $table .
                    '_' .
                    implode('_', $cols) .
                    '_unique'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | FOREIGN KEYS (QUEUE)
        |--------------------------------------------------------------------------
        */

        foreach (
            self::collectForeignKeys($definition)
            as $fk
        ) {

            $builder->foreign(
                $fk['column'],
                $fk['references']['table'],
                $fk['references']['column'],
                $fk['delete'],
                $fk['update']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | CREATE TABLE
        |--------------------------------------------------------------------------
        */

        $builder->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | COLLECT FOREIGN KEYS
    |--------------------------------------------------------------------------
    */

    private static function collectForeignKeys(
        array $definition
    ): array {

        $foreignKeys = [];

        foreach (
            $definition['columns']
            as $column => $meta
        ) {

            if (
                empty($meta['foreign'])
            ) {
                continue;
            }

            $foreignKeys[] = [

                'column' => $column,

                'references' => [

                    'table' =>
                        $meta['foreign']['table'],

                    'column' =>
                        $meta['foreign']['column']
                        ?? 'id',
                ],

                'delete' =>
                    $meta['foreign']['onDelete']
                    ?? 'CASCADE',

                'update' =>
                    $meta['foreign']['onUpdate']
                    ?? 'CASCADE',
            ];
        }

        return $foreignKeys;
    }
}