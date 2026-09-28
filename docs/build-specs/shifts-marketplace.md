# Build spec: shifts and the marketplace — THE EXEMPLAR (slice 1)

Built by the planning-class model. Every later slice replicates its files, ids, gates, flow and proof style. This is the
brief's heart: staff see their shifts on a phone and give away, pick up, swap and give shifts within the restaurant's
own settings, with a manager in control where the restaurant wants one.
Schema: `shifts`, `schedule_weeks` (db/008), `exchanges`, `exchange_claims`, `exchange_invitees` and the
`ts_exchange_*()` functions (db/010), `ts_assignment_warnings()` / `ts_shift_warnings()` (db/009), `site_settings` (db/005),
`ts_coverage_candidates()` (db/014), the views `mcp_shifts`, `mcp_exchanges`, `mcp_exchange_claims`, `mcp_staff` (db/013).
Never modify them. **The database is the referee**: overlap, the main-restaurant rule, the cutoff, hard rules, one winner
are enforced in the functions; the handlers only translate a refusal (`SQLSTATE P0001`) into its sentence as a 422.

## Screens (phone first — 375 px is the design)

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `dashboard` | `/` | Home: my next shift, my week, what waits for me, announcements (this slice fills the first three) |
| `my-schedule` | `/my-schedule` | My published shifts, by week (a strip of seven day chips) or as a list |
| `team-schedule` | `/team-schedule` | The published week for my restaurant by day, grouped by position |
| `shift-view` | `/shifts/{id}` | One shift, and what I may do with it |
| `marketplace` | `/marketplace` | The shifts that are up, and whether I may take each |
| `exchange-view` | `/exchanges/{id}` | One trade in full (the claims and warnings for an approver) |
| `approvals` | `/approvals` | What waits for a manager (trades now; time off and availability arrive with slice 3) |
| `coverage` | `/coverage?shift=` | Cover a gap: the eligible list and asking several at once |
| `my-requests` | `/requests` | My offers, swaps, gives and claims, by state |

