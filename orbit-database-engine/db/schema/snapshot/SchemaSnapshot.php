<?php

namespace DB\Schema\Snapshot;

class SchemaSnapshot
{
    /*
    |--------------------------------------------------------------------------
    | Snapshot Directory
    |--------------------------------------------------------------------------
    */

    protected static string $directory =
        HV_ROOT . '/storage/schema';

    /*
    |--------------------------------------------------------------------------
    | Ensure Directory Exists
    |--------------------------------------------------------------------------
    */

    protected static function ensureDirectory(): void
    {
        if (!is_dir(self::$directory)) {

            mkdir(
                self::$directory,
                0777,
                true
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Snapshot Path
    |--------------------------------------------------------------------------
    */

    protected static function path(
        string $app
    ): string {

        return
            self::$directory .
            '/' .
            strtolower($app) .
            '.snapshot.json';
    }

    /*
    |--------------------------------------------------------------------------
    | Save Snapshot
    |--------------------------------------------------------------------------
    */

    public static function save(
        string $app,
        array $schema
    ): void {

        self::ensureDirectory();

        file_put_contents(

            self::path($app),

            json_encode(
                $schema,
                JSON_PRETTY_PRINT
                |
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Load Snapshot
    |--------------------------------------------------------------------------
    */

    public static function load(
        string $app
    ): ?array {

        $path =
            self::path($app);

        if (!file_exists($path)) {
            return null;
        }

        return json_decode(

            file_get_contents($path),

            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Exists
    |--------------------------------------------------------------------------
    */

    public static function exists(
        string $app
    ): bool {

        return file_exists(
            self::path($app)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Snapshot
    |--------------------------------------------------------------------------
    */

    public static function delete(
        string $app
    ): void {

        $path =
            self::path($app);

        if (file_exists($path)) {

            unlink($path);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Compare Snapshot
    |--------------------------------------------------------------------------
    */

    public static function hasChanged(
        string $app,
        array $schema
    ): bool {

        $existing =
            self::load($app);

        if (!$existing) {
            return true;
        }

        return
            md5(json_encode($existing))
            !==
            md5(json_encode($schema));
    }

    /*
    |--------------------------------------------------------------------------
    | Diff Snapshot
    |--------------------------------------------------------------------------
    */

    public static function diff(
        string $app,
        array $schema
    ): array {

        $existing =
            self::load($app);

        if (!$existing) {

            return [
                'status' => 'new',
                'changes' => $schema
            ];
        }

        return [

            'changed' => (

                md5(json_encode($existing))
                !==
                md5(json_encode($schema))

            ),

            'old' => $existing,

            'new' => $schema
        ];
    }
}