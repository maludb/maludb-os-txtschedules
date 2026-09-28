# txtSchedules — design (Phase 0 of the new-app workflow)

2026-09-28 · the third application from us beside the kernel: **restaurant staff scheduling**. Managers at each
restaurant build and publish a week's schedule from their staff's availability and the restaurant's rules; staff see
their shifts on their phones and give away, pick up and trade shifts among themselves, within rules the restaurant
sets; everyone is signed in by the OS, and the OS's agents answer "who is on Friday night?" and draft next week's
schedule for a manager to publish. Its own repository `/srv/apps/txtschedules`, served as `txtschedules.<domain>`,
built with `htmx-php-builder` (how it is built) and fitted to `maludb-os-integration` 0.5.0 (how it fits), exactly
as Projects was (`/srv/apps/projects/docs/projects-design.md`).

> **Checkpoint.** This document and the schema in `db/` are the Phase 0 deliverable. Phase 1 adds
> `docs/txtschedules-mcp-tool-surface.md`, `docs/txtschedules-action-manifest.md` and the slice specs in
> `docs/build-specs/`. **No feature PHP is written until the owner approves them together.** The requirements this
> grew from, with the owner's decisions, are the kernel's `docs/build-specs/txtschedules-requirements.md`; the kernel
> services it relies on, `docs/build-specs/kernel-app-services.md` (K6 SMS, K7 application reads, db/161).

## 1. What txtSchedules is, and is not

txtSchedules **owns schedules**: which shifts a restaurant needs, who works them, who may and may not, what they
cost, and every change of hands between people. It is the 7shifts, HotSchedules or Deputy a restaurant group would
otherwise pay for, with the differences that matter here: people are the business's own OS members, each restaurant
is a kernel **site** shared with the other applications (Reservations first), agents read and draft schedules
through the same doors people use, and nothing about pay leaves the people allowed to see it.

The kernel **owns the directory and the sites** (who exists, which restaurants there are, who holds what where) and
the **identity** (sign-on at `app.<domain>`; no login form, no password, no account here). txtSchedules mirrors
them with the kernel's ids and never changes them. It **owns time off** for the staff it schedules — types, balances,
requests, a ledger — and replaces HR's leave desk for them (D5). It ships its expert and a scheduling assistant, which
the kernel hires and runs; txtSchedules never calls a model.

Not in version 1 (the requirements §8): time clock and timesheets, payroll export, POS integrations and sales
forecasting, AI demand forecasting, tip pooling, staff chat (D7), surveys, push notifications (the web app is
installable; push later), **fair workweek** (D8). Legal compliance is never claimed (FR-C4): rules are help.

## 2. Who uses it (the actors)

- **Staff** — everyone who works shifts, on a phone: sees their shifts and the published team schedule, sets
  availability, requests time off, offers, picks up, swaps and gives shifts **at their main restaurant** (D4).
- **A shift lead** — runs the floor: fills today's gaps from the eligible list, approves same-day trades.
- **A manager** — builds and publishes the week, approves time off, availability and trades, sees labor cost,
  posts announcements.
- **The txtSchedules admin** of a restaurant (the GM; a super-admin at every restaurant) — the restaurant's settings:
  positions, rules, trade settings, time-off types and balances, blackout dates, budgets, day-parts, ratios, and
  **pay**: each position's **default hourly rate** and an employee's **own rate**, which overrides it (§3, D10).
- **An agent** — the expert (answers), the scheduling assistant (drafts a week and proposes coverage; never
  publishes), any agent granted per site: reads through the two MCP servers under a run token, acts through the
  kernel's Actions MCP, pauses in the kernel's approvals where an action says so.
- **The kernel** — signs people in, delivers the directory and the sites, runs the agents, texts people for
  txtSchedules (K6), carries reads between applications (K7), and reads the roles (`app_roles`).

## 3. Who sees what — the rules, in one place

