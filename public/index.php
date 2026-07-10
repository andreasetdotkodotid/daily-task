<?php

declare(strict_types=1);

use DailyTask\Database;
use DailyTask\AuthClient;
use DailyTask\Config;
use DailyTask\TaskRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

session_start();

Config::loadEnv(dirname(__DIR__) . '/.env');

$dbPath = getenv('DB_PATH') ?: dirname(__DIR__) . '/storage/tasks.sqlite';
$repository = new TaskRepository(Database::connect($dbPath));
$currentUser = $_SESSION['user'] ?? null;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if ($path === '/logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: /login');
    exit;
}

if ($path === '/login') {
    $loginError = null;

    if ($currentUser) {
        header('Location: /');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $authClient = new AuthClient((string) getenv('AUTH_API_URL'), (string) getenv('AUTH_API_KEY'));
            $_SESSION['user'] = $authClient->login(
                trim((string) ($_POST['email'] ?? '')),
                (string) ($_POST['password'] ?? '')
            );
            session_regenerate_id(true);
            header('Location: /');
            exit;
        } catch (Throwable $exception) {
            $loginError = $exception->getMessage();
        }
    }

    renderLogin($loginError);
    exit;
}

if ($path === '/auth/callback') {
    try {
        $authClient = new AuthClient((string) getenv('AUTH_API_URL'), (string) getenv('AUTH_API_KEY'));
        $_SESSION['user'] = $authClient->verifySsoToken((string) ($_GET['token'] ?? ''));
        session_regenerate_id(true);
        header('Location: /');
        exit;
    } catch (Throwable $exception) {
        renderLogin($exception->getMessage());
        exit;
    }
}

if (! $currentUser) {
    header('Location: /login');
    exit;
}

$userId = (int) $currentUser['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create' && trim($_POST['title'] ?? '') !== '') {
        $repository->create($userId, [
            'title' => $_POST['title'],
            'notes' => $_POST['notes'] ?? '',
            'priority' => $_POST['priority'] ?? 'normal',
            'due_date' => $_POST['due_date'] ?? '',
        ]);
    }

    if ($action === 'toggle') {
        $repository->toggle($userId, (int) ($_POST['id'] ?? 0));
    }

    if ($action === 'delete') {
        $repository->delete($userId, (int) ($_POST['id'] ?? 0));
    }

    header('Location: /');
    exit;
}

$tasks = $repository->all($userId);
$total = count($tasks);
$done = count(array_filter($tasks, static fn (array $task): bool => (int) $task['completed'] === 1));
$today = date('Y-m-d');

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function renderLogin(?string $error): void
{
    $appUrl = rtrim((string) (getenv('APP_URL') ?: 'http://127.0.0.1:8000'), '/');
    $ssoUrl = (string) getenv('AUTH_SSO_URL');
    $googleUrl = $ssoUrl !== '' ? $ssoUrl . '?return_url=' . rawurlencode($appUrl . '/auth/callback') : '';

    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Login - Daily Task</title>
        <link rel="stylesheet" href="/assets/app.css">
    </head>
    <body class="auth-page">
        <main class="auth-shell">
            <section class="auth-copy">
                <p class="eyebrow">Private Workspace</p>
                <h1>Daily task yang hanya kamu yang bisa buka.</h1>
                <p class="subtitle">Masuk memakai akun pusat di login.dotko.id. Setelah login, task harian akan tersimpan sesuai user kamu.</p>
                <div class="auth-points" aria-label="Keamanan aplikasi">
                    <span>Google OAuth</span>
                    <span>Session privat</span>
                    <span>Task per user</span>
                </div>
            </section>

            <section class="panel auth-card" aria-label="Form login">
                <div class="auth-card-header">
                    <p class="eyebrow">Login</p>
                    <h2>Masuk ke Daily Task</h2>
                </div>

                <?php if ($error): ?>
                    <div class="alert"><?= e($error) ?></div>
                <?php endif; ?>

                <?php if ($googleUrl !== ''): ?>
                    <a class="google-login" href="<?= e($googleUrl) ?>">
                        <span class="google-mark">G</span>
                        <span>Masuk dengan Google</span>
                    </a>
                    <div class="divider"><span>atau pakai email</span></div>
                <?php endif; ?>

                <form method="post" class="task-form auth-form">
                    <label>
                        <span>Email</span>
                        <input name="email" type="email" placeholder="nama@email.com" autocomplete="email" required autofocus>
                    </label>
                    <label>
                        <span>Password</span>
                        <input name="password" type="password" placeholder="Password akun login" autocomplete="current-password" required>
                    </label>
                    <button type="submit">Masuk</button>
                </form>
            </section>
        </main>
    </body>
    </html>
    <?php
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
                <p class="subtitle">Catat prioritas, tenggat, dan progres pekerjaan harian dalam satu tampilan sederhana. Login sebagai <?= e($currentUser['name'] ?? '') ?>.</p>
            </div>
            <div class="summary" aria-label="Ringkasan tugas">
                <span><?= $done ?></span>
                <small>dari <?= $total ?> selesai</small>
                <a href="/logout">Logout</a>
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
