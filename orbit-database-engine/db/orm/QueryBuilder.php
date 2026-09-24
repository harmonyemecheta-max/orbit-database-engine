<?php
namespace DB\ORM;

use DB\Drivers\DBDriverInterface;
use Exception;
use DB\DBManager;
use DB\ORM\Grammar;
use DB\ORM\PostgreSQLGrammar;


class QueryBuilder
{
    // ============================
    // PROPERTIES
    // ============================
    public array $columns = ['*'];
    public array $where = [];
    public array $joins = [];
    public array $orderBy = [];
    public ?int $limit = null;
    public ?int $offset = null;
    public array $bindings = [];
    public array $groupBy = [];
    public array $having = [];
    public bool $distinct = false;

        // 1. ADD THIS PROPERTY FOR THE LOCK TRACKER
    public ?string $lock = null; 


    public ?string $table = null;
    public DBDriverInterface $db;
    public Grammar $grammar;

    // ============================
    // CONSTRUCTOR
    // ============================
    public function __construct(DBDriverInterface $driver)
    {
        $this->db = $driver;
        $this->grammar = $driver->grammar();
    }

       // 2. ADD THIS METHOD TO THE ENGINE
    /**
     * Add a pessimistic "shared-nothing" lock to the row being selected.
     */
    public function lockForUpdate(): self
    {
        $q = $this->cloneSelf();
        $q->lock = 'FOR UPDATE';
        return $q;
    }

    // ============================
    // IMMUTABILITY
    // ============================
    public function cloneSelf(): self
    {
        return clone $this;
    }

    // ============================
    // TABLE & SELECT
    // ============================

    public function table(string $table, ?string $alias = null): self
    {
        $q = $this->cloneSelf();
        
        if ($alias) {
            // Explicitly format as "table" AS "alias"
            $q->table = $this->grammar->wrap($table) . ' AS ' . $this->grammar->wrap($alias);
        } else {
            // Fallback: If user passed "users u", split it
            if (strpos($table, ' ') !== false) {
                $parts = preg_split('/\s+(as\s+)?/i', trim($table));
                $q->table = $this->grammar->wrap($parts[0]) . ' AS ' . $this->grammar->wrap($parts[1]);
            } else {
                $q->table = $this->grammar->wrap($table);
            }
        }
        return $q;
    }

    /**
     * Select columns for the query.
     * * @param array|string ...$columns
     * @return self
     */
    public function select(array|string ...$columns): self
    {
        $q = $this->cloneSelf();
        
        // If the first argument passed was an explicit array, flatten it 
        // (e.g. ->select(['id', 'name']))
        if (isset($columns[0]) && is_array($columns[0])) {
            $columns = $columns[0];
        }

        $q->columns = $columns;
        return $q;
    }

    // Add this helper snippet into your QueryBuilder class file:
    /**
     * Execute a direct raw SQL reading query statement against the driver instance.
     *
     * @param string $sql
     * @param array $bindings
     * @return array
     */
    public function raw(string $sql, array $bindings = []): array
    {
        return $this->db->fetchAll($sql, $bindings);
    }

    public function selectRaw(string $rawSql, array $bindings = []): self
    {
        $q = $this->cloneSelf();
        
        // If we haven't set columns yet, remove the default '*'
        if ($q->columns === ['*']) {
            $q->columns = [];
        }
        
        // Push the raw string into the array
        $q->columns[] = $rawSql;
        
        $q->bindings = array_merge($q->bindings, $bindings);
        return $q;
    }    

    public function updateRaw(string $column, string $expression, array $bindings = []): int
    {
        if (empty($this->where)) {
            throw new Exception("Mass update prevented: WHERE clause required.");
        }

        $sql = "UPDATE {$this->table} SET {$this->escapeIdentifier($column)} = {$expression}";
        $sql .= $this->grammar->compileWhere($this);

        $finalBindings = array_merge($bindings, $this->bindings);

        return $this->db->execute($sql, $finalBindings);
    }