- **Access** is a kernel grant **per site** with a set of roles (C1, C5). Nobody by default; the recommended grant is
  the kernel's grant to **everyone residing at a site**, as Staff (D3); managers and shift leads granted by the
  super-admin per restaurant. A super-admin holds the admin role at every site because the kernel lists it — the
  application never special-cases them (scoped-applications.md §4.1).
- **Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`):

| Role | Capability | Rights |
|---|---|---|
| `staff` Staff | write | `schedule.view_own`, `availability.edit`, `market.trade` |
| `shift_lead` Shift lead | write | staff's + `coverage.fill`, `market.approve_day` |
| `manager` Manager | write | shift lead's + `schedule.build`, `requests.approve`, `labor.view`, `announce.post` |
| `admin` txtSchedules admin (is_admin) | admin | every right + `settings.manage`, `pay.edit` |

- **The one rule** (db/013, the `mcp_*` views; PHP's gates mirror it with `has_right(right, site)`):
  - a site's data is seen by whoever holds a role **there**; drafts, templates, overrides, other people's profiles,
    availability and requests need `schedule.build` / `requests.approve` **there**;
  - a person's own shifts, profile, availability, requests, balances and claims are always theirs;
  - **pay** — wage rates, shift cost, budgets — needs `labor.view` at the position's site, or is one's own wage.
    The **effective rate** of a person at a position is **their own rate when they have one, else the position's
    default** (D10); cost = paid hours × the effective rate, for an assigned shift only; a person sees their own
    effective rate and nothing of anyone else's, and staff without `labor.view` see neither a default nor a cost;
  - nobody sees another person's email or phone (NF-3); a notification carries only the shift's facts and a link.
- **The marketplace** (D4): an open offer shows, and can be taken, only by staff whose **main restaurant** it is; the
  main restaurant is set by a manager (the first site a person is granted at, by default).
- Every right is asked **at a site** — there is no business-wide right. Caller checks in the views are uncorrelated
  sets tested once per statement (`ts_held_scope_ids()`, `ts_scopes_with_right()`), never a function per row.

## 4. Memory model — what txtSchedules remembers

**Record memory** (PostgreSQL 17, `<tenant>_txtschedules`, db/001–013):

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members`, `departments`, `department_members`, **`sites`**, **`member_site_roles`**, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; a site is a kernel scope (`scope_id`) at a kernel location (`location_id`, shared with Reservations) |
| Roles | `ts_rights`, `ts_roles`, `ts_role_rights`, `mcp_app_roles` | `ts_has_right(right, site)` |
| Restaurants | `site_settings` (week, currency, **trade settings**, reminders, overtime), `day_parts`, `app_settings` | seeded by `ts_site_materialise()` |
| People | `positions` (**`default_wage_rate`**), `staff_profiles` (**main restaurant**, max hours, minor), `staff_positions` (**`wage_override`**), `certification_kinds`, `certifications`, `position_certifications` | effective rate = `COALESCE(wage_override, default_wage_rate)` — `ts_effective_rate()` for the writer, inlined in the views |
| Availability, time off | `availability_rules`, `time_off_types`, `time_off_balances`, `time_off_requests`, `time_off_ledger`, `blackout_dates` | balances move only through `ts_time_off_post()`; decisions `ts_time_off_decide/cancel()` |
| Schedule | `schedule_weeks`, `shifts`, `schedule_templates`, `template_shifts` | no overlap for one person (exclusion constraint); a published shift is cancelled, never deleted; changes after publishing stamped |
| Rules | `rule_kinds` (12), `rule_presets` (generic), `site_rules`, `rule_overrides` | `ts_assignment_warnings()` — the one engine |
| Marketplace | `exchanges` (offer, open, give, swap, coverage), `exchange_claims`, `exchange_invitees` | `ts_exchange_create/claim/choose/accept/decide/cancel/expire()`; one winner, locked |
| Labor, forecast | `labor_budgets`, `forecast_covers`, `staffing_ratios` | `ts_staffing_needs()`; `mcp_labor_weekly` = scheduled hours and cost per site, week and area against the budget, at the effective rates |
| Talking | `announcements`, `announcement_reads`, `notification_prefs` (**email and text both on by default**), `notification_outbox`, `calendar_feeds` | SMS only through the kernel (K6); a reminder is queued once per shift, person **and channel** |
| Tokens | `mcp_access_tokens` | the application's own MCP/API bearer |

