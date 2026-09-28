# txtSchedules Action Manifest

2026-09-28 · **Phase 1, for the checkpoint**

> The registry the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve
> against. A screen or action missing here cannot be reached by voice or by an agent — unfinished design, like a
> question with no tool. Pairs with `db/` (tables), `docs/txtschedules-mcp-tool-surface.md` (read tools) and the slice
> specs in `docs/build-specs/`. Generated into `mcp/action_registry.json` by `bin/build_action_registry.php`; an action
> whose file does not exist yet is registered but not exposed.

## Conventions

### Screens
- **Ids** are kebab-case `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-txtschedules.conf`: `/{x}/new` → `form.php`, `/{x}/{id}/edit` →
  `form.php?id=`, `/{x}/{id}` → `view.php?id=`. A staff screen is designed at 375 px first; every screen is a page, never a modal.
- **Prefill params** become query-string values the GET controller reads. The **site** is the shell's current
  restaurant (the launcher's choice, then the switcher) and is carried as `?site=` where a URL is shared.
- **Every screen partial stamps** `data-screen`, `data-entity`, `data-record-id` on `#page-content`, so "that", "this
  shift" and "offer it" resolve against the page the user is on.

### Actions
- **Name** `{entity}_{verb}`; the **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with
  the site) and what the kernel's approval policies match — so an action an agent's call must pause on has **its own
  log event** (a draft edit `shift.update` never pauses; a live change `shift.change` does). `.delete` destroys a
  record; `.remove` detaches something; `.archive` keeps it out of the way. A published shift is never deleted: it is
  changed or cancelled.
- **Endpoints** are POST to `/{base}/{file}` with `require_post()`, `verify_csrf()` (or the relayed action token), the
  right **at the action's site**, and `log_activity()`. Handlers report through `emit_action_status()`; a create's
  `location` ends in the new record's id.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the
  last parameter). A param named for a record (`site`, `position`, `member`, `colleague`, `assignee`, `shift`,
  `swap_shift`, `exchange`, `week`, `template`, `time_off_type`, `request`, `availability`, `blackout`, `rule`,
  `announcement`, `certification`, `kind`) accepts an id or a name, resolved through txtSchedules' own tool (`docs/txtschedules-mcp-tool-surface.md`,
  resolution table). Times are **the site's local time** (`2026-10-09 17:00`); the handler stores UTC. `any field of X` =
  X's fields, all optional, a partial update. `override_reason` is required when the change breaks a soft rule: it is
  written as a `rule.override` row beside the action's own.
- **Who** is enforced in the endpoint, per site: `all` = anyone who holds a role at the site; `own` = the record's owner
  (a manager may act for a person where a row says so); `staff` = `market.trade` at the site — and, to pick a shift
  up, **the shift's site is the person's main restaurant** (D4); `lead` = `coverage.fill` (or `build`); `approve` =
  `requests.approve` (a shift lead for same-day exchanges when the restaurant lets them); `build` = `schedule.build`;
  `announce` = `announce.post`; `admin` = `settings.manage`; `pay` = `pay.edit`. A super-admin holds the admin role
  at every site (the kernel lists it). An agent is granted per site like a person.
- **Undo** is the inverse `undo_last` applies; — means it cannot be undone and the reply says so.
- **Confirm (✔)** = the command bar asks first (destructive, or it tells other people).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it: `other`,
  `external_send`. People are never paused. The categories are exactly `maludb-os.json` `approvals[]` — a week's
  publishing, a text or an announcement to staff, a change to a live shift, an approval, and anything that touches pay or a balance.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity).
- **Log payloads** (what `before`/`after` must carry, for the activity questions) are in
  `docs/txtschedules-mcp-tool-surface.md`; **a wage, a rate or an amount of pay is never in a payload** — `wage.update`
  says THAT a rate changed and for whom.

### Result contract
```json
{"status": "success", "did": "Offered Priya's Friday 5–11pm shift", "record_id": 412, "undo_id": "act_31", "refresh": "exchangeChanged"}
{"status": "pending_approval", "did": "Publishing the week of Oct 5 waits for Marco", "approval_request_id": 17}
{"status": "error", "message": "You can pick up shifts only at your main restaurant."}
```

