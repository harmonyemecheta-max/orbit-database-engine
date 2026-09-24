<?php
namespace DB\Schema;

class SchemaLock
{
    protected static array $locks = [];

    public static function acquire(string $key): void
    {
        if (isset(self::$locks[$key])) {
            throw new \Exception("Schema already running: {$key}");
        }

        self::$locks[$key] = true;
    }

    public static function release(string $key): void
    {
        unset(self::$locks[$key]);
    }
}