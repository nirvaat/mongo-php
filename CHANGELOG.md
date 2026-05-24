# Changelog

All notable changes to mongo-php are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Export / Restore MongoDB archive (server-to-server migration):** export any database to a portable `.mongo.json` archive and restore it into another MongoDB. (`export.php?format=mongo`, `lib/MongoExport.php`; `views/restore.php`, `lib/MongoImport.php`, `import_mongo` action)
  - The archive is line-delimited Extended JSON v2: a `meta` header line, then per collection a `collection` line (creation options + index specs) followed by one `doc` line per document.
  - All BSON types (`ObjectId`, `UTCDateTime`, `Decimal128`, `Binary`, `Regex`, `Timestamp`, …) round-trip losslessly; documents keep their original `_id`; non-`_id` indexes and collection options are recreated on restore.
  - Both sides stream — export walks a server cursor and writes incrementally, restore reads line by line and inserts in batches — so a large database doesn't exhaust memory. Restoring into an empty database is recommended (it appends and may collide on `_id` otherwise).
  - Returns a per-collection report (documents restored, indexes created, warnings, source DB). Designed as a `mongodump`/`mongorestore`-free way to move a database between servers.
- **Export to SQL (MySQL):** stream any MongoDB database as a `mysqldump`-compatible `.sql` file. Each collection becomes a table, the union of observed BSON types per field drives the column types, nested objects/arrays land in `JSON` columns, and MongoDB indexes are emitted as `CREATE INDEX` statements. (`export.php`, `lib/Export.php`)
- **Import MySQL dump → MongoDB:** upload a `mysqldump`-style `.sql` file from the Database view and reproduce the schema in MongoDB. (`views/import.php`, `lib/SqlImport.php`, `import_sql` action)
  - Each `CREATE TABLE` becomes a collection; each `INSERT` row becomes a document.
  - Column types map to BSON: `INT`→int, `DECIMAL`→Decimal128, `FLOAT`/`DOUBLE`→double, `DATE`/`DATETIME`/`TIMESTAMP`→UTCDateTime (UTC), `JSON`→nested object/array, `BLOB`/`BINARY`→Binary, `TINYINT(1)`→bool; bigint values outside PHP's 64-bit range are preserved as strings.
  - A single-column `PRIMARY KEY` is folded into `_id`; composite/missing PKs get a generated ObjectId plus a unique index.
  - `UNIQUE` / `KEY` / `INDEX` definitions are recreated as MongoDB indexes. MongoDB has no joins, so `FOREIGN KEY`s are not enforced — each foreign-key column is indexed instead so application-side `$lookup`s stay fast.
  - Returns a per-collection report (rows imported, `_id` source, indexes, FK indexes, warnings, and skipped statement types). Uploads are processed from PHP's temp dir — no writable project folder required.

## [0.1.0] - 2026-05-22

### Added

- Login screen with host / port / user / pass / auth DB / replica set / TLS options.
- Server view: list databases with size on disk, document counts; create / drop database.
- Database view: list collections with stats; create (incl. capped), drop, truncate, rename.
- Browse: paginated tabular view with filter / sort / projection / limit / skip from the URL.
- Edit: full-document JSON editor; `_id` preserved on save.
- Insert: Extended JSON v2 editor with type hints.
- Structure: sampled field-and-type inference; rename / move form.
- Query: Find / Aggregate / Run command sub-modes with pretty-printed EJSON output.
- Indexes: list, create (unique / sparse / compound / text / 2dsphere / hashed), drop; `_id_` protected.
- Operations: updateMany, deleteMany, rename, truncate, drop — all with confirmations.
- Stats: collStats summary and raw output.
- Status: serverStatus summary plus full raw output.
- Security: per-session CSRF tokens, escaped output, `nosniff` / `X-Frame-Options: DENY` / CSP headers, `HttpOnly` session cookies, auto-`Secure` over HTTPS, typed-name confirmations for destructive ops.

[Unreleased]: https://github.com/nirvaat/mongo-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/nirvaat/mongo-php/releases/tag/v0.1.0
