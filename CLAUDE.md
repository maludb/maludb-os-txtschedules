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
  The application is already applied on the kernel (application 56).
- **Phase 3 slice 2 — the week builder (2026-09-29) built and proven** (`docs/build-specs/week-builder.md`): builder grid + phone day tabs, day view, shift form with live check, templates, publish page;
  fifteen handlers in `html/{weeks,shifts,templates}/`, `app/features/{weeks,templates,autofill}/`, `shifts/write.php`, SortableJS drag; **no migration**; **293 checks** in `tests/phase3/slice2/`
  (`run.sh`, scratch `txtschedules_dev4`). Decisions: design §13.
- **Phase 3 slice 3 — availability and time off (2026-09-29) built and proven** (`docs/build-specs/availability-time-off.md`): six screens (`availability`, `time-off`, `time-off-add` with the live preview, `time-off-view`, `balances`,
  `time-off-types`), thirteen handlers in `html/{availability,time-off}/`, `app/features/{availability,timeoff}/`, Approvals / My requests / the menu badge carry time off and availability; approving can also open the covered shifts;
  **no migration**; **401 checks** in `tests/phase3/slice3/` (`run.sh`, scratch `txtschedules_dev5`: schema 20 · availability 67 · timeoff 117 · hours 20 · zones 11 · rights 42 · agents 14 · registry 13 · browser 97; all screenshots read).
  Registry 43 of 69 actions built. Decisions: design §13. Owed: the settings screen's half of the hours-a-day proof (slice 7). Next: slice 4 (people and positions).
- **Phase 3 slice 4 — people, positions and certifications (2026-09-29) built and proven** (`docs/build-specs/people-positions.md`): ten screens (`staff-list`, `staff-view`, `staff-edit`, `positions-list`, `position-add/edit`, `certifications` — the due list and the
  restaurant's kinds —, `certification-kind-add/edit`, `my-certifications`), eleven handlers in `html/{staff,staff/certifications,positions,certifications/kinds}/`, `app/features/{staff,positions,certifications}/`; the wage rule is the slice's spine
  (a rate only where the screen asked `labor.view` at the position's restaurant, a person's own effective rate on their own page, never in a `did`, reply, header, log row or notice — proved by greps over 43 pages × HTML and JSON × every kind of person);
  **no migration**; **396 checks** in `tests/phase3/slice4/` (`run.sh`, scratch `txtschedules_dev6`: schema 22 · wages 49 · staff 57 · positions 29 · certs 85 · rights 24 · agents 17 · registry 17 · browser 96; all 40 screenshots read; phase 2 and slices 1-3 re-run green). Registry 54 of 69 actions built. Decisions: design §13. Owed: the manager's warning-window notice (slice 6), `rule_save` (slice 7).
  **Hazard found and fixed:** `tests/setup_dev.sh` rotates the CLUSTER roles' passwords, which the installed application also uses (it was answering health 503 `database: error` after any proof run); `tests/restore_role_passwords.sh` (called by slice 4's run.sh on exit) puts back the passwords `config/.env` carries.
  Next: slice 5 (labor and forecast).
- **Phase 3 slice 5 — labor and forecast (2026-09-29) built and proven** (`docs/build-specs/labor-forecast.md`): two screens (`forecast` — grid or phone day tabs, the needs table, ratios, copy last week, fill from Reservations —
  and `budget` — bars by area, by day), five handlers in `html/labor/` (`forecast` `forecast-copy` `forecast-fill` `ratio` `budget`), `app/features/labor/{queries,present,reservations,handler}.php`, the builder's Forecast / Budget links;
  cost only for `labor.view` (budget screen 403 without it; `budget_save` needs `settings.manage` AND `labor.view`); **K7 read of Reservations' covers through the kernel, degrading to words** (`no_connection` — the connection is not
  approved yet — `not_at_location`, `not_shared`, `provider_failed`, no location), proven against a STUBBED kernel (`tests/fake_kernel.php` answers `/api/v1/apps/read.php`); **no migration**; **~270 checks** in `tests/phase3/slice5/`
  (`run.sh`, scratch `txtschedules_dev7`: schema 21 · forecast 69 · k7 46 · budget 61 · rights 49 · agents 18 · registry 17 · browser 90; all 26 screenshots read). Registry 59 of 69 actions built. Decisions: design §13.
  **Hazard fixed at the root:** `tests/setup_dev.sh` now READS the cluster roles' passwords from `config/.env` when the app is installed and never alters them (it alters only when nothing is installed); every slice run ends with the live health
  line in slice 5's run.sh. Owed: the super-admin's approval of the Reservations connection (kernel `bin/app_connection.php`), the daily 14-day fill by the notifications timer (slice 6). Next: slice 6 (announcements and notifications).