**Activity memory**: `activity_log` through one `log_activity()`, `entity.verb` events, **each row carrying the site**
(`scope_id`), shipped to the tenant's one MaluDB as `activity` episodes tagged `txtschedules`. There is **no second
history table**: an exchange's and a shift's history is the activity log (FR-S8). Events (the manifest names them):
`week.publish` (shifts published, warnings overridden), `shift.create|update|cancel|assign`, `exchange.create|claim|
accept|approve|decline|cancel|expire`, `timeoff.request|approve|decline|cancel`, `balance.adjust` (hours, never pay),
`availability.submit|approve|decline`, `rule.override`, `rule.update`, `settings.update` (the fields that changed),
`wage.update` (**that** a default or an employee's rate changed — never the rate), `announcement.post`, `forecast.update`, `budget.update`,
`member.sign_on`, `directory.sync`. A wage or an amount of pay is never in an episode.

## 5. The question inventory — what txtSchedules exists to answer

Each becomes one records-MCP tool (Phase 1), answered from the `mcp_*` views for the caller:

1. Who is working at a restaurant on a date, or now, by position? (`who_is_on`)
2. What are my next shifts? What is my week? (`my_shifts`)
3. What does a restaurant's week look like — scheduled, open, cancelled, draft? (`week_schedule`)
4. Which shifts are open or offered, and which could I take? (`marketplace`)
5. Who could cover this shift — eligible, free, fewest hours this week? (`coverage_candidates`)
6. What do the rules say about giving this person this shift? (`check_assignment`)
7. What warnings does this week have, and which were overridden, by whom and why? (`week_warnings`, `overrides`)
8. How many hours does a person have this week, and are they near overtime or their own limit? (`hours_this_week`)
9. What are a person's positions, main restaurant and certifications — which are expiring? (`staff_profile`, `expiring_certifications`)
10. What is someone's availability, and what changes wait for approval? (`availability`)
11. What time off is requested, approved, or overlapping a date — and a person's balances? (`time_off`, `time_off_balances`)
12. What is waiting for a manager at a restaurant: time off, availability, exchanges? (`pending_requests`)
13. What happened to this shift / this trade? (activity: `shift_history`, `exchange_history`)
14. What is the scheduled labor — hours, cost — against the budget, by day and week? (`labor_vs_budget`, labor.view only)
15. How many staff do the expected covers call for, against those scheduled? (`staffing_needs`)
16. What are this restaurant's settings: trades, cutoff, approvals, rules? (`site_settings`, `site_rules`)
17. What announcements are live, and who has read them? (`announcements`)
18. Who picks up the most shifts; how fast are trades approved; how many open shifts went unfilled? (`exchange_report`)
19. Who did what, when, at a restaurant? (activity: `who_did`, `site_activity`)
20. What did the scheduling assistant draft, and what did the manager change? (activity: `draft_history`)
21. The roles and rights (`app_roles`, for the kernel only) and one guarded search each (`records_search`, `activity_search`).

## 6. Screens (phone-first — every staff screen designed at 375 px before desktop; the nxl look)

**Staff (phone):** Home (my next shift with who else is on, my week, open requests, announcements) · My schedule
(week and list) · Team schedule (the published week by day, positions) · Shift (details; offer / swap / give; its
history) · Marketplace (offered and open shifts I can take, with why not when I cannot) · My requests (time off,
availability, trades — their state) · Availability · Time off (request, balances) · Announcements · Settings
(notifications; calendar feed link; texts need a phone verified in the OS).

**Managers (tablet and desktop, usable on a phone):** Week builder (rows by staff or position, days across, drag to
move and stretch, the same as buttons; hours per person; labor vs budget; warnings on each shift; staffing needs
beside the forecast) · Day view by hour · Templates · Approvals inbox (time off, availability, trades — with each
exchange's warnings) · Coverage (a gap → eligible list → offer to one or several) · Staff and positions (profile,
main restaurant, positions and wages, certifications) · Forecast (covers per day-part, ratios) · Budget ·
Announcements · Reports (hours, labor vs budget, open shifts unfilled, exchanges, overtime, overrides — each a CSV).

**Admin:** Restaurant settings (week, trades — D2, reminders, overtime), Rules (severity and parameters per rule),
Positions, Time-off types and balances, Blackout dates, Day-parts.

Rules of the look: no modals; full-page create/edit; every screen at 375 px; cards for named things (people,
positions, templates), tables for ledgers (shifts, requests, balances); every name a link; every page a way back.
A site switcher in the shell for a person who holds several (the launcher's choice is the first).

## 7. The agents' door

- **Read**: the records MCP (the tools of §5) and the activity MCP, FastMCP over streamable HTTP, bearer = a member's
  own token or the tenant's run token; agents are granted per site like people, and the run-facts gate offers
  exactly the tools the kernel names for the endpoint; a run whose facts say `scopes: []` reads nothing scoped.
- **Act**: the kernel's Actions MCP from the registry built from the action manifest; every handler honours the run
  token, checks the right **at the action's site**, logs with `source = 'agent'`.
- **Approval categories** (requirements OS-12) — an agent's call pauses for a person in the kernel's approvals:
  **publish a week** (`week_publish` — it notifies staff: `external_send`), **change or cancel a published shift**
  (`shift_update`, `shift_cancel` on a published shift: `other`), **approve an exchange** (`exchange_decide`:
  `other`), **change pay or a balance** (`wage_update`, `balance_adjust`: `other`). A person doing the same is not paused.
- **Shipped agents**: the **expert** (the command bar through the kernel's chat endpoint; answers; a few low-risk
  actions: request time off *for the asker*, offer or claim *the asker's* shift, post nothing); the **scheduler**
  (the scheduling assistant) — drafts a week from the template, availability, the forecast and the hours balance into
  a DRAFT week, and proposes coverage; it never publishes (publishing pauses), never approves, never touches pay.
- **Skills** (`skills/`): `txtschedules-basics` (how the application is organised, which tool answers what),
  `scheduling-rules` (the rules engine explained), `shift-marketplace` (the trade rules and settings), and runbooks
  `build-next-week`, `cover-a-gap`, `approve-requests`.

## 8. The kernel's part (owed to this application)

- **Sites shared with Reservations**: the super-admin adds each restaurant on txtSchedules' Scopes tab — the same
  kernel location as Reservations' site; `location_id` is the key the two applications share.
- **The residents grant** (D3): the kernel's grant to everyone residing at a site, as Staff.
- **K6 — SMS** (built, db/161): `POST /api/v1/notify/sms.php`; txtSchedules' outbox sends a text there when the
  person chose texts, and falls back to email on every refusal (`no_sender`, `no_verified_phone`, `opted_out`,
  `rate_limited`, `not_held`). No Twilio key in `config/.env`, ever. **Shift reminders go by both (D12):** each person's
  preferences start with email and text both on; a reminder before a shift is queued once for each channel; the text
  counts toward K6's 30 a member a day per application, and when K6 refuses, the email — which is independent —
  still goes. Everything else txtSchedules says (a published week, a change, an exchange, a decision, an
  announcement) follows the same two channels and the person's own choices.
- **K7 — application reads** (built, db/161): txtSchedules **reads** Reservations' `covers_by_service` for the
  forecast (FR-F2, D9) over a super-admin-approved connection, naming the site's `location_id`; `no_connection`
  degrades to the manual forecast.
- **HR and time off (D5, D11)**: HR must learn what time off txtSchedules approved, **per person**. The owner agreed
  (2026-09-28) that person-level facts may cross **only to HR**: the kernel lets a share be flagged
  `"people": true`, callable only by a consumer application with `directory.writes` (HR), over a super-admin-approved
  connection; the answer is keyed by the **kernel's member id** and holds facts only about people at the named site.
  txtSchedules **declares** `time_off_taken` in `maludb-os.json` `shares[]` as `{scoped: true, people: true}` (arguments
  `from`, `to`, `scope_id`; answer: per member id the approved time off in the period — type, dates or hours, days
  counted; never pay, never a reason). **The tool itself is Phase 4**; the kernel's part (the `people` flag and its
  check) is being built beside this document.

## 9. Decisions taken (the owner's, 2026-09-28 — rules, not questions)

| # | Decision | Where it lives |
|---|---|---|
| D1 | Name txtSchedules, key `txtschedules`, `txtschedules.<domain>` | `maludb-os.json`, `deploy/` |
| D2 | Trades are per-restaurant settings | `site_settings` (allow_*, approval_*, cutoff, claim mode, expiry, shift leads) |
| D3 | Web and mobile web app; access by the residents grant | §3; the manifest's web app manifest (Phase 2) |
| D4 | Staff pick up only at their main restaurant | `staff_profiles.main_scope_id`, `ts_exchange_check_taker()`, `mcp_exchanges` |
| D5 | Time off lives here and replaces HR's leave for its staff | db/007; the HR crossing is §8's gap |
| D6 | SMS through the kernel | K6; `notification_outbox` |
| D7 | No staff chat | announcements only |
| D8 | Fair workweek deferred | no rule kind; FR-C6 deferred |
| D9 | Forecast: manual covers + ratios; Reservations' covers next | db/011; K7 read |
| D10 | **Wages: a default rate per position for the restaurant, and a rate on each employee that overrides it** | `positions.default_wage_rate`, `staff_positions.wage_override`, the views |
| D11 | **Person-level facts cross only to HR** (the `people` share, `directory.writes` consumers) | `maludb-os.json` `shares[]` |
| D12 | **Shift reminders by both text and email**; a coverage request never goes outside the main restaurant | `notification_prefs`; `ts_exchange_check_taker()` |

Design choices of this document (the owner may overturn any): rights are only ever per site; the first site a person
is granted at is their main restaurant; a coverage request needs no approval (a manager or shift lead made it);
`claim_mode` first-wins by default; an offer expires at the shift's start; the rules preset is "generic" (no law
behind it); shifts are at most 16 hours; the minor flag carries an end date, never a date of birth.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document; the schema db/001–013, proven; the kit; `maludb-os.json`, `os/`, `skills/`, `deploy/`; the installer's plan | owner's go |
| 1 | `docs/txtschedules-mcp-tool-surface.md` (§5's tools), `docs/txtschedules-action-manifest.md` (every screen and action: who, undo, confirm, approval category, log payload), one spec per slice with **the shift and the marketplace** as the exemplar | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, the ingest bridge, the nxl shell (phone-first, site switcher, command bar), `/api/v1/health`, the vhost; installed beside the kernel | hand-off replay refused; a scope not held refused; 375 and 1280 |
| 3 | slices: **(1) shifts and the marketplace** (the exemplar: my schedule, team schedule, the shift, offer/pick up/give/swap, approvals of trades, coverage) → **(2) the week builder** (weeks, drafts, templates, publish, warnings and overrides) → **(3) availability and time off** (balances, blackout, approvals) → **(4) people and positions** (profiles, main restaurant, wages, certifications) → **(5) labor and forecast** (budget, covers, ratios, needs; the K7 read) → **(6) announcements and notifications** (outbox, email, K6 SMS, reminders, calendar feed) → **(7) settings, rules and reports** | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 |
| 4 | the two MCP servers with the run-facts gate; `app_roles`; the registry on the kernel; skills imported; the expert and the scheduler proposed | the command bar answers "who is on Friday"; an agent's publish pauses |
| 5 | the kernel installer's `apply` (the owner's), DNS and proxy, sites and grants; the end-to-end proof | a staff member launches on a phone into their restaurant and trades a shift |

The installer's `plan` runs at the end of **every** phase (the lesson of 2026-09-28's ZozoCal install). Size, by
Projects' measure (seven slices, 48 actions, 23 tools in a day and a half on the exemplar and workers): similar —
thirteen migrations, seven slices, ~45 actions, ~24 tools.

