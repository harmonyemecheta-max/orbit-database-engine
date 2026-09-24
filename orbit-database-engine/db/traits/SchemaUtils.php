<?php
namespace DB\Traits;

trait SchemaUtils {
    public static function table(string $key): string {
        return static::TABLES[$key] 
            ?? throw new \InvalidArgumentException("Table $key missing.");
    }

    public static function cols(string $key): array {
        return static::COLUMNS[$key] 
            ?? throw new \InvalidArgumentException("Cols for $key missing.");
    }

    public static function col(string $table, string $column): string {
        return static::COLUMNS[$table][$column] 
            ?? throw new \InvalidArgumentException("Column $column missing in $table.");
    }

    // 🔥 RELATIONS

    public static function relations(string $table): array {
        return static::RELATIONS[$table] ?? [];
    }

    public static function relation(string $table, string $column): ?array {
        return static::RELATIONS[$table][$column] ?? null;
    }

    public static function resolveRelation(string $table, string $column): ?array {
        $rel = static::relation($table, $column);
        if (!$rel) return null;

        return [
            'local_table'  => static::table($table),
            'local_column' => static::col($table, $column),

            // 🔥 RESOLVE KEYS → REAL NAMES
            'ref_table'    => static::table($rel['ref_table']),
            'ref_column'   => static::col($rel['ref_table'], $rel['ref_column']),

            'on_delete'    => $rel['on_delete'] ?? null,
        ];
    }


    public static function buildJoin(string $table, string $column, ?string $alias = null): ?string {
        $rel = static::resolveRelation($table, $column);
        if (!$rel) return null;

        $refTable = $rel['ref_table'];
        $alias = $alias ?? $refTable;

        return sprintf(
            "LEFT JOIN %s AS %s ON %s.%s = %s.%s",
            $refTable,
            $alias,
            $rel['local_table'],
            $rel['local_column'],
            $alias,
            $rel['ref_column']
        );
    }


    public static function getRelatedTable(string $table, string $column): ?string
    {
        $rel = static::relation($table, $column);
        return $rel['ref_table'] ?? null;
    }

    public static function fk(string $table, string $column): ?string {
        $rel = static::resolveRelation($table, $column);
        if (!$rel) return null;

        return sprintf(
            "FOREIGN KEY (%s) REFERENCES %s(%s) ON DELETE %s",
            $rel['local_column'],
            $rel['ref_table'],
            $rel['ref_column'],
            $rel['on_delete'] ?? 'NO ACTION'
        );
    }



}