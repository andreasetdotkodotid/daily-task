# Daily Task

Aplikasi daily task sederhana menggunakan PHP, Composer, PostgreSQL, HTML, CSS, dan JavaScript.

## Fitur

- Tambah task dengan catatan, prioritas, dan tanggal target.
- Tandai task selesai atau belum selesai.
- Hapus task.
- Filter task berdasarkan semua, belum selesai, dan selesai.
- Penyimpanan data memakai PostgreSQL.

## Menjalankan Lokal

```bash
composer install
composer serve
```

Buka `http://127.0.0.1:8000`.

Pastikan PostgreSQL berjalan dan `.env` sudah berisi `DB_DSN`, `DB_USER`, dan `DB_PASS`.

## PostgreSQL Dengan Docker Compose

Contoh service PostgreSQL tersedia di `docker-compose.postgres.example.yml` dan mengikuti pola Docker Compose dengan network `production` serta bind mount:

```yaml
volumes:
  - "$PWD/postgres/daily-task/data:/var/lib/postgresql/data"
```

Contoh environment untuk file `.env` Docker Compose:

```env
DAILY_TASK_POSTGRES_DB=daily_task
DAILY_TASK_POSTGRES_USER=daily_task
DAILY_TASK_POSTGRES_PASSWORD=change-this-strong-password
```

Jika PHP-FPM berjalan di network Docker yang sama, gunakan DSN:

```env
DB_DSN=pgsql:host=postgres;port=5432;dbname=daily_task
DB_USER=daily_task
DB_PASS=change-this-strong-password
```

Jika PHP berjalan dari host dan PostgreSQL diekspos ke localhost, gunakan:

```env
DB_DSN=pgsql:host=127.0.0.1;port=5432;dbname=daily_task
```

## Environment

Salin `.env.example` menjadi `.env` bila ingin mengatur konfigurasi production.

```env
APP_ENV=production
APP_URL=https://daily-task.example.com
DB_DSN=pgsql:host=127.0.0.1;port=5432;dbname=daily_task
DB_USER=daily_task
DB_PASS=change-this-password
AUTH_API_URL=https://login.dotko.id/api/login
AUTH_SSO_URL=https://login.dotko.id/sso/google
AUTH_API_KEY=change-this-api-client-secret
```

`AUTH_API_KEY` harus sama dengan `API_CLIENT_SECRET` di aplikasi login Flask.

Untuk Google OAuth, aplikasi ini akan redirect ke `AUTH_SSO_URL`, lalu menerima callback di `/auth/callback`. Pastikan `APP_URL` sesuai domain aplikasi ini dan host-nya masuk ke `SSO_ALLOWED_RETURN_HOSTS` pada aplikasi Flask.

## Deploy Production Dengan Deployer

1. Ubah `repository` di `deploy.php` jika nama repository berbeda.
2. Pastikan server memiliki PHP 8.1+, Composer, ekstensi `pdo_pgsql`, PostgreSQL, dan akses SSH.
3. Jalankan deploy dengan environment variable agar hostname dan user server tidak perlu disimpan di repository:

```bash
DEPLOY_HOST=your-server-ip-or-domain \
DEPLOY_USER=deploy \
DEPLOY_PATH=/var/www/daily-task \
DEPLOY_DB_DSN='pgsql:host=127.0.0.1;port=5432;dbname=daily_task' \
DEPLOY_DB_USER=daily_task \
DEPLOY_DB_PASS=change-this-password \
vendor/bin/dep deploy production
```

Document root web server arahkan ke folder `current/public` di deploy path, contoh `/var/www/daily-task/current/public`.

Jika Nginx/PHP-FPM dan PostgreSQL berjalan di Docker network yang sama, gunakan hostname service PostgreSQL pada `DEPLOY_DB_DSN`.

Deploy:

```bash
DEPLOY_HOST=your-server-ip-or-domain \
DEPLOY_USER=deploy \
DEPLOY_PATH=/root/compose/nginx/data/daily-task \
DEPLOY_DB_DSN='pgsql:host=postgres;port=5432;dbname=daily_task' \
DEPLOY_DB_USER=daily_task \
DEPLOY_DB_PASS=change-this-password \
vendor/bin/dep deploy production
```

Jika `.env` sudah pernah dibuat, Deployer tidak menimpa otomatis. Edit manual:

```env
DB_DSN=pgsql:host=postgres;port=5432;dbname=daily_task
DB_USER=daily_task
DB_PASS=change-this-password
```

## Upgrade Ke v2.0.0

