<?php

namespace DB\Schema\Compiler;

class SchemaCompiler
{
    public static function compileColumn(
        string $name,
        array $meta
    ): array {

        return [

            'name' => $name,

            'type' => self::mapType(
                $meta['type']
            ),

            'nullable' => $meta['nullable'] ?? false,

            'default' => $meta['default'] ?? null,

            'primary' => $meta['primary'] ?? false,

            'index' => $meta['index'] ?? false,

            'unique' => $meta['unique'] ?? false,
        ];
    }

    protected static function mapType(
        string $type
    ): string {

        return match ($type) {

            'serial' => 'BIGSERIAL',
            'string' => 'VARCHAR(255)',
            'text' => 'TEXT',
            'int' => 'INTEGER',
            'bigint' => 'BIGINT',
            'decimal' => 'DECIMAL(20,2)',
            'timestamp' => 'TIMESTAMP',
            'date' => 'DATE',
            'boolean' => 'BOOLEAN',

            default => 'TEXT'
        };
    }
}