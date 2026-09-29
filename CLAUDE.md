# txtSchedules — CLAUDE.md

This is **txtSchedules**, the third application from us beside the Business OS kernel (kernel repo `/var/www`,
its design `docs/business-os-integration.md` there; the requirements and the owner's decisions
`docs/build-specs/txtschedules-requirements.md`; the kernel services it relies on `docs/build-specs/kernel-app-services.md`).
Restaurant staff scheduling: managers build and publish each restaurant's week; staff see their shifts on their
phones and offer, pick up, swap and give shifts within the restaurant's rules. Read, in order:
`docs/txtschedules-design.md` (what and why, the actors, the one rule, the memory model, the question inventory, the
screens, the agents, the decisions, the state), then — once Phase 1 exists — `docs/txtschedules-mcp-tool-surface.md`,
`docs/txtschedules-action-manifest.md` and the slice specs in `docs/build-specs/` (the exemplar: shifts and the marketplace).

## Two plugins govern this repo
- `htmx-php-builder` — **how it is built**: `new-app` (the fixed phase order), `php-patterns` before any PHP,
  `design-system` before any markup, `php-session-auth` for session hardening and CSRF, `chat-actions` for the
  command bar, `mcp-servers` for the two read servers, `new-screen` for every screen.
- `maludb-os-integration` 0.5.0 (skill `os-integration`, `~/maludb-os-integration`) — **how it fits**: the mirror
  with the kernel's ids, **scoped by location** (`scoped-applications.md`: each restaurant a kernel site), the `/sso`
  receivers, the run token honoured everywhere, the command bar through the kernel's chat endpoint, roles and rights
  published by `app_roles`, texts through the kernel (K6) and reads of other applications through the kernel (K7,
  `sms-and-reads.md`), `maludb-os.json`. Where the two conflict, the integration contract wins: **no password, no
  login form, no account, no model key, no Twilio key, no shared tables**. txtSchedules never writes the directory.

## Rules that do not move
- **Checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + manifest + slice specs
  together (Phase 1). Schema changes after that are numbered additive migrations, each with sign-off.
- **Every right is asked at a site** (`has_right(right, site)`, `require_right(right, site)`; SQL
  `ts_has_right(right, scope_id)`). A site the person does not hold does not exist to them — `Not found.`
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right at the
  record's site + `log_activity()` with the site + `emit_action_status()`; a create's `location` ends in the record id.
- The kernel's rules copied here: activity `source` is `web` for the UI, `agent` under a run token; `action` is
  `entity.verb`; `mcp_*` views are the only thing the read roles see, with caller checks as uncorrelated sets
  (`ts_held_scope_ids()`, `ts_scopes_with_right()`) tested once per statement; `app.member_id` is set before any
  query; an unknown member id is refused, never created. A shift's and an exchange's history is the activity log —
  no second history table.
