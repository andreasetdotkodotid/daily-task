<?php

declare(strict_types=1);

use DailyTask\Database;
use DailyTask\AuthClient;
use DailyTask\Config;
use DailyTask\GoogleSheetClient;
use DailyTask\SheetSettingsRepository;
use DailyTask\TaskRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

session_start();

Config::loadEnv(dirname(__DIR__) . '/.env');

$dbPath = getenv('DB_PATH') ?: dirname(__DIR__) . '/storage/tasks.sqlite';
$pdo = Database::connect($dbPath);
$repository = new TaskRepository($pdo);
$sheetSettingsRepository = new SheetSettingsRepository($pdo);
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
        $_SESSION['user'] = $authClient->verifySsoToken(queryParam('token'));
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

if ($path === '/sheet') {
    $sheetDate = normalizeDate(queryParam('date')) ?? date('Y-m-d');
    $settings = $sheetSettingsRepository->get($userId);
    $sheetMessage = null;
    $sheetError = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_sheet_settings') {
            $sheetSettingsRepository->save($userId, [
                'webhook_url' => $_POST['webhook_url'] ?? '',
                'spreadsheet_id' => $_POST['spreadsheet_id'] ?? '',
                'sheet_name' => $_POST['sheet_name'] ?? '',
                'sync_secret' => $_POST['sync_secret'] ?? '',
            ]);
            header('Location: /sheet?date=' . rawurlencode($sheetDate) . '&saved=1');
            exit;
        }

        if ($action === 'sync_sheet') {
            try {
                $settings = $sheetSettingsRepository->get($userId);
                assertSheetSettings($settings);
                $sheetMessage = (new GoogleSheetClient())->sync(
                    $settings['webhook_url'],
                    buildSheetPayload($settings, $repository->forDate($userId, $sheetDate), $sheetDate, $currentUser)
                );
            } catch (Throwable $exception) {
                $sheetError = $exception->getMessage();
            }
        }
    }

    if (queryParam('saved') === '1') {
        $sheetMessage = 'Konfigurasi Google Sheet disimpan.';
    }

    renderSheetPage(
        $settings,
        $repository->forDate($userId, $sheetDate),
        $sheetDate,
        $currentUser,
        $sheetMessage,
        $sheetError
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create' && trim($_POST['title'] ?? '') !== '') {
        $repository->create($userId, [
            'title' => $_POST['title'],
            'obstacle' => $_POST['obstacle'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'priority' => $_POST['priority'] ?? 'normal',
            'due_date' => $_POST['due_date'] ?? '',
        ]);
    }

    if ($action === 'update' && trim($_POST['title'] ?? '') !== '') {
        $repository->update($userId, (int) ($_POST['id'] ?? 0), [
            'title' => $_POST['title'],
            'obstacle' => $_POST['obstacle'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'priority' => $_POST['priority'] ?? 'normal',
            'due_date' => $_POST['due_date'] ?? '',
            'completed' => $_POST['completed'] ?? 0,
        ]);
    }

    if ($action === 'toggle') {
        $repository->toggle($userId, (int) ($_POST['id'] ?? 0));
    }

    if ($action === 'delete') {
        $repository->delete($userId, (int) ($_POST['id'] ?? 0));
    }

    header('Location: ' . mutationRedirect($_POST));
    exit;
}

$today = date('Y-m-d');
$selectedDate = normalizeDate(queryParam('date')) ?? $today;
$view = queryParam('view') === 'all' ? 'all' : 'date';
$tasks = $view === 'all' ? $repository->all($userId) : $repository->forDate($userId, $selectedDate);
$total = count($tasks);
$done = count(array_filter($tasks, static fn (array $task): bool => (int) $task['completed'] === 1));
$previousDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
$pageTitle = $view === 'all' ? 'Semua task' : ($selectedDate === $today ? 'Task hari ini' : 'Task untuk ' . $selectedDate);
$editId = (int) queryParam('edit');

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function queryParam(string $key): string
{
    if (isset($_GET[$key])) {
        return (string) $_GET[$key];
    }

    $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);

    if (! is_string($query) || $query === '') {
        return '';
    }

    parse_str($query, $params);

    return isset($params[$key]) ? (string) $params[$key] : '';
}

function normalizeDate(string $date): ?string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
}

