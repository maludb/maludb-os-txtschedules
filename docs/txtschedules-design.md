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
| Restaurants | `site_settings` (week, currency, **trade settings**, reminders, **time-off hours per day**, overtime), `day_parts`, `app_settings` | seeded by `ts_site_materialise()` |
| People | `positions` (**`default_wage_rate`**), `staff_profiles` (**main restaurant**, max hours, minor), `staff_positions` (**`wage_override`**), `certification_kinds` (**per restaurant**, D15), `certifications` (verified by a manager), `position_certifications` | effective rate = `COALESCE(wage_override, default_wage_rate)` — `ts_effective_rate()` for the writer, inlined in the views |
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
`week.publish` (shifts published, warnings overridden), `shift.create|update|assign|delete` (a draft), `shift.add|change|cancel` (a published week),
`exchange.offer|give|swap|open|coverage|claim|withdraw|accept|refuse|choose|approve|decline|cancel|expire`, `timeoff.request|approve|decline|cancel`, `balance.adjust` (hours, never pay),
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
9. What are a person's positions, main restaurant and certifications — which are expired, due, missing or to verify? (`staff_profile`, `certification_kinds`, `certifications`, `certifications_due`)
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
availability, trades — their state) · Availability · Time off (request, balances) · My certifications (see, add, correct — a manager verifies) · Announcements · Settings
(notifications; calendar feed link; texts need a phone verified in the OS).

**Managers (tablet and desktop, usable on a phone):** Week builder (rows by staff or position, days across, drag to
move and stretch, the same as buttons; hours per person; labor vs budget; warnings on each shift; staffing needs
beside the forecast) · Day view by hour · Templates · Approvals inbox (time off, availability, trades — with each
exchange's warnings) · Coverage (a gap → eligible list → offer to one or several) · Staff and positions (profile,
main restaurant, positions and wages) · Certifications (the restaurant's kinds and which positions need each; who is expired, due, missing or to verify) · Forecast (covers per day-part, ratios) · Budget ·
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
  **publish a week** (`week_publish` — it notifies staff: `external_send`), **text or announce to staff**
  (`coverage_request`, `announcement_post`: `external_send`), **add, change or cancel a shift of a published week**
  (`shift_add`, `shift_change`, `shift_cancel`: `other` — a draft's `shift_create`/`shift_update`/`shift_assign` never
  pause, so the scheduler can draft), **approve or choose an exchange** (`exchange_approve`, `exchange_choose`: `other`),
  **approve time off** (`time_off_approve`: `other`), **change pay or a balance** (`wage_update`, `position_rate_update`,
  `balance_adjust`: `other`). A person doing the same is not paused. The approval policy matches the action's **log
  event**, so each of these has an event of its own (Phase 1 manifest).
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
| D13 | **Hours a day a time-off request counts is a per-restaurant setting** (default 8; no constant anywhere) | `site_settings.time_off_day_hours`; `ts_time_off_hours()` and the request trigger (db/007); `site_settings_save` |
| D14 | **An agent approving time off pauses for a person** (the approval category `other`) | `maludb-os.json` `approvals[]` (`time_off_approve`) |
| D15 | **Certifications are a screen of their own**: kinds per restaurant (name, expiry tracked or not, days of warning, the positions that need each), a person's cards with expiry, a manager's due list, a card the person enters is **verified by a manager**; the rules engine's `cert_required` reads the restaurant's kinds and required positions | db/006, db/009, db/013 (`mcp_certification_kinds`, `mcp_certifications`, `mcp_certifications_due`); slice 4 |
| D16 | **No accrual rules in version 1**: a balance changes only by a grant, an approval, a cancellation or an adjustment | db/007 `ts_time_off_post()` |
| D17 | **(The owner delegated.)** Trades keep their split actions — approve, decline, choose, accept, refuse — because each is a different right, log event and approval; and **`wage.update` is one event** for an employee's rate and a position's default, so one approval entry covers both | the manifest; `maludb-os.json` `approvals[]` |

Design choices of this document (the owner may overturn any): an unverified certification **counts** as held (a manager sees it "to verify"; a
missing or expired one is what the rule warns of); a person's card is entered per restaurant kind (the action adds it for each restaurant they work
at where the kind exists); a person editing a verified card clears its verification; a manager entering a card verifies it; rights are only ever per site; the first site a person
is granted at is their main restaurant; a coverage request needs no approval (a manager or shift lead made it);
`claim_mode` first-wins by default; an offer expires at the shift's start; the rules preset is "generic" (no law
behind it); shifts are at most 16 hours; the minor flag carries an end date, never a date of birth.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document; the schema db/001–013, proven; the kit; `maludb-os.json`, `os/`, `skills/`, `deploy/`; the installer's plan | owner's go |
| 1 | **written 2026-09-28:** `docs/txtschedules-mcp-tool-surface.md` (31 records tools + 6 activity tools + the `time_off_taken` share), `docs/txtschedules-action-manifest.md` (42 screens, 69 actions, 13 pausing for agents), `mcp/action_registry.json`, and eight specs in `docs/build-specs/` (`sso-shell` and seven slices) with **shifts-marketplace as the exemplar** | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, the ingest bridge, the nxl shell (phone-first, site switcher, command bar), `/api/v1/health`, the vhost; installed beside the kernel | hand-off replay refused; a scope not held refused; 375 and 1280 |
| 3 | slices: **(1) shifts and the marketplace** (the exemplar: my schedule, team schedule, the shift, offer/pick up/give/swap, approvals of trades, coverage) → **(2) the week builder** (weeks, drafts, templates, publish, warnings and overrides) → **(3) availability and time off** (balances, blackout, approvals) → **(4) people and positions** (profiles, main restaurant, wages, certifications) → **(5) labor and forecast** (budget, covers, ratios, needs; the K7 read) → **(6) announcements and notifications** (outbox, email, K6 SMS, reminders, calendar feed) → **(7) settings, rules and reports** | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 |
| 4 | the two MCP servers with the run-facts gate; `app_roles`; the registry on the kernel; skills imported; the expert and the scheduler proposed | the command bar answers "who is on Friday"; an agent's publish pauses |
| 5 | the kernel installer's `apply` (the owner's), DNS and proxy, sites and grants; the end-to-end proof | a staff member launches on a phone into their restaurant and trades a shift |

