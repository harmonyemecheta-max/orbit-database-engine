<?php
namespace DB\ORM;

class Subquery
{
    public function __construct(
        public QueryBuilder $builder,
        public string $alias
    ) {}

    public static function make(QueryBuilder $builder, string $alias): self
    {
        return new self($builder, $alias);
    }
}
