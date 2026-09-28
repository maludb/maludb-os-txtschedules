# Build spec: restaurant settings, rules and reports (slice 7)

Replicates slice 1's files, gates, flow and proof style. The restaurant's own settings — **the trade settings (D2)**, week
start, reminders, overtime — its day-parts, the **rules** it runs (each hard, soft or off), and the reports. (The time-off
types and blackout dates screen is slice 3's; the forecast ratios and the budget are slice 5's.)
Schema: `site_settings` (with `time_off_day_hours`), `day_parts` (`service_name`) (db/005); `rule_kinds`, `rule_presets`, `site_rules`, `rule_overrides`
(db/009); the views `mcp_sites`, `mcp_site_rules`, `mcp_day_parts`, `mcp_rule_overrides` and the reporting views of db/013–014.
Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `site-settings` | `/site/?site=` | Week start, currency, **how shifts change hands**, availability approval, reminders, **hours a day of time off**, overtime |
| `day-parts` | `/site/day-parts?site=` | Lunch, dinner and the like, with the service name Reservations uses |
| `rules` | `/rules/?site=` | The rules this restaurant runs and what each means |
| `reports` | `/reports/?report=&site=&from=&to=&format=` | The reports, each a table and a CSV |

## Restaurant settings (`settings.manage`)
- **How shifts change hands** — written in plain words, one row per kind, exactly `site_settings` (D2):
  *Staff may* **offer a shift they cannot work** (`allow_offer`) · **pick up an offered or open shift** (`allow_pickup`) · **swap shifts with a colleague** (`allow_swap`) · **give a shift to a colleague** (`allow_give`); **a manager must approve**: pick-ups / swaps / gives — *always · only when a rule warns · never* (`approval_pickup|swap|give`); **no trade closer than** N minutes to the start (`cutoff_minutes`); **shift leads may approve same-day and next-day trades** (`shift_lead_approves_same_day`); **when several people ask**: *the first wins · a manager chooses* (`claim_mode`); **an unclaimed offer ends** *at the start · at the cutoff* (`offer_expires`). A live preview sentence: *"Staff can offer, pick up, swap and give shifts. A manager looks at a pick-up only when a rule warns. Nothing changes hands within 2 hours of the start."*
