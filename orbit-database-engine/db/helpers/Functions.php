<?php
namespace DB\helpers;

use DB\ORM\QueryBuilder;
use DB\DBManager;
use PDO;
use DB\Drivers\DBDriverInterface;
use Shared\Classes\App;


/**
 * Get a DB connection by name.
 */
function db(?string $connectionName = null) {
    // 1. Get the current active App object
    $app = App::getInstance();

    // 2. Resolve target: 
    // If no name passed, try the active key, then the app's alias, then 'default'
    $target = $connectionName 
              ?? $app?->key 
              ?? $app?->connection_alias 
              ?? 'default';

    $driver = DBManager::connection($target);
    return new QueryBuilder($driver);
}

/**
 * Smart Query Helper
 * 100% Dynamic: Automatically detects the environment from the Blueprint.
 */
function query($connection = null): QueryBuilder
{
    $app = App::getInstance();

    // Dynamic resolution - no hardcoded 'hv' or 'default'
    $target = $connection 
              ?? $app?->key 
              ?? $app?->connection_alias 
              ?? 'default';

    $driver = is_object($target) ? $target : DBManager::connection($target);
    return new QueryBuilder($driver);
}


/**
 * Global Transaction Bridge
 */
function transaction(callable $callback, string $connection = 'hv') {
    return QueryBuilder::transaction($callback, $connection);
}
/*

| Task         | Use       |
| ------------ | --------- |
| Transactions | `db()`    |
| Queries      | `query()` |

Task              | Function to Use | Why?
------------------|-----------------|---------------------------------------------------
Start Transaction | db('hv')->begin() | PDO handles the connection state.
Fluent Query      | query('hv')->...  | Builder generates and executes SQL.
Commit/Rollback   | db('hv')->commit()| PDO finalizes or reverts changes.

*/


/**
 * Get a DB connection by name.
 */

/*
function db(?string $connectionName = null) {
    // 1. Get the raw driver (PostgreSQLDriver)
    //$driver = DBManager::connection($connectionName ?? 'default');
    $driver = DBManager::connection($connectionName ?? App::activeKey() ?? 'default');
    
    // 2. Return a QueryBuilder instance wrapped around that driver
    // This allows the ->table() method to work
    return new QueryBuilder($driver);
}

*/

/**
 * Smart Query Helper
 * Automatically detects the current app node if no connection is passed.
 */

/*
function query($connection = null): QueryBuilder
{
    // 1. SELF-AWARENESS: If no connection is named, ask the Blueprint where we are.
    // Fallback to 'hv' (the Control Plane) if no app is active.
    $connection = $connection ?? App::activeKey() ?? 'default';

    // 2. REGISTRY LOOKUP: Get the driver from the Manager
    $driver = is_object($connection) ? $connection : DBManager::connection($connection);
    
    // 3. BUILDER GENERATION: Return the fluent builder
    return new QueryBuilder($driver);
}

*/




//================================================================================================
/**
 * Start a new query builder.
 * OLD NON SMART CONNECTION
 */
/*
function query(string $connection = 'default'): QueryBuilder
{
    // Always get the Driver from the Manager first.
    // This ensures we use the same singleton instance.
    $driver = DBManager::connection($connection);
    
    // Use the driver's own table method to get a builder
    // This preserves the Driver -> QueryBuilder relationship correctly.
    return $driver->table(''); 
}

*/




//=====================================================================================


/**
 * Start a new query builder.
 */

/*
function query(string|PDO|DBDriverInterface $connection = 'default'): QueryBuilder
{
    // If it's a string (like 'default' or 'hv'), resolve it through the Manager
    if (is_string($connection)) {
        $connection = DBManager::connection($connection);
    }

    // If it's a Driver, get the PDO
    if ($connection instanceof DBDriverInterface) {
        $connection = $connection->getPDO();
    }

    // Now $connection is a real PDO instance, not just a string
    return (new QueryBuilder())->connection($connection);
}
*/


