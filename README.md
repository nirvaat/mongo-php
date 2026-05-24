# mongo-php

A lightweight, drop-in **MongoDB admin tool written in PHP** — think *phpMyAdmin, but for MongoDB*.

No Composer, no Node, no build step. Drop the folder into any PHP web root, point your browser at it, log in, and you're managing your MongoDB server through a familiar web UI.

[![PHP lint](https://github.com/nirvaat/mongo-php/actions/workflows/lint.yml/badge.svg)](https://github.com/nirvaat/mongo-php/actions/workflows/lint.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP >= 8.0](https://img.shields.io/badge/PHP-%3E%3D%208.0-777bb4)
![MongoDB >= 4.0](https://img.shields.io/badge/MongoDB-%3E%3D%204.0-13aa52)

---

## Features

- **Server overview** — list databases with size on disk, document counts, and index sizes.
- **Database management** — create, drop, view stats. List collections with per-collection metrics.
- **Collection management** — create (incl. capped), drop, truncate, rename / move between databases.
- **Browse documents** — paginated tabular view with auto-derived columns, ObjectId/Date/Binary rendered inline, filter + sort + projection from the URL.
- **Edit / Delete** — full document JSON editor (Extended JSON v2); `_id` is preserved on save.
- **Insert** — JSON editor with hints for `$oid`, `$date`, `$numberLong`, `$decimal`, etc.
- **Structure** — sampled field/type inference (`$sample` aggregation) — best-effort schema for a schemaless store.
- **Query** — three modes:
  - **Find**: filter / sort / projection / limit / skip
  - **Aggregate**: full pipeline editor
  - **Run command**: any database command (`buildInfo`, `serverStatus`, `collStats`, etc.)
- **Indexes** — list, create (unique, sparse, compound, 2dsphere, text...), drop. `_id_` protected.
- **Bulk operations** — updateMany, deleteMany, rename, truncate, drop — all with explicit confirmations.
- **SQL interop** — **export** any database to a MySQL `.sql` dump (tables, indexes, JSON columns for nested data), and **import** a `mysqldump` `.sql` file into MongoDB: each table becomes a collection, rows become documents, MySQL types map to BSON, single-column `PRIMARY KEY`s fold into `_id`, and `UNIQUE`/`KEY`/`FOREIGN KEY` columns are indexed.
- **Stats** — collection stats and full `serverStatus` output.
- **Security** — session-only credentials, CSRF tokens on every state-changing POST, escaped output, `nosniff` / `X-Frame-Options: DENY` / CSP headers, destructive ops require typing the exact name.

## Screenshots

> Drop screenshots in `docs/screenshots/` and reference them here. Placeholders below.

| Server view | Browse documents | Query / Aggregate |
|---|---|---|
| ![server](docs/screenshots/server.png) | ![browse](docs/screenshots/browse.png) | ![query](docs/screenshots/query.png) |

## Requirements

- **PHP 8.0+** (tested on 8.4)
- **`mongodb` PHP extension** (PECL) — *not* the legacy `mongo` extension
- **MongoDB 4.0+** (works with newer versions, including 7.x / 8.x)
- A web server: Apache, Nginx + PHP-FPM, or the built-in `php -S` for local use

Check your extension:

```bash
php -r "echo extension_loaded('mongodb') ? 'mongodb '.phpversion('mongodb') : 'NOT INSTALLED', PHP_EOL;"
```

Install if missing (one of):

```bash
# macOS (Homebrew PHP)
pecl install mongodb

# Debian / Ubuntu
sudo apt-get install php-mongodb

# RHEL / Rocky / AlmaLinux (Remi)
sudo dnf install php-pecl-mongodb
```

Then enable it in `php.ini` (`extension=mongodb`) and restart your web server.

## Install

### 1. Clone or download

```bash
git clone https://github.com/nirvaat/mongo-php.git
cd mongo-php
```

…or download the latest [release zip](https://github.com/nirvaat/mongo-php/releases) and extract it.

### 2. Drop into your web root

Any of the following work:

- **Apache**: copy the folder into `DocumentRoot` (e.g. `/var/www/html/mongo-php`).
- **Nginx + PHP-FPM**: point a `server { root ...; }` block at the folder.
- **Local / dev**:

  ```bash
  php -S 127.0.0.1:8080 -t .
  open http://127.0.0.1:8080/login.php
  ```

### 3. Make the folder web-readable but protected

The app needs **no writable directories** — sessions live in PHP's default session dir. The folder can be owned by `root` and chmod `0644`.

> **Strongly recommended:** put it behind HTTP basic auth, a VPN, or IP allow-listing. See [Production hardening](#production-hardening) below.

### 4. Log in

Open `https://your-host/mongo-php/login.php` and enter your MongoDB connection details:

| Field | Example | Notes |
|---|---|---|
| Host | `127.0.0.1` | hostname or IP |
| Port | `27017` | |
| Username | `admin` | leave blank for unauthenticated MongoDB |
| Password | `••••••` | |
| Auth DB | `admin` | the DB the user is defined in |
| Replica Set | `rs0` | optional |
| TLS | ☐ | enable for TLS-protected clusters |

Credentials are stored in your PHP session only — never written to disk.

## Usage

See **[USAGE.md](USAGE.md)** for a complete walkthrough of every screen with examples (queries, aggregations, index types, bulk ops, etc.).

## Production hardening

This tool gives full read/write/admin access to your MongoDB server. **Treat it like a root shell over the web.**

Minimum precautions:

1. **Never expose it publicly** without an outer auth layer.
2. **Serve over HTTPS only.** The login page submits credentials in cleartext otherwise.
3. **Add basic auth or IP allow-listing** at the web-server level:

   ```nginx
   location /mongo-php/ {
       allow 10.0.0.0/8;
       deny all;
       auth_basic "mongo-php";
       auth_basic_user_file /etc/nginx/htpasswd;
   }
   ```

4. **Create a least-privilege MongoDB user.** Don't log in as `root`/`__system` for daily admin.
5. **Set `session.cookie_secure = 1`** in `php.ini` (the app already sets it automatically when `$_SERVER['HTTPS']` is on).
6. **Disable PHP `expose_php`** and remove the `X-Powered-By` header from your web server.
7. **Keep the `mongodb` PECL extension up to date** — it tracks MongoDB driver security advisories.

For local development none of this matters, but if it's on a server reachable from the internet, please configure all of the above.

## File layout

```
mongo-php/
├── index.php          # Router (GET pages)
├── action.php         # POST handler (CSRF-protected)
├── export.php         # Streams a MongoDB database as a MySQL .sql dump
├── login.php
├── logout.php
├── lib/
│   ├── bootstrap.php  # session, security headers, CSRF
│   ├── Mongo.php      # MongoDB wrapper (uses ext-mongodb directly)
│   ├── Export.php     # MongoDB → MySQL .sql dump generator
│   ├── SqlImport.php  # MySQL .sql dump → MongoDB importer (parser + type mapping)
│   ├── helpers.php    # html escape, BSON ↔ Extended JSON v2, cell rendering
│   └── layout.php     # header / sidebar / tabs / footer
├── views/
│   ├── home.php
│   ├── database.php
│   ├── browse.php
│   ├── structure.php
│   ├── query.php
│   ├── insert.php
│   ├── edit.php
│   ├── indexes.php
│   ├── import.php
│   ├── collstats.php
│   ├── operations.php
│   └── status.php
├── assets/
│   ├── style.css
│   └── app.js
├── docs/
│   └── screenshots/
└── .github/
    ├── workflows/lint.yml
    ├── ISSUE_TEMPLATE/
    └── PULL_REQUEST_TEMPLATE.md
```

## Contributing

Pull requests welcome! See **[CONTRIBUTING.md](CONTRIBUTING.md)** for setup, style, and PR guidelines.

For bugs and feature requests, please use the [issue tracker](https://github.com/nirvaat/mongo-php/issues).

## Security

If you find a security issue, **please do not open a public issue.** See **[SECURITY.md](SECURITY.md)** for the responsible-disclosure process.

## License

Released under the [MIT License](LICENSE).

## Acknowledgements

- Inspired by [phpMyAdmin](https://www.phpmyadmin.net/) — the UI conventions, layout, and workflow take direct cues from it.
- Built on the official MongoDB [PHP driver (PECL ext-mongodb)](https://www.php.net/manual/en/set.mongodb.php).