The installer's `plan` runs at the end of **every** phase (the lesson of 2026-09-28's ZozoCal install). Size as specified in Phase 1: fourteen migrations, **eight specs (sso-shell + seven slices), 42 screens, 69 actions
(13 pause for an agent), 31 records tools, 6 activity tools**; Projects (seven slices, 48 actions, 23 tools) took a day
and a half on the exemplar and workers — this is a little larger, and its slices are smaller.

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

### The Phase 1 decisions (the owner, 2026-09-28)

1. **Hours a day of time off — per restaurant (D13).** `site_settings.time_off_day_hours`, default 8, editable by the
   admin (`settings.manage`); a request that gives no hours counts, for each calendar day it touches in the restaurant's
   time zone, the hours it covers capped at that setting; stored with the request, so a change of the setting alters new
   requests only. The hard-coded 8 is gone from the schema, the specs and the tool surface.
2. **An agent approving time off pauses for a person — fine (D14).**
3. **The certifications screen — added (D15).** A restaurant's kinds (name, whether an expiry is tracked, days of
   warning, the positions that need it), a person's cards with expiry (add, edit, remove; a document upload is out of
   scope), a manager's "due" view (expired, due, missing, to verify), a phone screen for staff to see and add their own, a
   manager verifying. `cert_required` uses the restaurant's kinds and required positions, ignores expiry for a kind that
   does not track it, and names what is missing or expired. Tools: `certification_kinds`, `certifications`,
   `certifications_due` (replacing `expiring_certifications`); actions: `certification_kind_save`, `_archive`,
   `certification_add`, `_update`, `_remove`, `_verify`.
4. **No accrual rules — fine (D16).**
5. **Split trade actions and the shared `wage.update` event — kept (D17), the owner's "you decide".**

## 13. State

**Phase 3, slice 5 — labor and forecast: built and proven, 2026-09-29** (`docs/build-specs/labor-forecast.md`; proofs in `tests/phase3/slice5/`, `run.sh` on scratch `txtschedules_dev7`; see CLAUDE.md State for the counts).
**Built:** screens `forecast` (day-parts x seven days of typed / copied / Reservations covers with a source tag, phone day tabs, scheduled-of-recommended per day-part and position with gaps in danger, surplus muted and open shifts apart,
the staffing ratios, Copy last week, Fill from Reservations) and `budget` (hours and amount against scheduled per area and total, over-budget badge and danger bars, by day, the form per area for `settings.manage`); five handlers in
`html/labor/`; `app/features/labor/`; the builder gained Forecast and Budget links (its needs strip and cost row already existed and are unchanged). **No migration** (db/015 still the last). Registry: 69 actions, 59 built.

