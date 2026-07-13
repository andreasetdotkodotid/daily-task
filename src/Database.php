<?php

declare(strict_types=1);

namespace DailyTask;

use PDO;

final class Database
{
    public static function connect(string $path): PDO
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::migrate($pdo);

        return $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                title TEXT NOT NULL,
                obstacle TEXT,
                notes TEXT,
                priority TEXT NOT NULL DEFAULT 'normal',
                due_date TEXT,
                completed INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);

        $columns = $pdo->query('PRAGMA table_info(tasks)')->fetchAll();
        $columnNames = array_column($columns, 'name');

        if (! in_array('user_id', $columnNames, true)) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN user_id INTEGER');
        }

        if (! in_array('obstacle', $columnNames, true)) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN obstacle TEXT');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_user_id ON tasks (user_id)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sheet_settings (
                user_id INTEGER PRIMARY KEY,
                webhook_url TEXT,
                spreadsheet_id TEXT,
                sheet_name TEXT,
                sync_secret TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);
    }
}
