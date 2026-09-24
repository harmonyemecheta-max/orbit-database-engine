<?php
namespace DB\ORM;

use DB\DBManager;

/**
 * BaseModel for SQL ORM capabilities.
 *
 * - Extends your lightweight Model
 * - Adds ActiveRecord ORM features for SQL drivers only
 * - Does NOT interfere with NoSQL drivers
 */
abstract class BaseModel extends Model
{
    protected string $primaryKey = 'id';
    protected bool $timestamps = true;      // created_at / updated_at
    protected bool $softDeletes = false;    // deleted_at column
    protected array $attributes = [];       // loaded row values
    protected array $original = [];         // original DB state (for dirty checking)
    protected bool $exists = false;         // whether the row exists in DB

    /**
     * Retrieve a record by primary key.
     */
    public static function find(mixed $id): ?static
    {
        $instance = new static();

        $row = DBManager::connection($instance->connection)
            ->query(
                "SELECT * FROM {$instance->table} WHERE {$instance->primaryKey} = ? LIMIT 1",
                [$id]
            );

        if (empty($row)) {
            return null;
        }

        return $instance->loadRow($row[0]);
    }

    /**
     * Load attributes into the model instance.
     */
    protected function loadRow(array $row): static
    {
        $this->attributes = $row;
        $this->original = $row;
        $this->exists = true;
        return $this;
    }

    /**
     * Return all attributes.
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * Magic getter for attribute access like: $user->email
     */
    public function __get(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Magic setter for: $user->name = "John"
     */
    public function __set(string $key, mixed $value)
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Save or update the model.
     */
    public function save(): bool
    {
        $db = DBManager::connection($this->connection);

        if (!method_exists($db, 'query')) {
            throw new \Exception("BaseModel requires an SQL-capable driver.");
        }

        // handle timestamps
        if ($this->timestamps) {
            $now = date("Y-m-d H:i:s");

            if (!$this->exists) {
                $this->attributes['created_at'] = $now;
            }

            $this->attributes['updated_at'] = $now;
        }

        if ($this->exists) {
            return $this->performUpdate();
        }

        return $this->performInsert();
    }

    /**
     * Perform INSERT.
     */
    protected function performInsert(): bool
    {
        $db = DBManager::connection($this->connection);

        $cols = array_keys($this->attributes);
        $vals = array_values($this->attributes);

        $placeholders = implode(", ", array_fill(0, count($cols), '?'));
        $colNames = implode(", ", $cols);

        $sql = "INSERT INTO {$this->table} ($colNames) VALUES ($placeholders)";
        $success = $db->query($sql, $vals);

        if ($success !== false) {
            $this->exists = true;

            // auto-set primary key for auto-incrementing IDs
            if ($this->primaryKey === 'id') {
                $id = $db->query("SELECT LAST_INSERT_ID() AS id");
                if ($id && isset($id[0]['id'])) {
                    $this->attributes['id'] = $id[0]['id'];
                }
            }
        }

        return $success !== false;
    }

    /**
     * Perform UPDATE using dirty-checking.
     */
    protected function performUpdate(): bool
    {
        $db = DBManager::connection($this->connection);

        // Only update changed fields
        $dirty = array_diff_assoc($this->attributes, $this->original);

        if (empty($dirty)) {
            return true; // nothing to save
        }

        $set = implode(", ", array_map(fn($c) => "$c = ?", array_keys($dirty)));
        $values = array_values($dirty);

        $sql = "UPDATE {$this->table} SET $set WHERE {$this->primaryKey} = ?";
        $values[] = $this->attributes[$this->primaryKey];

        $success = $db->query($sql, $values);

        if ($success !== false) {
            $this->original = $this->attributes;
        }

        return $success !== false;
    }

    /**
     * Delete the model.
     */
    public function delete(): bool
    {
        $db = DBManager::connection($this->connection);

        if (!method_exists($db, 'query')) {
            throw new \Exception("BaseModel delete() requires SQL driver.");
        }

        if ($this->softDeletes) {
            // soft delete = mark deleted_at
            $this->attributes['deleted_at'] = date("Y-m-d H:i:s");
            return $this->save();
        }

        // physical delete
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $success = $db->query($sql, [$this->attributes[$this->primaryKey]]);

        if ($success !== false) {
            $this->exists = false;
        }

        return $success !== false;
    }

    /**
     * Basic WHERE builder on BaseModel.
     */
    public static function where(string $column, string $operator, mixed $value): QueryBuilder
    {
        return static::query()->where($column, $operator, $value);
    }

    /**
     * Return first matching record.
     */
    public static function first(): ?static
    {
        $instance = static::query()->limit(1)->first();

        if (!$instance) {
            return null;
        }

        return (new static())->loadRow($instance);
    }

    /**
     * One-to-one relationship example
     */
    protected function hasOne(string $related, string $foreignKey, string $localKey = 'id'): mixed
    {
        $obj = new $related;
        return $related::where($foreignKey, '=', $this->attributes[$localKey])->first();
    }

    /**
     * One-to-many relationship example
     */
    protected function hasMany(string $related, string $foreignKey, string $localKey = 'id'): array
    {
        return $related::query()
            ->where($foreignKey, '=', $this->attributes[$localKey])
            ->get();
    }

    /**
     * Belongs-to relationship example
     */
    protected function belongsTo(string $related, string $ownerKey, string $foreignKey): mixed
    {
        $obj = new $related;
        return $related::where($ownerKey, '=', $this->attributes[$foreignKey])->first();
    }
}
