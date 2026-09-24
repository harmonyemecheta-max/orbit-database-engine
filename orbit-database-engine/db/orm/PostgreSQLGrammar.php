<?php

namespace DB\ORM;

use DB\ORM\Grammar;

class PostgreSQLGrammar extends Grammar
{

    public function wrap(string $identifier): string {
        if ($identifier === '*') return '*';
        // If it's already quoted, don't re-quote it
        if (strpos($identifier, '"') !== false) return $identifier;
        
        return '"' . str_replace('.', '"."', $identifier) . '"';
    }

}
