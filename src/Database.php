<?php

declare(strict_types=1);

namespace DailyTask;

use PDO;

final class Database
{
    public static function connect(string $dsn, string $user, string $password): PDO
    {
        $pdo = new PDO($dsn, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        self::migrate($pdo);

        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tasks (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL,
                title TEXT NOT NULL,
                obstacle TEXT,
                notes TEXT,
                priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                due_date DATE,
                completed BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_user_id ON tasks (user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_due_date ON tasks (due_date)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sheet_settings (
                user_id BIGINT PRIMARY KEY,
                webhook_url TEXT,
                spreadsheet_id TEXT,
                sheet_name TEXT,
                sync_secret TEXT,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL
            )
            SQL);
    }
}
