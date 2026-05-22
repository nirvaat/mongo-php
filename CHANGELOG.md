# Changelog

All notable changes to mongo-php are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
