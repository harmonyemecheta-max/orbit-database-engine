<?php

namespace DB\Migrations\Contract;

interface MigrationInterface
{
    public function up(): void;

    public function down(): void;

    public function version(): string;

    public function package(): string;
}