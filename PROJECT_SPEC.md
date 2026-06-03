# PROJECT_SPEC.md

# Olympic Day Celebrations 2026 — Sports Quiz Competition

> **Purpose of this document:** This is the anchor specification for building the platform with Claude Code. It captures the product vision, roles, workflow, data model, security posture, UI/UX direction, and the full sequence of step-by-step Claude Code prompts (Phases 0–10). Keep this file at the project root and refer Claude Code back to it whenever output drifts from scope.

---

## 1. Project Overview

A web-based quiz platform run by the Kerala Olympic Association to conduct the **Olympic Day Celebrations 2026 — Sports Quiz Competition**.

The platform manages the entire competition lifecycle:

1. Sports **Associations** contribute quiz questions through a portal that mirrors the official paper capture form.
2. **Experts** review, accept, edit, and curate questions into a master question bank, and can author their own.
3. **Experts** set up time slots for two rounds, assign schools to Round 1 slots, and assign questions to each slot.
4. **Schools** (one team of two participants per school) log in during their assigned slot and attend the quiz.
5. After Round 1, experts review results and select qualifying teams for Round 2.
6. Round 2 runs the same way for qualified teams.
7. Experts declare the final result; schools can then view their results with per-question explanations.

---

## 2. Tech Stack

| Layer | Technology |
|---|---|
| Server | PHP 8.x |
| Database | MySQL 8.x |
| Frontend | HTML5, **Tailwind CSS** (mobile-first), vanilla JS + jQuery |
| AJAX | jQuery AJAX (timer sync, auto-save, drag-and-drop, status updates) |
| Drag & Drop | SortableJS (touch-friendly) |
| Email | PHPMailer (SMTP) |
| Reports | Printable HTML templates opened in new tab; `window.print()` + CSS `@page` |
| Architecture | Flat, organized folders (no heavy MVC framework) |

### Folder Structure

```
/config        → db.php, mail.php, settings.php
/includes      → auth.php, csrf.php, db.php, helpers.php, header.php, footer.php
/public        → index.php (home), login.php, logout.php, assets/
/admin         → role-gated admin pages
/association   → role-gated association pages
/expert        → role-gated expert pages
/school        → role-gated school pages
/api           → AJAX endpoints (timer, autosave, dragdrop, etc.)
/reports       → printable HTML report templates
/uploads       → CSV uploads (outside web root if hosting allows)
/database      → schema.sql, seeds
```

### UI Palette (corporate / MNC feel)

- Primary navy: `#1A2B49`
- Accent teal: `#00897B`
- Neutral light grey: `#F5F7FA`
- Text dark grey: `#333333`
- White background

All pages are **mobile-first responsive**. The horizontal navbar collapses to a hamburger on small screens.

---

## 3. Roles & Permissions

Single login page; the system routes to a role-specific dashboard after authentication.

| Role | Capabilities |
|---|---|
| **Admin** | Create/manage Associations (incl. bulk CSV upload), Schools (CSV bulk upload + bulk credential email), and Experts. Manage settings. Marks exactly one association as the **Event Conducting Association**. |
| **Association** | Add/edit questions per the official capture template. Submit completed questions to experts. Once submitted, the association cannot edit those questions. |
| **Expert** | Review/accept/reject association-submitted questions; edit accepted questions; add/edit own questions in the master bank with a **Suggested Round** tag (Round 1 / Round 2 / Either); manage Round 1 & Round 2 time slots; assign schools to Round 1 slots; assign questions to slots via drag-and-drop; review Round 1 answers; select qualifying teams; assign qualified teams to Round 2 slots; assign Round 2 questions; declare final results. |
| **School** | One team with two participants. Login, view profile and assigned slot, attend quiz in assigned slot only, view quiz status, and view final result with explanations only after expert declaration. |

---

## 4. End-to-End Workflow

1. **Admin** creates Associations, Schools (CSV bulk), and Experts.
2. **Admin** uses the bulk-select page to email login credentials to schools.
3. **Associations** log in, add questions following the capture template, and submit.
4. **Experts** view submitted questions, accept (optionally edit) into the master bank. Experts may also add/edit their own questions with a Suggested Round tag.
5. **Experts** create Round 1 time slots and assign schools to slots.
6. **Experts** assign questions to each Round 1 slot via drag-and-drop.
7. **Schools** attend the quiz during their assigned slot — server-side timer, auto-save drafts, force-submit on time-out.
8. **Experts** review Round 1 results and select qualifying teams.
9. **Experts** create Round 2 slots, assign qualified teams, assign questions.
10. **Schools** attend Round 2 in their assigned slot.
11. **Experts** declare the final result; schools can then see scores and per-question explanations.

