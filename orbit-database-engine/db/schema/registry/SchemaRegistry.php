<?php

namespace DB\Schema\Registry;

class SchemaRegistry
{
    private static array $schemas = [];

    public static function register(string $package, array $definitions): void
    {
        self::$schemas[$package] = $definitions;
    }

    public static function all(): array
    {
        return self::$schemas;
    }

    public static function package(string $package): array
    {
        return self::$schemas[$package] ?? [];
    }
}