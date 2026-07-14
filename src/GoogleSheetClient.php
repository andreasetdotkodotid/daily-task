<?php

declare(strict_types=1);

namespace DailyTask;

use RuntimeException;

final class GoogleSheetClient
{
    /** @param array<string, mixed> $payload */
    public function sync(string $webhookUrl, array $payload): string
    {
        if ($webhookUrl === '') {
            throw new RuntimeException('Webhook URL Google Apps Script belum diisi.');
        }

        [$status, $response] = $this->postJson($webhookUrl, $payload);

        $decoded = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : trim(strip_tags($response));

            throw new RuntimeException(
                'Sync gagal dari Google Apps Script. HTTP ' . $status . ($message !== '' ? ': ' . substr($message, 0, 300) : '')
            );
        }

        if (is_array($decoded) && ($decoded['ok'] ?? false) === false) {
            throw new RuntimeException((string) ($decoded['message'] ?? 'Sync Google Sheet gagal.'));
        }

        if (is_array($decoded) && isset($decoded['message'])) {
            return (string) $decoded['message'];
        }

        return 'Sync Google Sheet berhasil.';
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        if (function_exists('curl_init')) {
            $handle = \curl_init($url);

            if ($handle === false) {
                throw new RuntimeException('Gagal memulai koneksi ke Google Apps Script.');
            }

            \curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 20,
            ]);

            $response = \curl_exec($handle);
            $status = (int) \curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = \curl_error($handle);
            \curl_close($handle);

            if ($response === false) {
                throw new RuntimeException('Google Apps Script tidak dapat dihubungi: ' . $error);
            }

            return [$status, $response];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('Google Apps Script tidak dapat dihubungi. Aktifkan ekstensi php-curl atau pastikan allow_url_fopen aktif.');
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