function mutationRedirect(array $data): string
{
    $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    parse_str(is_string($query) ? $query : '', $params);

    if (($params['view'] ?? '') === 'all') {
        return '/?view=all';
    }

    $date = normalizeDate((string) ($data['due_date'] ?? '')) ?? date('Y-m-d');

    return '/?date=' . rawurlencode($date);
}

function currentViewUrl(string $view, string $selectedDate): string
{
    return $view === 'all' ? '/?view=all' : '/?date=' . rawurlencode($selectedDate);
}

function editUrl(int $taskId, string $view, string $selectedDate): string
{
    $params = $view === 'all' ? ['view' => 'all'] : ['date' => $selectedDate];
    $params['edit'] = $taskId;

    return '/?' . http_build_query($params);
}

function renderThemeBoot(): void
{
    ?>
    <script>
        (() => {
            let theme = 'default';

            try {
                theme = localStorage.getItem('dailyTaskTheme') || 'default';
            } catch (error) {
                theme = 'default';
            }

            document.documentElement.dataset.theme = theme;
        })();
    </script>
    <?php
}

function renderThemeSwitcher(): void
{
    ?>
    <section class="theme-switcher panel" aria-label="Pilihan tema warna">
        <span>Tema</span>
        <button type="button" class="theme-option active" data-theme="default" aria-pressed="true"><i></i>Default</button>
        <button type="button" class="theme-option" data-theme="pink" aria-pressed="false"><i></i>Baby Pink</button>
        <button type="button" class="theme-option" data-theme="navy" aria-pressed="false"><i></i>Navy</button>
    </section>
    <?php
}

/** @param array{webhook_url:string,spreadsheet_id:string,sheet_name:string,sync_secret:string} $settings */
function assertSheetSettings(array $settings): void
{
    foreach (['webhook_url', 'spreadsheet_id', 'sheet_name', 'sync_secret'] as $field) {
        if (trim($settings[$field] ?? '') === '') {
            throw new RuntimeException('Konfigurasi Google Sheet belum lengkap.');
        }
    }
}

/**
 * @param array{webhook_url:string,spreadsheet_id:string,sheet_name:string,sync_secret:string} $settings
 * @param array<int, array<string, mixed>> $tasks
 * @param array<string, mixed> $currentUser
 * @return array<string, mixed>
 */
function buildSheetPayload(array $settings, array $tasks, string $date, array $currentUser): array
{
    return [
        'secret' => $settings['sync_secret'],
        'spreadsheet_id' => $settings['spreadsheet_id'],
        'sheet_name' => $settings['sheet_name'],
        'mode' => 'replace_date',
        'date' => $date,
        'user' => [
            'id' => (int) ($currentUser['id'] ?? 0),
            'name' => (string) ($currentUser['name'] ?? ''),
            'email' => (string) ($currentUser['email'] ?? ''),
        ],
        'rows' => array_map(static fn (array $task): array => sheetRow($task), $tasks),
    ];
}

/** @param array<string, mixed> $task */
function sheetRow(array $task): array
{
    $done = (int) $task['completed'] === 1;

    return [
        'date' => (string) ($task['due_date'] ?? ''),
        'task' => (string) ($task['title'] ?? ''),
        'obstacle' => (string) ($task['obstacle'] ?? ''),
        'note' => (string) ($task['notes'] ?? ''),
        'done' => $done,
        'on_progress' => ! $done,
    ];
}

/**
 * @param array{webhook_url:string,spreadsheet_id:string,sheet_name:string,sync_secret:string} $settings
 * @param array<int, array<string, mixed>> $tasks
 * @param array<string, mixed> $currentUser
 */