//===============================
// NEW REFERENCE TABLE
//==============================

/*
Goal,Call This,Returns
Simple CRUD,query('hv')->table('x')->insert([...]),bool or ID
Complex Chain,"query('hv')->table('x')->where('a','b')->get()",array
Manual SQL,"db('hv')->prepare(""SELECT..."")",PDOStatement
Safety First,"transaction(function(){...}, 'hv')",Result of callback

*/


/*
HOW TO USE THE FUNCTIONS:

Method,Returns,Best Use Case
query('name'),QueryBuilder,Fluent chaining: ->where()->get()
db('name'),PDO / Driver,"Raw SQL: $db->prepare(""..."")"

=====================================
MORE EXPLANATION ON USAGE BELOW:
===================================


Task,Function to Use,Why?
Start Transaction,db('hv')->begin(),PDO handles the connection state.
Fluent Query,query('hv')->table(...)->get(),Builder generates and executes SQL.
Commit/Rollback,db('hv')->commit(),PDO finalizes or reverts changes.
Raw SQL,"db('hv')->prepare(""..."")",Bypass the builder for complex manual SQL.


===================
THE POWER
===================
// 1. Log the registration in MongoDB (NoSQL)
db('logs_mongo')->table('auth_audit')->insert([
    'event' => 'registration',
    'key'   => $providerKey,
    'time'  => new MongoDB\BSON\UTCDateTime()
]);

// 2. Insert the main record into PostgreSQL/MySQL (SQL)
db('hv_core')->table('hv_users')->insert([...]);

// 3. Fetch legacy data from Oracle
$legacyData = db('oracle_node')->table('OLD_USERS')->where('ID', 101)->first();


-------------------------------------------------------
THE ERROR EXPLAINED:
The error happens because your helper function query('tradexenter') returns an instance of your QueryBuilder class, not a PDO object. Your QueryBuilder class does not have a method named prepare(), hence the fatal error.

You have two ways to fix this: the "Builder Way" (cleaner) or the "Direct DB Way" (faster for raw SQL).

Option 1: The "Builder Way" (Recommended)
Since you’ve already built a nice QueryBuilder with a delete() method, you should use it! This keeps your code consistent and handles the SQL generation for you.


=============================================================
Update your deleteInventoryItemForever method like this:
=============================================================
PHP

public static function deleteInventoryItemForever(int $userId, string $itemId): bool
{
    $table = TradexenterSchema::table('INVENTORY_ENTRIES');
    $col   = TradexenterSchema::COLUMNS['INVENTORY_ENTRIES'];

    // Use the builder's own logic instead of manual SQL
    return query('tradexenter')
        ->table($table)
        ->where($col['ITEM_ID'], '=', $itemId)
        ->where($col['USER_ID'], '=', $userId)
        ->delete();
}
Option 2: The "Direct DB Way"
If you prefer writing the raw SQL string manually, you need to get the actual PDO connection from your DBManager or via your db() helper, rather than the QueryBuilder.

PHP

public static function deleteInventoryItemForever(int $userId, string $itemId): bool
{
    // Use db() helper which returns the connection, NOT the query builder
    $db = db('tradexenter'); 
    
    $table = TradexenterSchema::table('INVENTORY_ENTRIES');
    $col   = TradexenterSchema::COLUMNS['INVENTORY_ENTRIES'];

    // Now $db is likely a PDO instance or your Driver, which has prepare()
    $stmt = $db->prepare("
        DELETE FROM {$table}
        WHERE {$col['ITEM_ID']} = :item_id AND {$col['USER_ID']} = :user_id
    ");

    return $stmt->execute([':item_id' => $itemId, ':user_id' => $userId]);
}
Why Option 1 might fail currently
Looking at your QueryBuilder::delete() code:

PHP

public function delete(): bool
{
    $db = $this->getActiveConnection();
    // ... it converts where clauses to a simple array ...
    return $db->delete($this->table, $whereSimple);
}
This assumes your Database Driver (inside $db) has a method named delete().

If your Driver has a delete() method: Option 1 is perfect.

If your Driver is just a PDO instance: 
You need to add a runDeleteSql() method to your QueryBuilder similar to how you wrote runSql().
=====================================================================================================

THE NEW SMART CONNECTION

=======================================================================================================

Your current setup is excellent. You have already built the **Self-Awareness** into the `App` blueprint and the `HVApp` extensions. 

To achieve the **100% Dynamic / No-Hardcoding** goal you requested, you only need to make **two small surgical updates**. These updates remove the `'default'` and `'hv'` strings from your logic and replace them with **Property-Driven Discovery**.

---

### Update 1: The App Blueprint (`Shared\Classes\App.php`)
We need to add a "Fallback" property. This ensures that if a helper asks "Who is the boss?", the App class can answer without a hardcoded string.

**Change this section in your `App` class:**

```php
abstract class App 
{
    public static ?App $instance = null;
    
    // ADD THIS LINE HERE:
    public string $connection_alias = 'default'; 

    // ... rest of your properties ...

    public function __construct(?string $id = '', string $name = '', ?string $key = '') 
    {
        // ... your existing identifier logic ...

        // ADD THIS LINE inside the constructor:
        // This ensures the alias matches the key automatically
        if (!empty($this->key)) {
            $this->connection_alias = $this->key;
        }

        // ... rest of your constructor ...
    }
    
    // ADD THIS STATIC HELPER to get the whole object, not just the key
    public static function getInstance(): ?App 
    {
        return self::$instance;
    }
}
```

---

### Update 2: The DB Helpers (`DB\helpers\functions.php`)
Now we rewrite the helpers to look at the **Instance Properties** instead of using hardcoded strings like `?? 'hv'`.

**Replace your `db()` and `query()` functions with this:**

```php

function db(?string $connectionName = null) {
    // 1. Get the current active App object
    $app = \Shared\Classes\App::getInstance();

    // 2. Resolve target: 
    // If no name passed, try the active key, then the app's alias, then 'default'
    $target = $connectionName 
              ?? $app?->key 
              ?? $app?->connection_alias 
              ?? 'default';

    $driver = DBManager::connection($target);
    return new QueryBuilder($driver);
}


 * Smart Query Helper
 * 100% Dynamic: Automatically detects the environment from the Blueprint.
 
function query($connection = null): QueryBuilder
{
    $app = \Shared\Classes\App::getInstance();

    // Dynamic resolution - no hardcoded 'hv' or 'default'
    $target = $connection 
              ?? $app?->key 
              ?? $app?->connection_alias 
              ?? 'default';

    $driver = is_object($target) ? $target : DBManager::connection($target);
    return new QueryBuilder($driver);
}



### What this accomplishes for you:

1.  **Whitelabel Ready:** If you decide to rename your Control Plane from `hv` to `nexus`, you only change the `public ?string $key = 'nexus'` in your `HyperVirtueApp` class. You **do not** have to search and replace `'hv'` inside your database helpers.
2.  **Context Switching:** When you are inside an `AppNode` (like TaskManager), `App::$instance->key` will be `'taskmanager'`. Your `query()` function will automatically point to the TaskManager database.
3.  **No "Ugly" Hacks:** The line `$connection = $connection ?? App::activeKey() ?? 'hv';` is gone. It is replaced by a property check (`$app?->connection_alias`), which is the professional way to handle defaults in an OS.

### Final Verification
In your `HV\HVApp` (the internal one), ensure it inherits the `key` correctly so the helper finds it:

```php
class HVApp extends SharedHV 
{
    public ?string $id  = 'hv';
    public ?string $key = 'hv'; 
    // ...
}
```

With these two updates, your system is now a **closed-loop**. The App defines its identity, and the Helpers simply reflect that identity. No hardcoding required.
*/