**Decisions taken (slice 5)** (the spec left these open; the conservative reading each time):
1. **`budget_save` needs `settings.manage` AND `labor.view`**: an amount is a cost figure, and a person who may not read cost may not open the screen that shows what they set. `ratio_save` needs `settings.manage` alone (a ratio is not pay); `forecast_*` need `schedule.build` (a planner without pay may type covers and fill).
2. **The grid never rewrites what it was not asked to.** A posted number equal to the stored one is skipped (its source stays), an empty box clears the cell, and the answer counts only what changed. A single-cell `forecast_save` is an explicit act: it always makes the cell `manual`, even at the same number (this pins a booked number against a later fill).
3. **A fill skips `manual` AND `copied` cells** unless `replace_manual` is yes (a copied cell is a person's work carried over); it touches only the days the answer names, sums two rows of one service and day, ignores rows dated outside the week, and matches a service to a day-part by `service_name` only, case-insensitively (no fall-back to the day-part's name).
4. **Copy** writes `copied` from `manual` or `copied` source cells, never from `reservations`, overwrites typed destinations, never overwrites a `reservations` destination, refuses copying a week onto itself (any date in it), and counts `cells`, `skipped_reservations` and `not_copied`.
5. **K7 degrades by refusal code**: `no_connection`, `not_at_location`, `not_shared`, `provider_failed` (also an unreachable kernel and any malformed answer, which is never half-used), `no_location` (a restaurant with `location_id` below 1; the button is hidden and the action refuses without asking). A browser is redirected back with a warning and the words (kept once in the session); JSON callers get 409 (state) or 503 (provider). Every attempt, refused or not, is logged `forecast.fill` with counts only — never the kernel's answer; a dump of the whole database holds none of it. The kernel is called BEFORE the transaction opens. The kernel's own `application.read` row is proven by the kernel (`bin/test_app_services.php`); here the stub records what was asked.
6. **Cost** is read only in `find_budget()` / `find_labor_by_day()` (through `mcp_labor_weekly`, `mcp_labor_budgets`, `mcp_shifts.cost`) and printed only by the budget screen and the builder (both already asked `labor.view`); no file of the slice names a wage column. Drafts count in scheduled hours and cost (as in the view); the page says overtime is not applied and an open shift costs nothing.
7. **A position with no effective ratio** (no N and no minimum) is not in the needs table; a ratio with only a minimum still calls for it whatever the covers.
8. **Log rows** are `forecast.update` (one a cell; entity `forecast_cover`), `forecast.copy`, `forecast.fill`, `ratio.update` (entity `position`), `budget.update` (entity `labor_budget`); each carries the site; a create-like reply's location ends in the record id (`#forecast-cover-<id>`, `#ratio-<position>`, `#budget-<id>`).
9. **Proofs**: the scratch setup never changes the installed roles' passwords (see CLAUDE.md).
**Owed:** the super-admin's approval of the Reservations connection (`php /var/www/bin/app_connection.php approve …` in the kernel); the notifications timer's daily fill of the next 14 days (slice 6); the MCP tools `labor_vs_budget` and `staffing_needs` (Phase 4).