(The builder — creating, editing, publishing shifts — is slice 2; this slice reads published shifts and moves them
between people. A test fixture creates weeks and shifts through the proof's SQL, never a screen.)

## My schedule (phone)
- A week strip at the top: seven chips (Mon 5 … Sun 11), today outlined, a dot on each day I work; tap a chip to scroll to that day; **‹ ›** step a week (`?week=YYYY-MM-DD`). Below, one **card per shift** (`RecordCard` pattern): the day and time in the restaurant's zone (`Fri Oct 9 · 5:00–11:00 pm`), the position with its colour, the restaurant's name when I hold more than one, a status badge (**Offered**, **Waiting for Sam**, **Waiting for a manager**, **Swap asked**), and "Working with Sam, Ana +2" (names of the others on at that time — from `mcp_shifts` of the published week; never pay, phone or email). The whole card links to `shift-view`.
- Empty: "You have no shifts this week." with the next week's first shift if there is one. Only **published** shifts, only mine (db/013).
- Desktop (≥ 992 px): the same cards in a seven-column week grid.

## Team schedule
- Day tabs (Mon…Sun, today first) for the restaurant in the switcher; under a day, groups by position (colour bar); each row: time, name (mine highlighted), an **open** shift as a dashed row "Open · 11:00–3:00 — take it" linking to the marketplace. Published shifts only; no cost, no phone.
- `?site=` `?week=` `?day=` `?position=`; a person sees only sites they hold.

## Shift view
- The card: day, time and zone, position, restaurant, my role on it (holder or teammate), note, who else is on, **paid hours**.
- **What I may do** (buttons, each ≥ 44 px, shown only when the restaurant's setting allows it and I hold the shift): **Offer this shift** (`shift_offer`), **Give to a colleague…** (`shift_give`: a select of eligible colleagues — same restaurant, holding the position), **Swap with a colleague…** (`shift_swap`: pick the colleague, then one of their shifts). A live exchange replaces the buttons with its status card and **Withdraw** (`exchange_cancel`, confirm).
- A colleague's shift I could take: a link to the marketplace card. A manager (`schedule.build`) sees **Change** and **Cancel shift** (slice 2) and **Find cover** (→ `coverage?shift=`).
- **History** (the shift's rows from `mcp_activity_log`, newest first, in words: "Priya offered it", "Sam took it — no approval needed", "Marco approved the trade"); `data-screen="shift-view" data-entity="shift" data-record-id="{id}"`.
- A shift I may not see (another restaurant, or a draft) answers 404 "Shift not found." — the same sentence either way.

## Marketplace
- Tabs (a segmented control): **Up for grabs** (kind `offer` and `open`, status `open`, at my main restaurant) · **For me** (a `give` or `swap` waiting for my answer; a `coverage` I was invited to) · **My claims** (claims I made and their state).
- Each card: day/time, position, "Offered by Priya" or "Open shift", her note, "Closes Fri 3:00 pm" (`expires_at`), and **one button**: **Take it** when I may, else a muted, disabled line saying **why not** in the words the database gives (`ts_exchange_check_taker()` raises them): *"You can pick up shifts only at your main restaurant."* · *"You are on approved time off then."* · *"That overlaps your shift on Friday."* · *"Too close to the start of the shift to change hands."* · *"This needs a food handler card you do not hold."* A soft warning does not disable the button: the card says *"A manager will look at this one: it leaves you under 8 hours' rest."* and the claim goes to approval.
- **Take it** POSTs `shift_pickup`; the outcome is a banner: *"It's yours — added to your schedule."* (approved), *"Sent to a manager."* (pending approval), or *"Someone else already took this shift."* Where the restaurant's claim mode is `manager_chooses` the outcome is *"Your interest is noted — a manager chooses."*
- An offer shows only to the staff of its main restaurant (D4): the view excludes it for anyone else; the sentence "That trade is no longer available." covers every case a person may not open one. Region `#marketplace-results` (Pattern B) listens to `exchangeChanged`; refreshed every 30 s while the tab is visible.

## Exchange view and approvals
- `exchange-view`: the shift(s), from, to, kind, state, the **warnings** (soft rules with their sentences), the claims (approver only) and: for `pending_approval` **Approve** / **Decline** (with a note) for `requests.approve` — or `market.approve_day` when the shift starts today or tomorrow in the restaurant's zone and `shift_lead_approves_same_day`; for a `give`/`swap` waiting for me **Accept** / **Refuse**; for `manager_chooses` a **Give it to…** button per claimant (`exchange_choose`).
- `approvals`: cards, oldest first, for the restaurants where I hold `requests.approve` (or `market.approve_day` for same-day ones): the trade in one line, its warnings as chips, **Approve** / **Decline** inline. A count badge on the menu item. Time off and availability join it in slice 3.

## Coverage
- `coverage?shift=`: the shift at the top; the **eligible list** from `ts_coverage_candidates()` — main-restaurant staff, free, no hard rule broken, **fewest hours that week first**, each with the hours already and any soft warning as a chip; checkboxes; a note; **Ask these people** (`coverage_request`). The first invitee to accept wins (`ts_exchange_claim` in coverage mode); the others are told it is gone. Nobody outside the main restaurant is ever listed (D12).
- A shift with a holder who cannot come is covered by clearing the holder in slice 2 (`shift_change`, assignee empty) or by asking directly: `coverage_request` on an assigned shift replaces the holder when someone accepts.
- Available to `coverage.fill` (shift lead) and `schedule.build`.

## Notifications this slice queues (`notification_outbox`, the sending is slice 6)
| Step | Who is told | Kind |
|---|---|---|
| a `give`/`swap` is asked | the named colleague | `exchange` |
| a `coverage_request` | each invitee | `exchange` |
| a claim is approved at once | the holder ("Sam took your shift") and the taker | `exchange` |
| a claim needs a manager | the approvers at the site (`requests.approve`); the taker "sent to a manager" | `exchange` |
| accepted / refused | the holder | `exchange` |
| approved / declined / chosen | the holder and the taker | `request_decided` |
| an offer expires | the holder | `exchange` |
An offer that merely goes on the marketplace tells nobody (the marketplace is the notice). A text carries the facts
(day, time, restaurant, position) and a link — never another person's pay, phone or email.

## Files (exactly these)
- `html/index.php` (dashboard fill) · `html/my-schedule.php` · `html/team-schedule.php` · `html/marketplace.php` · `html/approvals.php` · `html/coverage.php` · `html/requests.php`
- `html/shifts/view.php` · `html/exchanges/view.php`
- `html/exchanges/offer.php` · `give.php` · `swap.php` · `open.php` · `coverage.php` · `claim.php` · `withdraw.php` · `choose.php` · `accept.php` · `refuse.php` · `approve.php` · `decline.php` · `cancel.php`
- `app/features/shifts/queries.php` · `present.php` — `app/features/exchanges/queries.php` · `present.php` · `respond.php` (the shared `exchange_outcome()` translation and the notification queueing)
- `app/views/schedule/my.php` · `team.php` · `partials/shift-card.php` · `week-strip.php` — `app/views/exchanges/marketplace.php` · `partials/exchange-card.php` · `view.php` · `approvals.php` · `coverage.php` · `requests.php`

## Query functions (signatures fixed)
- `find_my_shifts(PDO, int $memberId, string $from, string $to): array` (rows from `mcp_shifts`, published, mine; each with `others` — the names of who else is on)
- `find_team_schedule(PDO, int $siteId, string $weekStart, ?int $positionId = null): array` (published; no cost)
- `find_shift(PDO, int $id): ?array` (mcp_shifts + the live exchange from mcp_exchanges) · `shift_history(PDO, int $id, int $limit = 50): array` (mcp_activity_log)
- `find_marketplace(PDO, int $siteId, int $memberId, string $tab): array` (mcp_exchanges) · `marketplace_reason(PDO, int $exchangeId, int $memberId): ?string` (calls `ts_exchange_check_taker()` and returns the raised sentence, else null and the soft warnings)
- `find_exchange(PDO, int $id): ?array` · `exchange_claims(PDO, int $id): array` (approver only, via mcp_exchange_claims)
- `find_approvals(PDO, array $siteIds, int $memberId): array` · `find_my_requests(PDO, int $memberId, string $state): array`
- `find_coverage_candidates(PDO, int $shiftId): array` (`ts_coverage_candidates()`)
- `create_exchange(PDO, string $kind, int $shiftId, int $by, ?int $to = null, ?int $swapShift = null, ?string $note = null, array $invitees = []): int` (`ts_exchange_create()` then `exchange_invitees`; one transaction)
- `claim_exchange(PDO, int $id, int $memberId): string` · `choose_claim(PDO, int $id, int $memberId, int $by): string` · `accept_exchange(PDO, int $id, int $memberId, bool $accept): string` · `decide_exchange(PDO, int $id, bool $approve, int $by, ?string $note): string` · `cancel_exchange(PDO, int $id, int $by): string` · `withdraw_claim(PDO, int $id, int $memberId): void`
- `notify(PDO, int $memberId, int $siteId, string $kind, string $subject, string $body, ?string $reference = null, ?string $dedupe = null): void` (inserts `notification_outbox` rows for the person's channels — email and text per their preferences; the sender is slice 6)

## Handlers (every one: `require_post(); verify_csrf();` the gate at the shift's site; one transaction; `log_activity` with the site; `emit_action_status`)
- `offer.php` / `give.php` / `swap.php` (`shift_offer`, `shift_give`, `shift_swap`): `require_right('market.trade', $site)` — the site comes from the shift, never from the session; `create_exchange()`; the DB refuses a non-holder, a trade the restaurant turned off, the cutoff, a swap for a shift that is not the colleague's; log `exchange.offer|give|swap` (`after`: kind, shift_id, to_member_id, swap_shift_id); a give or swap queues the colleague's notice; `saved_go("/shifts/$id")`; `HX-Trigger: exchangeChanged`.
- `open.php` (`shift_open`): `require_right('coverage.fill'|'schedule.build')`; log `exchange.open`. `coverage.php` (`coverage_request`): the same gate; ≥ 1 invitee, each must be a candidate (`ts_coverage_candidates()` again, server-side); log `exchange.coverage` (`after`: shift_id, invitees = count); queues each invitee's notice.
- `claim.php` (`shift_pickup`): `require_right('market.trade', $site)`; `claim_exchange()` → `approved` | `pending_approval` | `claimed`; a P0001 refusal → 422 with its sentence; log `exchange.claim` (`after`: exchange_id, outcome, warnings[]); on `approved` also log **`shift.assign`** for the shift(s) that moved (`before`/`after`: assignee_member_id and name, `after.via = 'exchange'`); queue the notices of the table above.
- `withdraw.php` (`claim_withdraw`): own claim, status `pending` only; log `exchange.withdraw`.
- `choose.php` (`exchange_choose`): `requests.approve` (or `market.approve_day` for a same-day shift); log `exchange.choose` + `shift.assign`.
- `accept.php` / `refuse.php`: the named colleague only (the DB checks); log `exchange.accept` / `exchange.refuse`; on the settle to `approved`, `shift.assign`.
- `approve.php` / `decline.php`: `requests.approve`, or `market.approve_day` when the shift starts today or tomorrow in the restaurant's zone and the site lets shift leads approve same-day trades; log `exchange.approve` (+ `shift.assign`) / `exchange.decline` (`after`: exchange_id, status, note).
- `cancel.php` (`exchange_cancel`): the creator, or `schedule.build`/`requests.approve`; log `exchange.cancel`.
- **Expiry** is not a handler: the notifications timer (slice 6) calls `ts_exchanges_expire()` and logs `exchange.expire` (actor: the system) for each row, telling the holder.

## Action manifest entries
- Screens: `dashboard`, `my-schedule`, `team-schedule`, `shift-view`, `marketplace`, `exchange-view`, `approvals`, `coverage`, `my-requests`.
- Actions: `shift_offer`, `shift_give`, `shift_swap`, `shift_open`, `coverage_request`, `shift_pickup`, `claim_withdraw`, `exchange_choose`, `exchange_accept`, `exchange_refuse`, `exchange_approve`, `exchange_decline`, `exchange_cancel`. Agent approvals: `coverage_request` (`external_send`), `exchange_choose` and `exchange_approve` (`other`).

## Activity log events
- `screen.view` (each screen), `exchange.offer`, `exchange.give`, `exchange.swap`, `exchange.open`, `exchange.coverage`, `exchange.claim`, `exchange.withdraw`, `exchange.choose`, `exchange.accept`, `exchange.refuse`, `exchange.approve`, `exchange.decline`, `exchange.cancel`, `exchange.expire`, `shift.assign` (via an exchange). Every row carries `scope_id`; **no row carries a wage**.

## Status vocabulary
- Exchange: `open` → primary "Up for grabs" · `pending_acceptance` → info "Waiting for {name}" · `pending_approval` → warning "Waiting for a manager" · `approved` → success · `declined` / `cancelled` → secondary · `expired` → dark. Kinds: offer `feather-share`, open `feather-plus-circle`, give `feather-gift`, swap `feather-repeat`, coverage `feather-users`. A warning chip: soft `feather-alert-triangle` warning, hard (only ever shown as the reason a button is disabled) `feather-slash` danger.

## Out of scope for this slice
- Creating, editing, publishing and cancelling shifts (slice 2); availability and time-off screens and their part of the approvals inbox (3); positions and profiles (4); labor cost and forecast (5); the sending of notifications, reminders and the calendar feed (6); settings and rules screens (7 — this slice reads the restaurant's settings, and its fixtures set them with SQL).

## Proof (run live as members through kernel-minted hand-offs, curl for the races, and headless Chromium at 375 × 740 and 1280 × 800)
- **See only what is mine or published.** Priya (main Airport) sees her published shifts and no draft, no other restaurant's; the team schedule shows Airport only; a direct URL to a Downtown shift → 404 "Shift not found."; no page of the slice contains a wage (grep every rendered page for the fixture rates).
- **Offer → take.** Priya offers; it is on Sam's marketplace (main Airport) and **not** on Marco's (main Downtown) — his direct URL to the exchange → 404; Sam's **Take it** → *"It's yours"*, the shift is on Sam's schedule and off Priya's, one `exchange.claim` and one `shift.assign` row carrying the site, two outbox rows.
- **Exactly one wins.** Two sessions POST `shift_pickup` for the same offer at the same instant (curl in parallel): one `approved`, the other 422 *"Someone else already took this shift."*; one winning claim in the table.
- **The database refuses, the screen says why.** For Marco (main Downtown) the button is disabled with *"…only at your main restaurant."*, and a forced POST is 422 with the same sentence; the same for time off, an overlap, the cutoff (an offer within the restaurant's cutoff is refused at `shift_offer`), a hard rule (made hard by SQL) and a swap where the restaurant turned swaps off (`allow_swap = false`: the Swap button is absent and the POST 422).
- **Approval follows the settings.** With `approval_pickup = on_warning` a soft warning sends the claim to a manager (the card says so); the approver's inbox shows it with the warning; **Decline** leaves the shift open and tells the taker; **Approve** moves it. With `approval_give = always` an accepted give waits for a manager; with `never` it goes through. A shift lead approves a same-day trade and is refused a next-week one; a plain staff member is refused both.
- **Give and swap.** The named colleague accepts (or refuses); a swap checks both directions and refuses *"The swap back: …"* when the holder would break a hard rule.
- **Manager chooses.** With `claim_mode = manager_chooses` two claims are both `pending`; **Give it to…** picks one, the other is `lost`.
- **Coverage.** The list has Airport-main, free staff only, fewest hours first, never Marco; a non-invitee's forced claim → 422 *"This shift was offered to others."*; the first invitee to accept wins, the others are told it is gone; an agent's `coverage_request` is `pending_approval` (proved in Phase 4 against the kernel's approvals).
- **Agents and the action token.** An action token for Priya offers *her* shift and is refused (422) offering Sam's; `shift_pickup` through the Actions MCP behaves as the screen; every row `source = 'agent'` under a run token.
- **Time zones.** A Downtown (New York) and an Airport (Chicago) shift are each shown in their own zone with the zone name when a person holds both.
- **375 px and 1280 px:** no horizontal scroll on any screen of the slice; every button ≥ 44 px tall at 375; the week strip scrolls inside its card; the bottom tab bar reaches Home, Schedule, Marketplace, Requests; the marketplace re-fetches only `#marketplace-results`.
- **Rules the exemplar sets** for the later slices: query functions take PDO and explicit values; reads through `mcp_*` views (the marketplace's reason call is the one function call); `refuse()` for every refusal, the database's sentence passed through; `saved_go()` after a save; every form field id `{entity}-form-field-{name}`; the manifest's param name and the column name both accepted (`colleague` / `to_member_id`, `swap_shift` / `swap_shift_id`); **the site of a write is derived from the record (or the `site` parameter), never from the session**, and checked with `require_right($right, $site)`; every write logs the site; no page or log line carries pay.

## Open questions
- (none)
