# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A zero-dependency, drop-in PHP web admin tool for MongoDB (think phpMyAdmin for MongoDB). No Composer, no Node, no build step. Runs against the official `mongodb` PECL extension directly.

## Commands

```bash
# Verify the mongodb PECL extension is loaded (required)
php -r "echo extension_loaded('mongodb') ? 'OK '.phpversion('mongodb') : 'MISSING', PHP_EOL;"

# Run the app locally
php -S 127.0.0.1:8080 -t .
# then open http://127.0.0.1:8080/login.php

# Lint every PHP file (mirrors CI in .github/workflows/lint.yml)
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

There is no test suite and no build step. CI only runs `php -l` against the matrix `php: 8.0–8.4`.

## Architecture

Request flow is intentionally tiny — two entry points, one wrapper class, plain PHP view templates.

- **`index.php`** — GET router. Boots session, requires login, instantiates `Mongo`, whitelists `$_GET['page']` against `$validPages`, then `include`s `views/<page>.php`. Adding a new page requires adding its slug to `$validPages` here.
- **`action.php`** — POST handler for all state-changing operations. Calls `csrf_check()` globally, then dispatches on `$_POST['action']` in a single `switch`. Every new mutating feature adds a `case` here. On success or error it sets a flash message and redirects to `$_POST['return']` (validated against an allowlist regex).
- **`login.php`** — credential form. On success stores connection params in `$_SESSION['conn']` (never written to disk) and `session_regenerate_id(true)`.
- **`lib/bootstrap.php`** — required by every entry point. Checks the extension is loaded, sets session cookie params + security headers (CSP, `X-Frame-Options: DENY`, `nosniff`), generates the CSRF token, and defines `require_login()`, `csrf_token()`, `csrf_check()`.
- **`lib/Mongo.php`** — the **only** place that talks to `MongoDB\Driver\*`. Wraps Manager/Command/Query/BulkWrite. All MongoDB cursors use a typemap that decodes documents to PHP arrays (`['root' => 'array', 'document' => 'array', 'array' => 'array']`) — views and helpers assume arrays, not stdClass. Don't bypass this class from views/actions.
- **`lib/helpers.php`** — `h()` for HTML escape, `doc_to_extjson()` / `extjson_to_doc()` for BSON ↔ Extended JSON v2 round-trip (prefers `MongoDB\BSON\Document` when available, falls back to the procedural `fromPHP`/`toPHP` API), `render_cell()` for table rendering of BSON values, `parse_id()` for tolerant `_id` parsing (ObjectId hex → JSON → string), `flash_set/pop()`, and `url()`.
- **`lib/layout.php`** — `render_header()` / `render_footer()`. The header re-fetches the DB list and (if a DB is selected) the collection list on every request to build the sidebar, then emits breadcrumb + per-collection tabs.
- **`views/*.php`** — plain PHP templates. They call `render_header(['mongo'=>$mongo, 'db'=>$db, 'coll'=>$coll, 'page'=>...])`, do their own queries through `$mongo`, and end with `render_footer()`. They may use short PHP forms; only `lib/` files require `declare(strict_types=1);`.

### Conventions that are load-bearing

- **CSRF**: every POST form must include `<input type="hidden" name="_csrf" value="<?=h(csrf_token())?>">`. `action.php` rejects requests without a valid token. `login.php` also calls `csrf_check()`.
- **Output escaping**: always `h()`. Never echo raw user input or raw values out of MongoDB.
- **Extended JSON v2** is the on-the-wire format for all document editor input/output (so users can type `{"$oid": "..."}`, `{"$date": "..."}`, etc.). Never `json_encode` BSON values directly.
- **Destructive actions** require typed confirmation that already follows one of two patterns: typed name (`drop_db`, `truncate_collection`, must match `$db` / `$coll`) or the literal string `DELETE` (`delete_many`). Use `class="confirm-form"` + a hidden `confirm` input with `data-confirm-prompt` on the front end and validate server-side in `action.php`.
- **`_id` preservation**: `update_document` deliberately strips `_id` from the user-supplied replacement and merges the original back in, so editing the JSON cannot accidentally re-key a document.
- **No new dependencies.** The "drop-in, zero-dependency" property is a feature — no Composer, no `vendor/`. Anything that would require one needs explicit user buy-in.
- **PHP version floor is 8.0** (CI matrix runs through 8.4). Don't use 8.1+ syntax (readonly, enums, `never`, first-class callable syntax) without checking.

## Personal rule reminder

Per `~/CLAUDE.md`: this is a DB tool that connects to MongoDB. **Do not run any command that would mutate a real MongoDB without asking first** — that includes spinning up the dev server and pointing it at anything other than a throwaway local instance, running `tinker`-equivalent operations through it, or invoking any `action.php` endpoint against a real DB. Read-only inspection (`buildInfo`, `listDatabases`, `find`) is fine.
