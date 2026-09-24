<?php

namespace DB\Schema\Orchestrator\Dependency;

use Exception;

/**
 * ------------------------------------------------------------
 * TopologicalSorter
 * ------------------------------------------------------------
 * ROLE:
 * Ensures correct execution order based on dependencies
 *
 * Prevents:
 * - FK before column exists
 * - index before column exists
 * - circular dependencies
 * ------------------------------------------------------------
 */

class TopologicalSorter
{
    public static function sort(array $nodes): array
    {
        $visited = [];
        $temp = [];
        $sorted = [];

        foreach ($nodes as $node) {
            self::visit($node, $nodes, $visited, $temp, $sorted);
        }

        return $sorted;
    }

    private static function visit($node, $nodes, &$visited, &$temp, &$sorted)
    {
        if (isset($visited[$node['id']])) {
            return;
        }

        if (isset($temp[$node['id']])) {
            throw new Exception("Circular dependency: " . $node['id']);
        }

        $temp[$node['id']] = true;

        foreach ($node['dependsOn'] as $dep) {
            if (isset($nodes[$dep])) {
                self::visit($nodes[$dep], $nodes, $visited, $temp, $sorted);
            }
        }

        $visited[$node['id']] = true;
        unset($temp[$node['id']]);

        $sorted[] = $node;
    }
}