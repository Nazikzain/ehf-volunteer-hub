# Security Documentation

| Control | Implementation | Verified by |
|---|---|---|
| Authentication | `password_hash()` (bcrypt by default, rehash on login), generic errors, constant-work verification for unknown accounts | auth tests |
| Rate limiting | HMAC-keyed counters (no raw emails or IPs stored): login, reset, registration, activation | auth + workflow tests |
| Sessions | HttpOnly, Secure, SameSite=Lax, strict mode, ID regeneration at login, 30 min idle, 12 h absolute, `session_version` revocation | auth, ST-33 |
| CSRF | Per-session token on every POST, constant-time comparison, rejections audited | ST-34 |
| Authorisation | One pipeline; code-defined permission map; scoping in services; 403 vs 404 rule | ST-01 to ST-40 |
| Primary Admin | `system_owner` singleton (database-enforced); guard on every user action; owner-only transfer with re-authentication and two confirmations | schema + ST-31/32 |
| SQL injection | PDO native prepared statements only; identifiers never taken from input | code review + tests |
| XSS | All output escaped with `e()`; CSP without inline script or style | UI test (no CSP violations) |
| Clickjacking / sniffing | `frame-ancestors 'none'`, X-Frame-Options DENY, nosniff | header test |
| Open redirect | Login redirect target must be a relative path; `notifications.link_path` CHECK | auth test, schema test |
| CSV injection | Leading `= + - @` neutralised in exports | unit test |
| Secrets | `.env` outside the web root and git; `.htaccess` denies; nothing secret in the database or logs; log redaction | ST-35, settings test |
| Audit | Append-only triggers, HMAC chain, fallback with MACs, anchors, verification | audit tests |
| Data minimisation | Volunteer actor emails not stored in the audit log; personal fields logged by name only; outbox bodies erased | audit + workflow tests |
| Erasure | Two-person rule; one transaction; history kept under an anonymised id; re-applied after a restore | workflow + backup tests |

## Residual risks (Design Rev3 §11)

| ID | Risk |
|---|---|
| U-1 | Without triggers **and** per-table grants on the host, the application account could modify audit rows. This is detected (hash chain plus anchors), not prevented. |
| U-3 | Someone controlling the whole hosting account can destroy the audit log. This is detectable only through the off-server anchors and backups. |
| U-4 | The live Supabase Edge Function has not been reviewed while public sign-up is ON. EHF action required. |
| — | The SMTP client has not been tested against a real mail server; test it on Hostinger. |
| — | No legal compliance claim is made. The retention schedule needs Data Protection Lead sign-off (D-25). |
