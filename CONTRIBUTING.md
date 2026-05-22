# Contributing to mongo-php

Thanks for your interest in improving mongo-php. This document covers how to set up a dev environment, the coding conventions, and the PR workflow.

## Quick start

```bash
git clone https://github.com/nirvaat/mongo-php.git
cd mongo-php

# Verify the mongodb PECL extension is loaded
php -r "echo extension_loaded('mongodb') ? 'OK '.phpversion('mongodb') : 'MISSING', PHP_EOL;"

# Spin up a local server
php -S 127.0.0.1:8080 -t .
open http://127.0.0.1:8080/login.php
```

You'll need a MongoDB to point at. The easiest options:

```bash
# Docker
docker run --rm -d -p 27017:27017 --name mongo-dev mongo:7

# Or Homebrew on macOS
brew services start mongodb-community
```

Then log in at `http://127.0.0.1:8080/login.php` with host `127.0.0.1`, port `27017`, no user/password.

## Reporting bugs / requesting features

Use the issue templates in `.github/ISSUE_TEMPLATE/`. For bugs, please include:

- MongoDB version (`{"buildInfo": 1}` from Query → Run command, or `mongosh --eval "db.version()"`)
- PHP version (`php -v`)
- `mongodb` extension version (`php -r "echo phpversion('mongodb');"`)
- Browser (rare, but some UI bugs are browser-specific)
- Exact steps to reproduce
- Expected vs actual behaviour
- A copy of the server-side error message if any (check your web-server error log)

## Coding conventions

- **PHP 8.0+**, `declare(strict_types=1);` at the top of every file in `lib/`.
- Indent with **4 spaces**. No tabs.
- One class per file in `lib/`. Views in `views/` are plain PHP templates; they may use shorter forms.
- Always escape output with `h()` (HTML) — never echo raw user input.
- Every state-changing action lives in `action.php` and **must** call `csrf_check()`.
- Destructive actions (drop, truncate, deleteMany) **must** require either typed-name confirmation or a typed `DELETE` string.
- New DB-touching methods go on the `Mongo` class in `lib/Mongo.php`. Don't sprinkle `MongoDB\Driver\*` usage directly across views or actions.
- Don't add Composer / vendor dependencies without a strong reason. The drop-in / zero-dependency property is a feature.

## Adding a new feature

A typical change touches three layers:

1. **`lib/Mongo.php`** — add a method that talks to MongoDB.
2. **`views/yourpage.php`** — render the form / table / output. Register the page name in `index.php`'s `$validPages` list.
3. **`action.php`** — add a new `case` for the action that mutates state. Always validate input and call `csrf_check()` at the top (it's already called once globally).

If the new feature has a destructive side, follow the existing pattern:

```php
$confirm = $_POST['confirm'] ?? '';
if ($confirm !== $expectedToken) {
    throw new InvalidArgumentException("Confirmation does not match '{$expectedToken}'.");
}
```

…and use `class="confirm-form"` plus a hidden `confirm` input with `data-confirm-prompt` on the front end.

## Lint and test

Run the syntax linter before pushing:

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

GitHub Actions runs this on every push and PR via `.github/workflows/lint.yml`.

There is no unit test suite (yet). If you'd like to add one (e.g. PHPUnit + a Dockerised MongoDB), that contribution is welcome — start with an issue describing the approach.

## Commit / PR style

- Keep commits focused. "Fix typo + add feature + rename file" in one commit is hard to review.
- Use imperative subject lines: "Add capped-collection support", not "Added" or "Adds".
- For PRs, include:
  - **What** changed and **why**
  - Screenshots for any UI change
  - Reproduction steps for any bug being fixed

## Code of conduct

Be respectful, be patient, assume good faith. We follow the [Contributor Covenant](https://www.contributor-covenant.org/version/2/1/code_of_conduct/) v2.1 in spirit; harassment of any kind isn't tolerated.