    // Add this inside your QueryBuilder class
    public function updateRawMultiple(array $columnsToExpressions, array $bindings = []): int
    {
        if (empty($this->where)) {
            throw new Exception("Mass update prevented: WHERE clause required.");
        }

        $setParts = [];
        foreach ($columnsToExpressions as $column => $expression) {
            $setParts[] = $this->escapeIdentifier($column) . " = " . $expression;
        }

        $setSql = implode(', ', $setParts);
        $whereSql = $this->grammar->compileWhere($this); // builds WHERE + bindings
        $finalBindings = array_merge($bindings, $this->bindings);

        $sql = "UPDATE {$this->table} SET {$setSql}{$whereSql}";
        return $this->db->execute($sql, $finalBindings);
    }
    public function distinct(bool $state = true): self
    {
        $q = $this->cloneSelf();
        $q->distinct = $state;
        return $q;
    }

    // ============================
    // WHERE
    // ============================


    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        $q = $this->cloneSelf();
        $q->where[] = [
            'type' => 'raw',
            'boolean' => 'OR',
            'sql' => $sql,
            'bindings' => $bindings
        ];
        return $q;
    }

    public function whereNotIn(string $column, $values): self
    {
        $q = $this->cloneSelf();
        $vals = is_array($values) ? $values : [$values];
        $placeholders = implode(',', array_fill(0, count($vals), '?'));

        $q->where[] = [
            'type' => 'raw',
            'boolean' => 'AND',
            'sql' => $this->grammar->wrap($column) . " NOT IN ($placeholders)",
            'bindings' => array_values($vals)
        ];

        return $q;
    }

    public function where($column, ?string $operator = null, $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof \Closure) {
            return $this->whereGroup($column, $boolean);
        }

        // If only 2 arguments are provided, assume the operator is '='
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $q = $this->cloneSelf();
        $q->where[] = [
            'type' => 'basic',
            'boolean' => $boolean,
            'column' => $column, 
            'operator' => $operator,
            'value' => $value
        ];
        return $q;
    }

    public function orWhere(string $column, string $operator, $value): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }
    public function whereGroup(callable $callback, string $boolean = 'AND'): self
    {
        $q = $this->cloneSelf();
        // Use 'new static' and pass the existing driver to keep grammar consistency
        $group = new static($this->db); 
        $callback($group);

        if (!empty($group->where)) {
            $q->where[] = [
                'type' => 'group',
                'boolean' => $boolean,
                'conditions' => $group->where
            ];
        }
        return $q;
    }
    public function orWhereGroup(callable $callback): self
    {
        return $this->whereGroup($callback, 'OR');
    }
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $q = $this->cloneSelf();
        $q->where[] = [
            'type' => 'raw',
            'boolean' => $boolean,
            'sql' => $sql,
            'bindings' => $bindings
        ];
        return $q;
    }    

    public function whereIn(string $column, $values): self
    {
        $q = $this->cloneSelf();
        $vals = is_array($values) ? $values : [$values];
        $placeholders = implode(',', array_fill(0, count($vals), '?'));
        
        $q->where[] = [
            'type' => 'raw',
            'boolean' => 'AND',
            'sql' => $this->grammar->wrap($column) . " IN ($placeholders)",
            'bindings' => array_values($vals) // Stored here for the compiler
        ];
        return $q;
    }
    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $q = $this->cloneSelf();
        $q->where[] = [
            'type' => 'raw',
            'boolean' => $boolean,
            // We use the grammar to wrap the column, then append the SQL check
            'sql' => $this->grammar->wrap($column) . " IS NULL"
        ];
        return $q;
    }
    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $q = $this->cloneSelf();
        $q->where[] = [
            'type' => 'raw',
            'boolean' => $boolean,
            'sql' => $this->grammar->wrap($column) . " IS NOT NULL"
        ];
        return $q;
    }
    public function orWhereNull(string $column): self
    {
        return $this->whereNull($column, 'OR');
    }
    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'OR');
    }

    // ============================
    // JOIN
    // ============================

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER', ?string $alias = null): self
    {
        $q = $this->cloneSelf();
        
        // 1. If an explicit alias is passed (like from your LoginController)
        if ($alias) {
            $tableStr = $this->grammar->wrap($table) . ' AS ' . $this->grammar->wrap($alias);
        } 
        // 2. If the table string contains a space (like "organizations o")
        elseif (strpos($table, ' ') !== false) {
            $parts = preg_split('/\s+(as\s+)?/i', trim($table));
            $tableStr = $this->grammar->wrap($parts[0]) . ' AS ' . $this->grammar->wrap($parts[1]);
        } 
        // 3. Just a plain table name
        else {
            $tableStr = $this->grammar->wrap($table);
        }

        $q->joins[] = [
            'type'     => strtoupper($type),
            'table'    => $tableStr,
            'first'    => $this->escapeIdentifier($first), 
            'operator' => $operator,
            'second'   => $this->escapeIdentifier($second),
        ];
        return $q;
    }    

    public function innerJoin(string $table, string $first, string $operator, string $second, ?string $alias = null): static
    {
        return $this->join($table, $first, $operator, $second, 'inner', $alias);
    }

    public function leftJoin(string $table, string $first, string $operator, string $second, ?string $alias = null): static
    {
        return $this->join($table, $first, $operator, $second, 'left', $alias);
    }


    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }


    public function crossJoin(string $table): static
    {
        $q = $this->cloneSelf();

        $q->joins[] = [
            'type'  => 'CROSS',
            'table' => $this->escapeIdentifier($table),
        ];

        return $q;
    }




    // ============================
    // ORDER / LIMIT / OFFSET
    // ============================
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $q = $this->cloneSelf();
        $q->orderBy[] = [
            'column' => $this->escapeIdentifier($column),
            'direction' => strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC'
        ];
        return $q;
    }

    public function limit(int $limit): self
    {
        $q = $this->cloneSelf();
        $q->limit = $limit;
        return $q;
    }

    public function offset(int $offset): self
    {
        $q = $this->cloneSelf();
        $q->offset = $offset;
        return $q;
    }

    // ============================
    // HAVING / GROUP BY
    // ============================
    public function having(string $column, string $operator, $value, string $boolean = 'AND'): self
    {
        $q = $this->cloneSelf();
        $q->having[] = [
            'boolean' => $boolean,
            'column' => $this->escapeIdentifier($column),
            'operator' => $operator,
            'value' => $value
        ];
        return $q;
    }

    public function orHaving(string $column, string $operator, $value): self
    {
        return $this->having($column, $operator, $value, 'OR');
    }

    // In QueryBuilder.php, around line 320

    /**
     * Add a GROUP BY clause to the query.
     * * @param array|string $columns
     * @return self
     */


    public function groupBy($columns): self
    {
        $q = $this->cloneSelf();
        
        // Ensure $columns is an array even if a single string is passed
        if (!is_array($columns)) {
            $columns = [$columns];
        }

        foreach ($columns as $col) {
            $q->groupBy[] = $this->escapeIdentifier($col);
        }
        return $q;
    }


    // ============================
    // EXECUTION: DQL / DML
    // ============================

