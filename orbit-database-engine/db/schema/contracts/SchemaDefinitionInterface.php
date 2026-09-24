<?php

namespace DB\Schema\Contracts;

interface SchemaDefinitionInterface
{
    public static function definition(): array;
}