## 11. Ports, names and files

- Repository `/srv/apps/txtschedules`; database `<tenant>_txtschedules` (`subello_txtschedules` here); roles
  `txtschedules_rw`, `txtschedules_records_ro`, `txtschedules_activity_ro`; MaluDB: the tenant's one memory,
  episodes tagged `txtschedules`.
- `txtschedules.<domain>` and a loopback port; MCP records and activity ports — **all chosen by the installer**
  (`APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT` in `config/.env`); `deploy/` files are templates
  (`{{APP_FQDN}}`, `{{APP_DIR}}`, `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`).
- Timers: directory sync (every minute), activity ingest (every minute), notifications (every minute: the outbox,
  reminders, expiring offers).
- `maludb-os.json`: `catalog_key txtschedules`, `business_area Operations`, `scopes {kind: location}`, `sso`,
  `directory {reads: true, writes: false}`, `assistant {command_bar: true, agent: expert}`, `agents [expert,
  scheduler]`, `approvals` (§7), `reads [reservations.covers_by_service]`, `shares [time_off_taken (people)]` (§8), endpoints (records,
  activity, web, health, calendar feed), services.

## 12. The owner's answers (2026-09-28)

The owner's nine decisions are §9 (D1–D9). The four questions Phase 0 left open were answered the same day:

