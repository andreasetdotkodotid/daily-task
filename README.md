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

1. Ubah `repository`, `hostname`, `remoteUser`, dan `deployPath` di `deploy.php`.
2. Pastikan server memiliki PHP 8.1+, Composer, ekstensi `pdo_sqlite`, dan akses SSH.
3. Jalankan deploy:

```bash
vendor/bin/dep deploy production
```

Document root web server arahkan ke folder `current/public` di deploy path, contoh `/var/www/daily-task/current/public`.
