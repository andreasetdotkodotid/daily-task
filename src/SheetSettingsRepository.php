<?php

declare(strict_types=1);

namespace DailyTask;

use PDO;

final class SheetSettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{webhook_url:string,spreadsheet_id:string,sheet_name:string,sync_secret:string} */
    public function get(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM sheet_settings WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        $settings = $statement->fetch();

        if (! $settings) {
            return [
                'webhook_url' => '',
                'spreadsheet_id' => '',
                'sheet_name' => 'Daily Report',
                'sync_secret' => '',
            ];
        }

        return [
            'webhook_url' => (string) ($settings['webhook_url'] ?? ''),
            'spreadsheet_id' => (string) ($settings['spreadsheet_id'] ?? ''),
            'sheet_name' => (string) ($settings['sheet_name'] ?? 'Daily Report'),
            'sync_secret' => (string) ($settings['sync_secret'] ?? ''),
        ];
    }

    /** @param array{webhook_url:string,spreadsheet_id:string,sheet_name:string,sync_secret:string} $data */
    public function save(int $userId, array $data): void
    {
        $now = date('c');
        $statement = $this->pdo->prepare(
            'INSERT INTO sheet_settings (user_id, webhook_url, spreadsheet_id, sheet_name, sync_secret, created_at, updated_at)
             VALUES (:user_id, :webhook_url, :spreadsheet_id, :sheet_name, :sync_secret, :created_at, :updated_at)
             ON CONFLICT(user_id) DO UPDATE SET
                webhook_url = excluded.webhook_url,
                spreadsheet_id = excluded.spreadsheet_id,
                sheet_name = excluded.sheet_name,
                sync_secret = excluded.sync_secret,
                updated_at = excluded.updated_at'
        );

        $statement->execute([
            'user_id' => $userId,
            'webhook_url' => trim($data['webhook_url']),
            'spreadsheet_id' => trim($data['spreadsheet_id']),
            'sheet_name' => trim($data['sheet_name']) !== '' ? trim($data['sheet_name']) : 'Daily Report',
            'sync_secret' => trim($data['sync_secret']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
