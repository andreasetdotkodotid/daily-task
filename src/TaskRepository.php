<?php

declare(strict_types=1);

namespace DailyTask;

use PDO;

final class TaskRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tasks WHERE user_id = :user_id ORDER BY completed ASC, due_date ASC NULLS LAST, created_at DESC'
        );

        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function forDate(int $userId, string $date): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tasks WHERE user_id = :user_id AND due_date = :due_date ORDER BY completed ASC, created_at DESC'
        );

        $statement->execute(['user_id' => $userId, 'due_date' => $date]);

        return $statement->fetchAll();
    }

    /** @param array{title:string,obstacle?:string,notes?:string,priority?:string,due_date?:string} $data */
    public function create(int $userId, array $data): void
    {
        $now = date('c');
        $statement = $this->pdo->prepare(
            'INSERT INTO tasks (user_id, title, obstacle, notes, priority, due_date, created_at, updated_at)
             VALUES (:user_id, :title, :obstacle, :notes, :priority, :due_date, :created_at, :updated_at)'
        );

        $statement->execute([
            'user_id' => $userId,
            'title' => trim($data['title']),
            'obstacle' => trim($data['obstacle'] ?? ''),
            'notes' => trim($data['notes'] ?? ''),
            'priority' => $this->normalizePriority($data['priority'] ?? 'normal'),
            'due_date' => $this->normalizeDate($data['due_date'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array{title:string,obstacle?:string,notes?:string,priority?:string,due_date?:string,completed?:string|int} $data */
    public function update(int $userId, int $id, array $data): void
    {
        $completed = $this->normalizeCompleted($data['completed'] ?? 0) ? 'TRUE' : 'FALSE';
        $statement = $this->pdo->prepare(
            "UPDATE tasks
             SET title = :title,
                 obstacle = :obstacle,
                 notes = :notes,
                 priority = :priority,
                 due_date = :due_date,
                 completed = $completed,
                 updated_at = :updated_at
             WHERE id = :id AND user_id = :user_id"
        );

        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'title' => trim($data['title']),
            'obstacle' => trim($data['obstacle'] ?? ''),
            'notes' => trim($data['notes'] ?? ''),
            'priority' => $this->normalizePriority($data['priority'] ?? 'normal'),
            'due_date' => $this->normalizeDate($data['due_date'] ?? ''),
            'updated_at' => date('c'),
        ]);
    }

    public function toggle(int $userId, int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tasks SET completed = NOT completed, updated_at = :updated_at WHERE id = :id AND user_id = :user_id'
        );

        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'updated_at' => date('c'),
        ]);
    }

    public function delete(int $userId, int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM tasks WHERE id = :id AND user_id = :user_id');
        $statement->execute(['id' => $id, 'user_id' => $userId]);
    }

    private function normalizePriority(string $priority): string
    {
        return in_array($priority, ['low', 'normal', 'high'], true) ? $priority : 'normal';
    }

    private function normalizeDate(string $date): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }

    private function normalizeCompleted(string|int $completed): bool
    {
        return $completed === '1' || $completed === 1;
    }
}
