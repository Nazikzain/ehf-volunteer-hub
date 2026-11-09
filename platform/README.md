# EHF Volunteer Hub (PHP/MariaDB platform)

A volunteer management platform for Ethnic Health Forum, built to the Revision 3 design (`EHF_Database_Design_Review_Rev3.md`, 34 tables).

| | |
|---|---|
| Stack | PHP 8.2+ (no framework, no runtime dependencies), MySQL ≥ 8.0.16 or MariaDB ≥ 10.6 |
| Status | Development build. **Not deployed.** See `EHF_Project_Execution_Tracker.md` |
| Tests | `php tests/run.php` (unit, schema, auth, audit, workflow, operations, 40-case security matrix, backup, migration) · `node tests/ui/ui-test.js <url> <demo-password>` (browser checks) · `php tests/perf.php` |

## Quick start (development)

```
cp .env.example .env              # fill in DB, APP_KEY, AUDIT_KEY_1 (see comments)
php bin/check-environment.php     # verifies every required database feature for real
php bin/install.php primary.admin@example.test
php bin/seed-demo.php <password>  # optional synthetic demonstration data
php -S 127.0.0.1:8080 -t public public/index.php
```

## Documentation

| Topic | File |
|---|---|
| Architecture | `docs/ARCHITECTURE.md` |
| Technical decisions | `docs/DECISION_LOG.md` |
| Security | `docs/SECURITY.md` |
| Deployment (XAMPP, InfinityFree, Hostinger) | `docs/DEPLOYMENT.md` |
| Backup and recovery | `docs/BACKUP_RECOVERY.md` |
| Supabase migration | `docs/MIGRATION.md` |
| Test report (generated) | `docs/TEST_REPORT.md` |
| Admin guide | `docs/ADMIN_GUIDE.md` |
| Supervisor guide | `docs/SUPERVISOR_GUIDE.md` |
| Volunteer guide | `docs/VOLUNTEER_GUIDE.md` |
