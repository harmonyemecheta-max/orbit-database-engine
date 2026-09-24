<?php

namespace DB\Migrations;

use DB\Drivers\DBDriverInterface;
use DB\Migrations\Contract\MigrationInterface;

abstract class BaseMigration implements MigrationInterface
{
    protected DBDriverInterface $db;

    protected MigrationBuilder $builder;

    public function __construct(DBDriverInterface $db)
    {
        $this->db = $db;

        $this->builder = new MigrationBuilder($db);
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function package(): string
    {
        return 'core';
    }
}