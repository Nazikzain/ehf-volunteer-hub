# Project Completion Report: development build

**Status: the application is built and tested in development. It is NOT production-ready yet.** Production readiness needs the host verification, SMTP test, migration inputs and EHF authorisation listed under "Open items" below.

## What was delivered

| Area | Delivered |
|---|---|
| Application | PHP 8.2+/MariaDB platform implementing the Revision 3 design: 34 tables, 3 migrations, 4 triggers, 68 foreign keys (9 composite) |
| Portals | Public registration (all existing form fields preserved, plus the day-selection fix); admin, supervisor and volunteer portals with role-specific navigation and dashboards |
| Modules | Users and roles (with Primary Admin protection); applications; volunteers (skills, availability, status history, notes, erasure); projects; supervisors; service periods; activities; rosters; attendance; tasks; hours with approval, void and resubmit; reviews; training and compliance; certificates; announcements; notifications; email outbox; settings; reports and CSV exports; audit log and verification; integrity report |
| Security | One authorisation pipeline; code-defined permissions; project scoping; CSRF; secure sessions with revocation; rate limiting; CSP and security headers; HMAC-chained audit log with fallback, anchors and verification |
| Operations | Installer, environment probe, scheduler, backup with manifest, post-restore procedure, Supabase migration tool, synthetic demo seeder |
| Tests | 106 automated tests (including all 40 Revision 3 security cases), 220 browser UI checks at three screen sizes, performance test. Report: `docs/TEST_REPORT.md` (generated from executed runs) |
| Documentation | Architecture, decision log, security, deployment, backup and recovery, migration, three user guides, tracker |

## Defects found by testing and fixed

1. Search pages crashed (repeated SQL placeholders under native prepared statements).
2. Volunteers could have seen the volunteer list on a project page (scope defect).
3. Responses differed from the matrix for ST-04/05/06/15/22/31.
4. Pages overflowed horizontally on mobile.
5. Session race on the development web server.
6. Test runs wrote anchor files into the real exports folder.
7. Irreversible retention purges would have run without EHF authorisation; they are now report-only by default.

## Open items before production

| Item | Detail |
|---|---|
| B-1 | How to push without triggering public Cloudflare preview deployments |
| B-3 | Run `bin/check-environment.php` on the Hostinger plan (and InfinityFree); configure HTTPS and cron |
| B-4 | SMTP delivery test (the SMTP client is untested) |
| B-5, B-6 | Supabase classification result and Edge Function source; then the test migration |
| B-7 | Explicit EHF authorisation for production installation and cutover |
| B-8 | D-10 training list; D-25 retention sign-off by the Data Protection Lead; privacy notice URL |
| Limitations | Certificates are printable HTML (no server-side PDF); exports are CSV (Excel-compatible), not .xlsx; Arabic/RTL not yet translated |

No legal compliance claim is made.