---

## 5. Question Capture Template (from the official paper form)

### Submission Header (per batch)
- Association name (auto-filled from login)
- Date (auto today, editable)
- Submitted by (name)
- Contact / email (auto-filled, editable)

### Per Question

| Field | Type | Required | Notes |
|---|---|---|---|
| Question No. | integer | Yes | Sequential per submission, auto-numbered |
| Question text | multi-line text | **Yes** | |
| Option A | text (~500 chars) | Yes | |
| Option B | text (~500 chars) | Yes | |
| Option C | text (~500 chars) | Yes | |
| Option D | text (~500 chars) | Yes | |
| Correct option | A / B / C / D | Yes | Exactly one |
| Sport | text | Optional (recommended) | e.g. "Athletics" |
| Category | text | Optional (recommended) | e.g. "Track & Field" |
| Difficulty | Easy / Medium / Hard | Optional | **Defaults to Medium** |
| Reference / Source | text | Optional | e.g. "World Athletics (worldathletics.org)" |
| Explanation | multi-line text | Optional | **Shown to participants on the result screen after submission (not during the quiz)** |

### Decisions Locked
- **Explanation timing:** Shown only on the final result/review screen after expert declares results.
- **Question entry mode:** Manual entry only for associations — no CSV upload for questions.

---

## 6. Database Schema (summary)

### Tables

| Table | Purpose |
|---|---|
| `users` | Single login table. Role enum: admin / association / expert / school. Stores `password_hash` (bcrypt), email, status, timestamps. |
| `associations` | Association profile. `is_event_conductor` boolean (only one true). FK to `users`. |
| `schools` | School profile: name, code, email, participant1_name, participant2_name, address, contact. FK to `users`. |
| `experts` | Expert profile. FK to `users`. |
| `question_submissions` | Batch header: association_id, submission_date, submitted_by_name, contact_email, status (draft/submitted). |
| `questions_association` | Per-question rows tied to a submission. All capture-form fields. Status: draft/submitted/accepted/rejected. |
| `questions_master` | Curated master bank. All capture-form fields + `suggested_round` (round1/round2/either), `source_association_id`, `source_question_id`, `created_by_expert_id`. |
| `rounds` | Round 1, Round 2. |
| `slots` | round_id, slot_name, start_time, end_time, slot_duration_min, quiz_duration_min, question_count. |
| `slot_schools` | Slot ↔ school assignments. |
| `slot_questions` | Slot ↔ question assignments with sequence_no. |
| `quiz_sessions` | school_id, slot_id, round_id, start_time, end_time, status (in_progress/submitted/force_submitted). |
| `responses` | session_id, question_id, selected_option (A/B/C/D nullable), status (draft/submitted), answered_at. |
| `results` | school_id, round_id, score, total_questions, qualified_for_next, declared, declared_at. |
| `settings` | Key/value for configurable defaults (slot_duration=30, quiz_duration=15, question_count=30). |
| `audit_log` | user_id, action, entity, entity_id, ip, user_agent, created_at. |

All tables use proper PK/FK constraints, indexes on lookup columns, and timestamps.

---

## 7. Configurable Settings (defaults)

| Setting | Default | Editable per |
|---|---|---|
| Slot duration | 30 minutes | Per slot |
| Quiz duration | 15 minutes | Per slot |
| Question count per quiz | 30 | Per slot |
| Force-submit on time-out | Enabled | Global |

Stored in the `settings` table; new slots inherit defaults but can be overridden.

---

## 8. Module-by-Module Functional Spec

### 8.1 Public Home Page
- Hero banner with project title and tagline
- "Competition Process" stepper explaining the workflow
- About section
- Top navbar: Home, About, Process, Login
- Login CTA
- Mobile-first responsive; hamburger on small screens

### 8.2 Common Login
- Single form for all roles
- Server validates credentials, regenerates session, routes to role dashboard
- CSRF token on the form, bcrypt password verification
- Audit-log every login attempt

### 8.3 Admin Module
- Dashboard: counts of associations, schools, experts, submissions, slots
- CRUD: Associations (with `is_event_conductor` toggle — exclusive)
- CRUD: Experts
- CRUD: Schools + **CSV bulk upload** with row-level error reporting
- **Bulk credential email** page: filter/select schools → send credentials via PHPMailer
- Settings page

