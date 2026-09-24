<?php

namespace DB\Schema\Orchestrator;

use DB\Schema\Orchestrator\Dependency\GraphBuilder;
use DB\Schema\Orchestrator\Dependency\TopologicalSorter;

/**
 * ------------------------------------------------------------
 * SchemaPlanner
 * ------------------------------------------------------------
 * ROLE:
 * Converts schema definition → execution plan
 *
 * OUTPUT:
 * Ordered list of actions (NO execution)
 * ------------------------------------------------------------
 */

class SchemaPlanner
{
    public static function create(array $definition): array
    {
        $nodes = GraphBuilder::build($definition);
        $sorted = TopologicalSorter::sort($nodes);

        $plan = [];

        foreach ($sorted as $node) {
            $plan[] = [
                'id' => $node['id'],
                'type' => $node['type'],
                'meta' => $node['meta'],
            ];
        }

        return $plan;
    }
}