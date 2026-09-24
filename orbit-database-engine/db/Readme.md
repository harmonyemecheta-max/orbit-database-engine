Perfect! Let’s do a **complete, structured documentation** of your HyperVirtue database & migration stack — everything from ORM to migrations, including all features and example usage. I’ll break it down module by module.

---

# **HyperVirtue DB & Migration System Documentation**

---

## **1. DB Drivers (`DB\Drivers`)**

### **Purpose:**

* Abstracts database access.
* Supports multiple databases (MySQL, PostgreSQL, Oracle, etc.).
* Provides safe, parameterized queries.

### **Interface: `DBDriverInterface`**

All drivers implement these:

* `query(string $sql, array $params = [])` – Execute queries.
* `fetchAll(string $sql, array $params = [])` – Fetch multiple rows.
* `insert(string $table, array $data)` – Insert a record.
* `update(string $table, array $data, array $where)` – Update records.
* `delete(string $table, array $where)` – Delete records.
* `grammar(): Grammar` – Return the driver-specific SQL grammar.

### **Example:**

```php
$mysql = new MySQLDriver($config);
$mysql->query("SELECT * FROM users WHERE active = ?", [1]);
```

### **Features:**

* DB-agnostic interface.
* Supports transactions: `begin()`, `commit()`, `rollback()`.
* Provides raw PDO access if needed.
* Supports schema introspection: list tables, list databases.

---

## **2. SQL Grammar (`DB\ORM\Grammar`)**

### **Purpose:**

* Handles DB-specific syntax differences.
* Wraps identifiers safely.
* Supports DB-agnostic column types.

### **Key Methods:**

* `wrap(string $identifier)` → Quotes identifiers per DB.
* Can be extended for MySQLGrammar, PostgreSQLGrammar, OracleGrammar.

### **Example:**

```php
$grammar = $db->grammar();
echo $grammar->wrap('users'); // MySQL: `users`, Postgres: "users"
```

---

## **3. ORM (`DB\ORM`)**

### **Purpose:**

* Fluent query builder.
* Works with any driver via DBDriverInterface.
* Supports select, insert, update, delete.

### **Features:**

* Query chaining: `->table()->where()->select()->get()`.
* DB-agnostic queries.
* Integrates with migrations for schema manipulation.

### **Example:**

```php
$users = $db->table('users')
            ->where('id', '=', 1)
            ->select(['id', 'name'])
            ->fetchAll();
```

---

## **4. Expression Builder (`DB\ORM\Expression`)**

### **Purpose:**

* Handles raw SQL expressions safely.
* Allows using DB functions in queries.

### **Example:**

```php
use DB\ORM\Expression;

$db->table('users')
   ->update(['updated_at' => new Expression('CURRENT_TIMESTAMP')])
   ->where('id', 1)
   ->execute();
```

---

## **5. Migration Base Class (`DB\Migrations\Migration`)**

### **Purpose:**

* Abstract base class for all migrations.
* Ensures access to DB driver and MigrationBuilder.

### **Constructor:**

```php
public function __construct(DBDriverInterface $connection)
```

### **Abstract Methods:**

* `up()` → Apply migration.
* `down()` → Rollback migration.

### **Example:**

```php
class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->builder->table('users')
                      ->addColumn('id', 'serial')
                      ->addColumn('name', 'string')
                      ->timestamps()
                      ->execute();
    }

    public function down(): void
    {
        $this->builder->table('users')->drop();
    }
}
```

---

## **6. Migration Builder (`DB\Migrations\MigrationBuilder`)**

### **Purpose:**

* Fluent, DB-agnostic API for table creation & modification.
* Supports columns, indexes, foreign keys, primary keys.
* Can alter tables: add/modify/rename/drop columns & tables.
* Supports safe operations: only apply changes if not exists.

### **Key Methods:**

