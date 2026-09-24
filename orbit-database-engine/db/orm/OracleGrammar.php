<?php
namespace DB\ORM;

class OracleGrammar extends Grammar
{
    public function wrap(string $identifier): string
    {
        return '"' . str_replace('.', '"."', $identifier) . '"';
    }

    public function compileLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        $sql = '';

        if ($offset !== null) {
            $sql .= " OFFSET {$offset} ROWS";
        }

        if ($limit !== null) {
            $sql .= " FETCH NEXT {$limit} ROWS ONLY";
        }

        return $sql;
    }
}