## Home and settings

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `dashboard` | `/` | their home: the next shift with who else is on, the week, what waits for them, announcements |
| `settings` | `/settings/` | how they are told (email and text and which events) and their calendar link (params: `tab`) |
| `tokens` | `/settings/tokens/` | their own access tokens for the two MCP servers |
| `activity` | `/activity` | recent activity at a restaurant or one shift's or trade's history (params: `site`, `entity_type`, `entity_id`, `period`) |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | by_email (yes or no), by_sms (yes or no), kinds[] (the events to be told about), reminder_minutes (0 to 2880 or empty for the restaurant's) | restore prior | | | `prefs.save` | own |
| `calendar_feed_rotate` | `calendar-feed.php` | — | — (the old link is dead) | ✔ | | `calendar_feed.rotate` | own |
| `token_mint` | `tokens/mint.php` | **label**, scope (mcp) | revoke it | | | `token.mint` | own |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own |

## Shifts and the marketplace

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `my-schedule` | `/my-schedule` | their own shifts, by week or as a list, with who else is on (params: `week`, `view`) |
| `team-schedule` | `/team-schedule` | the published week for a restaurant by day and position (params: `site`, `week`, `day`, `position`) |
| `shift-view` | `/shifts/{id}` | one shift: when and where, who is on with them, its trade if any, its history, offer or swap or give it |
| `marketplace` | `/marketplace` | the shifts that are up — offered and open and given to them — and whether they may take each, with why not (params: `site`, `kind`) |
| `exchange-view` | `/exchanges/{id}` | one trade: the shift, the claims, the warnings, and to decide it |
| `approvals` | `/approvals` | what waits for a manager: trades, time off, availability, with each trade's warnings (params: `site`, `kind`) |
| `coverage` | `/coverage` | to cover a gap: who could take the shift, fewest hours first, and ask one or several (params: `shift`) |
| `my-requests` | `/requests` | their own requests and trades and what became of them (params: `state`) |

Actions (base `/exchanges/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `shift_offer` | `offer.php` | **shift**, note | exchange_cancel | | | `exchange.offer` | staff (the holder) |
| `shift_give` | `give.php` | **shift**, **colleague**, note | exchange_cancel | | | `exchange.give` | staff (the holder) |
| `shift_swap` | `swap.php` | **shift**, **colleague**, **swap_shift**, note | exchange_cancel | | | `exchange.swap` | staff (the holder) |
| `shift_open` | `open.php` | **shift** (an unassigned shift), note | exchange_cancel | | | `exchange.open` | lead |
| `coverage_request` | `coverage.php` | **shift**, **members[]** (the staff to ask), note | exchange_cancel | ✔ | external_send | `exchange.coverage` | lead |
| `shift_pickup` | `claim.php` | **exchange** | claim_withdraw | | | `exchange.claim` | staff (main restaurant) |
| `claim_withdraw` | `withdraw.php` | **exchange** | claim again | | | `exchange.withdraw` | own (the claimant) |
| `exchange_choose` | `choose.php` | **exchange**, **member** (the claimant to give it to) | — | ✔ | other | `exchange.choose` | approve |
| `exchange_accept` | `accept.php` | **exchange** | — | | | `exchange.accept` | own (the named colleague) |
| `exchange_refuse` | `refuse.php` | **exchange** | — | | | `exchange.refuse` | own (the named colleague) |
| `exchange_approve` | `approve.php` | **exchange**, note | — (the shift moves back only by a new trade) | | other | `exchange.approve` | approve |
| `exchange_decline` | `decline.php` | **exchange**, note | — | | | `exchange.decline` | approve |
| `exchange_cancel` | `cancel.php` | **exchange** | — | ✔ | | `exchange.cancel` | own (who made it) |

## The week builder

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `builder` | `/builder` | a restaurant's week as a grid: staff or positions down and days across, hours per person, labor against the budget, the warnings, the staffing needs (params: `site`, `week`, `view`, `position`) |
| `day-view` | `/builder/day` | one day by the hour for the floor (params: `site`, `date`) |
| `shift-add` | `/shifts/new` | to add a shift (params: `site`, `position`, `date`, `assignee`) |
| `shift-edit` | `/shifts/{id}/edit` | to change a shift's times or position or person |
| `templates-list` | `/templates/` | the saved week templates (params: `site`) |
| `template-view` | `/templates/{id}` | one template: its shifts by weekday, and start a week from it |

Actions (base `/weeks/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `week_create` | `save.php` | **site**, **week_start** (any date in the week) | — (an empty draft week costs nothing) | | | `week.create` | build |
| `week_copy` | `copy.php` | **week** (the draft week to fill), **from_week** | week_clear | | | `week.copy` | build |
| `week_autofill` | `autofill.php` | **week** (a draft week), template (empty uses the last published week) | week_clear | | | `week.autofill` | build |
| `week_clear` | `clear.php` | **week** (a draft week) | — | ✔ | | `week.clear` | build |
| `week_publish` | `publish.php` | **week**, override_reason (required when the week has warnings) | — (a published shift is changed or cancelled) | ✔ | external_send | `week.publish` | build |

Actions (base `/shifts/`) — the first four act on a **draft** week only; a published week's shifts go through `shift_add`, `shift_change` and `shift_cancel`:

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `shift_create` | `save.php` | **site**, **position**, **starts_at** (site local as 2026-10-09 17:00), **ends_at** (site local as 2026-10-09 23:00), break_minutes, assignee (empty leaves it open), note, override_reason | shift_delete | | | `shift.create` | build |
| `shift_update` | `save.php` | **shift**, any field of shift_create | restore prior | | | `shift.update` | build |
| `shift_assign` | `assign.php` | **shift**, assignee (empty leaves it open), override_reason | restore prior | | | `shift.assign` | build or lead |
| `shift_delete` | `delete.php` | **shift** | — | ✔ | | `shift.delete` | build |
| `shift_add` | `add.php` | **site**, **position**, **starts_at** (site local as 2026-10-09 17:00), **ends_at** (site local as 2026-10-09 23:00), break_minutes, assignee, note, override_reason | shift_cancel | ✔ | other | `shift.add` | build |
| `shift_change` | `change.php` | **shift**, position, starts_at, ends_at, break_minutes, assignee, note, override_reason | restore prior | ✔ | other | `shift.change` | build |
| `shift_cancel` | `cancel.php` | **shift**, **reason** | — | ✔ | other | `shift.cancel` | build |

Actions (base `/templates/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `template_save` | `save.php` | **site**, **name**, week (the week to copy the template from), template (to rename an existing one) | template_archive | | | `template.save` | build |
| `template_apply` | `apply.php` | **template**, **week** (a draft week) | week_clear | | | `template.apply` | build |
| `template_archive` | `archive.php` | **template** | restore | | | `template.archive` | build |

## Availability and time off

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `availability` | `/availability` | a person's weekly availability and what waits for approval (params: `member`, `site`) |
| `time-off` | `/time-off` | time off requested and approved and who is off (params: `member`, `site`, `status`, `from`, `to`) |
| `time-off-add` | `/time-off/new` | to request time off (params: `type`, `from`, `to`) |
| `time-off-view` | `/time-off/{id}` | one request and its decision |
| `balances` | `/time-off/balances` | balances per time-off type and the ledger behind them (params: `member`, `site`) |

Actions (base `/availability/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `availability_submit` | `save.php` | **weekday** (0 Sunday to 6 Saturday), **starts_at** (HH:MM), **ends_at** (HH:MM), **kind** (available or unavailable or preferred), site (empty means every restaurant they work at), effective_from, effective_to, member | availability_remove | | | `availability.submit` | own (a manager for anyone) |
| `availability_remove` | `remove.php` | **availability** | resubmit it | ✔ | | `availability.remove` | own or build |
| `availability_approve` | `approve.php` | **availability**, note | — | | | `availability.approve` | approve |
| `availability_decline` | `decline.php` | **availability**, note | — | | | `availability.decline` | approve |

Actions (base `/time-off/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `time_off_request` | `request.php` | **time_off_type**, **starts_at** (site local as 2026-10-09 09:00), **ends_at** (site local as 2026-10-11 17:00), hours (empty counts each day at the restaurant's hours per day), note, member, site | time_off_cancel | | | `timeoff.request` | own (a manager for anyone) |
| `time_off_approve` | `approve.php` | **request**, note | time_off_cancel | | other | `timeoff.approve` | approve |
| `time_off_decline` | `decline.php` | **request**, note | — | | | `timeoff.decline` | approve |
| `time_off_cancel` | `cancel.php` | **request** | — | ✔ | | `timeoff.cancel` | own or approve |
| `balance_adjust` | `balance.php` | **member**, **time_off_type**, **delta_hours** (positive grants and negative removes), **reason** | the opposite adjustment | ✔ | other | `balance.adjust` | pay |
| `time_off_type_save` | `types/save.php` | **site**, **name**, paid (yes or no), tracks_balance (yes or no), allow_negative (yes or no), sort_order, time_off_type (to change an existing one) | restore prior | | | `timeoff_type.save` | admin |
| `time_off_type_archive` | `types/archive.php` | **time_off_type** | restore | | | `timeoff_type.archive` | admin |
| `blackout_save` | `blackout/save.php` | **site**, **on_date**, **reason** | blackout_remove | | | `blackout.save` | admin |
| `blackout_remove` | `blackout/remove.php` | **blackout** | add it again | ✔ | | `blackout.remove` | admin |

## People and positions

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `staff-list` | `/staff/` | the staff as cards with position and main restaurant (params: `site`, `q`, `position`) |
| `staff-view` | `/staff/{id}` | one person: positions and certifications and hours and time off; pay for whoever may see it |
| `staff-edit` | `/staff/{id}/edit` | to change a person's main restaurant or limits or positions |
| `positions-list` | `/positions/` | a restaurant's positions with their default rate for whoever may see it (params: `site`) |
| `position-add` | `/positions/new` | to add a position (params: `site`) |
| `position-edit` | `/positions/{id}/edit` | to change a position |
| `certifications` | `/certifications/` | a restaurant's certification kinds and who is expired or due or missing one or has one to verify (params: `site`, `state`, `position`) |
| `certification-kind-add` | `/certifications/kinds/new` | to add a certification kind to a restaurant (params: `site`) |
| `certification-kind-edit` | `/certifications/kinds/{id}/edit` | to change a certification kind: its name and expiry and warning days and the positions that need it |
| `my-certifications` | `/certifications/mine` | their own certifications on the phone — see them and add one and correct one |

Actions (base `/staff/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `staff_save` | `save.php` | **member**, main_site (the restaurant they may pick up shifts at), max_hours_week, is_minor (yes or no), minor_until (a date), active (yes or no), notes, positions[] (position names and the first is primary) | restore prior | | | `staff.save` | build |
| `wage_update` | `wage.php` | **member**, **position**, rate (the person's own hourly rate and empty removes it so the position's default applies) | restore prior | ✔ | other | `wage.update` | pay |
| `certification_add` | `certifications/add.php` | **kind** (one of the restaurant's kinds such as Food handler), member (empty is the caller), issued_on, expires_on, reference | certification_remove | | | `certification.add` | own (a manager for anyone) |
| `certification_update` | `certifications/update.php` | **certification**, any of issued_on, expires_on, reference | restore prior | | | `certification.update` | own (a manager for anyone) |
| `certification_remove` | `certifications/remove.php` | **certification** | — | ✔ | | `certification.remove` | own (a manager for anyone) |
| `certification_verify` | `certifications/verify.php` | **certification**, verified (yes or no) | the opposite | | other | `certification.verify` | build |

Actions (base `/certifications/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `certification_kind_save` | `kinds/save.php` | **site**, **name**, track_expiry (yes or no), warn_days (0 to 365), positions[] (the positions that need it), kind (to change an existing one) | restore prior | | | `certification_kind.save` | admin |
| `certification_kind_archive` | `kinds/archive.php` | **kind** | restore | ✔ | | `certification_kind.archive` | admin |

Actions (base `/positions/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `position_save` | `save.php` | **site**, **name**, area (front or kitchen or bar or management or other), color, sort_order, certifications[] (the kinds the position needs), position (to change an existing one) | restore prior | | | `position.save` | admin |
| `position_rate_update` | `rate.php` | **position**, rate (the hourly default for everyone at the position without a rate of their own and empty clears it) | restore prior | ✔ | other | `wage.update` | pay |
| `position_archive` | `archive.php` | **position** | restore | ✔ | | `position.archive` | admin |

## Labor and forecast

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `forecast` | `/forecast` | the covers expected per day-part and the staffing ratios, with the headcount they call for (params: `site`, `week`) |
| `budget` | `/budget` | the weekly labor budget against what is scheduled (params: `site`, `week`) |

Actions (base `/labor/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `budget_save` | `budget.php` | **site**, **week_start**, area (all or front or kitchen or bar or management or other), budget_hours, budget_amount | restore prior | | | `budget.update` | admin |
| `forecast_save` | `forecast.php` | **site**, **on_date**, **day_part**, **expected_covers** | restore prior | | | `forecast.update` | build |
| `forecast_copy` | `forecast-copy.php` | **site**, **from_week**, **to_week** | restore prior | | | `forecast.copy` | build |
| `forecast_fill` | `forecast-fill.php` | **site**, **week_start**, replace_manual (yes or no and no is the default) | restore prior | | | `forecast.fill` | build |
| `ratio_save` | `ratio.php` | **site**, **position**, covers_per_staff (one person per this many covers), min_staff | restore prior | | | `ratio.update` | admin |

## Announcements

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `announcements-list` | `/announcements/` | the live announcements for a restaurant (params: `site`) |
| `announcement-add` | `/announcements/new` | to post an announcement (params: `site`, `audience`) |

Actions (base `/announcements/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `announcement_post` | `save.php` | **site**, **title**, **body**, audience (site or position or people), position, members[], pinned_until (a date) | announcement_remove | ✔ | external_send | `announcement.post` | announce |
| `announcement_remove` | `remove.php` | **announcement** | — | ✔ | | `announcement.remove` | announce or admin |
| `announcement_read` | `read.php` | **announcement** | — | | | `announcement.read` | own |

## Restaurant settings, rules and reports

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `site-settings` | `/site/` | a restaurant's week and trade settings and reminders and time off hours per day and overtime (params: `site`) |
| `day-parts` | `/site/day-parts` | the restaurant's day-parts (lunch and dinner) (params: `site`) |
| `time-off-types` | `/site/time-off` | the time-off types and the blackout dates (params: `site`) |
| `rules` | `/rules/` | the rules the restaurant runs and each one's severity (params: `site`) |
| `reports` | `/reports/` | hours and labor against budget and open shifts unfilled and trades and overtime and overrides — each as a table and a CSV (params: `report`, `site`, `from`, `to`, `format`) |

Actions (base `/site/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `site_settings_save` | `save.php` | **site**, week_start (0 Sunday to 6 Saturday), currency, allow_offer (yes or no), allow_pickup (yes or no), allow_swap (yes or no), allow_give (yes or no), approval_pickup (always or on_warning or never), approval_swap (always or on_warning or never), approval_give (always or on_warning or never), cutoff_minutes (0 to 10080), shift_lead_approves_same_day (yes or no), claim_mode (first or manager_chooses), offer_expires (at_start or at_cutoff), availability_needs_approval (yes or no), reminder_minutes_before (0 to 2880), time_off_day_hours (what a day off counts when a request gives no hours from 0.25 to 24), overtime_weekly_hours, overtime_multiplier | restore prior | | | `settings.update` | admin |
| `day_part_save` | `day-part.php` | **site**, **name**, **starts_at** (HH:MM), **ends_at** (HH:MM), service_name (the word Reservations uses for this service), sort_order, day_part (to change an existing one) | restore prior | | | `day_part.save` | admin |
| `day_part_archive` | `day-part-archive.php` | **day_part** | restore | ✔ | | `day_part.archive` | admin |

Actions (base `/rules/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `rule_save` | `save.php` | **site**, **rule**, **severity** (hard or soft or off), params (the rule's values such as hours or minutes) | restore prior | | | `rule.update` | admin |
| `rule_preset_apply` | `preset.php` | **site**, **preset** (generic) | restore prior | ✔ | | `rule.preset` | admin |

## What is not an action

Reading a screen (`screen.view`), a report export (a GET with `format=csv`, logged `report.export`), notifications
(sent by the notifications timer: `notification.send`), an offer that runs out (the timer: `exchange.expire`), the
directory sync (`directory.sync`), and sign-on (`member.sign_on`) are events, not actions: nobody asks for them.
