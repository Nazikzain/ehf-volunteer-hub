# Deployment Guide: local (XAMPP), staging (InfinityFree), production (Hostinger)

**Nothing has been deployed.** Production deployment and cutover require explicit EHF authorisation.

## 1. Requirements (verify with `php bin/check-environment.php` on the exact host)

| Requirement | Mandatory |
|---|---|
| PHP ≥ 8.2 with pdo_mysql, mbstring, openssl, json, hash, session | Yes |
| MySQL ≥ 8.0.16 **or** MariaDB ≥ 10.6, InnoDB | Yes |
| Composite FKs, CHECK, JSON_VALID, DATETIME(6), transactions/row locks (the probe tests each one for real) | Yes |
| HTTPS | Yes (production) |
| Generated-column UNIQUE indexes (fallback documented in Design §3.3) | Strongly expected |
| Triggers (audit protection layer 2) | Preferred; residual risk U-1 if absent |
| Cron | Preferred; first-admin-request fallback exists |
| SMTP | Preferred; without it, activation links are shown to the inviting admin via the outbox and anchors via manifest files |

**If a production plan fails any mandatory row, do not install. Choose another plan.**

## 2. Local development (Windows + XAMPP)

1. **Check the database version first.** In phpMyAdmin, run `SELECT VERSION();`. Recent XAMPP builds are believed to ship MariaDB 10.4, which is **below the floor**. If so, install MariaDB 10.11 LTS (or MySQL 8.4) and point XAMPP's PHP at it. Do not lower the floor.
2. Copy `platform/` to e.g. `C:\xampp\ehf`, and point an Apache vhost's DocumentRoot at `C:\xampp\ehf\public`.
3. Create a database and user. Copy `.env.example` to `.env` and fill it in:
   - generate `APP_KEY` with `php -r "echo bin2hex(random_bytes(32));"`;
   - generate `AUDIT_KEY_1` with `php -r "echo base64_encode(random_bytes(32));"`;
   - set `SESSION_SECURE=false` for local http only, and keep `MAIL_TRANSPORT=log`.
4. Run the setup commands:
   ```
   php bin/check-environment.php
   php bin/install.php primary.admin@example.test
   php bin/seed-demo.php <demo-password>
   ```
   The last command is optional and loads synthetic data only.
5. Run the tests: `php tests/run.php`, which uses a separate `ehf_test` database (grant the DB user rights on it).

## 3. Staging (InfinityFree): synthetic data only

- **Never** use real volunteer data or production credentials here.
- InfinityFree only allows files inside `htdocs`:
  1. Upload the whole `platform/` folder into `htdocs/ehf-app/`.
  2. Copy `deny.htaccess` to `htdocs/ehf-app/.htaccess`.
  3. Put the contents of `public/` into `htdocs/`, and edit `htdocs/index.php` to require `ehf-app/src/bootstrap.php`.
  4. **Test ST-35:** a browser request to `/ehf-app/.env` and to `/ehf-app/storage/` must return 403 or 404.
- No SSH: run the setup through phpMyAdmin:
  1. Import `001`, `002` and `003` in order.
  2. If importing `002_triggers.sql` fails, record that triggers are unavailable (residual risk U-1).
  3. Create the owner with `bin/install.php` locally against an exported copy, or with the documented SQL in §6.
- Set `MAIL_TRANSPORT=disabled`; activation links are read from the outbox.
- Record every limitation in the tracker. A staging failure does not block production if XAMPP and Hostinger pass (Design §9.2).

## 4. Production (Hostinger)

1. **hPanel setup.**
   - Select PHP 8.2 or later.
   - Create a MySQL database and user, and note the MySQL/MariaDB version.
   - Enable SSL and force HTTPS.
2. **Upload.** Upload `platform/` to `/home/<user>/ehf/`, **above** `public_html`. Copy the contents of `public/` into `public_html/` and point `public_html/index.php` at `../ehf/src/bootstrap.php`; alternatively, set the domain's document root to `ehf/public` if the plan allows it.
3. **Configure `/home/<user>/ehf/.env`** (permissions 600):
   - `APP_ENV=production`, `SESSION_SECURE=true`;
   - real `APP_URL`;
   - new `APP_KEY` and `AUDIT_KEY_1` (**the Primary Admin escrows a copy of the audit key offline**, D-27);
   - SMTP settings with `MAIL_TRANSPORT=smtp`.
4. **Probe.** Over SSH: `php bin/check-environment.php`. Every mandatory row must PASS.
5. **Install.** `php bin/install.php rauf@ethnichealth.org.uk`. It prints the one-time activation link, valid 48 hours; give it to the Primary Admin securely. **Only with EHF authorisation.**
6. **Cron** (hPanel → Cron Jobs), every 15 minutes: `php /home/<user>/ehf/bin/scheduler.php`.
7. **Staff accounts.** The Primary Admin activates their account, then invites `nazikzein@gmail.com` as Organisation Admin.
8. **Smoke tests** (Section 5), then backups (`docs/BACKUP_RECOVERY.md`).

## 5. Smoke test checklist (production or staging)

- [ ] `/apply` loads over HTTPS; submit one clearly labelled test application, then reject it.
- [ ] Sign in and out as the Primary Admin; `/admin/audit` → **Verify** gives GREEN.
- [ ] `/admin/integrity` shows 0 issues.
- [ ] `/.env`, `/storage/logs/app.log` and `/src/bootstrap.php` return 403 or 404.
- [ ] Email outbox: the test invitation is sent, and its body is erased after sending.
- [ ] Response headers include CSP, X-Frame-Options and HSTS.

## 6. Rollback

- **Before go-live:** keep using the live Supabase system; nothing changes for users.
- **After go-live:** restore the pre-import backup (`docs/BACKUP_RECOVERY.md`), run `bin/post-restore.php`, and point users back to the Cloudflare site, which stays deployed until EHF formally accepts the new system (Architecture Rev3 §7 step 12).