- **Also**: week start, currency, **availability changes need a manager** (`availability_needs_approval`), **remind people N minutes before a shift** (`reminder_minutes_before`), **a day of time off counts N hours** (`time_off_day_hours`, 0.25–24, default 8 — what a time-off request with no hours counts for each day, *"Vacation of two whole days counts 16 hours."* shown as a live example under the field; the owner's D13) and **overtime after N hours a week** and its multiplier (used by reports and the hours bars).
- One form (`site_settings_save`), saved whole; the log records the fields that changed (`before`/`after`), never a rate. A change takes effect for the next action — an exchange already open keeps the rules it was made under except the cutoff, which is read live.

## Day-parts (`settings.manage`)
- Cards: name, start–end (may run past midnight), and **service name** (*"Reservations calls this Dinner"*) — how the forecast reads Reservations' covers (slice 5). Add / edit (`day_part_save`) / archive (`day_part_archive`, confirm; kept on old forecasts).

## Rules (`settings.manage` to change; everyone at the restaurant may read)
- A card per rule kind (the engine's twelve: position not held, on approved time off, marked unavailable, rest between shifts, hours in a day, overtime, a person's own limit, break planned, the three minor rules, certification — the last reads the restaurant's certification kinds and the positions that need them, slice 4): its plain sentence (`rule_kinds.explains`), a **severity** selector *Hard (refuse) · Soft (warn, a manager overrides with a reason) · Off*, and its **parameters** with the unit and bounds from `params_help` (hours, minutes, a time). Saved one rule at a time (`rule_save`); parameter values are validated per kind (a number in range; a time as HH:MM).
- **Preset** (`rule_preset_apply`, confirm): the *Generic* preset resets every rule to its starting values (`generic` — *"a starting point with no jurisdiction's law behind it"*). The page says in one sentence, always: **txtSchedules helps you follow your own rules; it does not guarantee legal compliance.** Fair workweek is not offered (D8).
- **Overrides** appear here too: the last 30 days of `rule.override` (who, which rule, the reason).

## Reports (managers by right; each a table, 50 rows a page, and `?format=csv` — a download logged `report.export`)
| Report | Shows | Right |
|---|---|---|
| Hours | paid hours per person, position and week; against limits | `schedule.build` |
| Labor vs budget | scheduled hours and cost against the budget by week and area | `labor.view` |
| Open shifts unfilled | open or cancelled shifts by week and position, and how long they stayed open | `schedule.build` |
| Trades | offers, gives, swaps, coverage: counts by state, time to approval, who picks up most and offers most | `schedule.build` |
| Overtime | people over the threshold by week, with the hours over | `schedule.build` (cost: `labor.view`) |
| Overrides | every rule override with who and why (the compliance report, FR-C5) | `schedule.build` |
| Time off | approved time off by person and type; balances | `requests.approve` |
- Cost columns appear only for `labor.view`; **no report carries a rate**; a CSV is exactly the table shown. Each report's data is a view or the reporting tool of the surface (`hours_this_week`, `labor_vs_budget`, `exchange_report`, `overrides`, `time_off`).

## Files (exactly these)
- `html/site/index.php` · `save.php` · `day-parts.php` · `day-part.php` · `day-part-archive.php` · `html/rules/index.php` · `save.php` · `preset.php` · `html/reports/index.php`
- `app/features/site/queries.php` · `present.php` — `app/features/rules/queries.php` · `present.php` — `app/features/reports/queries.php` · `csv.php`
- `app/views/site/settings.php` · `day-parts.php` · `partials/trade-rows.php` — `app/views/rules/list.php` · `partials/rule-card.php` — `app/views/reports/index.php` · `partials/table.php`

## Query functions (signatures fixed)
- `find_site_settings(PDO, int $siteId): ?array` · `save_site_settings(PDO, int $siteId, array $f, int $by): array` (returns the changed fields) · `trade_sentence(array $settings): string`
- `find_day_parts(PDO, int $siteId): array` · `save_day_part(PDO, int $siteId, ?int $id, array $f, int $by): array` · `archive_day_part(PDO, int $id, int $by): void`
- `find_site_rules(PDO, int $siteId): array` (mcp_site_rules) · `save_rule(PDO, int $siteId, string $ruleKey, string $severity, array $params, int $by): array` (validates by kind) · `apply_preset(PDO, int $siteId, string $preset, int $by): array` · `recent_overrides(PDO, int $siteId, int $days = 30): array`
- `report_hours(PDO, int $siteId, string $from, string $to): array` · `report_labor(PDO, int $siteId, string $from, string $to): array` · `report_open_shifts(PDO, int $siteId, string $from, string $to): array` · `report_trades(PDO, int $siteId, string $from, string $to): array` · `report_overtime(PDO, int $siteId, string $from, string $to): array` · `report_overrides(PDO, int $siteId, string $from, string $to): array` · `report_time_off(PDO, int $siteId, string $from, string $to): array` · `stream_csv(string $name, array $columns, array $rows): never`

## Handlers (every one: `require_post(); verify_csrf();` `settings.manage` at the site; one transaction; `log_activity` with the site; `emit_action_status`)
- `site/save.php` (`site_settings_save`): validate each field's range; `save_site_settings()`; log `settings.update` (`before`/`after`: the changed fields). `site/day-part.php` (`day_part_save`) / `day-part-archive.php`: log `day_part.save` / `day_part.archive`.
- `rules/save.php` (`rule_save`): log `rule.update` (`before`/`after`: severity, params); `rules/preset.php` (`rule_preset_apply`): log `rule.preset` (`after`: preset, rules_changed).
- `reports/index.php` GET with `format=csv`: the report's right; log `report.export` (`after`: report, site, from, to, rows).

## Action manifest entries
- Screens: `site-settings`, `day-parts`, `rules`, `reports`.
- Actions: `site_settings_save`, `day_part_save`, `day_part_archive`, `rule_save`, `rule_preset_apply`. No agent approvals.

## Activity log events
- `screen.view` (`reports` with `after.report`), `settings.update`, `day_part.save`, `day_part.archive`, `rule.update`, `rule.preset`, `report.export`. Every row carries `scope_id`.

## Out of scope for this slice
- Jurisdiction presets beyond *Generic* (fair workweek deferred, D8 — a preset is a row in `rule_presets`, addable later without code); custom rules.

## Proof (live as members, headless Chromium at 375 × 740 and 1280 × 800)
- **Trade settings act** (one flow, through the screens): turn `allow_swap` off → the Swap button disappears from the shift page and a forced `shift_swap` is 422; `approval_pickup = always` → a plain claim waits for a manager; `never` → goes through even with a soft warning; `claim_mode = manager_chooses` → claims wait and the manager chooses; `cutoff_minutes` 180 → an offer 2 hours out is refused, 4 hours out accepted; `shift_lead_approves_same_day` off → a shift lead is refused a same-day approval; the preview sentence matches each state.
- **Rules act**: making `min_rest` hard turns the builder's soft warning into a refusal; setting a rule to *Off* removes its warning; a bad parameter (a time `25:99`, negative hours) → 422; *Apply preset* restores the generic values; an admin of Downtown cannot change Airport's rules (404).
- **Hours a day of time off (D13)**: the admin sets *A day of time off counts 6 hours* → the example line under the field reads *"Vacation of two whole days counts 12 hours."*; saving logs `settings.update` with `before`/`after` of that field alone; 0 or 25 → 422; slice 3's requests with no hours count 6 from then on and earlier requests keep their hours; Downtown's setting is separate; a manager without `settings.manage` → 403.
- **Certification rule**: an admin makes `cert_required` hard → the builder refuses a shift for someone missing a card their position needs (*"Certification: Food handler missing"*), soft → a warning; the sentence names the restaurant's own kinds (slice 4).
- **Day-parts**: adding *Brunch* with service name *Brunch* lets the K7 fill (slice 5) map Reservations' Brunch covers; archiving one keeps old forecasts.
- **Reports**: each report equals its view/tool numbers; a manager without `labor.view` sees no cost column and no labor report (403); the CSV equals the table and is logged `report.export`; **no report, no CSV and no log line contains a fixture rate**.
- **Rights**: staff → 403 on settings, rules and reports; `settings.manage` needed for every write; the overrides list shows the reason a manager gave in the builder.
- **375 px and 1280 px**: no horizontal scroll; the trade rows are stacked selects on a phone; reports scroll inside their card.

## Open questions
- (none)
