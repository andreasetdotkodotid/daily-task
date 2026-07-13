# Daily Task

Aplikasi daily task sederhana menggunakan PHP, Composer, SQLite, HTML, CSS, dan JavaScript.

## Fitur

- Tambah task dengan catatan, prioritas, dan tanggal target.
- Tandai task selesai atau belum selesai.
- Hapus task.
- Filter task berdasarkan semua, belum selesai, dan selesai.
- Penyimpanan lokal memakai SQLite.

## Menjalankan Lokal

```bash
composer install
composer serve
```

Buka `http://127.0.0.1:8000`.

## Environment

Salin `.env.example` menjadi `.env` bila ingin mengatur konfigurasi production.

```env
APP_ENV=production
APP_URL=https://daily-task.example.com
DB_PATH=storage/tasks.sqlite
AUTH_API_URL=https://login.dotko.id/api/login
AUTH_SSO_URL=https://login.dotko.id/sso/google
AUTH_API_KEY=change-this-api-client-secret
```

`AUTH_API_KEY` harus sama dengan `API_CLIENT_SECRET` di aplikasi login Flask.

Untuk Google OAuth, aplikasi ini akan redirect ke `AUTH_SSO_URL`, lalu menerima callback di `/auth/callback`. Pastikan `APP_URL` sesuai domain aplikasi ini dan host-nya masuk ke `SSO_ALLOWED_RETURN_HOSTS` pada aplikasi Flask.

## Deploy Production Dengan Deployer

1. Ubah `repository` di `deploy.php` jika nama repository berbeda.
2. Pastikan server memiliki PHP 8.1+, Composer, ekstensi `pdo_sqlite`, dan akses SSH.
3. Jalankan deploy dengan environment variable agar hostname dan user server tidak perlu disimpan di repository:

```bash
DEPLOY_HOST=your-server-ip-or-domain DEPLOY_USER=deploy DEPLOY_PATH=/var/www/daily-task vendor/bin/dep deploy production
```

Document root web server arahkan ke folder `current/public` di deploy path, contoh `/var/www/daily-task/current/public`.

## Google Sheet Sync

Fitur Google Sheet memakai Apps Script Web App. Di aplikasi, buka menu `Sync Google Sheet`, isi:

- Apps Script Webhook URL
- Spreadsheet ID
- Sheet Name
- Sync Secret

Mode sync adalah `replace_date`: data pada tanggal yang dipilih akan diganti ulang agar tidak duplikat.

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
    sheet.getRange(sheet.getLastRow() + 1, 1, rows.length, 6).setValues(rows);
  }

  if (sheet.getLastRow() > 1) {
    sheet.getRange(2, 1, sheet.getLastRow() - 1, 6).sort({ column: 1, ascending: false });
  }

  return json({ ok: true, message: `${rows.length} baris berhasil disync.` });
}

function json(data) {
  return ContentService
    .createTextOutput(JSON.stringify(data))
    .setMimeType(ContentService.MimeType.JSON);
}
```

Deploy Apps Script sebagai Web App dengan akses yang sesuai, lalu gunakan URL `/exec` sebagai Webhook URL.
