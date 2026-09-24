<?php

namespace DB\Schema\Builder;

class SchemaDefinitionBuilder
{
    public static function fromLegacy(
        array $tables,
        array $columns,
        array $relations = []
    ): array {

        $definitions = [];

        foreach ($tables as $tableKey => $tableName) {

            $definition = [
                'table' => $tableName,
                'columns' => [],
                'indexes' => [],
                'foreign' => [],
            ];

            /*
            |--------------------------------------------------------------------------
            | Columns
            |--------------------------------------------------------------------------
            */

            foreach (($columns[$tableKey] ?? []) as $columnKey => $columnName) {

                $definition['columns'][$columnName] = [
                    'type' => self::inferType($columnName),
                    'nullable' => true,
                ];

                /*
                |--------------------------------------------------------------------------
                | Primary Key
                |--------------------------------------------------------------------------
                */

                if ($columnName === 'id') {

                    $definition['columns'][$columnName] = [
                        'type' => 'serial',
                        'primary' => true,
                        'nullable' => false,
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Auto indexes
                |--------------------------------------------------------------------------
                */

                if (
                    str_contains($columnName, '_id')
                    || str_contains($columnName, '_key')
                    || $columnName === 'email'
                ) {

                    $definition['indexes'][] = [
                        'columns' => [$columnName]
                    ];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Relations
            |--------------------------------------------------------------------------
            */

            foreach (($relations[$tableKey] ?? []) as $localKey => $relation) {

                $localColumn =
                    $columns[$tableKey][$localKey] ?? null;

                if (!$localColumn) {
                    continue;
                }

                $refTableKey =
                    $relation['ref_table'];

                $refColumnKey =
                    $relation['ref_column'];

                $definition['foreign'][] = [

                    'column' => $localColumn,

                    'table' => $tables[$refTableKey],

                    'references' => $columns[$refTableKey][$refColumnKey],

                    'on_delete' => $relation['on_delete'] ?? 'CASCADE',
                ];


            }

            $definitions[$tableKey] = $definition;
        }

        return $definitions;
    }
protected static array $timestampColumns = [

    'created_at',
    'updated_at',
    'deleted_at',
    'last_login_at',
    'verified_at',
];

protected static function inferType(
    string $column
): string {

    $column = strtolower($column);

    return match (true) {

        /*
        |--------------------------------------------------------------------------
        | PRIMARY KEY
        |--------------------------------------------------------------------------
        */

        $column === 'id'
            => 'serial',

        /*
        |--------------------------------------------------------------------------
        | FOREIGN KEYS
        |--------------------------------------------------------------------------
        */

        str_ends_with($column, '_id')
            => 'bigint',

        /*
        |--------------------------------------------------------------------------
        | TIMESTAMPS
        |--------------------------------------------------------------------------
        */

        in_array(
            $column,
            self::$timestampColumns
        )
            => 'timestamp',

        /*
        |--------------------------------------------------------------------------
        | DECIMALS
        |--------------------------------------------------------------------------
        */

        str_contains($column, 'price'),
        str_contains($column, 'amount'),
        str_contains($column, 'cost'),
        str_contains($column, 'balance')
            => 'decimal',

        /*
        |--------------------------------------------------------------------------
        | BOOLEANS
        |--------------------------------------------------------------------------
        */

        str_starts_with($column, 'is_'),
        str_starts_with($column, 'has_'),
        str_starts_with($column, 'can_')
            => 'boolean',

        /*
        |--------------------------------------------------------------------------
        | JSON
        |--------------------------------------------------------------------------
        */

        str_ends_with($column, '_json'),
        str_ends_with($column, '_meta'),
        str_ends_with($column, '_payload')
            => 'json',

        /*
        |--------------------------------------------------------------------------
        | TEXT FIELDS
        |--------------------------------------------------------------------------
        */

        str_contains($column, 'description'),
        str_contains($column, 'content'),
        str_contains($column, 'body'),
        str_contains($column, 'notes')
            => 'text',

        /*
        |--------------------------------------------------------------------------
        | STRINGS
        |--------------------------------------------------------------------------
        */

        default
            => 'string'
    };
}



}