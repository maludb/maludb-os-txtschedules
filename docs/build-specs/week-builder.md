# Build spec: the week builder (slice 2)

Replicates slice 1's files, gates, flow and proof style (`shifts-marketplace.md`). Managers build a restaurant's week as a
DRAFT, see hours, cost, warnings and staffing needs as they go, and publish; after publishing, changes are stamped,
told to the people they touch, and go through separate actions an agent's call must pause on.
Schema: `schedule_weeks`, `shifts` (the no-overlap exclusion constraint, the published-shift guard trigger),
`schedule_templates`, `template_shifts` (db/008), `ts_publish_week()`, `ts_assignment_warnings()`, `ts_week_warnings()`
(db/009, db/014), `rule_overrides` (db/009), the views `mcp_schedule_weeks`, `mcp_shifts`, `mcp_templates`,
`mcp_template_shifts`, `mcp_hours_weekly`, `mcp_labor_weekly`, `mcp_rule_overrides`. Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `builder` | `/builder?site=&week=` | The week as a grid; drafts included; the only screen that publishes |
| `day-view` | `/builder/day?site=&date=` | One day by the hour (a time axis), for the floor |
| `shift-add` / `shift-edit` | `/shifts/new?site=&position=&date=&assignee=`, `/shifts/{id}/edit` | Add or change a shift (full page, no modal) |
| `templates-list` | `/templates/` | The restaurant's saved weeks |
| `template-view` | `/templates/{id}` | One template by weekday; **Start a week from it** |

## Builder
- Header: the restaurant, **‹ week ›**, the week's state badge (**Draft** / **Published Oct 2 · 3:14 pm**, or **Published — changed after** when `changed_after_publish_at` is set on any shift), and the buttons: **Copy last week**, **From a template…**, **Auto-fill**, **Save as template**, **Publish**.
- The grid (desktop ≥ 992 px): **rows = people** (or positions; a toggle `?view=people|positions`), **columns = the seven days**; a shift is a block (time, position colour, a ⚠ chip when it has warnings, a dashed outline when open/unassigned, struck through when cancelled). An **Open shifts** row holds unassigned shifts. Row end: the person's **hours this week** against their limit and the restaurant's overtime threshold (a bar; over → danger) from `mcp_hours_weekly`. Column foot: the day's scheduled hours; with `labor.view` also **cost** and, under the grid, the week's **labor against the budget** (`mcp_labor_weekly`); the **staffing needs** strip (recommended vs scheduled per day-part and position, gaps in danger) from `ts_staffing_needs()` when the site has a forecast (slice 5).
- **Drag** (SortableJS, `data-sortable`): a block dropped on another day/person cell POSTs the **same action the buttons post** — `shift_update` (time) or `shift_assign` (person); the drop springs back with the sentence when refused. Stretching = editing start/end on the shift's own page or inline (`shift_update`); **Move to…** (day + person selects) is the keyboard and phone path.
- **Phone (< 992 px)**: no grid — day tabs, one column of shifts (time, person, position, ⚠), **+ Add a shift** at the day's foot, **Move to…** on each shift; a manager builds on a tablet, but everything works on a phone.
- Warnings: `ts_week_warnings()` for the week; each shift's chip lists its sentences (soft: warning colour; hard: never present in a saved shift — the database and handlers refuse them).
- A published week shows the same grid; **Add** and **Change** and **Cancel** there call `shift_add`, `shift_change`, `shift_cancel` (the buttons say *"This is live: staff will be told."*, with confirm).

