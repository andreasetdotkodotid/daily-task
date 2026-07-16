# Changelog

## [2.0.0] - 2026-07-16

- Changed database backend from SQLite to PostgreSQL.
- Added PostgreSQL Docker Compose example with bind mount storage.
- Updated deployment environment variables from `DB_PATH` to `DB_DSN`, `DB_USER`, and `DB_PASS`.
- Updated login copy to use more professional wording.
- Breaking: existing SQLite data is not read by this version. Start with an empty PostgreSQL database or migrate data manually.
