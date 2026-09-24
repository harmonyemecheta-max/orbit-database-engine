<?php
namespace DB\Traits;

trait LoggerTrait
{
    private string $logFile = __DIR__ . '/../../logs/dbmanager.log';
    private bool $debugEcho = false;

    protected function log(string $message, array $context = []): void
    {
        $date = date('Y-m-d H:i:s');
        $entry = "[$date] $message" . (!empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : '') . PHP_EOL;
        @file_put_contents($this->logFile, $entry, FILE_APPEND);
        if ($this->debugEcho) echo $entry;
    }

    public function setLogFile(string $path): void { $this->logFile = $path; }
    public function enableDebugEcho(bool $v=true): void { $this->debugEcho = $v; }
}
