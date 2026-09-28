# Build spec: availability and time off (slice 3)

Replicates slice 1's files, gates, flow and proof style. **txtSchedules owns time off** for the staff it schedules and
replaces HR's leave desk for them (D5): types per restaurant, balances in hours, requests, a ledger, blackout dates —
and recurring availability with an optional approval.
Schema: `availability_rules`, `time_off_types`, `time_off_balances`, `time_off_ledger`, `time_off_requests`,
`blackout_dates`, `ts_time_off_post()`, `ts_time_off_decide()`, `ts_time_off_cancel()`, the request-check trigger (db/007);
the views `mcp_availability`, `mcp_time_off_types`, `mcp_time_off_balances`, `mcp_time_off_requests`, `mcp_blackout_dates`
(db/013); the rules `time_off` and `unavailable` of the engine (db/009) already read both. Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `availability` | `/availability?member=&site=` | My weekly availability (a manager: anyone's at their restaurants) and what waits for approval |
| `time-off` | `/time-off?member=&site=&status=&from=&to=` | Requests — mine (staff) or the restaurant's (approver); who is off on a date |
| `time-off-add` | `/time-off/new?type=&from=&to=` | Request time off |
| `time-off-view` | `/time-off/{id}` | One request, its decision and the shifts it touches |
| `balances` | `/time-off/balances?member=&site=` | Balances per type and the ledger behind them |
| `time-off-types` | `/site/time-off?site=` | The restaurant's types and blackout dates (admin) |
The **approvals** inbox (slice 1) gains time-off and availability cards from this slice.

## Availability (phone first)
- A seven-day list (Sun…Sat) of blocks, each **Available / Unavailable / Preferred** with a time range (a whole day when empty); **+ Add** per day opens the form (`availability_submit`): weekday, from, to, kind, **effective from** (default today), optional end, and the restaurant (default every restaurant I work at). Blocks past midnight allowed (ends before it starts).
- **Approval** follows the restaurant's `availability_needs_approval`: pending blocks show *"Waiting for a manager"* and count for nothing until approved; when the setting is off they are approved at once. Approving a block **replaces** older approved blocks of the same person, weekday and restaurant that it overlaps (they become `replaced`); the screen shows the effective set and the pending changes apart.
- A manager sees any person's availability at the restaurants where they hold `schedule.build`, enters it for them (`member`), and approves/declines pending blocks (`availability_approve` / `availability_decline`, with a note) from the approvals inbox.

## Time off
- **Request** (`time_off_request`): type (the restaurant's active types: vacation/PTO, sick, unpaid, other by default), from and to (date, or date+time for part days) in the restaurant's zone, **hours** (empty counts **the restaurant's hours per day for each day the request touches** — `site_settings.time_off_day_hours`, default 8, the owner's D13; a part day counts only what it covers, capped at that; the hours are worked out by the database — `ts_time_off_hours()` and the request trigger — and stored, so changing the setting later changes new requests only), a note. The database refuses a request touching a **blackout date** (*"No time off on that date: Valentine's Day."*) or a type not offered; a type that tracks a balance shows *"Balance 16 h — this uses 8 h."* (the second figure from that setting — 6 h at a restaurant whose day is 6) and a request over the balance is allowed to be *asked* but is refused at approval unless the type `allow_negative`.
- **The approver's card** shows the person, dates, hours, balance before and after, and **the scheduled shifts the request would cover** (each with day and time). **Approve** (`time_off_approve`, note) draws the balance down through `ts_time_off_post()`; the checkbox **Also open those shifts** turns each covered published shift's assignee off (`shift.change` rows, `after.via = 'time_off'`, the old holder told) so the gap shows in the builder and the coverage tools. **Decline** (`time_off_decline`) needs no balance. Both tell the person (`request_decided`).
- **Cancel** (`time_off_cancel`): the person (pending or approved) or an approver; an approved one gives its hours back (`request_cancelled` in the ledger).
- **Who is off**: `/time-off?on=DATE` lists approved time off across the restaurant that day (for approvers; staff see only their own).

## Balances
- Per person and type: the balance in hours and the ledger (grant, request approved, cancelled, adjustment — date, hours, who, note). Staff see their own; an approver sees a restaurant's staff; **only `pay.edit` adjusts** (`balance_adjust`: member, type, **delta hours** — positive is recorded as a `grant`, negative as an `adjustment` — and a reason; confirm; an agent's call is paused, `other`). There are no accrual rules in version 1: a balance moves only by a grant, an adjustment, an approval or a cancellation.

## Types and blackout dates (admin, `settings.manage`)
- `time-off-types`: types as cards (name, paid, tracks a balance, allow negative), add/edit/archive (`time_off_type_save`, `time_off_type_archive` — an archived type stays on old requests and leaves the picker); blackout dates as a list with add/remove (`blackout_save`, `blackout_remove`, confirm).

## Files (exactly these)
- `html/availability.php` · `html/availability/save.php` · `remove.php` · `approve.php` · `decline.php`
- `html/time-off/index.php` · `form.php` · `view.php` · `balances.php` · `request.php` · `approve.php` · `decline.php` · `cancel.php` · `balance.php` · `html/time-off/types/save.php` · `archive.php` · `html/time-off/blackout/save.php` · `remove.php` · `html/site/time-off.php`
- `app/features/availability/queries.php` · `present.php` — `app/features/timeoff/queries.php` · `present.php` · `respond.php`
- `app/views/availability/index.php` · `partials/block.php` — `app/views/timeoff/index.php` · `form.php` · `view.php` · `balances.php` · `types.php` · `partials/request-card.php`

## Query functions (signatures fixed)
- `find_availability(PDO, int $memberId, ?int $siteId = null, ?string $status = null): array` · `submit_availability(PDO, array $f, int $by): array` (approved at once when the site does not need approval; else `pending`) · `remove_availability(PDO, int $id, int $by): bool` · `decide_availability(PDO, int $id, bool $approve, int $by, ?string $note): string` (approval marks overlapping older blocks `replaced`)
- `find_time_off(PDO, array $filters, int $page = 1, int $per = 50): array` · `find_time_off_request(PDO, int $id): ?array` · `request_shifts(PDO, int $requestId): array` (published scheduled shifts the request covers) · `find_balances(PDO, int $memberId, ?int $siteId = null): array` · `balance_ledger(PDO, int $memberId, int $typeId, int $limit = 50): array`
- `create_time_off(PDO, array $f, int $by): array` (inserts; the trigger refuses blackout and unoffered types) · `decide_time_off(PDO, int $id, bool $approve, int $by, ?string $note, bool $openShifts = false): array` (`ts_time_off_decide()`; optionally opens the shifts) · `cancel_time_off(PDO, int $id, int $by): string` · `adjust_balance(PDO, int $memberId, int $typeId, float $deltaHours, string $reason, int $by): float` (`ts_time_off_post()`)
- `save_time_off_type(PDO, int $siteId, ?int $id, array $f, int $by): array` · `archive_time_off_type(PDO, int $id, int $by): void` · `save_blackout(PDO, int $siteId, string $date, string $reason, int $by): array` · `remove_blackout(PDO, int $id, int $by): void`

## Handlers (every one: `require_post(); verify_csrf();` the gate at the record's site; one transaction; `log_activity` with the site; `emit_action_status`)
- `availability/save.php` (`availability_submit`): own (`availability.edit`), or `schedule.build` at the site for another person; log `availability.submit` (`after`: weekday, times, kind, status). `remove.php`: own or `schedule.build`; log `availability.remove`. `approve.php` / `decline.php`: `requests.approve`; log `availability.approve` / `availability.decline`; tell the person.
- `time-off/request.php` (`time_off_request`): own (`availability.edit`), or an approver for another person; log `timeoff.request` (`after`: type, starts_at, ends_at, hours); queue the approvers' notice (kind `request_decided`, subject "Time off to approve"; the person's notice of the decision is the same kind).
- `time-off/approve.php` (`time_off_approve`) / `decline.php` (`time_off_decline`): `requests.approve` at the request's site; log `timeoff.approve` (`after`: hours, balance_hours, shifts_opened) / `timeoff.decline`; a P0001 (*"Not enough …"*) → 422. An agent's `time_off_approve` is paused (`other`).
- `time-off/cancel.php` (`time_off_cancel`): the person or an approver; log `timeoff.cancel`.
- `time-off/balance.php` (`balance_adjust`): `pay.edit`; log `balance.adjust` (`after`: type, delta_hours, reason — **hours, never pay**).
- `types/save.php` / `archive.php`, `blackout/save.php` / `remove.php`: `settings.manage`; log `timeoff_type.save` / `.archive`, `blackout.save` / `.remove`.