1. **HR and time off — agreed (D11).** Person-level facts may cross between applications **only to HR**: a share
   flagged `people`, callable only by an application with `directory.writes`, over an approved connection, keyed by the
   kernel's member id, about people at the named site. `time_off_taken` is declared (§8); built in Phase 4.
2. **Coverage — no (D12).** A manager's or shift lead's coverage request does **not** go to staff whose main
   restaurant is elsewhere; the schema's rule stands (`ts_exchange_check_taker()` applies D4 to coverage too).
3. **Wages — (D10).** Each position has a restaurant-wide **default** hourly rate; each employee may have a rate of
   their own per position that **overrides** it; the effective rate is the override when set, else the default. Pay
   stays protected: `labor.view` to see, `pay.edit` to change. Labor cost, budgets and the views use the effective
   rate. Changing a default changes the effective rate of everyone without a rate of their own — never of anyone who
   has one.
4. **Reminders — both (D12).** By text and by email; each person's notification preferences start with both on; the
   text is K6's (30 a member a day counts) and its refusals never stop the email.

## 13. State

**Phase 0 answers applied 2026-09-28 (db edited in place — nothing was deployed — and re-proven).** The database
`subello_txtschedules` was dropped and recreated as postgres and db/001–013 applied clean again (no warnings). The proof
(`db/proof/phase0_proof.sql`) now has **76 checks**, all passing — the 57 of Phase 0 plus 19 for the answers: a default
used when the person has no rate of their own; an own rate winning over the default; Sam's shift priced at the default
(70.00) and Priya's at her own (82.50); the week's labor 14.5 h and 152.50 against a budget; raising the default raising
the effective rate of everyone without a rate of their own and no one else's (Sam's shift 100.00, Priya's unchanged); a
person with no row at a position priced at its default; no default and no own rate → no rate; a staff member seeing only
their own effective rate and nothing of another's (rate, override or source), no default, no cost and no labor view; the
records role unable to call the rate function; a manager seeing labor at the restaurant he manages and none at the one
where he is staff; notification preferences starting with both channels on. The claim race still **4 of 4**.

