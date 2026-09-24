<?php

namespace DB\Schema\Diff;

use DB\Migrations\MigrationBuilder;
use DB\Schema\Compiler\SchemaCompiler;

class SchemaDiff
{
    public static function reconcileAll(MigrationBuilder $builder, array $definitions): void
    {
        foreach ($definitions as $definition) {
            self::reconcile($builder, $definition);
        }
    }

    public static function reconcile(MigrationBuilder $builder, array $definition): void
    {
        $table = $definition['table'];

        if (!$builder->tableExists($table)) {
            return;
        }

        self::repairMissingColumns($builder, $definition);
        self::repairColumnTypes($builder, $definition);
        self::repairForeignKeys($builder, $definition);
    }


    private static function repairMissingColumns(MigrationBuilder $builder, array $definition): void
{
    $table = $definition['table'];
    $db = self::getDatabaseColumns($builder, $table);

    foreach ($definition['columns'] as $name => $meta) {

        if (isset($db[$name])) {
            continue;
        }

        $compiled = SchemaCompiler::compileColumn($name, $meta);

        $builder->alterAddColumn(
            $compiled['name'],
            $compiled['type'],
            $compiled['nullable'],
            $compiled['default']
        );
    }
}


private static function repairColumnTypes(MigrationBuilder $builder, array $definition): void
{
    $table = $definition['table'];
    $db = self::getDatabaseColumns($builder, $table);

    foreach ($definition['columns'] as $name => $meta) {

        if (!isset($db[$name])) {
            continue;
        }

        $compiled = SchemaCompiler::compileColumn($name, $meta);

        $current = strtolower($db[$name]['type']);
        $expected = strtolower($compiled['type']);

        if (self::typesMatch($current, $expected)) {
            continue;
        }

        if (self::isUnsafeTypeChange($current, $expected)) {
            continue;
        }

        $builder->modifyColumn(
            $name,
            $compiled['type'],
            $compiled['nullable'],
            $compiled['default']
        );
    }
}


    /*
    |--------------------------------------------------------------------------
    | TYPE COMPARISON
    |--------------------------------------------------------------------------
    */

    protected static function typesMatch(
        string $current,
        string $expected
    ): bool {

        $map = [

            'character varying' => 'varchar',
            'varchar' => 'varchar',

            'integer' => 'int',
            'int4' => 'int',
            'int' => 'int',

            'bigint' => 'bigint',
            'int8' => 'bigint',

            'serial' => 'int',
            'bigserial' => 'bigint',

            'timestamp without time zone' => 'timestamp',
            'timestamp' => 'timestamp',

            'text' => 'text',

            'json' => 'json',
            'jsonb' => 'json',
        ];


        $current =
            $map[$current] ?? $current;

        $expected =
            $map[$expected] ?? $expected;

        return
            str_contains($current, $expected)
            ||
            str_contains($expected, $current);
    }



private static function repairForeignKeys(MigrationBuilder $builder, array $definition): void
{
    $table = $definition['table'];

    $foreignKeys = self::collectForeignKeys($definition);

    foreach ($foreignKeys as $fk) {

        if (!self::columnExists($builder, $table, $fk['column'])) {
            continue;
        }

        if (!self::columnExists($builder, $fk['references']['table'], $fk['references']['column'])) {
            continue;
        }

        if (!self::canCreateForeignKey(
            $builder,
            $table,
            $fk['column'],
            $fk['references']['table'],
            $fk['references']['column']
        )) {
            continue;
        }

        if (self::foreignKeyExists(
                $builder,
                $table,
                $fk['column']
            )) {
                continue;
            }

        $builder->addForeignKey(
            $fk['column'],
            $fk['references']['table'],
            $fk['references']['column'],
            $fk['delete'],
            $fk['update']
        );
    }
}


    /*
    |--------------------------------------------------------------------------
    | GET DB
    |--------------------------------------------------------------------------
    */