### 8.4 Association Module
- Dashboard: question counts, submission status
- "My Question Bank" form matching the paper template exactly
- Submission header + per-question repeating form
- Edit/delete only on draft status
- "Submit to Experts" locks the submission

### 8.5 Expert Module
- Submission queue with filters (association, date, status)
- Accept / reject / edit accepted questions
- Add/edit own questions directly in master bank
- Suggested Round tag per master question
- Slot management for Round 1 & Round 2
- School ↔ slot assignment (Round 1, then Round 2 for qualifiers)
- **Drag-and-drop** question ↔ slot assignment (SortableJS), with live count
- Round 1 review and Round 2 qualifier selection
- "Declare Final Result" action

### 8.6 School Module / Quiz Engine
- Dashboard: profile, slot info, quiz status, result (post-declaration)
- Slot-window validation on quiz entry
- **Server-side timer** via `/api/timer.php`
- One question per page (text + 4 radio options)
- Controls: Back, Next, Save Draft, Finish
- Side panel: question list with status badges; mobile drawer
- AJAX auto-save on each selection
- Finish flow: confirmation → submit → score → result
- **Force-submit** when time = 0 (server-side, even if browser closed)
- Post-submit lockout on question/timer APIs
- Result-review screen (visible only after declaration) shows the participant's choice, the correct option, and the question's explanation

### 8.7 Reports
- Round 1 results, Round 2 results, Final consolidated result
- Open in **new tab** as printable HTML
- White background, repeating table headers, page numbers via `@page`
- `window.print()` button; clean print-only stylesheet

---

## 9. Security Checklist

- [ ] All DB access via PDO **prepared statements**
- [ ] Passwords hashed with **bcrypt** (`password_hash` / `password_verify`)
- [ ] **Sessions:** regenerate ID on login, set `HttpOnly`, `Secure`, `SameSite=Lax`
- [ ] **Role check** (`require_role(...)`) on every protected page
- [ ] **CSRF tokens** on every form (generation + validation helpers)
- [ ] **Input sanitization** and output escaping (`htmlspecialchars`) everywhere
- [ ] **Server-side validation** of all form input (never trust client)
- [ ] **Server-side timer** — client clock never trusted
- [ ] **Slot-window validation** on every quiz API call
- [ ] **Post-submit lockout** — question/timer APIs reject submitted/force-submitted sessions
- [ ] **CSV upload validation** — size, MIME type, header schema, row schema, max rows
- [ ] **Uploads stored outside web root** where hosting allows
- [ ] **AJAX endpoints** verify session + role + CSRF
- [ ] **Audit log** for sensitive actions (login, credential email, declare result, etc.)
- [ ] HTTPS in production; HSTS recommended

---

## 10. UI / UX Guidelines

- **Mobile-first**: design at 375px viewport first, scale up
- Top horizontal navbar with hamburger on mobile
- Tailwind utility classes; centralize theme via `tailwind.config` extend
- Consistent: buttons, form controls, tables, modals, status badges
- Status badges: green = answered/submitted, amber = draft, grey = unanswered
- Tables overflow horizontally on small screens, or stack as cards
- Drag-and-drop must be touch-friendly (SortableJS handles this)
- Quiz side panel becomes a slide-in drawer on small screens
- All clickable targets ≥ 44px tall for touch

---

## 11. Step-by-Step Claude Code Prompts

Run these in order. Test the output of each phase before moving on. If output drifts, point Claude Code back to this file.

### Phase 0 — Spec & Scaffold

**Prompt 1**
> Create a project called "Olympic Day Celebrations 2026 — Sports Quiz Competition." Use PROJECT_SPEC.md as the anchor specification. Scaffold the folder structure exactly as documented (/config, /includes, /public, /admin, /association, /expert, /school, /api, /reports, /uploads, /database) and create a README that references PROJECT_SPEC.md. Don't build features yet — only the skeleton and a placeholder index.php.

### Phase 1 — Database

**Prompt 2**
> Create `database/schema.sql` with all tables from PROJECT_SPEC.md section 6: users, associations, schools, experts, question_submissions, questions_association, questions_master, rounds, slots, slot_schools, slot_questions, quiz_sessions, responses, results, settings, audit_log. Include PKs, FKs, indexes on lookup columns, ENUMs as specified, timestamps, and seed data: 1 admin, 1 association marked event conductor, 1 expert, 2 sample schools, the two rounds, and default settings (slot_duration=30, quiz_duration=15, question_count=30). Explain the relationships after generating.

### Phase 2 — Security Foundation

