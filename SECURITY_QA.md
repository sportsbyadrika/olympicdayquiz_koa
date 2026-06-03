# Security & QA Checklist — Phase 10

Verification of PROJECT_SPEC.md §9 against the implementation, plus the
mobile-responsive (375px) review. Status as of the Phase 10 pass.

## Security checklist (§9)

| # | Item | Status | Where / Notes |
|---|------|--------|---------------|
| 1 | All DB access via PDO prepared statements | ✅ | `config/db.php` (PDO, `EMULATE_PREPARES=false`); every query uses bound params. `LIMIT/OFFSET` and `IN(...)` lists are integer-cast, never raw input. |
| 2 | Passwords hashed with bcrypt | ✅ | `create_user()`, `set_user_password()`, `attempt_login()` use `password_hash`/`password_verify` + `password_needs_rehash`. |
| 3 | Sessions: regenerate on login, HttpOnly, Secure, SameSite=Lax | ✅ | `auth_boot()` sets cookie params; `attempt_login()` calls `session_regenerate_id(true)`. `Secure` auto-enables under HTTPS (`COOKIE_SECURE`). |
| 4 | `require_role()` on every protected page | ✅ | Verified by grep — all of `admin/`, `association/`, `expert/`, `school/`, `api/`, `reports/` call `require_role`/`require_login`. |
| 5 | CSRF tokens on every form | ✅ | `csrf_field()` in all forms; `csrf_check()` at the top of every POST handler and AJAX write endpoint (verified by grep). |
| 6 | Input sanitization + output escaping | ✅ | `e()` (htmlspecialchars) for all output; `post()/get()` trim input; JSON output uses hex flags. |
| 7 | Server-side validation of all input | ✅ | Question, slot, CSV, and CRUD handlers validate server-side regardless of client checks. |
| 8 | Server-side timer — client clock never trusted | ✅ | `api/timer.php` + `remaining_seconds()` compute from stored `start_time` + `quiz_duration`; JS only displays. |
| 9 | Slot-window validation on quiz API calls | ✅ | `start_or_resume_session()` checks `slot_window_open()`; `autosave.php` checks expiry + lockout each call. |
| 10 | Post-submit lockout | ✅ | `autosave.php` returns 423 when submitted; `quiz.php` redirects finalized sessions; APIs treat finalize as idempotent. |
| 11 | CSV upload validation (size, MIME, headers, rows, max rows) | ✅ | `handle_association_csv()` / `handle_school_csv()`: 2 MB cap, `finfo` MIME check, exact header match, per-row validation, max-row guard. |
| 12 | Uploads stored outside web root where possible | ✅ | No persistent upload storage (CSV parsed from PHP tmp). `uploads/.htaccess` denies direct access; README advises serving only `/public`. |
| 13 | AJAX endpoints verify session + role + CSRF | ✅ | `slot_questions.php`, `autosave.php`, `submit.php` verify all three. `timer.php` verifies session + role + slot; its only write is an idempotent, self-only force-submit on expiry (accepted — see note below). |
| 14 | Audit log for sensitive actions | ✅ | Login, credential email, CRUD, accept/reject, declare, quiz start/submit/force-submit all call `audit_log()`. |
| 15 | HTTPS / HSTS in production | ⚠️ Deployment | Secure-cookie flags activate under HTTPS; enforce TLS + HSTS at the web server. Documented in README. |

### Accepted decisions / notes
- **`timer.php` uses GET without CSRF.** It is read-mostly; the single side
  effect is force-submitting the caller's *own* already-expired session, which
  is idempotent and cannot disadvantage another user. State-changing answer and
  submit endpoints require CSRF.
- **Tailwind via CDN** is used for development convenience; README documents
  switching to a compiled CLI build for production.

## Mobile responsiveness (375px viewport)

| Area | Result |
|------|--------|
| Navbar | Collapses to hamburger < `md`; mobile menu lists role links + logout. |
| Tables | Wrapped in `overflow-x-auto`; scroll horizontally without breaking layout. |
| Quiz side panel | Becomes a slide-in right drawer (`#mobileDrawer`) with overlay under `lg`. |
| Drag-and-drop | SortableJS is touch-enabled; cards are full-width and ≥44px tall. |
| Modals | `max-w-*` + `overflow-y-auto`, padded for small screens. |
| Touch targets | Primary buttons/inputs use `min-h-[44px]` / `py-2.5+`. |
| Forms | Single-column on mobile, grid columns at `sm:`/`lg:`. |

## Manual test matrix (run once a MySQL instance is available)

1. Load `database/schema.sql`; log in as each seeded role.
2. Admin: create + CSV-upload associations/schools; email credentials.
3. Association: add questions, submit to experts (locks).
4. Expert: accept/reject, author master questions, create slots, assign schools,
   drag-drop questions, declare results.
5. School: start quiz in window, autosave, finish; verify force-submit at 0;
   verify lockout after submit; view result only after declaration.
6. Reports: open each in a new tab and print.