| Method                                                                                             | Description                            |                |
| -------------------------------------------------------------------------------------------------- | -------------------------------------- | -------------- |
| `table(string $name)`                                                                              | Target table                           |                |
| `addColumn(string $name, string $type, bool $nullable = false, $default = null)`                   | Add a column                           |                |
| `primary(string                                                                                    | array $columns)`                       | Primary key    |
| `index(string                                                                                      | array $columns, ?string $name = null)` | Index creation |
| `foreign(string $col, string $refTable, string $refCol, $onDelete='CASCADE', $onUpdate='CASCADE')` | Foreign key                            |                |
| `timestamps()`                                                                                     | Add `created_at` and `updated_at`      |                |
| `execute()`                                                                                        | Apply table creation                   |                |
| `drop()`                                                                                           | Drop table                             |                |
| `renameTable(string $newName)`                                                                     | Rename table                           |                |
| `modifyColumn(string $col, string $type, bool $nullable, $default)`                                | Modify column                          |                |
| `renameColumn(string $old, string $new)`                                                           | Rename column                          |                |
| `safeAddColumn(...)`                                                                               | Add only if column doesn't exist       |                |
| `safeDropColumn(...)`                                                                              | Drop only if column exists             |                |
| `columnExists(string $name)`                                                                       | Check existence                        |                |

### **DB-Agnostic Type Mapping:**

| Generic   | MySQL              | PostgreSQL   | Oracle                                  |
| --------- | ------------------ | ------------ | --------------------------------------- |
| int       | INT                | INTEGER      | NUMBER(10)                              |
| bigint    | BIGINT             | BIGINT       | NUMBER(20)                              |
| string    | VARCHAR(255)       | VARCHAR(255) | VARCHAR2(255)                           |
| text      | TEXT               | TEXT         | CLOB                                    |
| timestamp | TIMESTAMP          | TIMESTAMP    | TIMESTAMP                               |
| serial    | INT AUTO_INCREMENT | SERIAL       | NUMBER GENERATED BY DEFAULT AS IDENTITY |

### **Example:**

```php
$builder->table('posts')
        ->addColumn('id', 'serial')
        ->addColumn('title', 'string')
        ->addColumn('body', 'text', true)
        ->primary('id')
        ->timestamps()
        ->execute();
```

---

## **7. Migration Engine (`DB\Migrations\MigrationEngine`)**

### **Purpose:**

* Automates migration execution and tracking.
* Supports rollback by batch.
* Keeps migration history in `migrations` table.

### **Key Methods:**

| Method                                           | Description                  |
| ------------------------------------------------ | ---------------------------- |
| `run()`                                          | Apply all pending migrations |
| `rollback()`                                     | Rollback last batch          |
| `hasRun(string $migration)`                      | Check if migration has run   |
| `recordMigration(string $migration, int $batch)` | Record migration             |
| `getCurrentBatch()`                              | Get last batch number        |

### **Features:**

* Tracks migration batches.
* Rollback safety.
* Works automatically with MigrationBuilder.
* Logs applied/rolled-back migrations.

### **Example:**

```php
$engine = new MigrationEngine(__DIR__ . '/migrations');
$engine->run();      // Apply all pending migrations
$engine->rollback(); // Undo last batch
```

---

## **8. Usage Example: Full Migration**

```php
class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->builder->table('users')
                      ->addColumn('id', 'serial')
                      ->addColumn('name', 'string')
                      ->addColumn('email', 'string')
                      ->timestamps()
                      ->primary('id')
                      ->index('email')
                      ->execute();
    }

    public function down(): void
    {
        $this->builder->table('users')->drop();
    }
}
```

* Rollback-safe operations:

```php
$builder->table('users')
        ->safeAddColumn('last_login', 'timestamp')
        ->safeDropColumn('temp_column');
```

---

## **9. System Features Summary**

* **DB-agnostic:** MySQL, PostgreSQL, Oracle (can extend more).
* **Migration Safety:** Track migrations, rollback by batch.
* **Fluent API:** Builder for readable schema creation.
* **Schema Management:** Create/modify/rename tables & columns.
* **Timestamps:** Automatic `created_at` & `updated_at`.
* **Safe Operations:** Only apply changes if needed.
* **Enterprise-ready:** Modular, integrates with ORM & Query Builder.
* **Extensible:** Easily add new drivers, grammars, expressions.

---

If you want, I can **also create a single reference table showing all classes, methods, and example usage in one “cheat sheet” style**, perfect for developers to glance at while coding.

Do you want me to make that cheat sheet?
==================================================================================

