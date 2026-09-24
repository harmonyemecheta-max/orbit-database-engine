<?php

namespace DB\Schema\Orchestrator;

use DB\Schema\Orchestrator\Dependency\GraphBuilder;
use DB\Schema\Orchestrator\Dependency\TopologicalSorter;

/**
 * ------------------------------------------------------------
 * DependencyGraph
 * ------------------------------------------------------------
 * ROLE:
 * Converts schema definitions into a DAG (Directed Acyclic Graph)
 *
 * OUTPUT:
 * Ordered execution graph for schema operations
 * ------------------------------------------------------------
 */
class DependencyGraph
{
    public static function build(array $definition): array
    {
        $nodes = GraphBuilder::build($definition);

        return TopologicalSorter::sort($nodes);
    }
}