**Prompt 3**
> Set up the security foundation. Create `/config/db.php` (PDO with prepared statements), `/includes/auth.php` (single login for all roles, role-based redirect, session regeneration, `require_role($role)` helper for protected pages), `/includes/csrf.php` (token generation + validation), `/includes/helpers.php` (sanitize, escape, redirect, log). Install Tailwind CSS via CDN with a note about switching to CLI build for production. Create `/includes/header.php` and `/includes/footer.php` with a mobile-first responsive horizontal Tailwind navbar that changes by role. Implement `login.php` and `logout.php`. Use bcrypt for passwords. Apply the palette from PROJECT_SPEC.md section 2.

### Phase 3 — Public Home Page

**Prompt 4**
> Build `/public/index.php`: hero banner with "Olympic Day Celebrations 2026 — Sports Quiz Competition" and a subtitle; a "Competition Process" section explaining the steps as a visual stepper with icons (Associations submit questions → Experts curate → Round 1 → Expert selects qualifiers → Round 2 → Final Result); an About section; a Login CTA. Top navbar with Home, About, Process, Login. Fully mobile-first responsive using Tailwind. Hamburger menu on mobile.

### Phase 4 — Admin Module

**Prompt 5**
> Build the Admin module: dashboard plus CRUD for Associations (with exclusive `is_event_conductor` toggle — only one allowed at a time) and Experts. Include bulk CSV upload of Associations with row-level error reporting. All forms server-side validated with CSRF; all queries via PDO prepared statements. Mobile-first Tailwind UI with responsive tables and status badges.

**Prompt 6**
> Add the Schools module to Admin: CRUD plus CSV bulk upload (columns: school_name, school_code, email, participant1_name, participant2_name, address, contact). Validate the CSV (size, MIME, headers, per-row), show errors per row, and generate a random password per school on creation (stored bcrypt-hashed). Then build a "Bulk Email Credentials" page: list schools with checkboxes, Select All, filters, and a Send Credentials button that uses PHPMailer to email login credentials to selected schools. Show send status per school and log to audit_log.

### Phase 5 — Association Module

**Prompt 7**
> Build the Association module. Login lands on a dashboard showing the association name, total drafted, total submitted, last submission date. Build "My Question Bank" mirroring the official capture template exactly. **Manual entry only — no CSV upload for questions.** The page has two sections: (1) Submission header — Association name (auto, read-only), Date (auto today, editable), Submitted by (editable), Contact/email (auto, editable) — saved as a `question_submissions` row. (2) Questions list — Add Question button opens a form with: Question No. (auto, editable), Question text (multi-line, required), Options A–D (~500 char each, required), Correct option (radio A/B/C/D, exactly one required), Sport (optional), Category (optional), Difficulty (Easy/Medium/Hard, defaults Medium), Reference/Source (optional), Explanation (optional — with UI note: "Shown to participants on the result screen after submission"). Responsive table of all questions with edit/delete (draft status only). A "Submit to Experts" button moves all draft questions in the submission to submitted and locks the submission. Client + server-side validation; CSRF on all forms; mobile-first Tailwind.

### Phase 6 — Expert Module (Part 1)

**Prompt 8**
> Build the Expert module part 1: a submission queue with filters (association, date, status). Clicking a submission opens its question list with full capture-form fields. The expert can: Accept (copies the question into `questions_master` with `source_association_id` and `source_question_id` for traceability; marks the source question accepted); Reject with a reason (marks rejected); Edit accepted questions in master (all fields including Suggested Round); Add own questions directly to master with the full set of fields plus `suggested_round` ENUM('round1','round2','either'). The Master Questions page is a filterable, paginated list (filter by Suggested Round, difficulty, sport, source). Mobile-first responsive throughout.

### Phase 6 — Expert Module (Part 2)

**Prompt 9**
> Build the Expert module part 2 — slot and assignment management. Experts can create/edit/delete time slots for Round 1 and Round 2 (each slot: start_time, end_time, slot_duration, quiz_duration, question_count — inheriting defaults from settings but editable per slot). For Round 1, assign schools to slots (one school per slot ideally; validate). Build the drag-and-drop question-to-slot assignment using SortableJS: master question bank on the left (filterable by Suggested Round, level, source), target slot on the right; on drop, an AJAX call writes to slot_questions with sequence, and a live "X / N" counter updates. Touch-friendly for mobile.

### Phase 7 — Quiz Engine (Part 1)

