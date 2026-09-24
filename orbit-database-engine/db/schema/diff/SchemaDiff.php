<?php

namespace DB\Schema\Diff;

use DB\Migrations\MigrationBuilder;
use DB\Schema\Compiler\SchemaCompiler;

class SchemaDiff
{
public static function reconcileAll(
    MigrationBuilder $builder,
    array $definitions
): void {

    foreach ($definitions as $definition) {

        if (
            !is_array($definition)
            || empty($definition['table'])
        ) {

            error_log(
                '[SchemaDiff] Invalid schema definition: ' .
                print_r($definition, true)
            );

            continue;
        }

        self::reconcile($builder, $definition);
    }
}



public static function reconcile(
    MigrationBuilder $builder,
    array $definition
): void {

    if (empty($definition['table'])) {

        error_log(
            '[SchemaDiff] Missing table key in definition'
        );

        return;
    }

    $table = $definition['table'];

    if (!$builder->tableExists($table)) {
        return;
    }

    self::repairMissingColumns($builder, $definition);
    self::repairColumnTypes($builder, $definition);
    self::repairForeignKeys($builder, $definition);
}

    /*
    |--------------------------------------------------------------------------
    | MISSING COLUMNS
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | TYPE REPAIR
    |--------------------------------------------------------------------------
    */
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
    | FOREIGN KEYS
    |--------------------------------------------------------------------------
    */
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

            // 🔥 FIX: prevent duplicate FK creation
            if (self::foreignKeyExists($builder, $table, $fk['column'])) {
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
    | FOREIGN KEY EXISTS (FIXED MISSING METHOD)
    |--------------------------------------------------------------------------
    */
    protected static function foreignKeyExists(
        MigrationBuilder $builder,
        string $table,
        string $column
    ): bool {

        $db = self::getDb($builder);

        $sql = "
            SELECT constraint_name
            FROM information_schema.key_column_usage
            WHERE table_name = ?
            AND column_name = ?
        ";

        $rows = $db->fetchAll($sql, [$table, $column]);

        return !empty($rows);
    }

    /*
    |--------------------------------------------------------------------------
    | COLUMN EXISTS WRAPPER
    |--------------------------------------------------------------------------
    */
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
    | TYPE COMPARISON
    |--------------------------------------------------------------------------
    */
    protected static function typesMatch(string $current, string $expected): bool
    {
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

        $current = $map[$current] ?? $current;
        $expected = $map[$expected] ?? $expected;

        return str_contains($current, $expected)
            || str_contains($expected, $current);
    }

    /*
    |--------------------------------------------------------------------------
    | UNSAFE TYPE CHANGE
    |--------------------------------------------------------------------------
    */
    protected static function isUnsafeTypeChange(string $current, string $expected): bool
    {
        $current = strtolower($current);
        $expected = strtolower($expected);

        $stringFamily = ['character varying', 'varchar', 'text'];

        $integerFamily = ['integer', 'int', 'int4', 'bigint', 'int8', 'serial', 'bigserial'];

        $temporalFamily = ['timestamp', 'timestamp without time zone', 'date', 'datetime'];

        if (in_array($current, $integerFamily) && in_array($expected, $integerFamily)) {
            return false;
        }

        if (in_array($current, $stringFamily) && in_array($expected, $integerFamily)) {
            return true;
        }

        if (in_array($current, $stringFamily) && in_array($expected, $temporalFamily)) {
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | DB HELPERS
    |--------------------------------------------------------------------------
    */
    protected static function getDb(MigrationBuilder $builder)
    {
        $reflection = new \ReflectionClass($builder);
        $property = $reflection->getProperty('db');
        $property->setAccessible(true);

        return $property->getValue($builder);
    }

    protected static function getDatabaseColumns(MigrationBuilder $builder, string $table): array
    {
        $db = self::getDb($builder);

        $sql = "
            SELECT column_name, data_type
            FROM information_schema.columns
            WHERE table_name = ?
            AND table_schema = 'public'
        ";

        $rows = $db->fetchAll($sql, [$table]);

        $columns = [];

        foreach ($rows as $row) {
            $columns[$row['column_name']] = [
                'type' => $row['data_type']
            ];
        }

        return $columns;
    }

    /*
    |--------------------------------------------------------------------------
    | FOREIGN KEY COLLECTION
    |--------------------------------------------------------------------------
    */
    protected static function collectForeignKeys(array $definition): array
    {
        $foreignKeys = [];

        foreach ($definition['columns'] as $column => $meta) {

            if (empty($meta['foreign'])) {
                continue;
            }

            $foreignKeys[] = [
                'column' => $column,
                'references' => [
                    'table' => $meta['foreign']['table'],
                    'column' => $meta['foreign']['column'] ?? 'id',
                ],
                'delete' => $meta['foreign']['onDelete'] ?? 'CASCADE',
                'update' => $meta['foreign']['onUpdate'] ?? 'CASCADE',
            ];
        }

        return $foreignKeys;
    }

    /*
    |--------------------------------------------------------------------------
    | FK VALIDATION
    |--------------------------------------------------------------------------
    */
    protected static function canCreateForeignKey(
        MigrationBuilder $builder,
        string $table,
        string $column,
        string $refTable,
        string $refColumn
    ): bool {

        $db = self::getDb($builder);

        $sql = "
            SELECT c1.data_type AS source_type,
                   c2.data_type AS target_type
            FROM information_schema.columns c1,
                 information_schema.columns c2
            WHERE c1.table_name = ?
              AND c1.column_name = ?
              AND c2.table_name = ?
              AND c2.column_name = ?
        ";

        $row = $db->fetch($sql, [$table, $column, $refTable, $refColumn]);

        if (!$row) {
            return false;
        }

        return self::typesMatch($row['source_type'], $row['target_type']);
    }
}