Versi `v2.0.0` mengganti database dari SQLite ke PostgreSQL. Ini breaking change: `DB_PATH` tidak dipakai lagi dan data SQLite lama tidak otomatis dimigrasikan. Untuk instalasi baru, mulai dengan database PostgreSQL kosong.

## Google Sheet Sync

Fitur Google Sheet memakai Apps Script Web App. Di aplikasi, buka menu `Sync Google Sheet`, isi:

- Apps Script Webhook URL
- Spreadsheet ID
- Sheet Name
- Sync Secret

Mode sync adalah `replace_date`: data pada tanggal yang dipilih akan diganti ulang agar tidak duplikat.

Jika muncul `HTTP 401`, cek hal ini terlebih dahulu:

- Gunakan URL deployment Web App yang berakhiran `/exec`, bukan `/dev`.
- Pada Apps Script, buka `Deploy` -> `Manage deployments` -> edit deployment -> buat `New version` setelah mengubah `SYNC_SECRET`.
- Pastikan `Who has access` diset ke `Anyone`. Request dari server PHP tidak membawa login Google Anda.
- Pastikan nilai `SYNC_SECRET` di Apps Script sama persis dengan kolom `Sync Secret` di aplikasi, termasuk huruf besar/kecil, `/`, `+`, dan `=`.

Contoh Apps Script:

```javascript
const SYNC_SECRET = 'ganti-dengan-secret-yang-sama';

function doPost(e) {
  const payload = JSON.parse(e.postData.contents || '{}');

  if (payload.secret !== SYNC_SECRET) {
    return json({ ok: false, message: 'Unauthorized' });
  }

  const spreadsheet = SpreadsheetApp.openById(payload.spreadsheet_id);
  const sheet = spreadsheet.getSheetByName(payload.sheet_name) || spreadsheet.insertSheet(payload.sheet_name);
  const header = ['Tgl', 'Pekerjaan', 'Kendala', 'Keterangan', 'Done', 'On Progress'];

  if (sheet.getLastRow() === 0) {
    sheet.appendRow(header);
  }

  const lastRow = sheet.getLastRow();
  if (lastRow > 1 && payload.mode === 'replace_date') {
    const values = sheet.getRange(2, 1, lastRow - 1, 6).getValues();
    for (let i = values.length - 1; i >= 0; i--) {
      const rowDate = Utilities.formatDate(new Date(values[i][0]), Session.getScriptTimeZone(), 'yyyy-MM-dd');
      if (rowDate === payload.date) {
        sheet.deleteRow(i + 2);
      }
    }
  }

  const rows = (payload.rows || []).map((row) => [
    new Date(row.date),
    row.task,
    row.obstacle || '-',
    row.note || '-',
    row.done === true,
    row.on_progress === true,
  ]);

  if (rows.length > 0) {
    const startRow = sheet.getLastRow() + 1;
    sheet.getRange(startRow, 1, rows.length, 6).setValues(rows);
    sheet.getRange(startRow, 5, rows.length, 2).insertCheckboxes();
  }

  if (sheet.getLastRow() > 1) {
    sheet.getRange(2, 5, sheet.getLastRow() - 1, 2).insertCheckboxes();
    sheet.getRange(2, 1, sheet.getLastRow() - 1, 6).sort({ column: 1, ascending: false });
  }

  return json({ ok: true, message: `${rows.length} baris berhasil disync.` });
}

function json(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}

function testDoPost() {
  return doPost({
    postData: {
      contents: JSON.stringify({
        secret: SYNC_SECRET,
        spreadsheet_id: 'ISI_SPREADSHEET_ID_UNTUK_TEST',
        sheet_name: 'Daily Report',
        mode: 'replace_date',
        date: '2026-07-10',
        rows: [
          {
            date: '2026-07-10',
            task: 'Test dari Apps Script',
            obstacle: '-',
            note: 'Baris test manual',
            done: false,
            on_progress: true,
          },
        ],
      }),
    },
  });
}
```

Deploy Apps Script sebagai Web App dengan akses yang sesuai, lalu gunakan URL `/exec` sebagai Webhook URL.

Catatan: `doPost(e)` tidak bisa dijalankan langsung dari editor Apps Script karena object `e.postData` hanya tersedia saat dipanggil lewat HTTP POST. Untuk test dari editor, jalankan fungsi `testDoPost()` setelah mengisi `spreadsheet_id` test.

Kolom `Done` dan `On Progress` memakai checkbox Google Sheet. Apps Script tetap menerima boolean `true/false` dari aplikasi, lalu menerapkan `insertCheckboxes()` pada kolom E dan F agar tampil sebagai ceklis, bukan teks `TRUE/FALSE`.
