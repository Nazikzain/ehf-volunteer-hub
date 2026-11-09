# EHF Project Execution Tracker

| | |
|---|---|
| Last updated | 9 November 2026 |
| Branch | `feature/php-platform` (local commits; **not pushed**, see blocker B-1) |
| Test evidence | `docs/TEST_REPORT.md`, generated from executed runs: **106/106 automated tests, 220/220 browser UI checks** |

**Status values:** Not started · In progress · Blocked · Testing · Completed.
A row is **Completed** only where its implementation and required tests have actually passed.

## Phases and modules

| Phase / module | Status | Main files | Tests executed (result) | Outstanding defects | Dependencies | Next action |
|---|---|---|---|---|---|---|
| **A** Final technical baseline | Completed | `docs/DECISION_LOG.md`, `docs/ARCHITECTURE.md` | n/a | None | — | — |
| **B** Foundation (routing, config, DB layer, logging, errors, sessions, CSRF, headers) | Completed | `src/Core/*`, `public/index.php`, `.env.example` | unit + auth suites (pass) | None | — | — |
| **C** Database: 34 tables, keys, composite FKs, CHECKs, triggers, reference data, migrations | Completed | `database/migrations/001-003` | schema suite: 10 tests (pass); real-feature probe on MariaDB 10.11 (pass) | None | — | — |
| **C** Host database verification (Hostinger, InfinityFree) | Blocked | `bin/check-environment.php` | Not executed on hosts | — | Hosting account access (B-3) | Run the probe on the exact plans |
| **D** Authentication: login, logout, activation, reset, sessions, rate limits | Completed | `src/Services/AuthService.php`, `src/Security/*` | auth suite: 12 tests (pass) | None | — | — |
| **D** Roles, permissions, project scoping, IDOR, exports, AJAX | Completed | `config/permissions.php`, `src/Security/Gate.php`, `src/Services/Scope.php` | security suite ST-01 to ST-40: 40 tests (pass) | ST-14 interpretation documented | — | — |
| **D** Audit logging (chain, fallback, verify, anchors) | Completed | `src/Audit/*` | audit suite: 7 tests, incl. tamper/truncation detection (pass) | None | Offline key escrow (D-27) | EHF escrows the key at go-live |
| **E** User management (invite, roles, deactivate, anonymise, delete invitation, ownership transfer) | Completed | `UserService`, `UserController` | workflow + ST-28 to ST-33 (pass) | None | — | — |
| **E** Public registration and applications/recruitment | Completed | `ApplicationService`, `PublicController`, `ApplicationController` | workflow (7) + ST-01 to ST-03, ST-40 (pass) | None | Privacy notice URL from EHF | EHF supplies the privacy notice URL |
| **E** Volunteers, skills, availability, status history | Completed | `VolunteerService`, `VolunteerController` | workflow + ST-04 to ST-06 + operations (pass) | None | — | — |
| **E** Projects, supervisor assignments, service periods | Completed | `ProjectService`, `ProjectController` | workflow + ST-07 to ST-10 (pass) | None | — | — |
| **E** Activities and activity assignments (rosters) | Completed | `ActivityService` | ST-11 to ST-13 + operations (pass) | None | — | — |
| **E** Tasks | Completed | `TaskService` | schema + all-pages tests (pass) | None | — | — |
| **F** Attendance | Completed | `ActivityService::recordAttendance` | ST-13, ST-14 + operations (pass) | None | — | — |
| **F** Time logs and hours approval (void, resubmit, late window, 24 h cap) | Completed | `TimeLogService` | schema + ST-15 to ST-20 + operations (pass) | None | D-16 to D-19 (defaults applied) | EHF confirms the defaults |
| **F** Performance reviews | Completed | `ReviewService` | ST-21, ST-22 (pass) | None | D-11 (default: not shared) | — |
| **F** Training types and records, compliance | Completed | `TrainingService` | operations (pass) | None | **D-10: EHF training list** | EHF supplies the list |
| **F** Certificates (printable HTML) | Completed | `CertificateService` | operations + ST-24 (pass) | Server-side PDF not provided (TD-15) | — | — |
| **F** Volunteer notes | Completed | `NoteService` | ST-23 (pass) | None | — | — |
| **F** Notifications, announcements | Completed | `Notifier`, `AnnouncementService` | operations (pass) | None | — | — |
| **F** Email outbox: log/disabled transports | Completed | `Mailer` | operations (pass) | None | — | — |
| **F** Email: SMTP transport | Blocked | `SmtpClient` | **Not executed** (no SMTP server) | Untested | SMTP credentials on Hostinger (B-4) | Send a test email in staging/production |
| **F** Settings | Completed | `AdminController::settings` | operations (pass) | None | — | — |
| **G** Dashboards (admin, supervisor, volunteer), monthly/annual reports, search and filters, CSV exports | Completed | `DashboardService`, `ReportService` | ST-25, ST-26 + operations (pass) | No Excel-native .xlsx (CSV opens in Excel) | — | — |
| **H** UI/UX: responsive, accessible, EHF branding | Completed | `views/*`, `public/assets/css/app.css` | 220 browser checks at 3 viewports (pass) | Arabic/RTL translation not built (`dir` attribute ready) | — | Future, if requested |
| **I** Testing: unit, integration, constraints, auth, permissions, isolation, audit, time logs, retention, backup/restore, performance, UI | Completed | `tests/*` | 106 + 220 + performance (pass) | None | — | — |
| **I** Integrity report + retention jobs + scheduler | Completed | `IntegrityReport`, `Retention`, `bin/scheduler.php` | operations (pass) | — | **D-25: retention sign-off** (purges report-only) | — |
| **J** Supabase migration tool (dry run, apply, reconcile, idempotent) | Completed | `SupabaseImporter`, `bin/migrate-supabase.php` | migration suite (pass, synthetic fixtures) | — | — | — |
| **J** Supabase migration execution | Blocked | `docs/MIGRATION.md` | Not executed | — | **B-5, B-6, B-7** | — |
| **K** Deployment preparation (guides, probe, backup/restore, rollback) | Completed | `docs/DEPLOYMENT.md`, `docs/BACKUP_RECOVERY.md` | backup suite (pass) | — | — | — |
| **K** Host verification and configuration (PHP, DB, privileges, triggers, cron, SMTP, HTTPS) | Blocked | — | Not executed | — | **B-3** | — |
| **L** Production release, cutover, smoke tests | Not started | `docs/DEPLOYMENT.md` §4–6 | — | — | **B-3**, explicit EHF authorisation | — |
| **L** Manuals (admin, supervisor, volunteer) | Completed | `docs/*_GUIDE.md` | n/a | — | — | — |

## Blockers (exact missing inputs)

| ID | Missing input | Why it is needed | Affected |
|---|---|---|---|
| B-1 | A decision on pushing the branch | `main` auto-deploys to Cloudflare Pages, which by default also publishes **preview deployments for every pushed branch**. Pushing would publish the PHP source at a public preview URL. No secrets are in it, but it would still be exposed. | Version control (work is safe in local commits and the delivered archive) |
| B-3 | Hosting account access (Hostinger plan; InfinityFree staging) | Run the compatibility probe and configure the hosts | Phases C (host), K, L |
| B-4 | SMTP mailbox credentials (entered in `.env` on the server, never in chat) | Test real email delivery | Email |
| B-5 | Result of `supabase_data_classification_READ_ONLY.sql` | Decide which records are genuine | Migration |
| B-6 | Source of Edge Functions `admin-user-directory`, `volunteer-registration` | Close live-system risk U-4; confirm registration rules | Migration, live system |
| B-7 | Explicit EHF authorisation of cutover and production deployment | Required by the mandate | Phase L |
| B-8 | EHF decisions D-10 (training list), D-25 (retention sign-off by DPL); privacy notice URL | Organisational and legal inputs | Training, retention, public form |