/**
     * Get an array with the values of a given column.
     *
     * @param string $column
     * @return array
     */
    public function pluck(string $column): array
    {
        $q = $this->cloneSelf();
        
        // Force the select to only the column we want
        $q->columns = [$column];
        
        $results = $q->get();

        // Flatten the array: from [['id' => 1], ['id' => 2]] to [1, 2]
        return array_map(function ($row) use ($column) {
            // Strip table alias if present (e.g., 'users.id' -> 'id')
            $key = strpos($column, '.') !== false ? substr($column, strrpos($column, '.') + 1) : $column;
            return $row[$key] ?? null;
        }, $results);
    }
    
    public function find(mixed $id, string $key = 'id'): ?array
    {
        return $this->where($key, '=', $id)->first();
    }

    public function get(): array
    {
        // Do NOT clear bindings here if compileSelect relies on them being built
        $sql = $this->grammar->compileSelect($this);
        // Use the bindings that were populated DURING compilation
        return $this->db->fetchAll($sql, $this->bindings);
    }

    public function first(): ?array
    {
        $q = $this->limit(1);
        $sql = $q->grammar->compileSelect($q);
        return $q->db->fetchOne($sql, $q->bindings);
    }


    public function insert(array $data): bool
    {
        $columns = array_map([$this, 'escapeIdentifier'], array_keys($data));
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$this->table} (" . implode(',', $columns) . ") VALUES ({$placeholders})";

        return $this->db->execute($sql, array_values($data)) > 0;
    }

    public function update(array $data): int
    {
        if (empty($this->where)) {
            throw new Exception("Mass update prevented: WHERE clause required.");
        }

        // Build SET clause
        $setParts = [];
        $setBindings = [];

        foreach ($data as $column => $value) {
            $setParts[] = $this->escapeIdentifier($column) . " = ?";
            $setBindings[] = $value;
        }

        $setSql = implode(', ', $setParts);

        // Build WHERE separately
        $whereSql = $this->grammar->compileWhere($this);
        $whereBindings = $this->bindings; // capture bindings created by WHERE

        $sql = "UPDATE {$this->table} SET {$setSql}{$whereSql}";

        // Merge in correct order
        $finalBindings = array_merge($setBindings, $whereBindings);


        return $this->db->execute($sql, $finalBindings);

    }



    public function delete(): int
    {
        if (empty($this->where)) throw new Exception("Mass delete prevented: WHERE clause required.");
        $this->bindings = [];
        $sql = "DELETE FROM {$this->table}" . $this->grammar->compileWhere($this);
        return $this->db->execute($sql, $this->bindings);
    }

    public function insertGetId(array $data, string $keyName = 'id'): int|string
    {
        $columns = array_map([$this, 'escapeIdentifier'], array_keys($data));
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$this->table} (" . implode(',', $columns) . ") VALUES ({$placeholders})";

        if ($this->grammar instanceof PostgreSQLGrammar) {
            // PostgreSQL requires RETURNING id
            $sql .= " RETURNING " . $this->escapeIdentifier($keyName);
            $result = $this->db->fetchOne($sql, array_values($data));
            return $result[$keyName] ?? 0;
        }

        // Default (MySQL) behavior
        $this->db->execute($sql, array_values($data));
        return $this->db->lastInsertId();
    }

    // ============================
    // AGGREGATE FUNCTIONS
    // ============================

    private function aggregate(string $fn, string $column = '*'): mixed
    {
        $q = $this->cloneSelf();
        $q->bindings = [];
        
        // Override columns for the aggregate call
        $wrappedCol = ($column === '*') ? '*' : $q->escapeIdentifier($column);
        $q->columns = ["{$fn}({$wrappedCol}) as agg"];
        
        $sql = $q->grammar->compileSelect($q);

        $row = $q->db->fetchOne($sql, $q->bindings);
        return $row['agg'] ?? null;
    }


    public function count(string $column = '*'): int { return (int)$this->aggregate('COUNT', $column); }
    public function sum(string $column): float { return (float)$this->aggregate('SUM', $column); }
    public function avg(string $column): float { return (float)$this->aggregate('AVG', $column); }
    public function min(string $column) { return $this->aggregate('MIN', $column); }
    public function max(string $column) { return $this->aggregate('MAX', $column); }
    public function exists(): bool { return $this->count() > 0; }

    // ============================
    // DEBUG
    // ============================
    public function toSql(): string
    {
        $this->bindings = [];
        return $this->grammar->compileSelect($this);
    }


    public function getBindings(): array
    {
        return $this->bindings;
    }

    // ============================
    // INTERNAL COMPILERS
    // ============================


    public function compileOrder(): string
    {
        if (empty($this->orderBy)) return '';
        $segments = array_map(fn($o) => "{$o['column']} {$o['direction']}", $this->orderBy);
        return ' ORDER BY ' . implode(', ', $segments);
    }

    public function compileLimitOffset(): string
    {
        $sql = '';
        if ($this->limit !== null) $sql .= " LIMIT {$this->limit}";
        if ($this->offset !== null) $sql .= " OFFSET {$this->offset}";
        return $sql;
    }

    public function compileGroupBy(): string
    {
        if (empty($this->groupBy)) return '';
        return ' GROUP BY ' . implode(', ', $this->groupBy);
    }

    public function compileHaving(): string
    {
        if (empty($this->having)) return '';
        $segments = [];
        foreach ($this->having as $index => $cond) {
            $prefix = $index === 0 ? '' : " {$cond['boolean']} ";
            $segments[] = $prefix . "{$cond['column']} {$cond['operator']} ?";
            $this->bindings[] = $cond['value'];
        }
        return ' HAVING ' . implode('', $segments);
    }

