<?php
    namespace DB\Migrations;

    use DB\Drivers\DBDriverInterface;
    use DB\ORM\Grammar;
    use Shared\Logging\Logger;

    class MigrationBuilder
    {
        private DBDriverInterface $db;
        private Grammar $grammar;
        private string $table;
        private array $columns = [];
        private array $primaryKeys = [];
        private array $indexes = [];
        private array $foreignKeys = [];
        private array $uniqueConstraints = [];

            protected array $postgresTypeCasts = [

                'BOOLEAN' => "USING CASE
                    WHEN %s IN ('1','true','TRUE','t','yes')
                    THEN TRUE
                    ELSE FALSE
                END",

                'INTEGER' => "USING NULLIF(%s, '')::integer",

                'BIGINT' => "USING NULLIF(%s, '')::bigint",

                'SMALLINT' => "USING NULLIF(%s, '')::smallint",

                'JSONB' => "USING %s::jsonb",
            ];

        /*
        |--------------------------------------------------------------------------
        | SQL DEFAULT EXPRESSIONS
        |--------------------------------------------------------------------------
        */

        protected array $defaultExpressions = [

            'CURRENT_TIMESTAMP',
            'CURRENT_DATE',
            'CURRENT_TIME',

            'NOW()',

            'NULL',
        ];

        /*
        |--------------------------------------------------------------------------
        | FORMAT DEFAULT VALUE
        |--------------------------------------------------------------------------
        */

        protected function formatDefaultValue(
            mixed $default
        ): string {

            /*
            |--------------------------------------------------------------------------
            | NULL
            |--------------------------------------------------------------------------
            */

            if ($default === null) {
                return 'NULL';
            }

            /*
            |--------------------------------------------------------------------------
            | BOOLEAN
            |--------------------------------------------------------------------------
            */

            if (is_bool($default)) {
                return $default
                    ? 'TRUE'
                    : 'FALSE';
            }

            /*
            |--------------------------------------------------------------------------
            | NUMERIC
            |--------------------------------------------------------------------------
            */

            if (
                is_int($default)
                ||
                is_float($default)
            ) {
                return (string) $default;
            }

            /*
            |--------------------------------------------------------------------------
            | SQL EXPRESSIONS
            |--------------------------------------------------------------------------
            */

            if (is_string($default)) {

                $normalized =
                    strtoupper(trim($default));

                if (
                    in_array(
                        $normalized,
                        $this->defaultExpressions
                    )
                ) {

                    return $default;
                }

                /*
                |--------------------------------------------------------------------------
                | FUNCTION CALLS
                |--------------------------------------------------------------------------
                |
                | Examples:
                | uuid_generate_v4()
                | NOW()
                | gen_random_uuid()
                |
                */

                if (
                    preg_match(
                        '/^[a-zA-Z_][a-zA-Z0-9_]*\(\)$/',
                        $default
                    )
                ) {

                    return $default;
                }

                /*
                |--------------------------------------------------------------------------
                | NORMAL STRING
                |--------------------------------------------------------------------------
                */

                return "'" .
                    str_replace(
                        "'",
                        "''",
                        $default
                    ) .
                    "'";
            }

            /*
            |--------------------------------------------------------------------------
            | FALLBACK
            |--------------------------------------------------------------------------
            */

            return (string) $default;
        }

        public function __construct(DBDriverInterface $db)
        {
            $this->db = $db;
            $this->grammar = $db->grammar();
        }

        public function table(string $name): self
        {
            $this->table = $name;
            $this->columns = [];
            $this->primaryKeys = [];
            $this->indexes = [];
            $this->foreignKeys = [];
            $this->uniqueConstraints = [];
            return $this;
        }



    public function unique(
        string|array $columns,
        ?string $name = null
    ): self {

        $cols =
            is_array($columns)
            ? $columns
            : [$columns];

        $wrapped = array_map(
            fn($c) => $this->grammar->wrap($c),
            $cols
        );

        $constraintName =
            $name
            ?:
            $this->table .
            '_' .
            implode('_', $cols) .
            '_unique';

        $this->uniqueConstraints[] =
            "CONSTRAINT " .
            $this->grammar->wrap($constraintName) .
            " UNIQUE (" .
            implode(', ', $wrapped) .
            ")";

        return $this;
    }


    public function addUnique(
        string|array $columns,
        ?string $name = null
    ): void {

        $cols =
            is_array($columns)
            ? $columns
            : [$columns];

        $wrapped = array_map(
            fn($c) => $this->grammar->wrap($c),
            $cols
        );

        $constraintName =
            $name
            ?:
            $this->table .
            '_' .
            implode('_', $cols) .
            '_unique';

        $sql =
            "ALTER TABLE " .
            $this->grammar->wrap($this->table) .
            " ADD CONSTRAINT " .
            $this->grammar->wrap($constraintName) .
            " UNIQUE (" .
            implode(', ', $wrapped) .
            ")";

        $this->db->query($sql);

        Logger::info(
            "HEALING DATABASE: Added UNIQUE [$constraintName]"
        );
    }



    public function uniqueExists(
        string $name
    ): bool {

        $driver =
            strtolower(get_class($this->db));

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL
        |--------------------------------------------------------------------------
        */

        if (
            strpos($driver, 'pgsql') !== false
            ||
            strpos($driver, 'postgresql') !== false
        ) {

            $sql = "
                SELECT constraint_name
                FROM information_schema.table_constraints
                WHERE lower(table_name) = lower(?)
                AND constraint_type = 'UNIQUE'
                AND lower(constraint_name) = lower(?)
            ";

            return !empty(

                $this->db->fetchAll(
                    $sql,
                    [
                        $this->table,
                        $name
                    ]
                )
            );
        }

        return false;
    }


    public function addForeignKey(
        string $column,
        string $refTable,
        string $refColumn = 'id',
        string $onDelete = 'CASCADE',
        string $onUpdate = 'CASCADE'
    ): void {

        $fkName =
            $this->table .
            '_' .
            $column .
            '_fk';

        $sql = "

            ALTER TABLE " .
            $this->grammar->wrap($this->table) .

            " ADD CONSTRAINT " .
            $this->grammar->wrap($fkName) .

            " FOREIGN KEY (" .
            $this->grammar->wrap($column) .
            ") REFERENCES " .
            $this->grammar->wrap($refTable) .
            "(" .
            $this->grammar->wrap($refColumn) .
            ")" .

            " ON DELETE $onDelete" .
            " ON UPDATE $onUpdate";

        $this->db->query($sql);

        Logger::info(
            "HEALING DATABASE: Added FOREIGN KEY [$fkName]"
        );
    }


        /**
         * Add a column with type mapping for DB agnostic
         */
        public function addColumn(
            string $name,
            string $type,
            bool $nullable = false,
            mixed $default = null
        ): self {

            $type =
                $this->mapType($type);

            $col =
                $this->grammar->wrap($name) .
                " $type";

            if (!$nullable) {
                $col .= " NOT NULL";
            }

            if ($default !== null) {

                $col .=
                    " DEFAULT " .
                    $this->formatDefaultValue(
                        $default
                    );
            }

            $this->columns[] = $col;

            return $this;
        }


        
        /**
         * Primary Key
         */
        public function primary(string|array $columns): self
        {
            $cols = is_array($columns) ? $columns : [$columns];
            $wrapped = array_map(fn($c) => $this->grammar->wrap($c), $cols);
            $this->primaryKeys[] = "PRIMARY KEY (" . implode(', ', $wrapped) . ")";
            return $this;
        }

        /**
         * Add Primary Key (ALTER mode)
         */
        public function addPrimaryKey(string|array $columns): void
        {
            $cols = is_array($columns) ? $columns : [$columns];
            $wrapped = array_map(fn($c) => $this->grammar->wrap($c), $cols);

            $sql = "ALTER TABLE " . $this->grammar->wrap($this->table) .
                " ADD PRIMARY KEY (" . implode(', ', $wrapped) . ")";

            $this->db->query($sql);

            Logger::info("HEALING DATABASE: Added PRIMARY KEY on [" . implode(',', $cols) . "] in table [" . $this->table . "]");
        }


        /**
         * Add index
         */
        public function index(string|array $columns, ?string $name = null): self
        {
            $cols = is_array($columns) ? $columns : [$columns];
            $wrapped = array_map(fn($c) => $this->grammar->wrap($c), $cols);
            $idxName = $name ?: $this->table . '_' . implode('_', $cols) . '_idx';
            $this->indexes[] = "CREATE INDEX " . $this->grammar->wrap($idxName) .
                            " ON " . $this->grammar->wrap($this->table) .
                            " (" . implode(', ', $wrapped) . ")";
            return $this;
        }


        /**
         * Add index (ALTER mode)
         */
        public function addIndex(string|array $columns, ?string $name = null): void
        {
            $cols = is_array($columns) ? $columns : [$columns];
            $wrapped = array_map(fn($c) => $this->grammar->wrap($c), $cols);

            $idxName = $name ?: $this->table . '_' . implode('_', $cols) . '_idx';

            $sql = "CREATE INDEX " . $this->grammar->wrap($idxName) .
                " ON " . $this->grammar->wrap($this->table) .
                " (" . implode(', ', $wrapped) . ")";

            $this->db->query($sql);

            Logger::info("HEALING DATABASE: Added INDEX [$idxName] on table [" . $this->table . "]");
        }





        /**
         * Add foreign key
         */
        public function foreign(string $column, string $refTable, string $refColumn, string $onDelete = 'CASCADE', string $onUpdate = 'CASCADE'): self
        {
            $fkName = $this->table . '_' . $column . '_fk';
            $this->foreignKeys[] = sprintf(
                "CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s(%s) ON DELETE %s ON UPDATE %s",
                $this->grammar->wrap($fkName),
                $this->grammar->wrap($column),
                $this->grammar->wrap($refTable),
                $this->grammar->wrap($refColumn),
                $onDelete,
                $onUpdate
            );
            return $this;
        }

        /**
         * Add timestamps
         */
        public function timestamps(): self
        {
            $this->addColumn('created_at', 'TIMESTAMP', false, 'CURRENT_TIMESTAMP');
            $this->addColumn('updated_at', 'TIMESTAMP', false, 'CURRENT_TIMESTAMP');
            return $this;
        }

        /**
         * Execute table creation
         */
        public function execute(): void
        {
            if (!$this->table || empty($this->columns)) {
                throw new \Exception("Table name or columns not defined");
            }

            $allCols = array_merge(
                $this->columns, 
                $this->primaryKeys, 
                $this->foreignKeys, 
                $this->uniqueConstraints
            );

            $sql = "CREATE TABLE " . $this->grammar->wrap($this->table) .
                " (\n" . implode(",\n", $allCols) . "\n)";
            
            $this->db->query($sql);

            foreach ($this->indexes as $idx) {
                $this->db->query($idx);
            }
        }

        /**
         * Drop table
         */
        public function drop(): void
        {
            // Safe drop for all drivers
            $driver = strtolower(get_class($this->db));
            if (strpos($driver, 'oracle') !== false) {
                $this->db->query("BEGIN EXECUTE IMMEDIATE 'DROP TABLE " . $this->grammar->wrap($this->table) . "'; EXCEPTION WHEN OTHERS THEN IF SQLCODE != -942 THEN RAISE; END IF; END;");
            } else {
                $this->db->query("DROP TABLE IF EXISTS " . $this->grammar->wrap($this->table));
            }
        }


        // ----------- New: Rename Table -----------------
        public function renameTable(string $newName): void
        {
            $sql = "ALTER TABLE " . $this->grammar->wrap($this->table) .
                " RENAME TO " . $this->grammar->wrap($newName);
            $this->db->query($sql);
            $this->table = $newName;
        }

        // ----------- New: Modify Column ----------------
        /*
        |--------------------------------------------------------------------------
        | MODIFY COLUMN
        |--------------------------------------------------------------------------
        */
        

        public function modifyColumn(
            string $column,
            string $newType,
            bool $nullable = true,
            mixed $default = null
        ): void {

            $newType =
                $this->mapType($newType);

            $sql =
                "ALTER TABLE " .
                $this->grammar->wrap($this->table);

            $driver =
                strtolower(
                    get_class($this->db)
                );

            /*
            |--------------------------------------------------------------------------
            | POSTGRESQL
            |--------------------------------------------------------------------------
            */

            if (
                strpos($driver, 'pgsql') !== false
                ||
                strpos($driver, 'postgresql') !== false
            ) {

                $sql .=
                    " ALTER COLUMN " .
                    $this->grammar->wrap($column) .
                    " TYPE $newType";

                /*
                |--------------------------------------------------------------------------
                | Safe Type Casting
                |--------------------------------------------------------------------------
                */

                $upperType = strtoupper($newType);
                

                $castTemplate =
                    $this->postgresTypeCasts[$upperType] ?? null;

                if ($castTemplate) {

                    $sql .= ' ' .
                        sprintf(
                            $castTemplate,
                            $this->grammar->wrap($column)
                        );
                }

            }

            /*
            |--------------------------------------------------------------------------
            | MYSQL
            |--------------------------------------------------------------------------
            */

            elseif (
                strpos($driver, 'mysql') !== false
            ) {

                $sql .=

                    " MODIFY COLUMN " .
                    $this->grammar->wrap($column) .
                    " $newType";

                if (!$nullable) {
                    $sql .= " NOT NULL";
                }

                if ($default !== null) {

                    $sql .=
                        " DEFAULT " .
                        $this->formatDefaultValue(
                            $default
                        );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | ORACLE
            |--------------------------------------------------------------------------
            */

            elseif (
                strpos($driver, 'oracle') !== false
            ) {

                $sql .=

                    " MODIFY " .
                    $this->grammar->wrap($column) .
                    " $newType";
            }

            $this->db->query($sql);

            Logger::info(
                "HEALING DATABASE: Modified column [$column]"
            );
        }



        
        // ----------- New: Rename Column ----------------
        public function renameColumn(string $old, string $new): void
        {
            $driver = strtolower(get_class($this->db));
            if (strpos($driver, 'mysql') !== false) {
                $sql = "ALTER TABLE " . $this->grammar->wrap($this->table) . " CHANGE " . $this->grammar->wrap($old) . " " . $this->grammar->wrap($new) . " VARCHAR(255)";
            } elseif (strpos($driver, 'postgresql') !== false || strpos($driver, 'pgsql') !== false) {
                $sql = "ALTER TABLE " . $this->grammar->wrap($this->table) . " RENAME COLUMN " . $this->grammar->wrap($old) . " TO " . $this->grammar->wrap($new);
            } elseif (strpos($driver, 'oracle') !== false) {
                $sql = "ALTER TABLE " . $this->grammar->wrap($this->table) . " RENAME COLUMN " . $this->grammar->wrap($old) . " TO " . $this->grammar->wrap($new);
            }
            $this->db->query($sql);
        }

        // ----------- Safe Add Column ----------------
        public function safeAddColumn(string $name, string $type, bool $nullable = false, $default = null): void
        {
            if (!$this->columnExists($name)) {
                $this->addColumn($name, $type, $nullable, $default);
                $this->execute();
            }
        }

        // ----------- Safe Drop Column ----------------
        public function safeDropColumn(string $name): void
        {
            if ($this->columnExists($name)) {
                $sql = "ALTER TABLE " . $this->grammar->wrap($this->table) . " DROP COLUMN " . $this->grammar->wrap($name);
                $driver = strtolower(get_class($this->db));
                if (strpos($driver, 'oracle') !== false) {
                    $sql = "ALTER TABLE " . $this->grammar->wrap($this->table) . " DROP COLUMN " . $this->grammar->wrap($name);
                }
                $this->db->query($sql);
            }
        }

        // ----------- Check Column Exists ----------------
    // ----------- Check Column Exists (Hardened) ----------------
    public function columnExists(string $name): bool
    {
        $driver = strtolower(get_class($this->db));
        $table = $this->table;

        if (strpos($driver, 'mysql') !== false) {
            $res = $this->db->fetchAll("SHOW COLUMNS FROM " . $this->grammar->wrap($table) . " LIKE '$name'");
            return !empty($res);
        } 
        
        if (strpos($driver, 'postgresql') !== false || strpos($driver, 'pgsql') !== false) {
            // Force lowercase for comparison in information_schema
            $name = strtolower($name);
            $sql = "SELECT column_name FROM information_schema.columns 
                    WHERE table_name = ? AND column_name = ? 
                    AND table_schema = 'public'";
            $res = $this->db->fetchAll($sql, [$table, $name]);
            return !empty($res);
        }

        return false;
    }


    /**
     * Check Index Exists
     */
    public function indexExists(string $name): bool
    {
        $driver = strtolower(get_class($this->db));
        $table = $this->table;

        if (strpos($driver, 'mysql') !== false) {
            $sql = "SHOW INDEX FROM " . $this->grammar->wrap($table) . " WHERE Key_name = ?";
            return !empty($this->db->fetchAll($sql, [$name]));
        }

        if (strpos($driver, 'postgresql') !== false || strpos($driver, 'pgsql') !== false) {
            $sql = "SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?";
            return !empty($this->db->fetchAll($sql, [$table, $name]));
        }

        return false;
    }



    /**
     * Alter table - add column (With Auto-Commit)
     */
        /*
        |--------------------------------------------------------------------------
        | ALTER ADD COLUMN
        |--------------------------------------------------------------------------
        */

        public function alterAddColumn(
            string $name,
            string $type,
            bool $nullable = false,
            mixed $default = null
        ): void {

            $type =
                $this->mapType($type);

            $sql =
                "ALTER TABLE " .
                $this->grammar->wrap($this->table) .

                " ADD COLUMN " .
                $this->grammar->wrap($name) .

                " $type";

            if (!$nullable) {
                $sql .= " NOT NULL";
            }

            if ($default !== null) {

                $sql .=
                    " DEFAULT " .
                    $this->formatDefaultValue(
                        $default
                    );
            }

            $this->db->query($sql);

            Logger::info(
                "HEALING DATABASE: Added column [$name]"
            );
        }



        /**
         * Map generic types to driver-specific types
         */
        private function mapType(string $type): string
        {
            $driver = strtolower(get_class($this->db));

            // DB-agnostic mapping
            $map = [
                'int' => ['mysql' => 'INT', 'pgsql' => 'INTEGER', 'oracle' => 'NUMBER(10)'],
                'bigint' => ['mysql' => 'BIGINT', 'pgsql' => 'BIGINT', 'oracle' => 'NUMBER(20)'],
                'string' => ['mysql' => 'VARCHAR(255)', 'pgsql' => 'VARCHAR(255)', 'oracle' => 'VARCHAR2(255)'],
                'text' => ['mysql' => 'TEXT', 'pgsql' => 'TEXT', 'oracle' => 'CLOB'],
                'timestamp' => ['mysql' => 'TIMESTAMP', 'pgsql' => 'TIMESTAMP', 'oracle' => 'TIMESTAMP'],
                'serial' => ['mysql' => 'INT AUTO_INCREMENT', 'pgsql' => 'SERIAL', 'oracle' => 'NUMBER GENERATED BY DEFAULT AS IDENTITY'],
                'json' => [
                    'mysql' => 'JSON',
                    'pgsql' => 'JSONB',
                    'oracle' => 'CLOB'
                ],
            ];

            foreach ($map as $key => $types) {
                if (strtolower($type) === $key) {
                    if (strpos($driver, 'mysql') !== false) return $types['mysql'];
                    if (strpos($driver, 'postgresql') !== false || strpos($driver, 'pgsql') !== false) return $types['pgsql'];
                    if (strpos($driver, 'oracle') !== false) return $types['oracle'];
                }
            }

            return $type; // fallback
        }



        public function tableExists(string $table): bool
        {
            $driver = strtolower(get_class($this->db));

            if (strpos($driver, 'postgresql') !== false || strpos($driver, 'pgsql') !== false) {
                $sql = "SELECT to_regclass(?)";
                $res = $this->db->fetchAll($sql, ['public.' . $table]);
                return !empty($res) && $res[0]['to_regclass'] !== null;
            }

            if (strpos($driver, 'mysql') !== false) {
                $sql = "SHOW TABLES LIKE ?";
                return !empty($this->db->fetchAll($sql, [$table]));
            }

            return false;
        }



    /**
     * Drop foreign key constraint (ALTER mode)
     */
    public function dropForeignKey(string $constraintName): void
    {
        $sql =
            "ALTER TABLE " .
            $this->grammar->wrap($this->table) .
            " DROP CONSTRAINT " .
            $this->grammar->wrap($constraintName);

        $this->db->query($sql);

        Logger::info(
            "HEALING DATABASE: Dropped FOREIGN KEY [$constraintName]"
        );
    }



    }