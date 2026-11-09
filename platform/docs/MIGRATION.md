# Supabase Migration Guide

The live Supabase system stays **unchanged and authoritative** until EHF formally accepts the new system (C-6, C-7).

## Status

| Prerequisite | Status |
|---|---|
| Data-classification result (`supabase_data_classification_READ_ONLY.sql`) | **Outstanding. Needed from EHF** |
| Edge Function source (`admin-user-directory`, `volunteer-registration`) | **Outstanding. Needed from EHF** (live-system risk U-4) |
| Public sign-up setting | Verified ON (Phase A). EHF decision D-26 |
| The 6 applications | Test data [C]: never migrated |

## What is migrated

Only records EHF classifies as **genuine** in projects (`volunteer_projects`) and tasks (`volunteer_tasks`).

- Volunteer, profile, review and hours tables are **not** imported. They are linked to the test applications and were most likely created from them; this is to be confirmed by the classification.
- Credentials are never migrated. Admins are recreated by `bin/install.php` (owner) and invitation (Organisation Admin).

## Steps

1. **Backup (EHF).** In the Supabase dashboard, take a full backup and export a CSV of every table. Store them securely, **not** on staging hosting.
2. **Classification file.** Create `classification.json`, signed off by EHF:
   ```json
   {"approved_by": "Name, role", "approved_on": "YYYY-MM-DD",
    "genuine": {"volunteer_projects": ["<uuid>", "..."], "volunteer_tasks": ["<uuid>"]}}
   ```
3. **Dry run** on XAMPP, against a copy: `php bin/migrate-supabase.php <csv-dir> classification.json`. Review the errors (invalid or orphaned records), warnings (duplicates, dropped assignees) and exclusions.
4. **Test migration** on a scratch database: add `--apply`. Check `reconciliation_ok: true`, `/admin/integrity` (0 issues), and an audit verification of GREEN.
5. **Final.** Immediately before cutover, re-export from Supabase and repeat steps 3 and 4 on production **only after EHF authorises the cutover**. The tool is idempotent: records already imported (same `legacy_uuid`) are skipped.
6. **Cutover and rollback.** See `docs/DEPLOYMENT.md` §6.
