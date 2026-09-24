<?php

namespace DB\Schema\Orchestrator\Dependency;

/**
 * ------------------------------------------------------------
 * GraphBuilder
 * ------------------------------------------------------------
 * ROLE:
 * Converts schema definition → dependency nodes
 *
 * Each node represents:
 * - table
 * - column
 * - index
 * - unique constraint
 * - foreign key
 * ------------------------------------------------------------
 */

class GraphBuilder
{
    public static function build(array $definition): array
    {
        $nodes = [];
        $table = $definition['table'];

        // TABLE NODE
        $nodes["table:$table"] = [
            'id' => "table:$table",
            'type' => 'table',
            'dependsOn' => [],
            'meta' => $definition
        ];

        foreach ($definition['columns'] as $col => $meta) {

            $colId = "column:$table.$col";

            $nodes[$colId] = [
                'id' => $colId,
                'type' => 'column',
                'dependsOn' => ["table:$table"],
                'meta' => [
                    'column' => $col,
                    'table' => $table,
                    'definition' => $meta
                ]
            ];

            // INDEX
            if (!empty($meta['index'])) {
                $nodes["index:$table.$col"] = [
                    'id' => "index:$table.$col",
                    'type' => 'index',
                    'dependsOn' => [$colId],
                    'meta' => ['column' => $col, 'table' => $table]
                ];
            }

            // UNIQUE
            if (!empty($meta['unique'])) {
                $nodes["unique:$table.$col"] = [
                    'id' => "unique:$table.$col",
                    'type' => 'unique',
                    'dependsOn' => [$colId],
                    'meta' => ['column' => $col, 'table' => $table]
                ];
            }

            // FOREIGN KEY
            if (!empty($meta['foreign'])) {

                $refTable = $meta['foreign']['table'];
                $refCol   = $meta['foreign']['column'];

                $nodes["fk:$table.$col"] = [
                    'id' => "fk:$table.$col",
                    'type' => 'foreign_key',
                    'dependsOn' => [
                        $colId,
                        "column:$refTable.$refCol"
                    ],
                    'meta' => [
                        'column' => $col,
                        'table' => $table,
                        'references' => $meta['foreign']
                    ]
                ];
            }
        }

        return $nodes;
    }
}