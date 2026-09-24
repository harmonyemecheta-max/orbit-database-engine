<?php
namespace DB\ORM;

use DB\ORM\QueryBuilder;
use DB\ORM\Expression;

abstract class Grammar
{
    abstract public function wrap(string $identifier): string;

    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        $sql = '';
        if ($limit !== null) $sql .= " LIMIT {$limit}";
        if ($offset !== null) $sql .= " OFFSET {$offset}";
        return $sql;
    }

    public function compileJoins(array $joins): string
    {
        if (empty($joins)) return '';

        $sql = '';
        foreach ($joins as $join) {
            $type = $join['type'];
            if ($type === 'CROSS') {
                $sql .= " {$type} JOIN {$join['table']}";
            } else {
                $sql .= " {$type} JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }
        return $sql;
    }

    /**
     * Compile a full SELECT statement from the QueryBuilder
     */
    public function compileSelect(QueryBuilder $query): string
    {
        $sql = 'SELECT ';

        if ($query->distinct) {
            $sql .= 'DISTINCT ';
        }

        // Process columns safely
        $processedColumns = array_map(function ($col) use ($query) {
            if ($col instanceof Expression) {
                if (!empty($col->bindings)) {
                    $query->bindings = array_merge($col->bindings, $query->bindings);
                }
                return $col->toSql();
            }

            if (is_string($col) && strpos($col, '(') !== false) {
                return $col;
            }

            return $query->escapeIdentifier($col);
        }, $query->columns);

        $sql .= implode(', ', $processedColumns);
        $sql .= ' FROM ' . $query->table;

        if (!empty($query->joins)) {
            $sql .= $this->compileJoins($query->joins);
        }

        // 🌟 LINE 85 CRITICAL FIX: Changed from $query->compileWhere() to $this->compileWhere($query)
        $sql .= $this->compileWhere($query);

        // GROUP BY (If these also throw errors later, we will move them here too!)
        $sql .= $query->compileGroupBy();
        $sql .= $query->compileHaving();
        $sql .= $query->compileOrder();

        $sql .= $this->compileLimitOffset($query->limit, $query->offset);

        if (!is_null($query->lock)) {
            $sql .= ' ' . $query->lock;
        }

        return $sql;
    }

    /**
     * Compile the WHERE clause from the QueryBuilder state
     */
    public function compileWhere(QueryBuilder $query): string
    {
        if (empty($query->where)) return '';

        $result = $this->compileNestedWhere($query, $query->where);
        
        if (!empty($result['bindings'])) {
            $query->bindings = array_merge($query->bindings, $result['bindings']);
        }
        
        return $result['sql'] ? " WHERE {$result['sql']}" : '';
    }

    /**
     * Recursively compile nested where arrays without side-effects
     */
    public function compileNestedWhere(QueryBuilder $query, array $conditions): array
    {
        $segments = [];
        $bindings = [];

        foreach ($conditions as $index => $cond) {
            $prefix = $index === 0 ? '' : " {$cond['boolean']} ";

            if ($cond['type'] === 'basic') {
                $column = $this->wrap($cond['column']);
                $segments[] = $prefix . "{$column} {$cond['operator']} ?";
                $bindings[] = $cond['value'];
            } 
            elseif ($cond['type'] === 'group') {
                $nestedResult = $this->compileNestedWhere($query, $cond['conditions']);
                if (!empty($nestedResult['sql'])) {
                    $segments[] = $prefix . "({$nestedResult['sql']})";
                    $bindings = array_merge($bindings, $nestedResult['bindings']);
                }
            } 
            elseif ($cond['type'] === 'raw') {
                $segments[] = $prefix . $cond['sql'];
                if (!empty($cond['bindings'])) {
                    $bindings = array_merge($bindings, $cond['bindings']);
                }
            }
        }

        return [
            'sql' => implode('', $segments),
            'bindings' => $bindings
        ];
    }

    public function wrapTable(string $table): string
    {
        $table = trim($table);
        if (preg_match('/^([A-Za-z0-9_.]+)+(?:as+)?([A-Za-z0-9_]+)$/i', $table, $m)) {
            return $this->wrap($m[1]) . ' AS ' . $this->wrap($m[2]);
        }
        return $this->wrap($table);
    }
}