- **Pay**: a wage (a position's default, a person's own rate, the effective rate), a cost or a budget is read only with
  `labor.view` at the site (or one's own effective wage) and changed only with `pay.edit`; never in an activity row, an
  MCP answer to anyone else, a notification or a log line (`wage.update` says THAT a rate changed).
- **The owner's decisions (2026-09-28) are rules**: D1 the name txtSchedules (`txtschedules`); D2 trades are
  per-restaurant settings (`site_settings`); D3 web and mobile web app (installable; no native app), access by the
  kernel's grant to a site's residents; D4 staff pick up shifts only at their MAIN restaurant; D5 time off (types,
  balances, the ledger) lives here and replaces HR's leave for the staff it schedules; D6 SMS only through the kernel
  (`POST /api/v1/notify/sms.php`), every refusal falls back to email; D7 no staff chat — announcements only; D8 fair
  workweek deferred; D9 the forecast is a manual covers entry with staffing ratios, Reservations' covers read through
  K7 next; **D10 wages: a default hourly rate per position for the restaurant and a rate on each employee that
  overrides it — effective rate = the employee's own when set, else the position's default; cost, budgets and the
  views use the effective rate; D11 person-level facts cross only to HR (a `people` share, `directory.writes`
  consumers) — `time_off_taken`; D12 shift reminders go by both text and email (preferences default both on; K6's
  refusals never stop the email), and a coverage request never goes outside the main restaurant; D13 the hours a day
  a time-off request counts is a per-restaurant setting (`site_settings.time_off_day_hours`, default 8 — never a
  constant); D14 an agent approving time off pauses for a person; D15 certifications are the restaurant's own kinds
  with a screen, a due list and a verifying manager (an unverified card counts, a manager entering one verifies it);
  D16 no accrual rules; D17 the split trade actions and the one `wage.update` event stay.**
- Integrity lives in the database: one person never holds overlapping shifts (the exclusion constraint); a published
  shift is cancelled, never deleted; a balance moves only through `ts_time_off_post()`; an exchange changes hands only
  through the `ts_exchange_*()` functions (the row lock makes one claim win); the rules engine is
  `ts_assignment_warnings()` — the builder, publishing, the marketplace and the tools all ask it.
- Phone first: every staff screen designed at 375 px before desktop; no modals; full-page create/edit; cards for
  named things, tables for ledgers; every name a link, every page a way back (the kernel's click-around rules).
- Times are shown in the site's time zone (`sites.timezone`), with the zone when a person works at two.
- Commit after every finished step on `main`, in the kernel repo's message style; never push.
- Smokes and fixtures are named `SMOKE <run>`; the schema proof (`db/proof/phase0_proof.sql`) runs in a transaction
  that is rolled back; the claim race (`db/proof/claim_race.sh`) on a throwaway database it drops.
- The installer's `plan` (`php /var/www/bin/app_install.php plan /srv/apps/txtschedules`) is run at the end of every phase.

## Environment facts
- Repo and install path `/srv/apps/txtschedules`; database `subello_txtschedules` on the office's PostgreSQL 17 (roles
  `txtschedules_rw`, `txtschedules_records_ro`, `txtschedules_activity_ro`), created 2026-09-28 by Phase 0.
- Ports are the installer's choice (`APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT` in `config/.env`);
  `deploy/` files are templates for `bin/app_install.php`.
- The kit came from Projects (itself from HR and the kernel): `app/auth.php` verifiers, `app/http.php`,
  `app/activity.php`, `app/partial_update.php`, `mcp/db.py`, `mcp/server_common.py`, `mcp/activity_ingest.py`,
  `bin/build_action_registry.php`, `bin/mint_mcp_token.php`, `app/directory.php`, `bin/directory_sync.php`,
  `html/sso.php`, `html/sso/logout.php` — adapted for sites (`mirror_apply_scope`, `mirror_apply_holding`).
- **State (2026-09-28): Phase 0 complete; Phase 1 written, revised for D13–D17 and APPROVED by the owner; Phase 2 built and
  proven** (sign-on, the mirror, the sites, the switcher, the phone-first shell, the directory sync, health, the ingest bridge with
  the site, the action-token/run-token gate; **212 proof checks** in `tests/phase2/`, run by `tests/phase2/run.sh` on a scratch
  database, against `php -S` and a real Apache with the rendered deploy vhost). `maludb-os.json` lists only what exists (the
  two MCP servers come back in Phase 4, the notifications worker in slice 6).  The record:
  `docs/txtschedules-design.md` §13.
- **Phase 3 slice 1 — shifts and the marketplace (2026-09-29) built and proven** (`docs/build-specs/shifts-marketplace.md`, the exemplar): nine screens, thirteen
  handlers in `html/exchanges/`, `app/features/{shifts,exchanges}/`, **db/015** (overlap refused when a shift is taken — apply by hand on the installed database,
  `deploy/ROOT_STEPS.sh`), **317 checks** in `tests/phase3/slice1/` (`run.sh`, scratch database `txtschedules_dev3`, `php -S` and Apache). Decisions: design §13.
  The application is already applied on the kernel (application 56). Next: slice 2 (the week builder).
- **The proofs never touch the installed application**: `tests/setup_dev.sh` makes `txtschedules_dev` and puts its environment
  in `$TS_DEV_ENV` (real environment variables, read ahead of `config/.env`); **never put a `config/.env` here before the
  installer's `apply`** — it would keep the scratch keys and skip the fresh role passwords.
