<?php
namespace DB\Drivers;

use DB\Drivers\DBDriverInterface;
use DB\Traits\LoggerTrait;
use DB\Traits\EncryptionTrait;
use Exception;

class DynamoDBDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    private string $region;
    private string $accessKey;
    private string $secretKey;
    private string $endpoint;
    private string $service = "dynamodb";
    private string $tablePrefix = "";

    public function __construct(array $config)
    {
        $this->region      = $config['region'] ?? "us-east-1";
        $this->accessKey   = $config['access_key'] ?? "";
        $this->secretKey   = $config['secret_key'] ?? "";
        $this->tablePrefix = $config['table_prefix'] ?? "";

        if (!$this->accessKey || !$this->secretKey) {
            throw new Exception("DynamoDBDriver requires access_key and secret_key");
        }

        $this->endpoint = "https://dynamodb.{$this->region}.amazonaws.com";
        $this->log("DynamoDBDriver initialized for region {$this->region}");
    }

    public function connect(): void
    {
        // REST driver does not need persistent connection
    }

    // -------------------------------
    // AWS Signature V4 Request Helper
    // -------------------------------
    private function awsRequest(string $target, array $body = []): array
    {
        $payload     = json_encode($body);
        $payloadHash = hash("sha256", $payload);
        $amzDate     = gmdate("Ymd\THis\Z");
        $date        = gmdate("Ymd");

        $headers = [
            "Content-Type: application/x-amz-json-1.0",
            "X-Amz-Date: {$amzDate}",
            "X-Amz-Target: {$target}",
            "Host: dynamodb.{$this->region}.amazonaws.com",
        ];

        $canonicalHeaders = "content-type:application/x-amz-json-1.0\n" .
                            "host:dynamodb.{$this->region}.amazonaws.com\n" .
                            "x-amz-date:$amzDate\n" .
                            "x-amz-target:$target\n";

        $signedHeaders = "content-type;host;x-amz-date;x-amz-target";

        $canonicalRequest = implode("\n", [
            "POST",
            "/",
            "",
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash
        ]);

        $algorithm       = "AWS4-HMAC-SHA256";
        $credentialScope = "$date/{$this->region}/{$this->service}/aws4_request";
        $stringToSign    = implode("\n", [
            $algorithm,
            $amzDate,
            $credentialScope,
            hash("sha256", $canonicalRequest)
        ]);

        $kDate    = hash_hmac("sha256", $date, "AWS4" . $this->secretKey, true);
        $kRegion  = hash_hmac("sha256", $this->region, $kDate, true);
        $kService = hash_hmac("sha256", $this->service, $kRegion, true);
        $kSigning = hash_hmac("sha256", "aws4_request", $kService, true);

        $signature = hash_hmac("sha256", $stringToSign, $kSigning);

        $authorization = "{$algorithm} Credential={$this->accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        $headers[] = "Authorization: $authorization";

        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) throw new Exception("DynamoDB error: $err");

        return json_decode($res, true) ?? [];
    }

    // -------------------------------
    // SQL Methods - Not Supported
    // -------------------------------
    public function query(string $sql, array $params = []): mixed
    {
        throw new Exception("DynamoDB does not support SQL queries");
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        throw new Exception("DynamoDB does not support SQL queries");
    }

    // -------------------------------
    // Item Encoding/Decoding
    // -------------------------------
    private function decodeItem(array $item): array
    {
        $output = [];
        foreach ($item as $key => $value) {
            $type = array_key_first($value);
            $val  = $value[$type];

            switch ($type) {
                case 'S': $output[$key] = $val; break;
                case 'N': $output[$key] = (float)$val; break;
                case 'BOOL': $output[$key] = (bool)$val; break;
                case 'NULL': $output[$key] = null; break;
                case 'L': $output[$key] = array_map(fn($v) => $this->decodeItem(['tmp' => $v])['tmp'], $val); break;
                case 'M': $output[$key] = $this->decodeItem($val); break;
                default: $output[$key] = $val;
            }
        }
        return $output;
    }

    private function encodeItem(array $item): array
    {
        $formatted = [];
        foreach ($item as $key => $value) {
            if (is_string($value)) $formatted[$key] = ['S' => $value];
            elseif (is_numeric($value)) $formatted[$key] = ['N' => (string)$value];
            elseif (is_bool($value)) $formatted[$key] = ['BOOL' => $value];
            elseif (is_null($value)) $formatted[$key] = ['NULL' => true];
            elseif (is_array($value)) {
                if ($this->isAssoc($value)) $formatted[$key] = ['M' => $this->encodeItem($value)];
                else $formatted[$key] = ['L' => array_map(fn($v) => $this->encodeItem(['tmp'=>$v])['tmp'], $value)];
            } else $formatted[$key] = ['S' => strval($value)];
        }
        return $formatted;
    }

    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    // -------------------------------
    // Schema / Tables
    // -------------------------------
    public function getSchema(): array
    {
        $res = $this->awsRequest("DynamoDB_20120810.ListTables");
        return $res['TableNames'] ?? [];
    }

    public function listDatabases(): array
    {
        return ["dynamodb"];
    }

    public function listTables(string $database): array
    {
        return $this->getSchema();
    }

    // -------------------------------
    // Table Records
    // -------------------------------
    public function getTableRecords(string $database, string $table, int $limit = 50): array
    {
        $table = $this->tablePrefix . $table;
        $res   = $this->awsRequest("DynamoDB_20120810.Scan", [
            "TableName" => $table,
            "Limit"     => $limit
        ]);

        if (!isset($res['Items'])) return [];
        return array_map([$this, 'decodeItem'], $res['Items']);
    }

    public function findAll(string $collection): array
    {
        return $this->getTableRecords("dynamodb", $collection, 99999);
    }

    // -------------------------------
    // Create Table / Database
    // -------------------------------
    public function createTable(string $database, string $tableName, array $columns): bool
    {
        $tableName = $this->tablePrefix . $tableName;
        $primaryKey = array_key_first($columns);
        $type = strtoupper($columns[$primaryKey]) === "NUMBER" ? "N" : "S";

        $this->awsRequest("DynamoDB_20120810.CreateTable", [
            "TableName" => $tableName,
            "AttributeDefinitions" => [
                ["AttributeName" => $primaryKey, "AttributeType" => $type]
            ],
            "KeySchema" => [
                ["AttributeName" => $primaryKey, "KeyType" => "HASH"]
            ],
            "BillingMode" => "PAY_PER_REQUEST"
        ]);

        $this->log("Created DynamoDB table: $tableName");
        return true;
    }

    public function createDatabase(string $dbName): bool
    {
        // DynamoDB uses a single database — no-op
        return true;
    }

    // -------------------------------
    // Backup / Import / Export
    // -------------------------------
    public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string
    {
        $tables = $this->getSchema();
        $dump   = [];

        foreach ($tables as $tbl) {
            $dump[$tbl] = $this->findAll($tbl);
        }

        $json = json_encode($dump, JSON_PRETTY_PRINT);
        if ($encrypt && $key && $iv) $json = $this->encryptData($json, $key, $iv);

        file_put_contents($path, $json);
        $this->log("DynamoDB backup saved to: $path");
        return true;
    }

    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool
    {
        return $this->backup($path, $encrypt, $key, $iv);
    }

    public function import(array $data): bool
    {
        foreach ($data as $table => $rows) {
            $tableName = $this->tablePrefix . $table;
            foreach ($rows as $item) {
                $this->awsRequest("DynamoDB_20120810.PutItem", [
                    "TableName" => $tableName,
                    "Item"      => $this->encodeItem($item)
                ]);
            }
        }

        $this->log("DynamoDB import completed");
        return true;
    }
}