## Add / change a shift
| Field | Input | Required | Rule | id |
|---|---|---|---|---|
| site | hidden | yes | a site where the caller holds `schedule.build` | `shift-form-field-site` |
| position | select of the restaurant's positions | yes | not archived | `shift-form-field-position` |
| date | date | yes | in the site's zone | `shift-form-field-date` |
| starts / ends | time pickers | yes | 15-minute steps; an end at or before the start means the next day (overnight); at most 16 hours | `shift-form-field-starts`, `-ends` |
| break | select 0/15/30/45/60 | no | default 0 | `shift-form-field-break` |
| assignee | select of eligible staff (holds the position; a manager may pick anyone the position allows) + "Leave open" | no | live warnings shown as the person changes | `shift-form-field-assignee` |
| note | text | no | ≤ 200 | `shift-form-field-note` |
| override_reason | textarea, shown only when the chosen person/time gives a soft warning | when warned | a sentence; recorded with each rule | `shift-form-field-override-reason` |
- **Live check**: choosing the person and times calls `check_assignment` (`ts_check_assignment()`) and lists the sentences under the field; a hard one blocks Save with its sentence; a soft one asks for the reason. On a phone the same inline.
- A shift in a draft week saves through `shift_create` / `shift_update`; in a published week the form posts `shift_add` / `shift_change` (the page says which).

## Publishing
- **Publish** opens a full page: the week's summary (shifts, people told, open shifts left, hours, cost for `labor.view`), **every soft warning** with the person and rule, and a reason box when there are any; **Publish and tell staff** (`week_publish`, confirm) → `ts_publish_week()`; each affected person gets one notice (`schedule_published`, dedupe `publish:{week}:{member}`) listing their shifts; the warnings overridden are written as `rule.override` rows (one per warning, the same reason) before `week.publish`.
- A published shift is never deleted: **Cancel shift** (`shift_cancel`, reason) sets `status = cancelled`, cancels a live exchange on it, and tells the holder. A change to a published shift (`shift_change`) sets `changed_after_publish_at` (trigger) and queues `shift_changed` to the holder(s) affected — never to the whole restaurant.

## Templates and copying
- **Save as template** copies the week's shifts to `template_shifts` (weekday, times, position, assignee kept when chosen). **From a template** or **Copy last week** fills a **draft** week: each template shift lands on its weekday in the target week; one whose person would then overlap or break a hard rule is left **open** and listed in the result ("3 left open: Priya has time off Friday …"). Never onto a published week; never over an existing draft shift (added beside, reported).
- **Auto-fill** (`week_autofill`): for each **open** shift of a draft week — in order, earliest first — pick from the restaurant's main-restaurant staff who hold the position, are active, are free, break no hard rule, and are not on approved time off or marked unavailable; prefer **preferred** availability, avoid soft warnings when anyone else fits, then **fewest hours that week**, then name. It writes a DRAFT (`shift.assign` rows, `after.via = 'autofill'`) and answers a summary: filled, still open, warnings left, hours per person. Deterministic — the same week gives the same result.

## Files (exactly these)
- `html/builder.php` · `html/builder/day.php` · `html/templates/index.php` · `html/templates/view.php`
- `html/shifts/form.php` · `save.php` · `assign.php` · `delete.php` · `add.php` · `change.php` · `cancel.php`
- `html/weeks/save.php` · `copy.php` · `autofill.php` · `clear.php` · `publish.php` · `publish-confirm.php` (the summary page)
- `html/templates/save.php` · `apply.php` · `archive.php`
- `app/features/weeks/queries.php` · `present.php` — `app/features/shifts/write.php` (create/update/assign/add/change/cancel and the override recording) — `app/features/templates/queries.php` — `app/features/autofill/autofill.php`
- `app/views/builder/grid.php` · `day.php` · `partials/shift-block.php` · `hours-bar.php` · `needs-strip.php` · `publish.php` — `app/views/shifts/form.php` — `app/views/templates/list.php` · `view.php`