- **Phase 3 slice 6 — announcements and notifications (2026-09-29) built and proven** (`docs/build-specs/announcements-notifications.md`; design §13): announcements (`html/announcements/`, read receipts, remove), the notifications worker `bin/notifications.php`
  (outbox `notification_outbox`: email through MaluMail and text through the kernel's K6 `notify/sms.php` as independent rows — every refusal `no_verified_phone`/`no_sender`/`opted_out`/`not_held`/`rate_limited` is a SKIP with the code, the email still goes;
  shift reminders by the person's lead time or the restaurant's, offer/give/coverage expiry, the daily pass incl. the 14-day forecast fill and certification warnings), per-person prefs (`/settings/`), the calendar feed (`/api/v1/calendar/{token}.ics`,
  rotatable), notices that never carry wages, phones or another person's address or a surname; **382 checks** in `tests/phase3/slice6/` (`run.sh`, scratch `txtschedules_dev8`: schema 15 · announcements 58 · reminders 24 · k6 29 · email 13 · sensitive 9 ·
  expiry 11 · calendar 32 · daily 28 · prefs 28 · rights 40 · agents 11 · registry 17 · browser 67; live health `database: ok`). Regression the same day, one run at a time: phase 2 = 212 checks (7 proofs), slices 1-5 all green.
  **Test hazards fixed:** published shifts are never deleted, so proofs' shifts piled up and the fixture's long shifts collide with a proof's at hours that change with the clock — `reset6()` cancels earlier `SMOKE s6` shifts and `shift_in()` cancels any
  overlapping shift of that person (a cancelled shift no longer counts); the tampered-token test in `tests/phase2/gates.php` now always flips the last character to a DIFFERENT one (a signature ending in `0` made it a coin toss). No app bug was found.
  Owed to the owner's `app_install.php apply`: install the notifications service and timer (`deploy/ROOT_STEPS.sh`), register the calendar feed endpoint, refresh roles and `mcp/registries/txtschedules.json`. Next: Phase 4.
