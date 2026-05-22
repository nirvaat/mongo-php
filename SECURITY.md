# Security Policy

## Supported versions

mongo-php is a small project; only the **`main` branch** receives security fixes. If you're running an older snapshot, please update before reporting.

## Reporting a vulnerability

**Please do not open a public GitHub issue for security problems.**

Instead, email a private report to:

> security@example.com  *(replace with your real address before publishing)*

Or, on GitHub, use the **[Security → Report a vulnerability](https://github.com/nirvaat/mongo-php/security/advisories/new)** flow (private advisory).

Include in your report:

- A description of the issue and the impact
- Steps to reproduce (or a proof-of-concept)
- Affected file(s), line numbers if possible
- Your suggested fix (optional, but appreciated)
- Whether you'd like to be credited in the advisory

You should expect:

- **An acknowledgement within 72 hours.**
- A coordinated disclosure timeline (typically 30–90 days depending on severity).
- Credit in the eventual public advisory (unless you'd rather stay anonymous).

## Threat model

mongo-php is an **admin tool**. It is *designed* to give the authenticated user full read/write/admin access to the connected MongoDB server. That means:

- **In-scope** vulnerabilities (please report):
  - CSRF bypass on state-changing actions
  - Stored or reflected XSS
  - Session fixation / hijacking
  - Authentication bypass (acting without a valid session)
  - Credentials leaking into logs, error pages, query strings, or the filesystem
  - Command injection via JSON parsing or any user input
  - Path traversal / arbitrary file read or write
  - Bypassing the typed-name confirmation on destructive actions
  - Mongo-driver misuse that leads to unintended writes/reads

- **Out of scope** (these are *intentional*, not bugs):
  - An authenticated user can read, modify, or destroy any data their MongoDB user has permissions for. That is the entire purpose of the tool.
  - Lack of rate limiting / brute-force protection on the login form (deploy behind a reverse proxy that handles this).
  - The tool being dangerous when exposed publicly without basic auth or IP allow-listing (this is documented prominently and is the operator's responsibility).

## Hardening checklist for operators

If you're running mongo-php on a public server, please follow [README — Production hardening](README.md#production-hardening) **before** the first connection. The most important steps:

1. HTTPS only.
2. HTTP basic auth or IP allow-list in front of `/mongo-php/`.
3. A least-privilege MongoDB user — not `root` or `__system`.
4. Keep the `mongodb` PECL extension up to date.
