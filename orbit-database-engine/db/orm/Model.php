<?php
namespace DB\ORM;

use DB\DBManager;

abstract class Model
{
    protected string $table;
    protected string $connection = 'default';

    public function __construct()
    {
        if (!isset($this->table)) {
            $cls = (new \ReflectionClass($this))->getShortName();
            $this->table = strtolower($cls) . "s";
        }
    }

    public function findAll(int $limit = 100): array
    {
        $db = DBManager::connection($this->connection);

        if (method_exists($db, 'findAll')) {
            return $db->findAll($this->table);
        }

        throw new \Exception("findAll not supported for this driver.");
    }

    public static function query(): QueryBuilder
    {
        $driver = DBManager::connection((new static)->connection);
        return new QueryBuilder($driver)->table((new static)->table);
    }


}