## Query functions (signatures fixed)
- `find_week(PDO, int $siteId, string $weekStart): ?array` · `ensure_week(PDO, int $siteId, string $weekStart, int $by): array` (creates the draft on first use) · `find_week_shifts(PDO, int $weekId): array` (mcp_shifts, drafts and cancelled included) · `week_hours(PDO, int $weekId): array` (mcp_hours_weekly) · `week_labor(PDO, int $siteId, string $weekStart): ?array` (mcp_labor_weekly, `labor.view` only) · `week_needs(PDO, int $siteId, string $from, string $to): array` (`ts_staffing_needs()`) · `week_warnings(PDO, int $weekId): array` (`ts_week_warnings()`)
- `check_assignment(PDO, int $memberId, int $siteId, int $positionId, string $startsUtc, string $endsUtc, int $break, ?int $ignoreShift = null): array`
- `create_shift(PDO, array $f, int $by): array` (draft weeks only; hard → refuse; soft → needs `override_reason`) · `update_shift(PDO, int $id, array $f, int $by): array` · `assign_shift(PDO, int $id, ?int $memberId, int $by, ?string $reason): array` · `delete_shift(PDO, int $id, int $by): bool` (draft only) · `add_live_shift(PDO, array $f, int $by): array` · `change_live_shift(PDO, int $id, array $f, int $by): array` · `cancel_live_shift(PDO, int $id, string $reason, int $by): array`
- `record_overrides(PDO, array $warnings, string $reason, ?int $shiftId, int $by): void` (`rule_overrides`; one `rule.override` log row each)
- `publish_week(PDO, int $weekId, int $by, ?string $reason): array` (`ts_publish_week()`; queues the notices; returns counts) · `clear_week(PDO, int $weekId, int $by): int` (draft shifts only)
- `find_templates(PDO, int $siteId): array` · `save_template(PDO, int $siteId, string $name, ?int $fromWeek, ?int $id, int $by): array` · `apply_template(PDO, int $templateId, int $weekId, int $by): array` (returns placed and left-open) · `copy_week(PDO, int $fromWeek, int $toWeek, int $by): array` · `autofill_week(PDO, int $weekId, ?int $templateId, int $by): array`

## Handlers (every one: `require_post(); verify_csrf();` `require_right('schedule.build', $site)` — the site from the week or shift — a transaction; `log_activity` with the site; `emit_action_status`)
- `weeks/save.php` (`week_create`): `ensure_week()`; log `week.create`. `weeks/copy.php` (`week_copy`) / `autofill.php` (`week_autofill`) / `clear.php` (`week_clear`): a **draft** week only (a published one → 422 *"This week is published."*); log `week.copy` (`after`: from_week, placed, left_open) / `week.autofill` (`after`: filled, open, warnings) / `week.clear` (`after`: removed).
- `weeks/publish.php` (`week_publish`): re-checks the warnings server-side; any soft warning without `override_reason` → 422; `record_overrides()`; `publish_week()`; log `week.publish` (`after`: week_id, shift_count, warnings[], overridden, people_told); an agent's call is paused by the kernel first (`external_send`).
- `shifts/save.php` (`shift_create`, `shift_update`): draft weeks only; `check_assignment()`; log `shift.create` (`after`: position_id, starts_at, ends_at, assignee_member_id, week_id) / `shift.update` (`before`/`after`: changed fields); overrides recorded; `saved_go("/builder?...")`; `HX-Trigger: shiftChanged`.
- `shifts/assign.php` (`shift_assign`): `schedule.build` or `coverage.fill`; a draft shift; log `shift.assign` (`before`/`after`: assignee_member_id and name).
- `shifts/delete.php` (`shift_delete`): a draft shift; log `shift.delete` (`before`: position, times, assignee).
- `shifts/add.php` (`shift_add`), `change.php` (`shift_change`), `cancel.php` (`shift_cancel`): a **published** week; log `shift.add` / `shift.change` (`before`/`after`, `after.notified`) / `shift.cancel` (`after`: reason, exchange_cancelled); queue `shift_changed` to the person(s) it touches (the old and the new holder on a reassignment); an agent's call is paused (`other`).
- `templates/save.php` (`template_save`), `apply.php` (`template_apply`), `archive.php` (`template_archive`): log `template.save` / `template.apply` (`after`: placed, left_open) / `template.archive`.

