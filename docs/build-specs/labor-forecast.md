# Build spec: labor and forecast (slice 5)

Replicates slice 1's files, gates, flow and proof style. The weekly labor **budget**, the **forecast** (covers expected per
day-part — typed, copied, or **filled from Reservations' booked covers through the kernel, K7**) and the **staffing
ratios** that turn covers into a recommended headcount beside the scheduled one (D9).
Schema: `labor_budgets`, `forecast_covers` (`source` manual | copied | reservations), `staffing_ratios`, `day_parts`
(`service_name`), `ts_staffing_needs()` (db/011, gated), the views `mcp_labor_budgets`, `mcp_labor_weekly`,
`mcp_forecast_covers`, `mcp_staffing_ratios`, `mcp_day_parts` (db/013). Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `forecast` | `/forecast?site=&week=` | Covers per day-part for the week, the ratios, and the headcount they call for beside what is scheduled |
| `budget` | `/budget?site=&week=` | The weekly labor budget against scheduled hours and cost |

## Forecast
- A grid: **rows = the restaurant's day-parts** (Lunch, Dinner …), **columns = the seven days**; each cell an **expected covers** number (typed inline: `forecast_save`), with a small tag for its source (*typed*, *copied*, *Reservations*). Under it, per day-part and position, **recommended vs scheduled** (`ts_staffing_needs()`): the recommended headcount is `max(min_staff, ceil(covers ÷ covers_per_staff))`; scheduled counts assigned shifts overlapping the day-part; gaps in danger, surplus muted; **open shifts** counted separately.
- **Copy last week** (`forecast_copy`: from a week to a week; typed values overwrite typed, never Reservations'). **Fill from Reservations** (`forecast_fill`, below).
- **Ratios** (`ratio_save`, `settings.manage`): per position, *one person per N covers* and a minimum; empty ratio = no recommendation for that position.
- Phone: a day-tab list of the day-parts with the number field and the recommendation lines.

## Reservations' covers through the kernel (K7)
- `forecast_fill` (and a daily pass of the notifications timer, slice 6, for the next 14 days) calls the kernel: `POST {OS_INTERNAL_URL}/api/v1/apps/read.php` with the application token, `{"provider": "reservations", "tool": "covers_by_service", "arguments": {"from", "to"}, "location_id": <sites.location_id>}`; the answer is rows `{date, service, reservations, covers}`. Each row lands in the day-part whose **`service_name`** matches (case-insensitive; `Lunch` → Lunch); covers for a service no day-part names are listed on the page (*"Brunch — 42 covers not mapped — set a service name on a day-part"*) and are never dropped silently.
- It writes `forecast_covers` with `source = 'reservations'` for the days returned, **skipping cells a person typed** unless `replace_manual` is yes; a Reservations number is a *forecast of booked covers*, not walk-ins — the page says so, and a person can overtype it (the cell becomes `manual`).
- **Degrades, never fails**: `no_connection` → *"Reservations is not connected — ask a super-admin to approve the connection in the operating system"* and the manual forecast stands; `not_at_location` → *"Reservations does not serve this restaurant"*; `provider_failed` → the sentence and a retry; the button is hidden when the restaurant's site has no `location_id`. Nothing about the kernel's answer is stored except the covers.
- `maludb-os.json` `reads[]` already names `reservations.covers_by_service`; the installer proposes the connection; a super-admin approves it (`bin/app_connection.php`).

## Budget
- Per week and area (`all`, front, kitchen, bar, management, other): **budget hours** and/or **budget amount** (`budget_save`, `settings.manage`). The screen shows, from `mcp_labor_weekly`, scheduled hours and **scheduled cost** against each budget as bars (over → danger), by area and total, and by day for the selected week. **Cost is hours × the effective rate** (the person's own, else the position's default; an open shift costs nothing until someone holds it; overtime multipliers are not applied — the page says so). **Only `labor.view`** sees any of it; without it the screen is 403 and the builder's cost row is absent.

## Files (exactly these)
- `html/forecast.php` · `html/budget.php` · `html/labor/forecast.php` · `forecast-copy.php` · `forecast-fill.php` · `ratio.php` · `budget.php`
- `app/features/labor/queries.php` · `present.php` · `reservations.php` (the kernel read and the service → day-part mapping)
- `app/views/labor/forecast.php` · `budget.php` · `partials/needs-table.php` · `budget-bars.php`

## Query functions (signatures fixed)
- `find_forecast(PDO, int $siteId, string $from, string $to): array` (mcp_forecast_covers + mcp_day_parts) · `find_needs(PDO, int $siteId, string $from, string $to): array` (`ts_staffing_needs()`) · `find_ratios(PDO, int $siteId): array`
- `save_forecast(PDO, int $siteId, string $date, int $dayPartId, int $covers, int $by): array` (`source = 'manual'`) · `copy_forecast(PDO, int $siteId, string $fromWeek, string $toWeek, int $by): array` · `save_ratio(PDO, int $siteId, int $positionId, ?float $coversPerStaff, int $minStaff, int $by): array`
- `fetch_reservation_covers(int $locationId, string $from, string $to): array` (the kernel call; returns rows or a refusal code) · `fill_forecast(PDO, int $siteId, string $weekStart, bool $replaceManual, int $by): array` (maps services to day-parts, writes, returns written / skipped / unmapped / refusal)
- `find_budget(PDO, int $siteId, string $weekStart): array` (mcp_labor_weekly + mcp_labor_budgets) · `save_budget(PDO, int $siteId, string $weekStart, string $area, ?float $hours, ?float $amount, int $by): array` · `find_labor_by_day(PDO, int $siteId, string $weekStart): array` (mcp_shifts cost by day, `labor.view` only)

## Handlers (every one: `require_post(); verify_csrf();` the gate at the site; one transaction; `log_activity` with the site; `emit_action_status`)
- `labor/forecast.php` (`forecast_save`): `schedule.build`; log `forecast.update` (`before`/`after`: covers, source). `forecast-copy.php` (`forecast_copy`): log `forecast.copy` (`after`: from_week, to_week, cells). `forecast-fill.php` (`forecast_fill`): log `forecast.fill` (`after`: written, skipped, unmapped, refusal).
- `labor/ratio.php` (`ratio_save`): `settings.manage`; log `ratio.update` (`before`/`after`: covers_per_staff, min_staff). `labor/budget.php` (`budget_save`): `settings.manage`; log `budget.update` (`before`/`after`: area, hours, amount — a budget is a plan, not a wage).

## Action manifest entries
- Screens: `forecast`, `budget`.
- Actions: `forecast_save`, `forecast_copy`, `forecast_fill`, `ratio_save`, `budget_save`. No agent approvals.

## Activity log events
- `screen.view`, `forecast.update`, `forecast.copy`, `forecast.fill`, `ratio.update`, `budget.update`. Every row carries `scope_id`.

## Out of scope for this slice
- POS sales and any AI forecast; overtime cost; per-person labor targets.

## Proof (live as members, headless Chromium at 375 × 740 and 1280 × 800)
- **Forecast**: 80 covers Friday dinner with *one server per 25* and a minimum of 1 → **4** recommended; with 3 scheduled the gap shows; a position with no ratio shows none; copying a week copies typed cells and not Reservations' ones; overtyping a Reservations cell makes it `manual`.
- **K7 on the deployed kernel** (with the connection approved): Reservations' booked covers for the site's week land in the matching day-parts as `source = 'reservations'`, typed cells are skipped, an unmapped service is listed; **not approved** → *"Reservations is not connected…"* and the manual grid untouched; a location Reservations does not serve → the `not_at_location` sentence; the answer is never stored beyond the covers; the kernel's `application.read` row exists (the kernel logs it).
- **Budget**: hours and amount per area and total against scheduled; the numbers equal `mcp_labor_weekly`; changing a position's default rate (slice 4) changes the cost, not the hours; an open shift adds hours and no cost.
- **Rights**: a manager without `labor.view` → 403 on `/budget` and no cost row in the builder or by day; `settings.manage` is needed for a budget and a ratio (a manager without it → 403); Downtown's manager → 404 on Airport.
- **375 px and 1280 px**: no horizontal scroll; the forecast grid scrolls inside its card on a phone or shows the day-tab list; the number fields are ≥ 44 px.

## Open questions
- (none)