**Phase 3, slice 4 — people, positions and certifications: built and proven, 2026-09-29** (`docs/build-specs/people-positions.md`; proofs in `tests/phase3/slice4/`, run by
`tests/phase3/slice4/run.sh` on the scratch database `txtschedules_dev6` — see CLAUDE.md State for the per-proof counts; plus the Phase 0 schema proof).
**Built:** the screens `staff-list` (cards, search, position and on-the-schedule filters, an expired-certification chip), `staff-view` (positions with pay only for whoever may see it, certifications with verify / add / correct /
remove, this week's hours against the limit, time-off balances, restaurants held), `staff-edit` (main restaurant, hours limit, minor with its end date, on/off the schedule, notes, positions with one main — no pay on it),
`positions-list` / `position-add` / `position-edit` (cards; the default rate and who it reaches for `labor.view`, its form for `pay.edit`), `certifications` (the due list — expired, missing, due, to verify — and the restaurant's kinds as
cards), `certification-kind-add` / `-edit`, `my-certifications`; eleven handlers (`html/staff/{save wage}`, `html/staff/certifications/{add update remove verify}`, `html/certifications/kinds/{save archive}`, `html/positions/{save rate archive}`);
`app/features/{staff,positions,certifications}/`. Staff, Positions, Certifications and My certifications left the placeholder list. **No migration** (db/015 is still the last). Registry: 69 actions, 54 built; 43 screens, 41 built.

**Decisions taken (slice 4)** (the spec left these open; the conservative reading each time):
1. **No schema change.** db/006, db/009, db/013 had everything; `tests/phase3/slice4/schema.php` proves the database's own refusals (one main position, a live position name, a negative rate, a minor without an end date, a kind of another restaurant).
2. **Files:** the certification functions live in `app/features/certifications/queries.php` (not the two the spec names) and the handlers' shared prelude is `app/features/staff/handler.php`.
3. **Pay, one rule everywhere:** a rate is printed or returned only where the SCREEN asked `has_right('labor.view', <the position's restaurant>)` (or it is the person's own effective rate — then that number alone, no source, no own-rate, no form). A NULL from a view is never turned into
   "no rate set" for someone who may not see rates. The list query names no wage column at all (proved by a grep of its source). No `did`, notice banner, JSON reply, `X-Action-Data` header, activity row or outbox body carries a number; `wage.update` says scope, member, position and `cleared`. Six files
   name a wage column and the registry proof lists them.
4. **A person's own page (`/staff/{their id}`)** is theirs to open without `schedule.build`: their positions with their own effective rate, cards (they may add, correct and remove their own), hours, balances, restaurants; no notes, no edit form, no pay form. Everyone else without `schedule.build` is 403; a restaurant nobody in common is 404.
5. **The pay form** appears on a position row for `pay.edit` at that restaurant; it is prefilled only when the viewer also holds `labor.view` (a `pay.edit` holder without it types blind, and an empty rate removes the override).
6. **The gate for a write about a person** is the restaurant among theirs the caller holds where the caller holds the right, the person's main restaurant first (`person_gate_site()`); nothing in common is 404, a shared restaurant without the right is 403. A main-restaurant change needs `schedule.build` at the NEW one and the person must work there (422 otherwise).
   Positions are replaced whole only at the restaurants the caller manages; positions at another restaurant, and each kept position's own rate, are left alone; the first position (or `primary`) is the main one, moved from another restaurant if need be (one main per person).
7. **Positions:** archive is refused while an upcoming scheduled shift (draft weeks included) uses it, and an archived one cannot be changed. A position accepts kinds by id or name; a kind of another restaurant is 422 (the database refuses it too). Names are unique among live positions (any case). `staff_save` accepts positions by id or name.
8. **Certifications:** a kind that tracks expiry needs an expiry date on a card (422 otherwise); a kind that does not may carry any date and never warns. A kind is unique by name among the restaurant's live kinds; its key is made from the name. Adding a card names a kind by id or by NAME and makes one card for each restaurant of the person's,
   held by the caller, where a kind of that name exists (and, for someone else's card, where the caller holds `schedule.build`); it is verified where the enterer holds `schedule.build` (so a manager entering their OWN card verifies it, as D15 says literally; Marco, manager at Downtown and staff at Airport, gets one card verified and one waiting).
   A person correcting a verified card clears the verification; a manager correcting one verifies it. Removing marks `removed_at` (there is no DELETE grant). Correcting one restaurant's twin card does not touch the other.
9. **The due list** hides an old card once the person holds a later or current card of the same kind (a renewal is not "expired"), and a card waiting to be verified once a later card of the kind is verified; "missing" ignores archived kinds; the position filter keeps people who WORK that position. The staff list's danger chip counts the same rows, so the two agree. The certification log carries ids and dates; only `certification.update` names the card's reference (the spec says so).
10. **Not built here:** the notification to a manager when a card enters its warning window (slice 6's timer reads `mcp_certifications_due`); the settings screen's `rule_save` (slice 7 — the hard/soft proof sets the rule's severity in the scratch database and asks the real assign handler); the kernel's pause of `wage_update`, `position_rate_update` and `certification_verify` (registered `other`; Phase 4 proves the pause itself).

**Phase 3, slice 3 — availability and time off: built and proven, 2026-09-29** (`docs/build-specs/availability-time-off.md`; proofs in `tests/phase3/slice3/`, run by
`tests/phase3/slice3/run.sh` on the scratch database `txtschedules_dev5` — see CLAUDE.md State for the per-proof counts; plus the Phase 0 schema proof 129 of 129).
**Built:** the screens `availability` (seven day cards, effective blocks apart from what waits, the add form on the page, a manager's person chooser), `time-off` (mine / Everyone / one person / who is off on a date, status chips,
pages), `time-off-add` (one form with the live preview line), `time-off-view` (the request, the shifts it covers, the balance before and after, decide / cancel, history), `balances` (balance cards with the ledger, the pay.edit adjust form)
and `time-off-types` (kinds as cards, blackout dates); thirteen handlers (`html/availability/{save remove approve decline}`, `html/time-off/{request approve decline cancel balance}`, `html/time-off/types/{save archive}`,
`html/time-off/blackout/{save remove}`); `app/features/{availability,timeoff}/`; Approvals gained time-off and availability cards (with `?kind=`), My requests gained time off and availability changes, the menu badge and the
home line count all three; Availability and Time off left the placeholder list. **No migration** (db/015 is still the last). Registry: 69 actions, 43 built; 43 screens, 35 built.

**Decisions taken (slice 3)** (the spec left these open; the conservative reading each time):
1. **No schema change.** db/007, db/009, db/013 had everything; `tests/phase3/slice3/schema.php` proves the database's own refusals and that no PHP writes a balance or the ledger except through `ts_time_off_post()`.
2. **The availability form is on the availability page** (`/availability?add=<weekday>`), not a page of its own — the spec lists no `form.php` for it. A create's location ends in the record id as an anchor
   (`…#availability-block-12`, `#ledger-9`, `#type-4`, `#blackout-3`); `time_off_request` lands on `/time-off/{id}`.
3. **A block with no restaurant** (`scope_id` NULL, "every restaurant I work at") is approved, declined and removed by someone who holds the right at ANY of the person's restaurants; it needs approval if ANY of them asks for it.
4. **Whole day = 00:00 to 00:00** (the engine reads an end at or before the start as the next day, so it is 24 hours). A block may run past midnight.
5. **A manager who may approve (`requests.approve`) and enters a block for another person** gets it approved at once (decided_by = the manager); anyone else's entry, and everyone's own, follows the restaurant's setting.
   **Nobody decides their own** availability or time off (403 "You cannot decide your own …"); another approver — the owner — does.
6. **Approving a block replaces** the older approved blocks of the same person, weekday and restaurant scope (NULL only against NULL) whose hours and dates it overlaps, whatever their kind. A block that takes effect LATER than
   today does not wipe the present: the older one is ended the day before. **Removing** a block sets it `replaced` (there is no deleted state and no DELETE grant); it stays in the record.
7. **Time off:** the restaurant is the kind's (a `site` that disagrees is 422); a request that overlaps one already pending or approved is refused; staff cannot ask for time already over or cancel time that is over (an approver can
   do both); the person's own cancel of an approved request tells the approvers; a manager's cancel tells the person. `hours` given are kept, empty are counted by the database (D13). A request entered for someone else
   by an approver is `pending` like any other (someone must still decide it; not the requester's own).
8. **"Also open those shifts"** (`open_shifts=yes`) opens the person's published, scheduled shifts at THE REQUEST'S restaurant that overlap the time and have not ended: assignee to nobody (the database stamps
   `changed_after_publish_at`), the shift's live trade is withdrawn, the old holder gets a `shift_changed` notice, one `shift.change` row each (`after.via = time_off`, `request_id`). Draft-week shifts are left to the builder's
   warning (the engine's hard `time_off` rule); shifts at another restaurant of the same person are not the approver's to open. `timeoff.approve` records `shifts_opened` as the list of shift ids.
9. **Balances:** a person reads their own; an approver reads their restaurant's staff (requests.approve at the type's restaurant); a builder without approve sees a request but no balance and cannot decide.
   `balance_adjust` needs `pay.edit` at the kind's restaurant, a kind that keeps a balance, a person who works there, a non-zero number of hours (≤ 2000) and a reason of 3–500 characters; the record id is the ledger row.
   The database's own sentence is used as is ("Not enough vacation / pto left: this needs 24.00 hours."; db/007 is not modified).
10. **Types and blackout dates** (`settings.manage`): a new kind's key is made from its name and numbered when taken; `allow_negative` needs `tracks_balance`; archiving is one way (no restore action in the manifest). A blackout
    date added later refuses NEW requests only; requests already asked stay decidable. `/site/time-off?site=&edit=&add=type` is one page (the spec's `html/site/time-off.php`).
11. **The settings-screen half of the hours-a-day proof** (an admin changes `time_off_day_hours` on `/site/`; a manager without `settings.manage` gets 403 there) belongs to the settings handler of slice 7 and is not built: this slice proves
    the database refuses 0, 25 and −1, that a change reaches new requests only, the cap, another restaurant's own setting and the preview line.
12. **Approvals** shows time off for `requests.approve` sites only (a shift lead's day-only rights show trades only); `?kind=exchange|time_off|availability`. `mcp_availability` shows a person's blocks to builders, so an approver
    reads pending blocks through `schedule.build` as well — both are the manager's rights in the shipped catalogue.
13. **Not proved here:** the kernel's pause of `time_off_approve` and `balance_adjust` (D14, registered `other`) — asserted in the registry; Phase 4 proves the pause itself. HR's `time_off_taken` share is Phase 4; the sending of notices, slice 6.

**Phase 3, slice 2 — the week builder: built and proven, 2026-09-29** (`docs/build-specs/week-builder.md`; proofs in `tests/phase3/slice2/`, run by
`tests/phase3/slice2/run.sh` on the scratch database `txtschedules_dev4` — schema 7 · draft 24 · rules 38 · published 46 · numbers 20 · templates 43 · autofill 22 ·
rights 23 · agents 15 · browser 55 = 293 checks, plus the Phase 0 schema proof 129 of 129). **Built:** the screens `builder` (the week as a grid, people or positions,
drafts, cancelled, open row, hours bars, cost and budget with labor.view, the staffing strip, the warnings; a phone gets day tabs and cards), `day-view` (SVG bars on an
hour axis, people on by hour), `shift-add` / `shift-edit` (one form; live check under the person; a draft posts shift_create/update, a published week shift_add/change), `templates-list`,
`template-view`, and `week-publish` (the summary page, added to the manifest); fifteen handlers (`html/weeks/{save copy autofill clear publish}`, `html/shifts/{save assign delete add change cancel}`,
`html/templates/{save apply archive}`) + `html/shifts/check.php` (the live check, a read); `app/features/{weeks,templates,autofill}/`, `app/features/shifts/write.php`;
SortableJS drag (`html/assets/js/builder.js`) posting the same actions as the buttons; the shift page gained Change / Move to… / Cancel shift / Delete for a builder;
Builder and Templates left the placeholder list. No migration (db/015 is still the last). Registry: 69 actions, 30 built; 43 screens, 31 built.

**Decisions taken (slice 2)** (the spec left these open; the conservative reading each time):
1. **No schema change.** Everything slice 2 needs was in db/008, 009, 013, 014; `tests/phase3/slice2/schema.php` proves the privileges and guards. `partial_update.php` no longer lists `/shifts/save.php`
   (a prefill from the base row would put UTC times into a field the wire reads as local); `shift_update` keeps an absent field itself.
2. **A create's location is the shift/template page** (`/shifts/{id}`, `/templates/{id}`), not the builder (the spec's `saved_go("/builder?...")`), so it ends in the record id; `week_create` has no page of its own: its
   location is `/builder?site=&week=<Monday>` and `record_id` carries the week id. Week actions (copy, template, auto-fill, publish, clear) land on the builder.
3. **Week actions take `week` (id) OR `site` + `week_start`** (any date in the week; the week is made as an empty draft when it does not exist). What a copy / template / auto-fill did (placed, left open with reasons,
   filled, still open, hours) is answered in the action's data and shown ONCE on the builder (session, not for JSON callers).
4. **Times on the wire:** `starts_at`/`ends_at` are site-local "2026-10-09 17:00" and an end before the start is refused; the form's `date`, `starts`, `ends` make an end at or before the start the next day; a lone `date` MOVES a
   shift keeping its times (what drag and Move to… post). Times go in 15-minute steps, breaks are 0/15/30/45/60, at most 16 hours, note ≤ 200. A shift stays in its own week (a move across a week is refused).
5. **Hard rules also stop a manager who gives a reason**; soft rules need `override_reason` (3–500 characters) and write one `rule_overrides` + one `rule.override` row each (context `build`, or `publish`). Hard sentences are
   prefixed with the person ("SMOKE Lee: A minor may not work past 22:00."); a person who does not work at the restaurant and a clash with the person's own shift (named) are hard too.
6. **`shift_assign` needs `schedule.build`** (a shift lead is 403): a lead fills gaps of the PUBLISHED schedule through coverage; a draft is the managers'. A published week's shift is never assigned by `shift_assign` (422, use shift_change).
7. **Publishing** is refused for an empty week, a week already published, and any HARD warning (rules can tighten after a shift was saved); soft warnings need one reason for all. Each person with an assigned shift gets one
   notice per channel (`publish:{week}:{member}`); open shifts tell nobody.
8. **Live changes:** `shift_change`/`shift_cancel` are refused on a shift that has ended; a change of time, position or person withdraws the shift's live trade (and logs `exchange.cancel`); the notified are the holder,
   the old and new holder on a reassignment, nobody else. `shift_cancel` needs a reason (3–500).
9. **Copy / template / auto-fill:** a person is left open (and listed) when they no longer work here, have approved time off then (whatever the rule's severity), already have a shift then, or a hard rule would break. Copying
   keeps LOCAL times across a clock change. Auto-fill order: preferred availability (overlap), then no soft warning, then fewest hours that week, then name, then id; excluded regardless of rule severity: time off and marked
   unavailable; pool = staff whose MAIN restaurant it is, active, holding the position. `template` given seeds the week from it first; an EMPTY week with none is seeded from the last published week before it.
10. **Drag:** SortableJS (copied from Projects' assets, loaded only when a draft grid is on the page); a published week's grid is not draggable (its changes are Change / Cancel, which confirm and tell staff);
    a drop between rows of the same day posts `shift_assign`, to another day `shift_update` (`date`, and `assignee` when the row changed); in the by-position view a block moves between days only.
11. **Live check** is an added read endpoint (`/shifts/check.php`, HTMX fragment) — the spec's live check needed one; its region keeps its wrapper so a quick second change is not lost; a hard rule disables Save (out of band).
12. **Staff rows** in the grid are everyone with a role at the restaurant on the schedule, plus anyone with a shift that week. The builder is for people (agents use the tools): an agent opening the screen is 403.
13. **Not proved here:** the kernel's pause of `week_publish` (external_send) and the live-change approvals (`other`) — registered in the registry and `maludb-os.json` and asserted there; Phase 4 proves the pause itself.
    The sender of the queued notices is slice 6. The proof's "manager without labor.view" is a scratch role `planner` (schedule.build without labor.view) — the shipped catalogue has none.
    Playwright's own click could not reach elements under the fixed command bar with scripts off, so the no-JavaScript proofs follow the link's href and press Enter in the form (the page itself scrolls fine).

**Phase 3, slice 1 — shifts and the marketplace: built and proven, 2026-09-29** (`docs/build-specs/shifts-marketplace.md`; proofs in `tests/phase3/slice1/`,
run by `tests/phase3/slice1/run.sh` on a scratch database `txtschedules_dev3`, against `php -S` and against a real Apache with the rendered deploy vhost
(`TS_APP=apache`) — 317 checks: schema 6 · world 3 · see 25 · trade 30 · race 15 · refuse 29 · approval 36 · giveswap 27 · choose 24 · coverage 30 ·
agents 14 · zones 8 · browser 70; plus the Phase 0 schema proof 129 of 129 and the claim race 4 of 4 on the new schema; Phase 2's 212 still pass).
**Built:** nine screens (`dashboard` filled, `my-schedule` week strip + list, `team-schedule`, `shift-view`, `marketplace` with its three tabs and the
`#marketplace-results` region, `exchange-view`, `approvals`, `coverage`, `my-requests`); thirteen handlers under `html/exchanges/` (`offer give swap open
coverage claim withdraw choose accept refuse approve decline cancel`), each `require_post` + login + CSRF + the right at the RECORD's site + one transaction +
`log_activity` with the site + `emit_action_status` + `saved_go`; `app/features/{shifts,exchanges}/` queries, presenters and `respond.php`; the outbox rows a step
queues (`notify()`; the sender is slice 6); the menu badge on Approvals; the JSON branch of every screen (whitelist presenters, no pay); the action registry
(69 actions, 15 built). 42 screens, 26 built. Sent nothing: the outbox only queues.
**Decisions taken** (the spec left these open or contradicted the schema; the conservative reading each time):
1. **db/015 (the only schema change, additive):** `ts_exchange_check_taker()` now refuses an overlap with the taker's own scheduled shift ("That overlaps your
   shift on Friday.") — db/010 caught two shifts at once only when a trade was APPLIED, so a claim could wait with a manager and fail there, and the
   marketplace had no sentence to show. A swap's own swapped-away shift does not count. Live database: apply the file by hand (`deploy/ROOT_STEPS.sh`).
2. **The reasons are the database's words**, not the spec's paraphrase: "On approved time off then." (the `time_off` rule's message), "Does not work this
   position here.", "Less than 10 hours between shifts." A food-handler card is a SOFT rule by the generic preset, so it never disables the button: the card says
   "A manager will look at this one: Certification: Food handler missing."
3. **A create's `location` is the new exchange** (`/exchanges/{id}`), not the shift (`saved_go("/shifts/$id")` in the spec was ambiguous with "ends in the record id");
   the shift page shows the live trade and its Withdraw too. A finished action lands with `?notice=<key>` (a whitelist: `notice_words()`), never request text.
4. **Handlers derive the site from the record** through one base-table read (`shift_site_id()`, `exchange_site_id()`); a site the person does not hold is
   "Shift not found." / "That trade is no longer available." (the same sentence as a missing record, JSON and HTML alike). So Marco (staff at Airport, main
   Downtown) cannot see an Airport offer (view) but a forced POST reaches the database and is 422 "You can pick up shifts only at your main restaurant."; the
   disabled-button screen is shown to whoever CAN see the offer without it being their main restaurant — an approver (the owner).
5. **Nobody decides a trade they are part of** (approve, decline, choose: 403 "You cannot decide a trade you are part of."), and a trade one is part of is not
   in one's own inbox. A shift lead's same-day rule: the EARLIEST shift of the trade starts today or tomorrow in the restaurant's zone, and the restaurant lets
   shift leads (`shift_lead_approves_same_day`); a lead therefore reaches `/approvals` (menu right `requests.approve|market.approve_day`; Phase 2's gates matrix
   changed for that one row: `/approvals` is now 200 for a shift lead).
6. **"Decline leaves the shift open"** = the shift does not move and the trade is `declined` (db/010 never re-lists it); an open shift stays unassigned.
7. **Outbox:** "two outbox rows" = two PEOPLE told (holder and taker), one row per channel each (email and text both on by default, D12) = four rows; a person's
   `notification_prefs.kinds` can turn a kind off. Other invitees of a coverage request are told "The shift is taken" when one wins. The taker is told even
   though they acted (the spec's table).
8. **Colleagues for a give or swap** are those whose MAIN restaurant is the shift's, on the schedule, holding its position (`mcp_members` + `mcp_staff_positions`; staff
   cannot read `mcp_staff`); enforced server-side too. A swap is chosen in two GET steps on the shift page (`?swap_with=`), so it works without JavaScript.
9. **A position's colour is an SVG `fill`** (the data is the restaurant's own, a checked `#rrggbb`), not a `style=` attribute; all new CSS is in `app-overrides.css`.
10. **The marketplace's "Up for grabs" leaves out** my own offers and trades I already claimed (they are in My requests and My claims). A trade's history shows the
    rows `mcp_activity_log` lets the caller see (their own; a builder's, all) — every `exchange.*` row carries `after.shift_id` so a shift's history finds them.
11. **Time off and the `time_off_requests` fixture** needed the database owner (the application role cannot write it until slice 3): the proofs use `admin_sql()` on the scratch database only.
12. **Handlers order the gates** `require_post` → `require_login` → `verify_csrf`, so an anonymous POST is 401 (JSON) or the launcher, not a bare 403.
**Not proved here:** `coverage_request` pausing for an agent in the kernel's approvals (Phase 4); the sender of the outbox, expiry (`ts_exchanges_expire()` + `exchange.expire`) and
the 30-second refresh over a full 30 seconds (the trigger is asserted and the event-driven refetch proved) are slice 6. The installer's plan (2026-09-29) shows txtschedules
already applied on the kernel (application 56): two steps left, both the owner's (`app_roles` read, the registry refresh — 15 built actions).

**Phase 2 — sign-on, the mirror, the shell: built and proven, 2026-09-28** (`docs/build-specs/sso-shell.md`; proofs in
`tests/phase2/`, run by `tests/phase2/run.sh`, on a scratch database — never the installed one). What exists: the
kit wired to the shell; `/sso` and `/sso/logout`; the mirror (members, sites = restaurants, `member_site_roles` from the claims
and the feed, the first restaurant a person holds their MAIN restaurant); `bin/directory_sync.php` (`--full`, `--from-file`, a
restaurant seeded per site); the guard on every page (the mirror row, the session list and the session's site re-checked
each request); the restaurant switcher; rights per site (`has_right`, `require_right`); the shell, phone first — a bottom
tab bar (Home, Schedule, Market, Requests, More) on a phone and the sidebar from 992 px, the command bar above the tab bar,
one menu table (`app/features/shell/nav.php`) that the sidebar, the tab bar and the placeholder screens all read; the home
screen's four empty states; `/activity`; `/settings/tokens/`; the command bar through the kernel's chat endpoint; the web app
manifest and placeholder icons (no service worker, no push); `/api/v1/health` (ingest lag, sync state, version from
`maludb-os.json`); the activity ingest bridge tagged `txtschedules` — **now shipping `scope_id`** (the copied bridge dropped
the site). **212 checks, all passing, against both `php -S` and a real Apache serving the rendered deploy vhost**: sign-on 51
(replay, audience, expiry, key, tampered claims, status, capability, the first sign-on creating the member and the main
restaurant, the site chosen / the only one / none held refused, the switcher, revocation ending sessions and the launcher on
the next request, sign-out notices, own sign-out); gates 48 (21 screens × 4 roles = 84 answers of 200 or 403, per site — Marco is
a manager at Downtown and staff at Airport —, the menu following the rights, CSRF, the tokens screen, the activity
trail's visibility, the command bar, action tokens and run tokens including an agent the kernel does not vouch for, health);
sync 28; ingest 12 (a fake MaluDB API: a 401 does not move the checkpoint); kernel compatibility 6 (the kernel's own
`mint_sso_token()`, `sign_sso_claims()`, `mint_sso_logout_notice()`, `mint_action_token()` and `mint_kernel_token()`
run from `/var/www/app/auth.php` under a key shim — what the kernel mints, txtSchedules accepts); the vhost 21 (URL rules;
nothing outside `html/` served); the browser 46 (headless Chromium at 375 × 740 and 1280 × 800: no sideways scroll, the tab
bar on a phone and the sidebar on a desktop, the bars thumb-reachable and at least 44 px, HTMX navigation pushing URL, title,
highlight and `data-screen`, no console errors, the dashboard whole with JavaScript off, the manifest installable, no
service worker). Also found and fixed on the way: the kit's shared `app-overrides.css` put the command bar under the last 20
px of the sidebar (260 px left against a 280 px sidebar).
**What this phase declares.** `maludb-os.json` (0.2.0) lists only what exists: the directory-sync and ingest timers, the Web UI
and Health endpoints. The two MCP servers (Phase 4) and the notifications worker (slice 6) come back into `services`, `endpoints`,
`env.required` and the vhost's `ProxyPass` lines when they are built — otherwise `bin/app_install.php apply` would stop at "an
endpoint is not answering" before it registered the application. Their unit templates stay in `deploy/`.
**Not proved here, and why**: the launcher's return trip (`?app=txtschedules`, kernel bc23820) and a token from the kernel's
own `sso_launch_url()` on the real application row need the installed application — the owner's `apply`; the kernel side of the
plan (catalog, the application row, scopes, grants) is the installer's. Small differences from the spec: the session key is
`scope_id` (not `site_id`); switching to a restaurant not held answers 403 (the spec's proof list) where a `?site=` read of
one not held would be `Not found.` (404); the placeholder screens' menu is the spec's plus Certifications and My certifications.
Nothing of slice 1 onward is built.

**The Phase 1 decisions applied, 2026-09-28.** Time off's hours a day is `site_settings.time_off_day_hours` (D13) and
certifications are the restaurant's own with a screen, a due list and a verifying manager (D15) — db/005–007, 009 and 013
edited in place (nothing deployed), `subello_txtschedules` dropped and recreated, all fourteen migrations re-applied
clean. The proof is **129 checks**, all passing (36 new: the setting drives days to hours, per restaurant, capped, for new
requests only, and refuses 0 and 25; kinds per restaurant and seeded, a kind unique in its restaurant, a position never
needing another restaurant's kind; missing, expired, current and untracked cards; hard or soft; an archived kind; who sees
which cards; the due list — expired, due, missing, to verify; the verify flow); the claim race **4 of 4**. The manifest is
now **42 screens and 69 actions** (`certifications`, `certification-kind-add`, `certification-kind-edit`,
`my-certifications`; `certification_update`, `_verify`, `certification_kind_save`, `_archive`), 12 pausing for agents
(`certification_verify` among them; `maludb-os.json` `approvals[]` is 12 entries — one log event covers a wage and a position default); the tool surface **31 records tools** (`certification_kinds`, `certifications`,
`certifications_due`) and 6 activity tools; the registry rebuilt, `--check` OK.

**Phase 1 — the checkpoint set, written 2026-09-28.** `docs/txtschedules-mcp-tool-surface.md` (29 records tools including `app_roles`, 6 activity tools, the `time_off_taken` share for Phase 4, the four gated functions), `docs/txtschedules-action-manifest.md` (38 screens, 65 actions), `mcp/action_registry.json` (built, `--check` OK, no unresolved endpoint, no phantom parameter), and `docs/build-specs/`: `sso-shell` (Phase 2), `shifts-marketplace` (**the exemplar**), `week-builder`, `availability-time-off`, `people-positions`, `labor-forecast`, `announcements-notifications`, `settings-rules-reports` — each with its screens, files, query functions, handlers, manifest entries, events and a proof list. Also: db/014 and one column (`day_parts.service_name`) from the review. **Awaiting the owner's checkpoint before any PHP.**

**Phase 1 schema additions (db/014, 2026-09-28)**, found in the tool-surface review as Projects' Phase 1 found its own:
`mcp_hours_weekly` (a person's scheduled paid hours per site and week against their limit and the overtime threshold),
`ts_coverage_candidates(shift)` (main-restaurant staff, free, no hard rule broken, fewest hours first, asked by
`coverage.fill`/`schedule.build` at the site), `ts_check_assignment(...)` and `ts_week_warnings(week)` (the rules engine
for the records role, the caller's right checked inside), `ts_staffing_needs` made a gated definer function, and a **leak closed in db/013**: a staff member could read a *draft*
shift assigned to them through `mcp_shifts` — a draft is the managers' until it is published. The proof is now **93
checks**, all passing (seventeen new: the draft rule, hours, coverage candidates, the rules and the forecast for the records role, and who may ask).

**Phase 0 answers applied 2026-09-28 (db edited in place — nothing was deployed — and re-proven).** The database
`subello_txtschedules` was dropped and recreated as postgres and db/001–013 applied clean again (no warnings). The proof
(`db/proof/phase0_proof.sql`) had **76 checks** after the answers (93 with db/014), all passing — the 57 of Phase 0 plus 19 for the answers: a default
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
