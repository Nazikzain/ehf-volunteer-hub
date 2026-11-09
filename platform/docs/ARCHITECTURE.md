# Technical Architecture

**Authoritative design:** `EHF_Database_Design_Review_Rev3.md`. This document describes how the code implements it.

## Layout

```
platform/
  public/            web root: index.php (front controller), .htaccess, assets/
  src/
    bootstrap.php    autoload, .env, UTC, error handling
    Core/            Env, Database (PDO), Router, Kernel, Request/Response, Session, Csrf, View, Migrator, Installer, SecurityHeaders
    Security/        Auth (current user + scope), Gate (permissions), RateLimiter, Tokens, PasswordPolicy
    Audit/           Canonical (v1), AuditLogger, FallbackLog, AuditVerifier, AuditAnchor, Keys
    Services/        business rules per module (all writes are transactional and audited)
    Controllers/     thin HTTP adapters
  views/             PHP templates (every value escaped with e())
  config/            routes.php, permissions.php
  database/migrations/  001_schema.sql, 002_triggers.sql, 003_reference_data.sql
  bin/               install, migrate, check-environment, scheduler, backup, post-restore, migrate-supabase, seed-demo
  storage/           logs, audit-fallback, exports, backups (never web-reachable)
  tests/             harness, unit + integration suites, UI checks, performance test, report generator
```

## Request pipeline (Design §8.1)

`public/index.php` → `Kernel::handle()`:

1. **Session.** Start a secure session and set the audit request id and IP.
2. **Authenticate.** Load the current user; this checks status, `session_version` and timeouts.
3. **Route match.** Unknown paths → 404.
4. **CSRF.** Required on every POST; failures are audited.
5. **Authentication required.** Not signed in → HTML redirects to login, AJAX gets 401.
6. **Permission.** `Gate::authorize(route perm)` → 403 if missing.
7. **Controller → Service.** Services enforce **scope**: admin all, supervisor active supervised projects, volunteer own record. Out of scope → 404.
8. **Error handling.** `AccessDenied` is logged as `access.denied` (after any rollback) and rendered as 403/404. Other exceptions are logged to `storage/logs/app.log`, and the user sees a generic 500 page.

## Data integrity

| Layer | What it covers |
|---|---|
| **Database** | 68 foreign keys (9 composite project-boundary keys), 32 CHECK constraints, UNIQUE keys including generated-column keys, 4 triggers |
| **Service layer** | Date rules IR-01 to IR-16 (Design §4.1) |
| **Detection** | A read-only integrity report (`/admin/integrity`, daily via the scheduler) lists violations of service-layer rules |

## Audit (Design §6)

- **Success path.** The audit row is written in the same transaction as the change, with the chain lock taken last.
- **Denials and failures.** Written after rollback, in their own transaction.
- **If the database write fails.** The event goes to an HMAC-protected JSONL fallback file, which is reconciled idempotently.
- **Verification.** Recomputes the whole chain and compares it with the latest anchor.
- **Keys.** Kept in `.env` only.

## Scheduling

`bin/scheduler.php`, run by cron where available:
- processes the email outbox;
- once a day: anchor, retention, integrity report and audit verification.

Without cron, the daily anchor and outbox processing run on the first admin dashboard load of the day.