**Phase 0 — built and proven, 2026-09-28.**

- **Schema**: db/001–013 applied clean, as postgres, on an empty `subello_txtschedules` (`CREATE DATABASE` then each
  file with `ON_ERROR_STOP`), no warnings.
- **Proof** (`db/proof/phase0_proof.sql`, one transaction, rolled back; fixtures written as `txtschedules_rw`, reads
  as `txtschedules_records_ro` / `_activity_ro` with `app.member_id` set as PHP and the servers set it): **57 of 57**.
  Covered: the catalogue (4 roles, 11 rights, one admin role giving every right); a new site seeded (settings, 2
  day-parts, 3 time-off types, 12 rules; the trade settings' defaults); visibility across two sites (a staff member at
  Airport sees Airport only, its published shifts only, her own wage and no one else's, no cost, no email but her own,
  no budget or template; a manager at Downtown who is staff at Airport sees Downtown's draft and cost and no Airport
  cost or wage; the owner sees every cost; an unknown member sees nothing); integrity (no overlapping shifts for one
  person; a published shift cannot be deleted; a shift cannot use another restaurant's position); the rules engine
  (a minor past 22:00 — hard; closing then opening — min_rest soft; a position not held — hard; eight hours without a
  break — break_required); the marketplace (offer; refused outside the main restaurant, D4; refused on one's own
  shift; first claim wins with no manager when no warning; a second claim is told it is gone; a soft warning sends a
  claim to a manager with the warning attached; a decline leaves the shift open; a hard rule refuses the claim with
  its reason; a swap refused where the site turned swaps off, D2; a give accepted, approved by a manager, carried
  out; no trade inside the cutoff; the marketplace view shows an offer to the main restaurant's staff only); time off
  (8 of 16 hours approved → 8 left; cancelled → 16; three ledger rows; 24 hours refused for want of balance; a
  blackout date refused; another staff member and a manager elsewhere cannot see the request; the site's approver
  can); the records role cannot read wages from the base table.
- **The claim race** (`db/proof/claim_race.sh`, two real sessions on a throwaway database built from db/ and dropped):
  **4 of 4** — the first session wins; the second, arriving during the first's claim, waits on the lock and is told
  the shift is gone; exactly one winning claim; the shift is the winner's.
- **The kit** copied from Projects and adapted for sites (commit 0d9f74b); lint clean; sign-on and the mirror are
  proven in Phase 2, as in Projects.
- **Registration**: `maludb-os.json` (scope kind location, 4 endpoints, 8 services, 6 skills — 3 skills and 3
  runbooks —, the expert and the scheduler with their tool grants, 6 approval entries, `reads` Reservations'
  `covers_by_service`, `shares []` for §8's gap), `os/expert.md`, `os/scheduler.md`, `deploy/` templates (the vhost
  with the /sso rewrites, the calendar feed and the loopback port; the two MCP servers; three timers — directory
  sync, activity ingest, notifications), all placeholders the installer renders (`{{APP_FQDN}}`, `{{APP_DIR}}`,
  `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`).
- **Installer** (`php /var/www/bin/app_install.php plan /srv/apps/txtschedules --scheme https`, read-only):
  **29 steps to do, 7 notes, no stop** — code done; database done (it exists: Phase 0 created it as postgres, so the
  installer will not re-run the migrations — the owner may drop it before the Phase 5 `apply` to have the installer
  create it owned by `txtschedules_rw`); ports picked 8101/8102/8103; the endpoints wait for their servers (Phase 2
  and 4); the registry waits for Phase 1's manifest; 6 approval categories covered; the expert and the scheduler
  proposed (38 and 28 tools). The K7 `reads` step runs once the application is registered (not visible in a plan
  made before). The units name `mcp/records_server.py`, `mcp/activity_server.py` (Phase 4) and
  `bin/notifications.php` (slice 6), which do not exist yet — the installer is for Phase 5.
