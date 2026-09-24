<?php
namespace DB\ORM;

class Expression
{
    public function __construct(
        public string $sql,
        public array $bindings = []
    ) {}

    public static function raw(string $sql, array $bindings = []): self
    {
        return new self($sql, $bindings);
    }

    // ADD THIS METHOD TO FIX THE RED UNDERLINE
    /**
     * Get the raw SQL string representation of the expression.
     */
    public function toSql(): string
    {
        return $this->sql;
    }
}
