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
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT * FROM tasks ORDER BY completed ASC, due_date IS NULL ASC, due_date ASC, created_at DESC'
        );

        return $statement->fetchAll();
    }

    /** @param array{title:string,notes?:string,priority?:string,due_date?:string} $data */
    public function create(array $data): void
    {
        $now = date('c');
        $statement = $this->pdo->prepare(
            'INSERT INTO tasks (title, notes, priority, due_date, created_at, updated_at)
             VALUES (:title, :notes, :priority, :due_date, :created_at, :updated_at)'
        );

        $statement->execute([
            'title' => trim($data['title']),
            'notes' => trim($data['notes'] ?? ''),
            'priority' => $this->normalizePriority($data['priority'] ?? 'normal'),
            'due_date' => $this->normalizeDate($data['due_date'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function toggle(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tasks SET completed = CASE completed WHEN 1 THEN 0 ELSE 1 END, updated_at = :updated_at WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'updated_at' => date('c'),
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM tasks WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    private function normalizePriority(string $priority): string
    {
        return in_array($priority, ['low', 'normal', 'high'], true) ? $priority : 'normal';
    }

    private function normalizeDate(string $date): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
    }
}
