<?php

namespace DB\ORM;

class MySQLGrammar extends Grammar
{
    public function wrap(string $identifier): string
    {
        return '`' . str_replace('.', '`.`', $identifier) . '`';
    }
}
