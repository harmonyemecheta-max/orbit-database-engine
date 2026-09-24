<?php

namespace DB\Schema\Definitions;

use DB\Schema\Contracts\SchemaDefinitionInterface;

abstract class BaseSchema implements SchemaDefinitionInterface
{
    protected static function table(
        string $name,
        array $columns,
        array $indexes = [],
        array $foreign = [],
        array $meta = []
    ): array {
        return [
            'table' => $name,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign' => $foreign,
            'meta' => $meta,
        ];
    }
}