<?php

declare(strict_types=1);

use DailyTask\Database;
use DailyTask\TaskRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$dbPath = getenv('DB_PATH') ?: dirname(__DIR__) . '/storage/tasks.sqlite';
$repository = new TaskRepository(Database::connect($dbPath));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create' && trim($_POST['title'] ?? '') !== '') {
        $repository->create([
            'title' => $_POST['title'],
            'notes' => $_POST['notes'] ?? '',
            'priority' => $_POST['priority'] ?? 'normal',
            'due_date' => $_POST['due_date'] ?? '',
        ]);
    }

    if ($action === 'toggle') {
        $repository->toggle((int) ($_POST['id'] ?? 0));
    }

    if ($action === 'delete') {
        $repository->delete((int) ($_POST['id'] ?? 0));
    }

    header('Location: /');
    exit;
}

$tasks = $repository->all();
$total = count($tasks);
$done = count(array_filter($tasks, static fn (array $task): bool => (int) $task['completed'] === 1));
$today = date('Y-m-d');

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daily Task</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <main class="shell">
        <section class="hero">
            <div>
                <p class="eyebrow">Daily Task</p>
                <h1>Susun hari ini dengan lebih ringan.</h1>
                <p class="subtitle">Catat prioritas, tenggat, dan progres pekerjaan harian dalam satu tampilan sederhana.</p>
            </div>
            <div class="summary" aria-label="Ringkasan tugas">
                <span><?= $done ?></span>
                <small>dari <?= $total ?> selesai</small>
            </div>
        </section>

        <section class="panel task-form-panel">
            <form method="post" class="task-form" id="taskForm">
                <input type="hidden" name="action" value="create">
                <label>
                    <span>Nama tugas</span>
                    <input name="title" id="title" type="text" placeholder="Contoh: Review laporan mingguan" maxlength="160" required>
                </label>
                <label>
                    <span>Catatan</span>
                    <textarea name="notes" rows="3" placeholder="Tambahkan konteks singkat bila perlu"></textarea>
                </label>
                <div class="form-grid">
                    <label>
                        <span>Prioritas</span>
                        <select name="priority">
                            <option value="normal">Normal</option>
                            <option value="high">Tinggi</option>
                            <option value="low">Rendah</option>
                        </select>
                    </label>
                    <label>
                        <span>Tanggal</span>
                        <input name="due_date" type="date" value="<?= e($today) ?>">
                    </label>
                </div>
                <button type="submit">Tambah Task</button>
            </form>
        </section>

        <section class="toolbar" aria-label="Filter tugas">
            <button type="button" class="filter active" data-filter="all">Semua</button>
            <button type="button" class="filter" data-filter="open">Belum selesai</button>
            <button type="button" class="filter" data-filter="done">Selesai</button>
        </section>

        <section class="tasks" id="taskList">
            <?php if ($tasks === []): ?>
                <article class="empty panel">
                    <h2>Belum ada task.</h2>
                    <p>Tambahkan tugas pertama untuk memulai hari.</p>
                </article>
            <?php endif; ?>

            <?php foreach ($tasks as $task): ?>
                <?php $isDone = (int) $task['completed'] === 1; ?>
                <article class="task panel <?= $isDone ? 'is-done' : '' ?>" data-status="<?= $isDone ? 'done' : 'open' ?>">
                    <div class="task-main">
                        <form method="post">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                            <button class="check" type="submit" aria-label="Ubah status <?= e($task['title']) ?>"></button>
                        </form>
                        <div>
                            <div class="task-title-row">
                                <h2><?= e($task['title']) ?></h2>
                                <span class="badge priority-<?= e($task['priority']) ?>"><?= e($task['priority']) ?></span>
                            </div>
                            <?php if ($task['notes']): ?>
                                <p><?= nl2br(e($task['notes'])) ?></p>
                            <?php endif; ?>
                            <?php if ($task['due_date']): ?>
                                <time datetime="<?= e($task['due_date']) ?>">Target: <?= e($task['due_date']) ?></time>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="post" class="delete-form">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                        <button type="submit" class="delete">Hapus</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
    <script src="/assets/app.js" defer></script>
</body>
</html>