public function escapeIdentifier(string $name): string
{
    $name = trim($name);

    // 1. Handle the "all columns" wildcard specifically
    if ($name === '*') {
        return '*';
    }

    // 2. Detect alias patterns: "table alias" or "table AS alias"
    if (preg_match('/^([A-Za-z0-9_.]+)\s+(?:as\s+)?([A-Za-z0-9_]+)$/i', $name, $m)) {
        $table = $this->wrap($m[1]);
        $alias = $this->wrap($m[2]);
        return "{$table} AS {$alias}";
    }

    // 3. Handle "table.*" patterns
    if (strpos($name, '.*') !== false) {
        $parts = explode('.', $name);
        // Wrap everything except the last part (the *)
        return $this->wrap($parts[0]) . '.*';
    }

    // 4. Update the strict regex to allow the dot for table.column
    if (!preg_match('/^[A-Za-z0-9_.]+$/', $name)) {
        throw new Exception("Invalid identifier: {$name}");
    }

    return $this->wrap($name);
}


    public function wrap(string $name): string
    {
        return $this->grammar->wrap($name);
    }
    

public function upsert(array $data, array $uniqueBy, ?array $updateColumns = null): int
{
    if (!$this->table) {
        throw new Exception("Table not specified for upsert.");
    }

    if (empty($data)) {
        throw new Exception("Upsert data cannot be empty.");
    }

    $columns = array_keys($data);
    $wrappedColumns = array_map([$this, 'escapeIdentifier'], $columns);

    $placeholders = implode(',', array_fill(0, count($data), '?'));
    $bindings = array_values($data);

    $driver = $this->db; // DBDriverInterface
    $grammarClass = get_class($this->grammar);

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL
    |--------------------------------------------------------------------------
    */
    if ($this->grammar instanceof PostgreSQLGrammar) {

        $conflictCols = implode(',', array_map([$this, 'escapeIdentifier'], $uniqueBy));

        if ($updateColumns === null) {
            $updateColumns = array_diff($columns, $uniqueBy);
        }

        $updates = implode(', ', array_map(
            fn($col) => $this->escapeIdentifier($col) . " = EXCLUDED." . $this->escapeIdentifier($col),
            $updateColumns
        ));

        $sql = "INSERT INTO {$this->table} (" . implode(',', $wrappedColumns) . ")
                VALUES ({$placeholders})
                ON CONFLICT ({$conflictCols})
                DO UPDATE SET {$updates}";

        return $driver->execute($sql, $bindings);
    }

    /*
    |--------------------------------------------------------------------------
    | MySQL
    |--------------------------------------------------------------------------
    */
    if ($this->grammar instanceof \DB\ORM\MySQLGrammar) {

        if ($updateColumns === null) {
            $updateColumns = array_diff($columns, $uniqueBy);
        }

        $updates = implode(', ', array_map(
            fn($col) => $this->escapeIdentifier($col) . " = VALUES(" . $this->escapeIdentifier($col) . ")",
            $updateColumns
        ));

        $sql = "INSERT INTO {$this->table} (" . implode(',', $wrappedColumns) . ")
                VALUES ({$placeholders})
                ON DUPLICATE KEY UPDATE {$updates}";

        return $driver->execute($sql, $bindings);
    }

    /*
    |--------------------------------------------------------------------------
    | Unsupported drivers
    |--------------------------------------------------------------------------
    */
    throw new Exception("Upsert not supported for this database driver.");
}


}