THE FUNCTION AND CHEAT SHEET
====================================================================================


Absolutely — that helper file you shared is **very useful** for the builder and the entire ORM/migration ecosystem we’ve built. Let me break down **why and how** it integrates with everything we have:

---

## **1. Purpose of the `db()` and `query()` Helpers**

### **`db()`**

* Returns the **raw DB driver / PDO** connection.
* Use this when you want **manual SQL execution**, transactions, or DB-specific features that aren’t handled by the builder.
* Example:

```php
$db = db('hv'); // get the driver
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([1]);
$user = $stmt->fetch();
```

* Perfect for **low-level control** or **transaction management**.

---

### **`query()`**

* Returns a **QueryBuilder instance** wrapped around a driver.
* Use this for **fluent, DB-agnostic, chainable queries**:

```php
$query = query('hv')->table('users')
                    ->where('active', 1)
                    ->orderBy('created_at', 'DESC')
                    ->get();
```

* Fully integrates with **MigrationBuilder**, **ORM**, and **DBDriverInterface**.
* Allows easy switching between DBs without changing your query code.

---

## **2. How This Fits With Your Builder / Migration System**

### **Fluent Integration**

* `query()->table()` is essentially the **front-end for your ORM & MigrationBuilder**.
* Any migration or schema operation can be tested or queried using `query()`:

```php
// Check if a table exists before migration
$exists = query('hv')->table('users')->exists();

// Update a row in a migration safely
query('hv')->table('users')->where('id', 1)->update(['last_login' => date('Y-m-d H:i:s')]);
```

### **Low-Level Control**

* Sometimes migrations need raw SQL for edge cases (like Oracle-specific types):

```php
db('hv')->query("ALTER TABLE users ADD COLUMN status NUMBER(1)");
```

* Your helper makes this **straightforward without breaking the fluent API**.

---

## **3. Transactions & Safety**

* Your helper also allows **transaction management**:

```php
$db = db('hv');
try {
    $db->beginTransaction();
    
    query('hv')->table('users')->insert(['name' => 'Test']);
    query('hv')->table('profiles')->insert(['user_id' => 1, 'bio' => '...']);
    
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    throw $e;
}
```

* Works **hand-in-hand** with the builder’s safe operations like `safeAddColumn` or `safeDropColumn`.

---

## **4. Cheat Sheet / Reference Table (Integrated)**

| Goal          | Call This                                        | Returns      | Use Case                             |
| ------------- | ------------------------------------------------ | ------------ | ------------------------------------ |
| Simple CRUD   | `query('hv')->table('x')->insert([...])`         | bool / ID    | Insert/update/delete records         |
| Complex Chain | `query('hv')->table('x')->where('a','b')->get()` | array        | Select + conditions + ordering       |
| Raw SQL       | `db('hv')->prepare("SELECT...")`                 | PDOStatement | Complex queries, Oracle-specific SQL |
| Transaction   | `db('hv')->beginTransaction()`                   | void         | Safety-first multi-step operations   |
| Commit        | `db('hv')->commit()`                             | void         | Commit transaction                   |
| Rollback      | `db('hv')->rollback()`                           | void         | Undo changes on error                |

---

### **5. Best Practices**

* Use **`query()`** whenever possible for DB-agnostic operations.
* Use **`db()`** when you need raw SQL, transactions, or driver-specific features.
* Combine both for migrations:

```php
// Migration example
$this->builder->table('users')
              ->safeAddColumn('last_login', 'timestamp')
              ->execute();

// Then optionally check or update raw values
db('hv')->query("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE last_login IS NULL");
```

---

✅ **Conclusion:**
This helper file **completes your enterprise-ready stack**:

* `query()` → Fluent DB-agnostic queries (ORM + MigrationBuilder friendly).
* `db()` → Raw PDO / low-level SQL, perfect for complex operations or transactions.

It allows your migrations and builder to **run safely, flexibly, and portably** across MySQL, Postgres, Oracle — exactly what your enterprise system needs.

---

If you want, I can **now combine everything we’ve documented and the helper into a single, visual cheat sheet** showing **all classes, methods, DB-agnostic operations, and helper usage**, so any developer can literally glance and start coding migrations and queries without opening any files.

Do you want me to do that?
