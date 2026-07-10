<?php

declare(strict_types=1);

namespace DailyTask;

use RuntimeException;

final class AuthClient
{
    public function __construct(
        private readonly string $loginUrl,
        private readonly string $apiKey,
    ) {
    }

    /** @return array{id:int,email:string,name:string,provider:string} */
    public function login(string $email, string $password): array
    {
        if ($this->loginUrl === '' || $this->apiKey === '') {
            throw new RuntimeException('Konfigurasi API login belum lengkap.');
        }

        $handle = curl_init($this->loginUrl);

        if ($handle === false) {
            throw new RuntimeException('Gagal memulai koneksi ke API login.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 12,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException('API login tidak dapat dihubungi: ' . $error);
        }

        $payload = json_decode($response, true);

        if (! is_array($payload)) {
            throw new RuntimeException('Response API login tidak valid.');
        }

        if ($status !== 200) {
            throw new RuntimeException((string) ($payload['message'] ?? 'Login gagal.'));
        }

        if (! isset($payload['user']) || ! is_array($payload['user'])) {
            throw new RuntimeException('Data user tidak ditemukan dari API login.');
        }

        return [
            'id' => (int) $payload['user']['id'],
            'email' => (string) $payload['user']['email'],
            'name' => (string) $payload['user']['name'],
            'provider' => (string) $payload['user']['provider'],
        ];
    }

    /** @return array{id:int,email:string,name:string,provider:string} */
    public function verifySsoToken(string $token): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Konfigurasi secret SSO belum lengkap.');
        }

        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            throw new RuntimeException('Token SSO tidak valid.');
        }

        [$encodedPayload, $signature] = $parts;
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->apiKey, true));

        if (! hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException('Signature token SSO tidak valid.');
        }

        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Payload token SSO tidak valid.');
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token SSO sudah kedaluwarsa.');
        }

        return [
            'id' => (int) $payload['id'],
            'email' => (string) $payload['email'],
            'name' => (string) $payload['name'],
            'provider' => (string) $payload['provider'],
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new RuntimeException('Token SSO tidak dapat dibaca.');
        }

        return $decoded;
    }
}
