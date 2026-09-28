# txtSchedules — MCP tool surface

2026-09-28 · the read tools every question in `docs/txtschedules-design.md` §5 is answered by. Writes are never
here: they are the action tools the kernel's Actions MCP builds from `docs/txtschedules-action-manifest.md`.

Conventions (mcp-and-api.md): Python 3 + FastMCP, streamable HTTP at `/mcp`, loopback, one systemd unit each;
`db.py` and `server_common.py` copied from the kernel (already in `mcp/`); every query runs with `app.member_id` set
transaction-locally from the verified bearer, so the `mcp_*` views decide the rows — and the four gated functions
below decide theirs. Tool names are snake_case; every list tool takes `limit` (1–100, default 25); every tool
carries `readOnlyHint: true`, `openWorldHint: false`; results are JSON strings. **Times are answered as the site's
local time with the zone name and as UTC**; a person who works at two restaurants sees each shift in its own zone.

Gates are what the view or function already enforces — the tool only trims. **Everything is per site** (kernel scope
= restaurant): a tool without `site_id` answers across the sites the caller holds. Words used below:

| Word | Means |
|---|---|
| `held` | the caller holds a role at the site (`ts_held_scope_ids()`) — a published schedule, the team, the marketplace |
| `own` | the caller's own rows (their shifts once published, profile, availability, requests, balances, claims) |
| `build` | `schedule.build` at the site (manager or admin): drafts, templates, others' profiles, availability, requests |
| `approve` | `requests.approve` at the site |
| `lead` | `coverage.fill` (or `build`) at the site |
| `labor` | `labor.view` at the site: **pay** — a position's default rate, a person's own rate, the effective rate, a shift's cost, budgets |

**Pay is never trimmed after the fact** — the views and functions blank it by right (db/013, D10). Where a tool row says
"pay: labor", its pay fields are `null` for a caller without `labor.view` at that site, and a person always sees their
own **effective** rate. An agent is granted per site like a person, so an agent without `labor.view` cannot read a
wage through any tool, `records_search` included (the search reads the same views).

Two token shapes, one key: a person's own `mcp_` token (`mcp_resolve_token`), or the tenant's signed run token
(`ACTION_TOKEN_KEY`), for which the server asks the kernel's run-facts call once per run and offers exactly the tools
granted — fail closed, a run whose facts say `scopes: []` reads nothing scoped, and no tool acts when `is_eval`.

## Records server — `txtschedules_records_mcp` (role `txtschedules_records_ro`, `MCP_RECORDS_PORT`)

### Roles (kernel db/145, maludb-os-integration 0.5.0)
`app_roles` — no arguments — answers `os.app-roles/1`: txtSchedules' four roles (key, name, description, capability,
is_admin, rights[]) and its eleven rights, from `mcp_app_roles` (db/004). The kernel reads it with its own
60-second token, which reaches this tool and the shared tools of `maludb-os.json` `shares[]` only; any person or agent
may call it too.