## Action manifest entries
- Screens: `availability`, `time-off`, `time-off-add`, `time-off-view`, `balances`, `time-off-types`.
- Actions: `availability_submit`, `availability_remove`, `availability_approve`, `availability_decline`, `time_off_request`, `time_off_approve`, `time_off_decline`, `time_off_cancel`, `balance_adjust`, `time_off_type_save`, `time_off_type_archive`, `blackout_save`, `blackout_remove`. Agent approvals: `time_off_approve`, `balance_adjust` (`other`).

## Activity log events
- `screen.view`, `availability.submit`, `availability.remove`, `availability.approve`, `availability.decline`, `timeoff.request`, `timeoff.approve`, `timeoff.decline`, `timeoff.cancel`, `balance.adjust`, `timeoff_type.save`, `timeoff_type.archive`, `blackout.save`, `blackout.remove`; `shift.change` (`via: time_off`) when approving opens shifts. Every row carries `scope_id`.

## Out of scope for this slice
- Accrual rules and bulk grants; telling HR what was taken (the kernel's `people` share, Phase 4); the sending of notices (6); the settings screens other than `time-off-types` (7).

## Proof (live as members, headless Chromium at 375 × 740 and 1280 × 800)
- **Availability**: Priya submits *Unavailable Fri 5–11 pm*; with approval required it is `pending` and the builder still schedules her (a soft `unavailable` only once approved); a manager approves it — then it warns; a second approved block over it replaces the first; with `availability_needs_approval` off it is approved at once; Sam cannot read or change Priya's availability; a manager at another restaurant → 404.
- **Time off**: a request on a blackout date → 422 with the reason; a type the restaurant does not offer → 422; approval of 8 h from a 16 h balance leaves 8 and writes one ledger row; cancelling gives 16 back (3 ledger rows); 24 h with 16 left → refused at approval with *"Not enough …"*; a type with `allow_negative` goes through; **Approve + Also open those shifts** turns the covered shifts open, stamps them changed, tells the old holder, and the builder shows the gap; the request card lists exactly the covered published shifts.
- **Who sees what**: Sam and a manager of another restaurant see nothing of Priya's request or balance (404); an approver at her restaurant does; only `pay.edit` adjusts a balance (a manager without it → 403), and an agent's `balance_adjust` is `pending_approval` (Phase 4).
- **Hours a day (D13)**: at a restaurant whose setting is 8 a two-day request with no hours counts 16 h and a 09:00–13:00 request 4 h; the admin changes the setting to 6 on `/site/` → a new one-day request counts 6 h, the earlier 16 h request is unchanged, a 12-hour day is capped at 6; another restaurant still counts 8; the setting refuses 0 and 25 with the sentence; the form's preview line (*"This uses 6 h."*) follows the setting; a manager without `settings.manage` cannot change it (403).
- **Time zones**: a request entered in the restaurant's zone (New York vs Chicago) stores the right UTC range and shows back in the restaurant's zone.
- **375 px and 1280 px**: no horizontal scroll; the day list and the request form are one column on a phone; every button ≥ 44 px; the approver's card is readable on a phone.

## Open questions
- None from the owner's answers. (Decided: hours a day is the restaurant's setting, D13; no accrual rules, D16 — a balance moves only by a grant, an approval, a cancellation or an adjustment.)
