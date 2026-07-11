# Consulta SupportPal

*[Leia em português (PT-BR)](README.pt-br.md)*

A single-file, read-only viewer for a **SupportPal** database. Built to keep
internal support staff (N1/N2/N3) searching Knowledge Base articles and
tickets **after a SupportPal license has been cancelled or the instance has
gone offline**, without depending on the original application or an active
license.

## Why this exists

SupportPal (helpdesk software) requires an active license to run. When a
license lapses, years of ticket history and KB articles become inaccessible
through the normal UI, even though the data is still sitting in MySQL. This
tool reads that data directly, so support teams don't lose access to their
own history.

## What it does

- Search and browse Knowledge Base articles (public/internal)
- Search and browse tickets: by number, subject, requester name/email, tags,
  department, status, date range, and optionally full-text across message
  bodies ("deep search")
- View a full ticket thread (replies + internal notes) and article content,
  rendered with visual fidelity to the original
- Serve original attachments (images, documents) straight from disk
- Single shared-password login (bcrypt), IP allowlisting via `.htaccess`

## What it deliberately doesn't do

No framework, no Composer dependencies, no write access to the SupportPal
database. It's ~900 lines of plain PHP in one file, read-only end to end.

## Requirements

- PHP 8.1+ with PDO/MySQL
- Read access to the SupportPal MySQL/MariaDB schema (a dedicated `SELECT`-only
  user is strongly recommended, see [DOCUMENTATION.md §6](DOCUMENTATION.md))
- Read access to the SupportPal `storage/app` directory (for attachments)
- Apache with `.htaccess` support (`mod_env`, `mod_headers`, `mod_authz_core`)

## Quick start

1. Copy `sp_viewer.php` to your web root.
2. Copy `.htaccess.example` to `.htaccess` in the same directory and fill in
   the IP allowlist (the access-password hash and MySQL credentials can go
   here too, but see the recommended option below).
3. **Recommended:** copy `sp_local_config.php.example` to `sp_local_config.php`,
   place it one directory *above* your web root (a sibling of it, not inside
   it), and fill in your MySQL credentials, storage path, and access-password
   hash (generate with `php -r 'echo password_hash("your-password",
   PASSWORD_DEFAULT);'`). This file takes priority over `.htaccess` and works
   regardless of whether `mod_env` is available, and since it sits outside
   the served directory tree, there is no server misconfiguration that can
   expose it over HTTP.
4. Open the URL. If anything required is missing, the app shows a setup
   screen instead of failing silently.

For the full replication guide (new client/provider checklist), database
schema mapping, and architecture notes, see **[DOCUMENTATION.md](DOCUMENTATION.md)**.

## Security

This app has been through a full code + live-environment security audit
(2026-07-11). Findings and fixes are documented in
[DOCUMENTATION.md §6](DOCUMENTATION.md), most notably a critical stored-XSS
fix in the attachment-serving endpoint (mime-type allowlist), plus session
cookie and security-header hardening. Read that section before deploying to
a new environment.

Real infrastructure details (hostnames, server accounts, internal paths)
from any production deployment are intentionally kept out of this
repository. See [DOCUMENTATION.md](DOCUMENTATION.md) for the generic setup
this documents instead.

## License

[MIT](LICENSE).