### Restaurants, people, positions
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_sites` | Which restaurants the caller holds, with their time zone, week start, the caller's role there, and each site's trade switches. **Call before any action that needs a site** | 16 | `mcp_sites` | `q?`, `limit` | held |
| `find_staff` | Listing or looking up staff by name, restaurant, position, main restaurant, on or off the schedule. **Call before any action that needs a person** | 9 | `mcp_members`, `mcp_staff`, `mcp_staff_positions` | `q?`, `site_id?`, `position_id?`, `main_site_id?`, `on_schedule?`, `limit` | held (names of the site's staff); build (profiles) |
| `find_positions` | A restaurant's positions (server, host, line cook …) with their area and colour; the default hourly rate for `labor`. **Call before any action that needs a position** | 9 | `mcp_positions` | `site_id?`, `q?`, `include_archived?` | held; pay: labor |
| `staff_profile` | One person in full: main restaurant, positions (which is primary; each rate for `labor`, and the person's own effective rate for themself), maximum hours, minor flag and its end date, certifications (expiring marked), sites held with roles, the week's hours | 9 | `mcp_staff`, `mcp_staff_positions`, `mcp_certifications`, `mcp_member_site_roles`, `mcp_hours_weekly` | `member_id?` (default: the caller) | own; another: build at their main restaurant; pay: labor or own |
| `expiring_certifications` | Which certifications are expired or expire within N days, per person, so a manager can act before a shift is refused | 9 | `mcp_certifications`, `mcp_members` | `site_id?`, `within_days?` (default 30), `limit` | build |

### Schedule
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `who_is_on` | Who is working at a restaurant on a date, at a time, or right now — by position, with the day-part; open (unassigned) shifts listed apart | 1 | `mcp_shifts`, `mcp_sites`, `mcp_day_parts` | `site_id`, `date?` (default today), `at?` (a time or `now`), `position_id?` | held (published); build (drafts marked) |
| `my_shifts` | The caller's next shifts, or their week: when, where, position, who else is on, the exchange on it if any. A manager may ask for another person's | 2 | `mcp_shifts`, `mcp_exchanges` | `member_id?` (default: the caller), `from?`, `to?` (default: the next 14 days), `limit` | own (published only); another: build |
| `week_schedule` | A restaurant's week as the builder shows it: shifts by day and position, who, hours, open and cancelled, the week's state (draft or published) and the changes since publishing. Returns the `week_id` | 3 | `mcp_schedule_weeks`, `mcp_shifts`, `mcp_hours_weekly` | `site_id`, `week_start?` (any date in the week; default this week), `position_id?`, `assignee_member_id?`, `include_cancelled?` | held (published); build (drafts); cost: labor |
| `get_shift` | One shift in full: times in the site's zone, position, holder, note, its live exchange with its claims and warnings, the rule warnings if it were assigned as it is, whether it was changed after publishing | 3, 4, 6 | `mcp_shifts`, `mcp_exchanges`, `mcp_exchange_claims`, `ts_check_assignment()` | `shift_id` | as `mcp_shifts`; claims: the holder, the claimants, build |
| `find_templates` | The saved week templates of a restaurant, with their shift counts and what a template holds | 3 | `mcp_templates`, `mcp_template_shifts` | `site_id?`, `template_id?` | build |
| `marketplace` | Which shifts are up: offered, open, given to me, swaps waiting for me, coverage asked of me — and, for each, **whether the caller may take it and why not** (main restaurant, position, time off, overlap, a hard rule, the cutoff) | 4 | `mcp_exchanges`, `mcp_exchange_claims`, `mcp_shifts`, `ts_check_assignment()` | `site_id?`, `kind?` (offer, open, give, swap, coverage), `mine?`, `status?` (default open), `limit` | held; an offer shows only to the staff of its main restaurant (D4) |
| `coverage_candidates` | Who could cover this shift: main-restaurant staff, free, no hard rule broken, the soft warnings beside each, fewest hours that week first. Never someone whose main restaurant is elsewhere (D12) | 5 | `ts_coverage_candidates()` | `shift_id`, `limit` | lead |
| `check_assignment` | What the rules say about giving this person this shift — an existing shift, or one being planned (site, position, start, end, break) | 6 | `ts_check_assignment()`, `mcp_site_rules` | `member_id`, `shift_id?` or `site_id`, `position_id`, `starts_at`, `ends_at`, `break_minutes?` | own; another: build or lead |
| `week_warnings` | Every warning a week has — each shift against the rules, hard first — and, beside it, the overrides already recorded (who, why, when) | 7 | `ts_week_warnings()`, `mcp_rule_overrides` | `week_id` or `site_id` + `week_start`, `severity?` | build |
| `overrides` | Which warnings were overridden, by whom and why: over a period, for a person or a rule (the compliance report, FR-C5) | 7 | `mcp_rule_overrides`, `mcp_members` | `site_id?`, `member_id?`, `rule_key?`, `from?`, `to?`, `limit` | build |
| `hours_this_week` | A person's paid hours in a week, against their own limit and the restaurant's overtime threshold; or everyone's near overtime | 8 | `mcp_hours_weekly` | `site_id?`, `week_start?`, `member_id?`, `near_overtime?`, `limit` | own; others: build |

### Availability, time off, requests
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `availability` | A person's recurring availability (available, unavailable, preferred by weekday and time) and the changes waiting for approval; or who is unavailable at a time | 10 | `mcp_availability` | `member_id?` (default: the caller), `site_id?`, `weekday?`, `status?`, `at?` | own; others: build |
| `time_off` | Time off requested, approved or declined, by person, restaurant, status or period; who is off on a date. **Call before an action that needs a request** | 11 | `mcp_time_off_requests`, `mcp_time_off_types`, `mcp_blackout_dates` | `member_id?`, `site_id?`, `status?` (pending, approved, declined, cancelled), `on?`, `from?`, `to?`, `limit` | own; others: approve; the blackout dates: held |
| `time_off_balances` | A person's balance per time-off type in hours, and the ledger that made it (grants, approvals, cancellations, adjustments) | 11 | `mcp_time_off_balances`, `mcp_time_off_types` | `member_id?` (default: the caller), `site_id?`, `ledger?`, `limit` | own; others: approve |
| `my_requests` | The caller's own requests and what became of them: time off, availability, offers, swaps, gives, claims — by state | 11, 12 | `mcp_time_off_requests`, `mcp_availability`, `mcp_exchanges`, `mcp_exchange_claims` | `state?` (waiting, decided, all), `limit` | own |
| `pending_requests` | What waits for a manager at a restaurant: time off, availability changes, exchanges pending approval (each with its warnings), claims to choose among — oldest first | 12 | `mcp_time_off_requests`, `mcp_availability`, `mcp_exchanges`, `mcp_exchange_claims` | `site_id?`, `kind?` (time_off, availability, exchange), `limit` | approve; same-day exchanges: lead when the site lets shift leads approve |

### Labor, forecast, settings, talking
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `labor_vs_budget` | Scheduled hours and cost against the budget, by week and area, or by day for a week; each person's overtime cost is not applied (the report says so) | 14 | `mcp_labor_weekly`, `mcp_labor_budgets`, `mcp_shifts` | `site_id`, `week_start?`, `by?` (week or day), `area?` | labor |
| `staffing_needs` | The covers expected per day-part, the headcount they call for at the restaurant's ratios, the number scheduled and open shifts to fill — the gaps first | 15 | `ts_staffing_needs()`, `mcp_forecast_covers`, `mcp_staffing_ratios`, `mcp_day_parts` | `site_id`, `from?`, `to?` (default: the week) | build |
| `site_settings` | A restaurant's trade settings (which exchanges exist, which need a manager, the cutoff, claim mode, offer expiry), reminders, availability approval, overtime, its day-parts and time-off types | 16 | `mcp_sites`, `mcp_day_parts`, `mcp_time_off_types` | `site_id` | held (the trade rules affect everyone); the rest as the views |
| `site_rules` | The rules a restaurant runs — each rule's severity (hard, soft, off) and parameters — and the preset they came from; what each rule means | 16 | `mcp_site_rules`, `rule_kinds`, `rule_presets` | `site_id`, `rule_key?` | held |
| `announcements` | The live announcements for a restaurant, pinned first; for the poster, who has read each | 17 | `mcp_announcements` | `site_id?`, `mine?`, `limit` | held (the audience the caller belongs to); read counts: announce.post |
| `exchange_report` | Trades over a period: how many of each kind, how many taken, declined, expired; time to approval; who picks up most and who offers most; open shifts that went unfilled | 18 | `mcp_exchanges`, `mcp_shifts` | `site_id`, `from?`, `to?`, `group_by?` (kind, person, week) | build |
| `records_search` | The long tail: one SELECT over the views named in its docstring (`mcp_sites`, `mcp_members`, `mcp_member_site_roles`, `mcp_positions`, `mcp_staff`, `mcp_staff_positions`, `mcp_certifications`, `mcp_availability`, `mcp_time_off_types`, `mcp_time_off_balances`, `mcp_time_off_requests`, `mcp_blackout_dates`, `mcp_schedule_weeks`, `mcp_shifts`, `mcp_templates`, `mcp_template_shifts`, `mcp_exchanges`, `mcp_exchange_claims`, `mcp_site_rules`, `mcp_rule_overrides`, `mcp_labor_budgets`, `mcp_labor_weekly`, `mcp_hours_weekly`, `mcp_day_parts`, `mcp_forecast_covers`, `mcp_staffing_ratios`, `mcp_announcements`) | any | all views | `sql` | as the views; 5 s timeout; 200 rows |

**Not tools of the records server, on purpose:** the base tables (no privilege), `mcp_access_tokens` (the servers'
own), and any write.

### Shared with another application (Phase 4, kernel path)
`time_off_taken` — **declared in `maludb-os.json` `shares[]` as `{scoped: true, people: true}`; built in Phase 4.**
Called only by the kernel, with its own token, for HR (an application with `directory.writes`) over a
super-admin-approved connection (kernel `docs/build-specs/kernel-app-services.md`, K7 `people`). Arguments `from`, `to`,
`scope_id` (txtSchedules' own scope id for the restaurant, set by the kernel). Answer, keyed by the **kernel's member
id**: per person the approved time off in the period — type key and name, start, end, hours, days counted. **Never** a
reason, a note, a balance or a rate, and only people at that restaurant. It reads a `SECURITY DEFINER` function
`ts_time_off_taken(scope, from, to)` whose only caller is the records role on the kernel-token path (no `app.member_id`
is set on it); Phase 4 adds the function and its proof.

## Activity server — `txtschedules_activity_mcp` (role `txtschedules_activity_ro`, `MCP_ACTIVITY_PORT`)

| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `shift_history` | Everything that happened to one shift, oldest first: created, assigned, changed after publishing (before/after), offered, claimed, traded, cancelled — with who and when | 13 | `mcp_activity_log` (entity `shift`, and `exchange` rows for its exchanges) | `shift_id`, `action_prefix?`, `limit` | as the view: own; a site's: build |
| `exchange_history` | One trade's story: offered by whom, who claimed, accepted or refused, the warnings, who approved or declined and the note, expiry | 13 | `mcp_activity_log` (entity `exchange`) | `exchange_id`, `limit` | as the view |
| `who_did` | What one person or agent did in txtSchedules over a period | 19 | `mcp_activity_log` | `member_id?` (default: the caller), `period?`, `site_id?`, `action_prefix?` | own; another's: build at the site |
| `site_activity` | What happened at a restaurant: yesterday, this week, since a date; by kind of event (publishes, changes, trades, requests, settings) | 19 | `mcp_activity_log`, `mcp_sites` | `site_id`, `period?`, `action_prefix?`, `limit` | build |
| `draft_history` | What the scheduling assistant drafted for a week (shifts created and assigned by an agent, with the run) and what the manager changed before publishing | 20 | `mcp_activity_log` (`shift.*`, `week.*`, `source = 'agent'` and the manager's edits after) | `week_id` or `site_id` + `week_start` | build |
| `activity_search` | The long tail: one SELECT over `mcp_activity_log` (and `mcp_members`, `mcp_sites` for names) | any | those views | `sql` | as the view; 5 s; 200 rows |

## Question → tool map

1 `who_is_on` · 2 `my_shifts` · 3 `week_schedule` + `get_shift` + `find_templates` · 4 `marketplace` · 5 `coverage_candidates` ·
6 `check_assignment` + `get_shift` · 7 `week_warnings` + `overrides` · 8 `hours_this_week` · 9 `staff_profile` + `find_staff` +
`find_positions` + `expiring_certifications` · 10 `availability` · 11 `time_off` + `time_off_balances` + `my_requests` ·
12 `pending_requests` + `my_requests` · 13 `shift_history` + `exchange_history` · 14 `labor_vs_budget` · 15 `staffing_needs` ·
16 `site_settings` + `site_rules` + `find_sites` · 17 `announcements` · 18 `exchange_report` · 19 `who_did` + `site_activity` ·
20 `draft_history` · 21 `app_roles` + `records_search` + `activity_search`.

## What the log must carry for the activity tools (the manifest's log-payload rules)

Every row carries **`scope_id`** (the site). `shift.create` — `after.position_id`, `after.starts_at`, `after.ends_at`,
`after.assignee_member_id`, `after.week_id` · `shift.assign` — `before`/`after.assignee_member_id` (and names) ·
`shift.change` (a published shift) — `before`/`after` of the changed fields, `after.notified` · `shift.cancel` —
`after.reason`, `after.exchange_cancelled` · `week.publish` — `after.week_id`, `after.shift_count`, `after.warnings[]`
(rule and member), `after.overridden` · `rule.override` — `after.rule_key`, `after.member_id`, `after.shift_id`,
`after.reason` · `exchange.offer` / `.give` / `.swap` / `.open` / `.coverage` — `after.kind`, `after.shift_id`, `after.to_member_id`,
`after.swap_shift_id`, `after.invitees` (a count) · `exchange.claim` — `after.exchange_id`, `after.outcome`
(approved, pending_approval, claimed), `after.warnings[]` · `exchange.accept` / `.refuse` / `.approve` / `.decline` /
`.choose` / `.cancel` / `.withdraw` — `after.exchange_id`, `after.status`, `after.note` · `timeoff.request` — `after.type`, `after.starts_at`, `after.ends_at`, `after.hours` · `timeoff.approve`
— `after.hours`, `after.balance_hours` · `balance.adjust` — `after.type`, `after.delta_hours`, `after.reason` (**hours,
never pay**) · `wage.update` — `after.scope` (employee or position_default), `after.member_id`, `after.position_id`,
**never the rate** · `settings.update` — `before`/`after` of the changed fields (never a rate) · `announcement.post` —
`after.audience`, `after.recipients` (count).

## Entity resolution for the action tools (the registry's `resolve` block)

| Entity param | Tool | query param | id field | label field |
|---|---|---|---|---|
| `site` | `find_sites` | `q` (name) | `site_id` | `name` |
| `position` | `find_positions` | `q` (name) | `position_id` | `name` |
| `member`, `colleague`, `assignee`, `holder`, `members[]` | `find_staff` | `q` | `member_id` | `display_name` |
| `shift`, `swap_shift` | `week_schedule` (its shifts) | `q` (a person and a day: "Priya Friday") | `shift_id` | `label` |
| `exchange` | `marketplace` | `q` | `exchange_id` | `label` |
| `week` | `week_schedule` | `q` (a date) | `week_id` | `label` |
| `template` | `find_templates` | `q` (name) | `template_id` | `name` |
| `time_off_type` | `site_settings` (time-off types) | `q` | `type_id` | `name` |
| `day_part` | `site_settings` (day-parts) | `q` | `day_part_id` | `name` |
| `availability` | `availability` | `q` | `availability_id` | `label` |
| `blackout` | `time_off` (blackout dates) | `q` (a date) | `blackout_id` | `label` |
| `request` | `time_off` | `q` | `request_id` | `label` |
| `rule` | `site_rules` | `q` | `rule_key` | `name` |
| `announcement` | `announcements` | `q` | `announcement_id` | `title` |
| `certification` | `staff_profile` (the screen supplies the id) | — | `certification_id` | — |

`find_sites`, `find_staff`, `find_positions` and `find_templates` are the directory tools of this application (as HR's
`find_people`), on the records server — so an action's `colleague` or `position` resolves without leaving it. A shift or
an exchange is addressed by a person and a day when someone types ("Priya's Friday shift"), and by id in every table.

## The four gated functions (why the records role can reach the engine at all)

The records role has **no table privilege**; the views are its only door. Four `SECURITY DEFINER` functions are the
other, each checking the caller's right inside because it reads base tables as its owner: `ts_coverage_candidates`
(lead), `ts_check_assignment` (own, or build/lead), `ts_week_warnings` (build) and `ts_staffing_needs` (build) — db/011
and db/014, each proven for who may and may not ask (`db/proof/phase0_proof.sql`, 93 checks). `ts_effective_rate` is
**not** granted to the records role: the views inline the same `COALESCE(wage_override, default_wage_rate)`.