## Action manifest entries
- Screens: `builder`, `day-view`, `shift-add`, `shift-edit`, `templates-list`, `template-view`.
- Actions: `week_create`, `week_copy`, `week_autofill`, `week_clear`, `week_publish`, `shift_create`, `shift_update`, `shift_assign`, `shift_delete`, `shift_add`, `shift_change`, `shift_cancel`, `template_save`, `template_apply`, `template_archive`. Agent approvals: `week_publish` (`external_send`), `shift_add`, `shift_change`, `shift_cancel` (`other`); a draft's create/update/assign/delete never pause — the scheduling assistant drafts freely and cannot publish.

## Activity log events
- `screen.view` (builder with `after.week`, day-view, templates), `week.create`, `week.copy`, `week.autofill`, `week.clear`, `week.publish`, `shift.create`, `shift.update`, `shift.assign`, `shift.delete`, `shift.add`, `shift.change`, `shift.cancel`, `rule.override`, `template.save`, `template.apply`, `template.archive`. Every row carries `scope_id`; no row carries a wage or a cost.

## Status vocabulary
- Week: `draft` → secondary, `published` → success, published-and-changed → warning. Shift block: scheduled → its position's colour; open → dashed; cancelled → strikethrough muted; warning chip `feather-alert-triangle`. Hours bar: under 90 % of the threshold → primary; 90–100 % → warning; over → danger.

## Out of scope for this slice
- The forecast and budget screens and the numbers behind the strip and cost row (slice 5 — this slice shows them when present); positions, profiles and certifications screens (4); availability and time-off screens (3 — the builder already reads both as warnings); the sending of the notices queued (6); rules and settings screens (7).

## Proof (live as members, headless Chromium at 375 × 740 and 1280 × 800)
- **A draft is invisible to staff**: a staff member's my-schedule, team-schedule and marketplace show nothing of a draft week; a manager sees it. **Publish** makes it visible, tells each person once with their shifts, and writes `week.publish`.
- **The database and the handlers agree**: an overlapping shift for one person → 422 with the constraint's sentence; a hard rule (minor after 22:00, position not held) → 422 with its sentence and nothing saved; a soft rule → 422 until `override_reason` is sent, then saved with one `rule.override` row per warning; a shift over 16 hours or ending before it starts (same day, no overnight) → 422; an overnight shift 22:00–02:00 saves and shows on the right days in the restaurant's zone.
- **Published means published**: `shift_delete` on a published shift → 422; `shift_update` on one → 422 *"This week is published — use shift_change."*; `shift_change` stamps `changed_after_publish_at`, queues one notice for the holder and none for anyone else; `shift_cancel` cancels its live exchange and tells the holder; a reassignment tells both people.
- **Hours, cost, needs**: the row-end hours equal `mcp_hours_weekly`; the cost row and the budget bar appear only for `labor.view` (a manager without it sees hours and nothing else — and no page contains a wage); the staffing strip matches `ts_staffing_needs()`.
- **Templates and copy**: save a week as a template, apply it to a draft: every shift lands on its weekday; a person on time off that day is left open and listed; applying to a published week → 422; **Auto-fill** gives the same result twice, never breaks a hard rule, prefers *preferred* availability and fewest hours, and reports what it left open.
- **Drag and buttons agree** (real drag in headless Chromium): a drop posts `shift_update`/`shift_assign`; a refused drop springs back with the sentence; **Move to…** does the same without JavaScript drag; JavaScript off still renders the builder read-only.
- **Rights**: staff and shift lead → 403 on every builder URL and POST (a shift lead may `shift_assign` a draft only through coverage); a manager of Downtown → 404 on an Airport week; an action token for a member without `schedule.build` at the site → 403.
- **Agents**: the scheduler's action token creates and assigns draft shifts (rows `source = 'agent'`), and its `week_publish` is `pending_approval` in the kernel (Phase 4).
- **375 px and 1280 px**: no horizontal scroll; the phone builder is the day-tab list; the grid scrolls inside its card at 992–1280 with a visible scrollbar.

## Open questions
- (none)
