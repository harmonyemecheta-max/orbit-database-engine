<?php

namespace DB\Schema\Definitions;

use DB\Schema\Contracts\SchemaDefinitionInterface;

class CoreSchema implements SchemaDefinitionInterface
{
    public static function definition(): array
    {
        return [

            'USERS' => [

                'table' => 'users',

                'columns' => [

                    'id' => [
                        'type' => 'serial',
                        'primary' => true
                    ],

                    'email' => [
                        'type' => 'string',
                        'nullable' => false,
                        'index' => true
                    ],

                    'first_name' => [
                        'type' => 'string'
                    ],

                    'last_name' => [
                        'type' => 'string'
                    ]
                ]
            ]
        ];
    }
}