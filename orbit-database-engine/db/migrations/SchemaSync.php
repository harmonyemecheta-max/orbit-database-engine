<?php
namespace DB\Migrations;

use DB\DBManager;


class SchemaSync
{
    public static function monitor(
        string $connectionName,
        string $tableName,
        array $schemaColumns,
        string $tableKey,
        string $schemaClass
    ): void {
        $db = DBManager::connection($connectionName);
        $builder = new MigrationBuilder($db);

        $builder->table($tableName);
        $hasColumnsToAdd = false;

        // =========================
        // CREATE TABLE
        // =========================
if (!$builder->tableExists($tableName)) {

    error_log("[SchemaSync] Creating table $tableName");

    // ❗ DO NOT reuse previous builder state blindly
    $builder = new MigrationBuilder($db);
    $builder->table($tableName);

    foreach ($schemaColumns as $columnName) {
        $builder->addColumn(
            $columnName,
            self::mapColumnToType($columnName),
            true
        );
    }

    // PRIMARY KEY must be part of CREATE definition
    if (in_array('id', $schemaColumns)) {
        $builder->primary('id');
    }

    $builder->execute();
    return;
}else {
        // --- ALTER MODE ---
            foreach ($schemaColumns as $columnName) {
                if (!$builder->columnExists(strtolower($columnName))) {
                    $type = self::mapColumnToType($columnName);
                    // This executes the ALTER immediately
                    $builder->alterAddColumn($columnName, $type, true); 
                }
            }
        }



        // =========================
        // ALTER TABLE - ADD MISSING COLUMNS
        // =========================
        foreach ($schemaColumns as $columnName) {
            if (!$builder->columnExists(strtolower($columnName))) {
                $type = self::mapColumnToType($columnName);
                error_log("[SchemaSync] HEALING: Adding $columnName to $tableName");
                $builder->alterAddColumn($columnName, $type, true);
                $hasColumnsToAdd = true;
            }
        }

        // =========================
        // ADD INDEXES
        // =========================
        if (defined("$schemaClass::INDEXES") && isset($schemaClass::INDEXES[$tableKey])) {
            foreach ($schemaClass::INDEXES[$tableKey] as $column) {
                $indexName = strtolower($tableName . '_' . $column . '_idx');
                if (!$builder->indexExists($indexName)) {
                    error_log("[SchemaSync] HEALING: Adding index $indexName");
                    $builder->addIndex($column, $indexName);
                    // indexes don't require execute in your MigrationBuilder
                }
            }
        }

        // =========================
        // EXECUTE ONLY IF COLUMNS TO ADD
        // =========================
   
        if ($hasColumnsToAdd) {
            $builder->execute();
        } else {
            //error_log("[SchemaSync] No column changes for $tableName, skipping execute.");
        }
          
    }




    
private static function mapColumnToType(string $col): string
{
    $col = strtolower($col);

    // Primary key
    if ($col === 'id') {
        return 'BIGSERIAL';
    }

    // Integer IDs
    if (str_ends_with($col, '_id') || $col === 'max_members') return 'int';

    // Money / numeric
    if (str_contains($col, 'balance') || str_contains($col, 'amount') || $col === 'price') {
        return 'decimal(20,2)';
    }

    // Strings
    if (str_contains($col, 'key') 
        || str_contains($col, 'name') 
        || str_contains($col, 'code') 
        || str_contains($col, 'currency') 
        || str_contains($col, 'period')
        || str_contains($col, 'status')) {
        return 'varchar(255)';
    }

    // Timestamp columns
    if (str_contains($col, 'created') || str_contains($col, 'updated')) return 'timestamp';

    // Everything else
    return 'text';
}



}