- **Phase 3 slice 7 — settings, rules and reports (2026-09-29) built and proven — PHASE 3 COMPLETE** (`docs/build-specs/settings-rules-reports.md`; design §13): four screens (`site-settings` — trade settings with a live sentence, week, reminders, the hours a day of time off with its
  live example (D13), overtime; `day-parts` — the service name Reservations uses; `rules` — a card per engine rule, hard / soft / off with values, the Generic preset, the 30-day overrides list; `reports` — seven tables, 50 rows a page, CSV of every row, `report.export`),
  five handlers in `html/{site,rules}/`, `app/features/{site,rules,reports}/`; rules readable by whoever builds, changed only with `settings.manage`; cost columns only for `labor.view` (grep-proved over 4 people x 7 reports x page/JSON/CSV); **the shell's placeholder mechanism is gone**; registry
  **69 of 69 actions, 43 of 43 screens built**; **no migration** (db/015 is the last); **446 checks** in `tests/phase3/slice7/` (`run.sh`, scratch `txtschedules_dev9`: schema 21 · settings 62 · rules 57 · daypart 35 · reports 83 · rights 55 · agents 13 · registry 20 · browser 100; the 44 screenshots at 375 and 1280 read).
  **Phase 3 in numbers (one run at a time, all green 2026-09-29):** phase 2 = 212 · slice 1 = 317 · slice 2 = 293 · slice 3 = 401 · slice 4 = 396 · slice 5 ≈ 270 · slice 6 = 382 · slice 7 = 446; live health `database: ok` after each.
  Earlier proofs updated for what slice 7 legitimately changed: phase 2's gates (a manager reads Rules; no placeholder), slice 5's cost-file allow-list (reports' query file), slice 6's registry count (`>= 64`) and root-steps text. `deploy/ROOT_STEPS.sh` is now the complete ordered script of what the owner runs
  (db/015 by hand, `app_install.php apply`, the mail key, the K6 number, the Reservations connection, the agents, DNS/TLS); the installer's `plan` shows only owner/apply todos (notifications units, the calendar endpoint, `app_roles`, the registry refresh — `mcp/action_registry.json` now counts 69 built).
  Owed to the owner: all of `deploy/ROOT_STEPS.sh`. Next: Phase 4 (the two MCP servers, `app_roles`, the tools, the expert and scheduler agents' reads, the kernel's pause proofs).
- **Phase 4 — the two MCP servers, `app_roles`, the registry, the agents (2026-09-30) built and proven** (design §13, *Phase 4 decisions*; tool surface "As built"): `mcp/records_server.py` (31 tools: 30 + `app_roles`, plus the kernel-only share `time_off_taken`),
  `mcp/activity_server.py` (6), tool modules `mcp/ts_{people,schedule,requests,labor}.py`, the run-facts gate in `mcp/server_common.py` (agents see exactly the tools the kernel grants on 'Records MCP' / 'Activity MCP', fail closed; an
  unadmitted agent is admitted at first contact only when the kernel vouches; a person or the command bar's action token sees every tool, the views decide the rows), **db/016** (additive: `mcp_admit_agent`, `mcp_member_kind`, `ts_time_off_taken`,
  `mcp_time_off_ledger`, `mcp_exchange_invitees` — apply by hand, ROOT_STEPS 1b, before `apply`), the vhost proxying `/mcp/records` and `/mcp/activity`, `deploy/kernel-registry-txtschedules.json` (the resolver's `resolve` block: ten tools answer
  a plain LIST when given `q` alone), `maludb-os.json` 0.3.0 (the two MCP endpoints and services). **Wage rule proved by grep**: every view that carries pay asks `labor.view` (or the caller's own id), the servers read only `mcp_*` views and gated functions,
  and a 62-call battery of every records tool as 13 callers (people, the command bar, agents) finds no pay key with a value and none of eleven distinct fixture numbers bar a person's own rate. **Proofs `tests/phase4/run.sh`** (scratch `txtschedules_dev10`,
  servers :8194/:8195/:8197, `TS_APP=apache` also proves the vhost proxy): phase 0 schema 129 · schema/roles' reach 19 · gate 60 · kernel contract 43 (`app_roles` judged by the kernel's own `validate_application_roles()`, the share called by its own
  `mcp_call_tool_as_kernel()`) · tools schedule 126 (**"who is on Friday" answered**) · tools requests 121 · activity 55 · wages 41 · hook 59 (the kernel's own `application_actions.register()` against a stubbed approval hook: the 13 pausing
  actions ask the hook first and never reach their handler when it pauses/records/errs; the other 56 never ask) · registration 152 = **805**; live health `database: ok`. Owed to the owner: all of `deploy/ROOT_STEPS.sh` (now with 1b and 3b); the LIVE
  pause of an agent's publish is Phase 5's. Regression the same day, one run at a time, all green: phase 2 (7 proofs) and slices 1-7 (earlier proofs updated for what Phase 4 legitimately changes: db/016 is the last migration; `mcp/ts_people.py` names wage columns; a clock-dependent reminder-text regex). Next: Phase 5 (the installer's `apply`, DNS, sites and grants, the end-to-end proof).
- **The proofs never touch the installed application**: `tests/setup_dev.sh` makes `txtschedules_dev` and puts its environment
  in `$TS_DEV_ENV` (real environment variables, read ahead of `config/.env`); **never put a `config/.env` here before the
  installer's `apply`** — it would keep the scratch keys and skip the fresh role passwords.
