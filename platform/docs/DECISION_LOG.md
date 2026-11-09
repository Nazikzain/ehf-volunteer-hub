# Technical Decision Log

Routine technical decisions taken under the Full Project Execution Mandate. Each one keeps to the Revision 3 baseline (34 tables, no Revision 1/2 designs reintroduced). Organisational, legal and retention decisions remain with EHF (Design Review Rev3 §12); for those, the conservative default actually implemented is listed in the last table.

| ID | Decision | Reason |
|---|---|---|
| TD-01 | Plain PHP 8.2+ with a small in-house core (router, kernel, PDO layer, views). No framework, and no Composer at runtime. | Runs on any shared host, including InfinityFree. Small attack surface; nothing to keep updated beyond PHP itself. |
| TD-02 | Database: MariaDB 10.11 LTS for development (meets the ≥ 10.6 floor). The installer refuses MySQL < 8.0.16 and MariaDB < 10.6. | CHECK constraints are silently ignored on older MySQL (Rev3 R-07). |
| TD-03 | Schema generated once from the Revision 3 documentation model, then kept as versioned SQL migrations (`001_schema`, `002_triggers`, `003_reference_data`). | Guarantees the SQL matches the approved specification and ERD. |
| TD-04 | Dependency-free test harness (`tests/run.php`). It rebuilds a separate `ehf_test` database on every run, and HTTP tests run through the real kernel. | No Composer needed. The same tests can run on any host with PHP CLI. |
| TD-05 | Browser UI checks use Playwright as **development tooling only** (`tests/ui`). Node is not used in production. | Real-browser checks for responsiveness, labels and CSP, with no runtime dependency. |
| TD-06 | Native prepared statements (`EMULATE_PREPARES=false`). Repeated named placeholders are expanded automatically. | Stronger protection against SQL injection. This fixed a crash in search found by the tests. |
| TD-07 | Applied migrations are recorded in `settings` (`schema.migrations`), not in a migrations table. | Keeps the 34-table model unchanged. |
| TD-08 | Permission map in code (`config/permissions.php`). The Primary Admin holds every permission via `system_owner`. | Rev3 R-08/R-09 (EHF decision D-21 recorded as recommended). |
| TD-09 | Response codes: missing permission → 403; permission held but resource out of scope → 404. Every 403/404 is audited. Volunteers have own-scope access, so another person's record → 404. | Rev3 §8.1 rule 9 and the ST matrix. ST-14 interpretation is documented in the test report. |
| TD-10 | Audit log: canonical form v1, HMAC-SHA-256, gap-free `seq` under a `FOR UPDATE` lock taken last, fallback JSONL with per-line MAC, triggers, daily anchors written to `storage/exports` and emailed to the Primary Admin. | Rev3 §6, implemented as specified. |
| TD-11 | `details` is stored as canonical JSON **text**, not the MySQL JSON type. | MySQL re-serialises JSON (Rev3 R3-02). |
| TD-12 | A pending volunteer erasure request is stored in `settings` (`erasure.request.<id>`). | No extra table needed (34-table model). |
| TD-13 | Erased volunteer ids are kept in an ids-only register outside the database (`storage/exports/erasure-register.json`), and re-applied by `bin/post-restore.php`. | Rev3 §7 W7: erased data must not reappear after a restore. |
| TD-14 | Email: outbox plus transports `log` (development only, refused in production), `smtp` (minimal built-in client: STARTTLS or implicit TLS, AUTH LOGIN) and `disabled`. | No library dependency; email works on any host. The SMTP client has **not** been tested against a real server (see the tracker). |
| TD-15 | Certificates are produced as printable HTML (browser "Save as PDF"), not server-side PDF. | No PDF library on shared hosting. A deliberate, documented limitation. |
| TD-16 | Charts are pure CSS bars or tables. No JavaScript chart library. | The CSP stays `script-src 'self'`, with no third-party code. |
| TD-17 | Content-Security-Policy with no inline scripts or styles; X-Frame-Options DENY; nosniff; HSTS in production; no-store caching. | Defence in depth against XSS and clickjacking. |
| TD-18 | Sessions: HttpOnly, Secure (configurable for local http), SameSite=Lax, strict mode, 30-minute idle and 12-hour absolute timeouts, `session_version` revocation. | Rev3 §8.1 item 7. |
| TD-19 | Rate limits (HMAC-keyed): login 5 per account per 15 min (30 per IP); password reset 3 per account per hour; registration 5 per IP per hour; activation 10 per IP per 15 min. | Rev3 R-11. |
| TD-20 | When a supervisor adds a volunteer to their own project, the volunteer picker shows **names only** of approved or active volunteers. | Minimum data needed to assign people. Contact details stay scoped (D-13). |
| TD-21 | Public application: a duplicate open application shows the same thank-you page and is silently dropped, but audited. | No enumeration (ST-40). |
| TD-22 | Personal-data retention purges are **report-only** until `retention.auto_purge_enabled = true`. Technical purges (rate limits, tokens) always run. | Irreversible deletion requires EHF authorisation. |
| TD-23 | The PHP built-in development server serves static files directly. | Parallel asset requests caused session races (development only; Apache unaffected). |
| TD-24 | Logo: the dark-text logo on light backgrounds; the website's white-text logo (unaltered) on the dark top bar. | Brand rule, Phase A §1.8a. |

## Conservative defaults applied for open EHF decisions (all reversible)

| Decision | Default implemented | Where to change |
|---|---|---|
| D-01 audit retention | No automatic deletion; archiving is a manual Primary Admin runbook | Backup & Recovery guide |
| D-02 fallback file retention | 90 days after reconciliation (applied only when auto purge is enabled) | Settings |
| D-03 actor email | Staff actors only | Code (`Auth::actorSnapshot`) |
| D-05/D-06 erasure | Scope per Design §7 W4; two-person rule (Org Admin prepares, Primary Admin approves) | Code |
| D-07/D-08 retention | 12 months; report-only until enabled | Settings |
| D-10 training types | None seeded; EHF adds its own | Admin → Training types |
| D-11 reviews | Not shared unless the reviewer ticks "share" | Per review |
| D-12 notes | Never visible to volunteers | Code |
| D-13 contact details | Supervisors see contact details of volunteers on their projects | Code |
| D-15 service periods | Multiple periods supported | — |
| D-16 to D-19 time logs | One live log per activity; 30-day late window; any active project volunteer may log against a task; duplicate general work warns | Settings / code |
| D-20 supervisor history | Audit log only | — |
| D-25 retention schedule | Auto purge **off** | Settings |
