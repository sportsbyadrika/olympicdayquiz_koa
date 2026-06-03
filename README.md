# Olympic Day Celebrations 2026 — Sports Quiz Competition

A web-based quiz platform for the **Kerala Olympic Association** to conduct the
Olympic Day Celebrations 2026 Sports Quiz Competition.

> The full product vision, roles, data model, security posture and phased build
> plan live in **[PROJECT_SPEC.md](PROJECT_SPEC.md)** — the anchor specification
> for this project. Refer back to it whenever scope is unclear.

## Tech stack

- **PHP 8.x** (flat, organised folders — no heavy framework)
- **MySQL 8.x** (PDO + prepared statements)
- **Tailwind CSS** (mobile-first; via CDN in dev, CLI build for production)
- Vanilla JS + jQuery, **SortableJS** for touch-friendly drag-and-drop
- **PHPMailer** (SMTP) for credential emails

## Folder structure

```
/config        db.php, mail.php, settings.php
/includes      auth.php, csrf.php, db.php, helpers.php, header.php, footer.php
/public        index.php (home), login.php, logout.php, assets/
/admin         role-gated admin pages
/association   role-gated association pages
/expert        role-gated expert pages
/school        role-gated school pages
/api           AJAX endpoints (timer, autosave, dragdrop, …)
/reports       printable HTML report templates
/uploads       CSV uploads (protected; outside web root where possible)
/database      schema.sql, seeds.php
```

## Local setup

1. **Create the database** and load the schema + seed data:
   ```bash
   mysql -u root -p -e "CREATE DATABASE olympicday_quiz CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p olympicday_quiz < database/schema.sql
   ```
2. **Configure credentials.** Copy `config/local.example.php` to
   `config/local.php` and fill in DB + SMTP settings. `config/local.php` is
   gitignored and is **not** deployed by `.cpanel.yml`, so it survives every
   `git pull` / cPanel deploy. (Environment variables and built-in defaults are
   used as fallbacks if `local.php` is absent.)
3. **Mail** settings live in the `mail` section of `config/local.php` (or the
   `SMTP_*` environment variables).

### Applying schema changes to an existing database

Fresh installs get everything from `database/schema.sql`. For a database created
from an earlier schema, run the files in `database/migrations/` once (in date
order), e.g. `2026_06_profile_fields.sql`, `2026_06_password_resets.sql`.
4. **Serve** the app. For a quick dev server:
   ```bash
   php -S localhost:8000
   ```
   Then open <http://localhost:8000/> (redirects to `/public/index.php`).

### Seed accounts

All seeded users share the password **`Password@123`** (change in production).

| Role        | Email                              |
|-------------|------------------------------------|
| Admin       | `admin@olympicday2026.test`        |
| Association | `association@olympicday2026.test`  |
| Expert      | `expert@olympicday2026.test`       |
| School      | `school1@olympicday2026.test`      |
| School      | `school2@olympicday2026.test`      |

To regenerate fresh hashes: `php database/seeds.php "YourNewPassword"` and run
the printed `UPDATE` statements.

## PHPMailer

Place PHPMailer's `PHPMailer.php`, `SMTP.php`, `Exception.php` in
`/includes/PHPMailer/` (or install via Composer and adjust the include path in
`includes/helpers.php`). Without it, the app falls back to PHP `mail()` in dev.

## Production notes

- Replace the Tailwind CDN with a compiled CLI build.
- Serve only `/public` as the web root where possible; keep `/uploads` outside
  the web root.
- Enforce HTTPS (HSTS recommended); secure-cookie flags activate automatically
  under HTTPS.

## Build status

This repository is being built in phases per PROJECT_SPEC.md section 11.