**Prompt 10**
> Build the school quiz engine. School dashboard shows: school name, two participants, assigned slot(s) with timing, current quiz status (Not started / In progress / Submitted / Result pending / Result declared). Quiz start: PHP validates current server time is within the assigned slot window. On start, insert a `quiz_sessions` row with start_time. **Server-side timer:** `/api/timer.php` returns remaining_seconds = (start_time + quiz_duration*60) − server_now. JS only displays it — never trusts the browser clock. Re-login within the window resumes with correct remaining time and saved drafts intact. Quiz UI: one question per page with text and 4 radio options (A/B/C/D); controls — Back, Next, Save Draft, Finish. Side panel lists all questions with status badges (answered/draft/unanswered) for jump-navigation; on mobile this collapses to a slide-in drawer. Visible countdown. Auto-save each selection via AJAX into `responses` (status='draft'). **Explanations are NOT shown during the quiz** — only after expert declares results.

### Phase 7 — Quiz Engine (Part 2)

**Prompt 11**
> Add submission, lockout, and the result-review screen. (1) Finish button with confirmation modal: flip all `responses` for the session to 'submitted', mark the session 'submitted', calculate score, write a `results` row. (2) Server-side force-submit: when remaining time hits 0, the timer API finalizes the session; any subsequent quiz API call after expiry also force-submits. Session status becomes 'force_submitted'. Unanswered questions count as zero. (3) Post-submission lockout: question and timer APIs reject the school's requests; only the result-status view is accessible. (4) Result-review screen — visible only after expert declares the result — shows each question with all 4 options, the participant's selected option highlighted, the correct option marked, and the explanation (from `questions_master.explanation`) below if present. Before declaration, the school sees "Result will be declared after expert review."

### Phase 8 — Round 2 & Result Declaration

**Prompt 12**
> Build Round 2 selection and management for Experts: view Round 1 results per slot, mark qualifying teams, create Round 2 time slots, assign qualified teams to Round 2 slots, assign questions to Round 2 slots via the same drag-and-drop UI. Re-use the quiz engine for Round 2. Add a "Declare Final Result" action — until declared, schools see only "pending"; once declared, schools see their score, rank, and the full result-review screen with explanations.

### Phase 9 — Printable Reports

**Prompt 13**
> Build printable HTML reports opened in a NEW browser tab: Round 1 results, Round 2 results, Final Consolidated Result. Print-optimized template: white background, clean headings, data tables where the `<thead>` uses CSS `display: table-header-group` so column headers repeat on every printed page, page numbers via CSS `@page` counters, and a Print button calling `window.print()`. Tailwind for screen view; a dedicated `@media print` block for paper. On-screen view also fully mobile responsive.

### Phase 10 — Hardening & QA

**Prompt 14**
> Final security and QA pass against the Security Checklist in PROJECT_SPEC.md section 9. Verify: all queries use PDO prepared statements; every protected page calls `require_role()`; every form has CSRF; every AJAX endpoint validates session + role + slot-timing; passwords are bcrypt; CSV uploads validate size, MIME, headers, and rows; uploads are outside web root where possible; post-submit lockout cannot be bypassed by re-login or replay; server-side timer cannot be tricked by client clock changes. Then walk every module at a 375px mobile viewport and list responsive issues to fix (navbar, tables, quiz side-drawer, drag-and-drop on touch). Produce a fix-list and apply the fixes.

---

## 12. Acceptance Criteria

- [ ] All four roles can log in via a single login page and land on their dashboard.
- [ ] Admin can bulk-upload schools via CSV and bulk-email credentials.
- [ ] Associations can add/edit questions matching the capture template and submit them.
- [ ] Experts can accept, edit, reject, and author questions, and tag suggested round.
- [ ] Experts can create slots, assign schools to slots, and drag-and-drop questions to slots.
- [ ] Schools can log in only during their slot window and attend the quiz.
- [ ] The quiz timer is server-driven and cannot be bypassed by the browser.
- [ ] Drafts auto-save and survive re-login within the time window.
- [ ] Force-submit triggers at time-out even if the browser is closed.
- [ ] Post-submit, the school cannot access questions until result declaration.
- [ ] Experts can select Round 2 qualifiers, run Round 2, and declare final results.
- [ ] After declaration, schools see their full result with explanations.
- [ ] All reports open in a new tab, print cleanly with repeating headers and page numbers.
- [ ] Every module is fully responsive at 375px viewport.
- [ ] Security checklist (section 9) all green.

---

## 13. Out of Scope (v1)

- Mobile native apps (web app is mobile-responsive instead)
- SMS notifications (email only)
- Multi-language UI
- Live leaderboards during the quiz
- Question banks for future years (this is built for 2026)

---

*End of PROJECT_SPEC.md*
