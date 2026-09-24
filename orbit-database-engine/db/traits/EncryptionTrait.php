<?php
namespace DB\Traits;

trait EncryptionTrait
{
    public function encryptData(string $data, string $key, string $iv): string
    {
        return openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    }

    public function decryptData(string $data, string $key, string $iv): string
    {
        return openssl_decrypt($data, 'aes-256-cbc', $key, 0, $iv);
    }
}
