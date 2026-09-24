<?php
declare(strict_types=1);
namespace DB\Drivers;



/**
 * FirestoreDriver.php
 * Google Firestore driver using pure REST API (no SDK required)
 *
 * Config:
 *  - project_id  (required)
 *  - auth_key    (Bearer token or OAuth token)
 *
 * Notes:
 *  - Uses Firestore v1 REST endpoints
 *  - Firestore is document-based (collections -> documents)
 */

class FirestoreDriver implements DBDriverInterface
{
    use LoggerTrait, EncryptionTrait;

    private string $projectId;
    private string $authKey;
    private string $endpoint;

    public function __construct(array $config)
    {
        if (!isset($config['project_id']) || !isset($config['auth_key'])) {
            throw new Exception("FirestoreDriver requires 'project_id' and 'auth_key'");
        }

        $this->projectId = $config['project_id'];
        $this->authKey   = $config['auth_key'];

        $this->endpoint = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents";

        $this->log("FirestoreDriver initialized for project {$this->projectId}");
    }

    public function connect(): void
    {
        // REST driver — no persistent connection required
    }

    private function request(string $method, string $path, array $data = []): mixed
    {
        $url = $this->endpoint . '/' . ltrim($path, '/');

        $headers = [
            "Authorization: Bearer {$this->authKey}",
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new Exception("Firestore REST error: $err");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Firestore REST returned HTTP $httpCode: $res");
        }

        return json_decode($res, true);
    }

    // Firestore does not support SQL
    public function query(string $sql, array $params = []): mixed { throw new Exception("Firestore does not support SQL"); }
    public function fetchAll(string $sql): array { throw new Exception("Firestore does not support SQL"); }

    /**
     * Return all documents from a collection as associative PHP arrays
     */
    public function findAll(string $collection, int $limit = 0): array
    {
        $res = $this->request('GET', $collection);

        $docs = [];
        foreach ($res['documents'] ?? [] as $doc) {
            $docs[] = $this->decodeFirestoreDocument($doc);
            if ($limit && count($docs) >= $limit) break;
        }

        return $docs;
    }

    /**
     * Decode Firestore document (REST format) → PHP array
     */
    private function decodeFirestoreDocument(array $doc): array
    {
        $fields = $doc['fields'] ?? [];
        $parsed = [];

        foreach ($fields as $key => $value) {
            $parsed[$key] = $this->decodeValue($value);
        }

        return $parsed;
    }

    private function decodeValue(array $value)
    {
        if (isset($value['stringValue']))  return $value['stringValue'];
        if (isset($value['integerValue'])) return (int)$value['integerValue'];
        if (isset($value['doubleValue']))  return (float)$value['doubleValue'];
        if (isset($value['booleanValue'])) return (bool)$value['booleanValue'];
        if (isset($value['nullValue']))    return null;
        if (isset($value['arrayValue']))   return array_map([$this, 'decodeValue'], $value['arrayValue']['values'] ?? []);
        if (isset($value['mapValue'])) {
            $map = [];
            foreach (($value['mapValue']['fields'] ?? []) as $k => $v) {
                $map[$k] = $this->decodeValue($v);
            }
            return $map;
        }
        return $value; // fallback
    }

    public function getSchema(): array
    {
        $url = $this->endpoint . ':listCollectionIds';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$this->authKey}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) throw new Exception("Firestore schema error: $err");

        $decoded = json_decode($res, true);
        return $decoded['collectionIds'] ?? [];
    }

    public function backup(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool|string
    {
        $schema = $this->getSchema();
        $dump   = [];

        foreach ($schema as $collection) {
            $dump[$collection] = $this->findAll($collection);
        }

        $json = json_encode($dump, JSON_PRETTY_PRINT);

        if ($encrypt && $key && $iv) {
            $json = $this->encryptData($json, $key, $iv);
        }

        file_put_contents($path, $json);
        $this->log("Firestore backup saved to $path" . ($encrypt ? " (encrypted)" : ""));

        return true;
    }

    public function import(array $data): bool
    {
        foreach ($data as $collection => $documents) {
            foreach ($documents as $doc) {
                $this->request('POST', $collection, [
                    "fields" => $this->encodeDocument($doc)
                ]);
            }
        }
        return true;
    }

    private function encodeDocument(array $doc): array
    {
        $encoded = [];
        foreach ($doc as $k => $v) {
            $encoded[$k] = $this->encodeValue($v);
        }
        return $encoded;
    }

    private function encodeValue($value): array
    {
        if (is_string($value))  return ['stringValue' => $value];
        if (is_int($value))     return ['integerValue' => $value];
        if (is_float($value))   return ['doubleValue' => $value];
        if (is_bool($value))    return ['booleanValue' => $value];
        if (is_null($value))    return ['nullValue' => null];
        if (is_array($value)) {
            if (self::isAssoc($value)) {
                $fields = [];
                foreach ($value as $k => $v) {
                    $fields[$k] = $this->encodeValue($v);
                }
                return ['mapValue' => ['fields' => $fields]];
            } else {
                return ['arrayValue' => ['values' => array_map([$this, 'encodeValue'], $value)]];
            }
        }
        return ['stringValue' => strval($value)];
    }

    private static function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    public function export(string $path, bool $encrypt = false, ?string $key = null, ?string $iv = null): bool
    {
        return $this->backup($path, $encrypt, $key, $iv);
    }

    public function listDatabases(): array
    {
        return [$this->projectId];
    }

    public function listTables(string $database): array
    {
        return $this->getSchema();
    }

    public function getTableRecords(string $database, string $table, int $limit = 50): array
    {
        return $this->findAll($table, $limit);
    }

    public function createDatabase(string $dbName): bool
    {
        // Firestore is one database per project — no-op
        return true;
    }

    public function createTable(string $database, string $tableName, array $columns): bool
    {
        // Firestore is schemaless — create dummy doc
        $this->request('POST', $tableName, [
            "fields" => [
                "_init" => ["stringValue" => "created"]
            ]
        ]);
        return true;
    }

    public function deleteTable(string $tableName): bool
    {
        // Firestore cannot delete a collection via REST; no-op
        return true;
    }

    public function dropDatabase(string $database): bool
    {
        // No-op; Firestore is one DB per project
        return true;
    }
}