    protected static function getDb(
        MigrationBuilder $builder
    ) {

        $reflection =
            new \ReflectionClass($builder);

        $property =
            $reflection->getProperty('db');

        $property->setAccessible(true);

        return $property->getValue($builder);
    }





protected static function canCreateForeignKey(
    MigrationBuilder $builder,
    string $table,
    string $column,
    string $refTable,
    string $refColumn
): bool {

    $db =
        self::getDb($builder);

    $sql = "
        SELECT
            c1.data_type AS source_type,
            c2.data_type AS target_type
        FROM information_schema.columns c1,
             information_schema.columns c2
        WHERE
            c1.table_name = ?
        AND c1.column_name = ?
        AND c2.table_name = ?
        AND c2.column_name = ?
    ";

    $row =
        $db->fetch(
            $sql,
            [
                $table,
                $column,
                $refTable,
                $refColumn
            ]
        );

    if (!$row) {
        return false;
    }

    return self::typesMatch(
        $row['source_type'],
        $row['target_type']
    );
}


    /*
    |--------------------------------------------------------------------------
    | INTERNAL HELPERS
    |--------------------------------------------------------------------------
    */

    protected static function getDatabaseColumns(
        MigrationBuilder $builder,
        string $table
    ): array {

        $reflection = new \ReflectionClass($builder);

        $dbProp =
            $reflection->getProperty('db');

        $dbProp->setAccessible(true);

        $db =
            $dbProp->getValue($builder);

        $driver =
            strtolower(get_class($db));

        /*
        |--------------------------------------------------------------------------
        | POSTGRESQL
        |--------------------------------------------------------------------------
        */

        if (
            strpos($driver, 'postgresql') !== false ||
            strpos($driver, 'pgsql') !== false
        ) {

            $sql = "
                SELECT
                    column_name,
                    data_type
                FROM information_schema.columns
                WHERE table_name = ?
                AND table_schema = 'public'
            ";

            $rows =
                $db->fetchAll($sql, [$table]);

            $columns = [];

            foreach ($rows as $row) {

                $columns[
                    $row['column_name']
                ] = [
                    'type' => $row['data_type']
                ];
            }

            return $columns;
        }

        return [];
    }



    protected static function isUnsafeTypeChange(
    string $current,
    string $expected
): bool {

    $current =
        strtolower($current);

    $expected =
        strtolower($expected);

    /*
    |--------------------------------------------------------------------------
    | STRING TYPES
    |--------------------------------------------------------------------------
    */

    $stringFamily = [

        'character varying',
        'varchar',
        'text',
    ];

    /*
    |--------------------------------------------------------------------------
    | INTEGER TYPES
    |--------------------------------------------------------------------------
    */

    $integerFamily = [

        'integer',
        'int',
        'int4',

        'bigint',
        'int8',

        'serial',
        'bigserial',
    ];

    /*
    |--------------------------------------------------------------------------
    | TEMPORAL TYPES
    |--------------------------------------------------------------------------
    */

    $temporalFamily = [

        'timestamp',
        'timestamp without time zone',
        'date',
        'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | NUMERIC FAMILY CHANGES
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | INTEGER FAMILY
    |--------------------------------------------------------------------------
    |
    | integer -> bigint
    | serial  -> bigint
    | int4    -> int8
    |
    | These are safe upgrades.
    |
    */

    if (
        in_array($current, $integerFamily)
        &&
        in_array($expected, $integerFamily)
    ) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | STRING -> NUMERIC
    |--------------------------------------------------------------------------
    */

    if (in_array($current, $stringFamily) && in_array($expected, $integerFamily)) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | STRING -> TEMPORAL
    |--------------------------------------------------------------------------
    */

    if (in_array($current, $stringFamily) && in_array($expected, $temporalFamily)) {
        return true;
    }

    return false;
}

protected static function columnExists(
    MigrationBuilder $builder,
    string $table,
    string $column
): bool {

    $builder->table($table);

    return $builder->columnExists($column);
}


    /*
|--------------------------------------------------------------------------
| COLLECT FOREIGN KEYS FROM COLUMN DEFINITIONS
|--------------------------------------------------------------------------
|
| Your schema stores FK definitions inside columns:
|
| 'user_id' => [
|     'foreign' => [...]
| ]
|
| This converts them into a normalized structure.
|
*/

protected static function collectForeignKeys(
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