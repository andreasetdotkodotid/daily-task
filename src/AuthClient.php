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

        [$status, $response] = $this->postJson($this->loginUrl, ['email' => $email, 'password' => $password]);

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
        $token = trim($token);

        if ($this->apiKey === '') {
            throw new RuntimeException('Konfigurasi secret SSO belum lengkap.');
        }

        if ($token === '') {
            throw new RuntimeException('Token SSO tidak ditemukan. Silakan login ulang dengan Google.');
        }

        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            throw new RuntimeException('Format token SSO tidak valid. Silakan login ulang dengan Google.');
        }

        [$encodedPayload, $signature] = $parts;

        if ($encodedPayload === '' || $signature === '') {
            throw new RuntimeException('Token SSO tidak lengkap. Silakan login ulang dengan Google.');
        }

        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->apiKey, true));

        if (! hash_equals($expectedSignature, $signature)) {
            throw new RuntimeException('Signature token SSO tidak valid. Pastikan AUTH_API_KEY sama dengan API_CLIENT_SECRET.');
        }

        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Payload token SSO tidak valid.');
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token SSO sudah kedaluwarsa.');
        }

        foreach (['id', 'email', 'name', 'provider'] as $field) {
            if (! isset($payload[$field])) {
                throw new RuntimeException('Payload token SSO tidak lengkap.');
            }
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

    /** @param array<string, string> $payload */
    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
        ];

        if (function_exists('curl_init')) {
            $handle = \curl_init($url);

            if ($handle === false) {
                throw new RuntimeException('Gagal memulai koneksi ke API login.');
            }

            \curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 12,
            ]);

            $response = \curl_exec($handle);
            $status = (int) \curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = \curl_error($handle);
            \curl_close($handle);

            if ($response === false) {
                throw new RuntimeException('API login tidak dapat dihubungi: ' . $error);
            }

            return [$status, $response];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('API login tidak dapat dihubungi. Aktifkan ekstensi php-curl atau pastikan allow_url_fopen aktif.');
        }

        $status = 200;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return [$status, $response];
    }
}
