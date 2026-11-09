# Backup and Recovery

## Backups

| Method | When | Command / steps |
|---|---|---|
| Application backup (SSH) | Daily (cron) and before every upgrade | `php bin/backup.php /home/<user>/backups`. It verifies the audit chain first (it refuses if the result is RED), dumps the database with triggers, and writes `<file>.manifest.json` (SHA-256, audit head, schema version). |
| Hosting backup | Per the Hostinger plan | hPanel → Backups. With each hosting backup, also download the latest `storage/exports/audit-anchor-*.json` (it records the audit state at that time). |
| Files | Weekly | Download `storage/exports/` (anchors, erasure register) and `storage/audit-fallback/`. |

**Store copies off the server.** Backups contain personal data. Keep them for the backup retention period (D-09; proposed 30 days) and then destroy them.

**Audit keys are never in backups.** The Primary Admin keeps an offline escrow copy (Design §6.5).

## Restore

1. Put the site into maintenance: temporarily rename `public_html/index.php`.
2. Restore the database from the chosen `.sql` file (phpMyAdmin import or `mysql < file.sql`). Check its SHA-256 against the manifest.
3. Run `php bin/post-restore.php`. If the latest daily anchor *email* is newer than the files in `storage/exports`, pass `php bin/post-restore.php <seq> <hash>` instead. The script:
   - verifies the audit chain;
   - compares it with the latest off-database anchor and records `audit.restore`, including the **lost range** of audit entries (they are documented, not hidden);
   - re-applies every volunteer erasure listed in the off-database erasure register (W7);
   - creates a fresh anchor and verifies again. The result must be GREEN.
4. Reopen the site. Tell users that changes made after the backup time must be re-entered.

## Restore test

Run a restore test before go-live and twice a year, on a copy. The automated test `backup → restore → post-restore` runs in `tests/run.php` (suite `backup`).

## Audit archiving (Primary Admin only, rare)

Archiving follows Design §6.11:

1. Export the segment and verify it.
2. Append `audit.archive_checkpoint`.
3. Using the DDL account, drop the triggers in `002_triggers.sql`, delete the segment, and re-run `002_triggers.sql`.
4. Verify again.

Do not archive until EHF has set the audit retention period (D-01).
