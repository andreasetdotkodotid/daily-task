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

        $handle = curl_init($webhookUrl);

        if ($handle === false) {
            throw new RuntimeException('Gagal memulai koneksi ke Google Apps Script.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new RuntimeException('Google Apps Script tidak dapat dihubungi: ' . $error);
        }

        $decoded = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Sync gagal dari Google Apps Script. HTTP ' . $status);
        }

        if (is_array($decoded) && ($decoded['ok'] ?? false) === false) {
            throw new RuntimeException((string) ($decoded['message'] ?? 'Sync Google Sheet gagal.'));
        }

        if (is_array($decoded) && isset($decoded['message'])) {
            return (string) $decoded['message'];
        }

        return 'Sync Google Sheet berhasil.';
    }
}
