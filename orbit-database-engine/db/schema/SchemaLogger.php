<?php
namespace DB\Schema;

class SchemaLogger
{
    public static function info(string $msg): void
    {
        error_log("[SCHEMA] " . $msg);
    }
}