function renderSheetPage(array $settings, array $tasks, string $sheetDate, array $currentUser, ?string $message, ?string $error): void
{
    $previousDate = date('Y-m-d', strtotime($sheetDate . ' -1 day'));
    $nextDate = date('Y-m-d', strtotime($sheetDate . ' +1 day'));
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Google Sheet Sync - Daily Task</title>
        <?php renderThemeBoot(); ?>
        <link rel="stylesheet" href="/assets/app.css">
    </head>
    <body>
        <main class="shell">
            <?php renderThemeSwitcher(); ?>
            <section class="hero">
                <div>
                    <p class="eyebrow">Google Sheet Sync</p>
                    <h1>Laporan task untuk <?= e($sheetDate) ?></h1>
                    <p class="subtitle">Preview data sebelum dikirim ke Google Sheet. Sync bersifat manual dan memakai mode replace tanggal agar tidak duplikat.</p>
                </div>
                <div class="summary" aria-label="Ringkasan sync">
                    <span><?= count($tasks) ?></span>
                    <small>baris laporan</small>
                    <a href="/">Kembali</a>
                </div>
            </section>

            <?php if ($message): ?>
                <div class="alert success-alert"><?= e($message) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <section class="date-nav panel" aria-label="Navigasi tanggal laporan">
                <a href="/sheet?date=<?= e($previousDate) ?>">&larr; Sebelumnya</a>
                <a href="/sheet?date=<?= e(date('Y-m-d')) ?>">Hari Ini</a>
                <a href="/sheet?date=<?= e($nextDate) ?>">Berikutnya &rarr;</a>
                <form method="get" action="/sheet" class="date-jump">
                    <label>
                        <span>Lompat tanggal</span>
                        <input name="date" type="date" value="<?= e($sheetDate) ?>">
                    </label>
                    <button type="submit">Lihat</button>
                </form>
            </section>

            <section class="panel task-form-panel sheet-settings">
                <form method="post" class="task-form">
                    <input type="hidden" name="action" value="save_sheet_settings">
                    <label>
                        <span>Apps Script Webhook URL</span>
                        <input name="webhook_url" type="url" value="<?= e($settings['webhook_url']) ?>" placeholder="https://script.google.com/macros/s/.../exec">
                    </label>
                    <label>
                        <span>Spreadsheet ID</span>
                        <input name="spreadsheet_id" type="text" value="<?= e($settings['spreadsheet_id']) ?>" placeholder="ID dari URL Google Sheet">
                    </label>
                    <div class="form-grid">
                        <label>
                            <span>Sheet Name</span>
                            <input name="sheet_name" type="text" value="<?= e($settings['sheet_name']) ?>" placeholder="Daily Report">
                        </label>
                        <label>
                            <span>Sync Secret</span>
                            <input name="sync_secret" type="password" value="<?= e($settings['sync_secret']) ?>" placeholder="Secret yang sama di Apps Script">
                        </label>
                    </div>
                    <button type="submit">Simpan Konfigurasi</button>
                </form>
            </section>

            <section class="panel sheet-preview">
                <div class="sheet-preview-header">
                    <div>
                        <p class="eyebrow">Preview</p>
                        <h2>Data yang akan dikirim</h2>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="sync_sheet">
                        <button type="submit" <?= $tasks === [] ? 'disabled' : '' ?>>Sync ke Google Sheet</button>
                    </form>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Tgl</th>
                                <th>Pekerjaan</th>
                                <th>Kendala</th>
                                <th>Keterangan</th>
                                <th>Done</th>
                                <th>On Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($tasks === []): ?>
                                <tr><td colspan="6">Belum ada task untuk tanggal ini.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($tasks as $task): ?>
                                <?php $row = sheetRow($task); ?>
                                <tr>
                                    <td><?= e($row['date']) ?></td>
                                    <td><?= e($row['task']) ?></td>
                                    <td><?= e($row['obstacle'] ?: '-') ?></td>
                                    <td><?= nl2br(e($row['note'] ?: '-')) ?></td>
                                    <td class="check-cell"><?= $row['done'] ? '&#10003;' : '' ?></td>
                                    <td class="check-cell"><?= $row['on_progress'] ? '&#10003;' : '' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="sheet-note">Mode sync: baris pada tanggal ini akan diganti ulang di Google Sheet agar tidak duplikat.</p>
            </section>
        </main>
        <script src="/assets/app.js" defer></script>
    </body>
    </html>
    <?php
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
        <?php renderThemeBoot(); ?>
        <link rel="stylesheet" href="/assets/app.css">
    </head>
    <body class="auth-page">
        <main class="auth-shell">
            <?php renderThemeSwitcher(); ?>
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
        <script src="/assets/app.js" defer></script>
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
    <?php renderThemeBoot(); ?>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <main class="shell">
        <?php renderThemeSwitcher(); ?>
        <section class="hero">
            <div>
                <p class="eyebrow">Daily Task</p>
                <h1><?= e($pageTitle) ?></h1>
                <p class="subtitle">Catat prioritas, tenggat, dan progres pekerjaan harian dalam satu tampilan sederhana. Login sebagai <?= e($currentUser['name'] ?? '') ?>.</p>
            </div>
            <div class="summary" aria-label="Ringkasan tugas">
                <span><?= $done ?></span>
                <small>dari <?= $total ?> selesai</small>
                <a href="/logout">Logout</a>
            </div>
        </section>

        <section class="date-nav panel" aria-label="Navigasi tanggal task">
            <a href="/?date=<?= e($previousDate) ?>">&larr; Sebelumnya</a>
            <a class="<?= $view === 'date' && $selectedDate === $today ? 'active' : '' ?>" href="/?date=<?= e($today) ?>">Hari Ini</a>
            <a href="/?date=<?= e($nextDate) ?>">Berikutnya &rarr;</a>
            <a href="/sheet?date=<?= e($selectedDate) ?>">Sync Google Sheet</a>
            <form method="get" class="date-jump">
                <label>
                    <span>Lompat tanggal</span>
                    <input name="date" type="date" value="<?= e($selectedDate) ?>">
                </label>
                <button type="submit">Lihat</button>
            </form>
            <a class="<?= $view === 'all' ? 'active' : '' ?>" href="/?view=all">Semua task</a>
        </section>

        <section class="panel task-form-panel">
            <form method="post" class="task-form" id="taskForm">
                <input type="hidden" name="action" value="create">
                <label>
                    <span>Pekerjaan</span>
                    <input name="title" id="title" type="text" placeholder="Contoh: Review laporan mingguan" maxlength="160" required>
                </label>
                <label>
                    <span>Kendala <small>opsional</small></span>
                    <textarea name="obstacle" rows="2" placeholder="Contoh: akses lambat, IP hilang, tidak ada kendala"></textarea>
                </label>
                <label>
                    <span>Keterangan <small>opsional</small></span>
                    <textarea name="notes" rows="3" placeholder="Tambahkan tindakan, hasil, atau konteks singkat bila perlu"></textarea>
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
                        <input name="due_date" type="date" value="<?= e($selectedDate) ?>">
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
                <article class="task panel <?= $isDone ? 'is-done' : '' ?> <?= $editId === (int) $task['id'] ? 'is-editing' : '' ?>" data-status="<?= $isDone ? 'done' : 'open' ?>">
                    <?php if ($editId === (int) $task['id']): ?>
                        <form method="post" class="task-edit-form">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                            <label>
                                <span>Pekerjaan</span>
                                <input name="title" type="text" value="<?= e($task['title']) ?>" maxlength="160" required>
                            </label>
                            <label>
                                <span>Kendala <small>opsional</small></span>
                                <textarea name="obstacle" rows="2"><?= e($task['obstacle'] ?? '') ?></textarea>
                            </label>
                            <label>
                                <span>Keterangan <small>opsional</small></span>
                                <textarea name="notes" rows="3"><?= e($task['notes'] ?? '') ?></textarea>
                            </label>
                            <div class="form-grid">
                                <label>
                                    <span>Prioritas</span>
                                    <select name="priority">
                                        <?php foreach (['normal' => 'Normal', 'high' => 'Tinggi', 'low' => 'Rendah'] as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= $task['priority'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Tanggal</span>
                                    <input name="due_date" type="date" value="<?= e($task['due_date']) ?>">
                                </label>
                                <label>
                                    <span>Status</span>
                                    <select name="completed">
                                        <option value="0" <?= ! $isDone ? 'selected' : '' ?>>On Progress</option>
                                        <option value="1" <?= $isDone ? 'selected' : '' ?>>Done</option>
                                    </select>
                                </label>
                            </div>
                            <div class="edit-actions">
                                <button type="submit">Simpan Perubahan</button>
                                <a href="<?= e(currentViewUrl($view, $selectedDate)) ?>">Batal</a>
                            </div>
                        </form>
                    <?php else: ?>
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
                            <?php if ($task['obstacle'] ?? ''): ?>
                                <p><strong>Kendala:</strong> <?= nl2br(e($task['obstacle'])) ?></p>
                            <?php endif; ?>
                            <?php if ($task['notes']): ?>
                                <p><strong>Keterangan:</strong> <?= nl2br(e($task['notes'])) ?></p>
                            <?php endif; ?>
                            <?php if ($task['due_date']): ?>
                                <time datetime="<?= e($task['due_date']) ?>">Target: <?= e($task['due_date']) ?></time>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="task-actions">
                        <a class="edit-link" href="<?= e(editUrl((int) $task['id'], $view, $selectedDate)) ?>">Edit</a>
                        <form method="post" class="delete-form">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
                            <button type="submit" class="delete">Hapus</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
    <script src="/assets/app.js" defer></script>
</body>
</html>
