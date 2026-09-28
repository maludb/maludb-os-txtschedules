# Build spec: people, positions and pay (slice 4)

Replicates slice 1's files, gates, flow and proof style. Positions per restaurant, each person's profile (the **main
restaurant**, limits, the minor flag, the positions they work), certifications with expiry, and **pay**: a **default
hourly rate per position** and a **rate of a person's own that overrides it** (the owner's D10). The person themself is the
kernel's (`members`); these are txtSchedules' own facts about them.
Schema: `positions` (`default_wage_rate`), `staff_profiles`, `staff_positions` (`wage_override`), `certification_kinds`,
`certifications`, `position_certifications`, `ts_ensure_staff_profile()`, `ts_effective_rate()` (db/006); the views
`mcp_positions`, `mcp_staff`, `mcp_staff_positions`, `mcp_certifications`, `mcp_members` (db/013). Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `staff-list` | `/staff/?site=&q=&position=` | The restaurant's staff as cards: name, positions, main restaurant, an expired-certification flag |
| `staff-view` | `/staff/{id}` | One person: positions, certifications, hours, time off; **pay for whoever may see it** |
| `staff-edit` | `/staff/{id}/edit` | Main restaurant, limits, minor flag, positions, active, notes |
| `positions-list` | `/positions/?site=` | The restaurant's positions as cards with colour and area; the default rate for `labor.view` |
| `position-add` / `position-edit` | `/positions/new?site=`, `/positions/{id}/edit` | Add or change a position |

## Staff
- **List**: cards (`RecordCard`), search by name, filters restaurant/position/`on_schedule`; a person with an expired certification carries a danger chip; managers (`schedule.build`) see the restaurants they manage; staff without it do not have this screen (the team schedule names people; profiles are managers').
- **View**: header (name, main restaurant, active, "minor until …" for a minor), **Positions** (name, primary star; for `labor.view` each row's **effective rate** with its source — *"Own rate"* or *"Default for the position"* — and the person's own rate; for the person themself their own effective rate and nothing else; for everyone else **no pay at all, not even a dash that reveals it**), **Certifications** (kind, issued, expires; expired in danger, ≤ 30 days in warning; add/remove), **Hours** (this week against their limit), **Time off** (balances; approvers) and **Restaurants held** with roles (from the kernel; read-only — grants are the super-admin's in the kernel).
- **Edit** (`staff_save`): **main restaurant** (a select of the restaurants they hold — *"They can pick up shifts only here."*), max hours a week, minor (yes + an end date; no date of birth is kept), active (off = off the schedule without leaving the directory), notes (managers only), positions (multi-select from the restaurant's positions, one primary). Pay is **not** on this form.
- **Pay** (`wage_update`, `pay.edit` only): a small separate form on the position row: the person's **own hourly rate**, empty to remove it so the position's default applies; confirm; an agent's call is paused (`other`). The effective rate after the change is shown back to the person who holds `labor.view`.

## Positions
- **Cards**: name, colour dot, area (front/kitchen/bar/management/other), how many people work it, certifications it needs, and — `labor.view` only — the **default hourly rate**. Add/edit form (`position_save`): name (unique among live positions), area, colour, sort, certifications the position needs. **Archive** (`position_archive`, confirm): stays on old shifts, leaves the pickers; refused while a future scheduled shift uses it.
- **Default rate** (`position_rate_update`, `pay.edit`): one hourly figure for everyone at the position who has no rate of their own; empty clears it. The page says how many people it applies to (those without an override) — *"Applies to 6 people; 2 have their own rate."* Changing it changes the cost of their shifts from then on (cost is always computed at the current effective rate). Confirm; agent-paused (`other`); the log says THAT it changed (`wage.update`, `after.scope = 'position_default'`).

## Certifications
- `certification_add` (member, kind, issued, expires, reference) / `certification_remove` (confirm); kinds are the seeded `food_handler`, `alcohol_service` (adding kinds is a later admin screen — Open questions). A position that needs a kind makes the `cert_required` rule warn when someone without a live one is scheduled or takes the shift.

## Files (exactly these)
- `html/staff/index.php` · `view.php` · `form.php` · `save.php` · `wage.php` · `html/staff/certifications/add.php` · `remove.php`
- `html/positions/index.php` · `form.php` · `save.php` · `rate.php` · `archive.php`
- `app/features/staff/queries.php` · `present.php` — `app/features/positions/queries.php` · `present.php`
- `app/views/staff/list.php` · `view.php` · `form.php` · `partials/card.php` · `positions-block.php` · `certifications.php` — `app/views/positions/list.php` · `form.php` · `partials/card.php`

## Query functions (signatures fixed)
- `find_staff(PDO, array $filters, int $page = 1, int $per = 24): array` (mcp_members/mcp_staff) · `find_staff_member(PDO, int $memberId): ?array` (profile, positions with pay **as the view gives it**, certifications, hours) · `find_staff_for_edit(PDO, int $memberId): ?array` (base tables, behind `schedule.build`)
- `save_staff(PDO, int $memberId, array $f, int $by): array` (upserts the profile through `ts_ensure_staff_profile()` for a new person; positions replaced whole, one primary; a change of main restaurant needs `schedule.build` at the new one) · `set_wage_override(PDO, int $memberId, int $positionId, ?float $rate, int $by): void` (writes `staff_positions.wage_override`; the caller has `pay.edit`)
- `find_positions(PDO, int $siteId, bool $archived = false): array` (mcp_positions) · `find_position_for_edit(PDO, int $id): ?array` · `save_position(PDO, int $siteId, ?int $id, array $f, int $by): array` · `set_position_rate(PDO, int $positionId, ?float $rate, int $by): void` · `archive_position(PDO, int $id, int $by): void` · `people_at_position(PDO, int $positionId): array` (counts: with and without their own rate)
- `add_certification(PDO, int $memberId, int $kindId, ?string $issued, ?string $expires, ?string $reference, int $by): array` · `remove_certification(PDO, int $id, int $by): void`

## Handlers (every one: `require_post(); verify_csrf();` the gate at the site; one transaction; `log_activity` with the site; `emit_action_status`)
- `staff/save.php` (`staff_save`): `require_right('schedule.build', $site)`; validate; `save_staff()`; log `staff.save` (`before`/`after`: main_scope_id, max_hours_week, is_minor, minor_until, active, position ids — **never a rate**); `saved_go("/staff/$id")`; `HX-Trigger: staffChanged`.
- `staff/wage.php` (`wage_update`): `require_right('pay.edit', $site)`; `set_wage_override()`; log `wage.update` (`after`: `scope = 'employee'`, member_id, position_id, `cleared` true when emptied — **never the rate**); the rate is never echoed in the JSON reply either.
- `staff/certifications/add.php` / `remove.php`: `schedule.build`; log `certification.add` (`after`: kind, expires_on) / `certification.remove`.
- `positions/save.php` (`position_save`): `settings.manage`; log `position.save` (`before`/`after`: name, area, color, sort, certification kinds — no rate). `positions/rate.php` (`position_rate_update`): `pay.edit`; log `wage.update` (`after`: `scope = 'position_default'`, position_id, `cleared`). `positions/archive.php`: `settings.manage`; log `position.archive`.

## Action manifest entries
- Screens: `staff-list`, `staff-view`, `staff-edit`, `positions-list`, `position-add`, `position-edit`.
- Actions: `staff_save`, `wage_update`, `certification_add`, `certification_remove`, `position_save`, `position_rate_update`, `position_archive`. Agent approvals: `wage_update` (`other` — its log event `wage.update` also covers `position_rate_update`).

## Activity log events
- `screen.view`, `staff.save`, `certification.add`, `certification.remove`, `position.save`, `position.archive`, `wage.update`. Every row carries `scope_id`; **`wage.update` carries no amount**.

## Out of scope for this slice
- Adding certification kinds (a later admin screen); bulk pay changes; pay history (a rate change is logged, not versioned — labor cost always uses the current effective rate); the kernel's directory (names, emails, phones are the kernel's).

## Proof (live as members, headless Chromium at 375 × 740 and 1280 × 800)
- **The wage model, through the screens** — Airport Server default $14.00; Priya's own $15.00; Sam has none: the owner sees Priya *"$15.00 — own rate"*, Sam *"$14.00 — default for the position"*; raising the default to $20 shows Sam $20 and Priya still $15 and the page says *"Applies to 1 person; 1 has their own rate."*; clearing Priya's own rate makes her $20; the shift costs in the builder move accordingly (cost = paid hours × the effective rate).
- **Pay is invisible to everyone else**: Priya sees her own effective rate and nothing of Sam's; Sam sees neither a default nor any cost; a manager without `labor.view` sees no rate, no default and no cost anywhere (grep every rendered page and every JSON reply for the fixture rates — none); a manager with `labor.view` but not `pay.edit` sees rates and cannot change them (403 on `wage.php` and `rate.php`); an agent's `wage_update` is `pending_approval` (Phase 4).
- **The log never carries pay**: after every change the `after` of `wage.update` holds scope, ids and `cleared` only; no `activity_log` row and no notification body contains a fixture rate.
- **Main restaurant**: changing it moves where the person may pick up shifts (their marketplace at the old restaurant empties, the new one fills); a manager without `schedule.build` at the new restaurant → 403; a person who holds one restaurant cannot be given another as main.
- **Positions**: archiving one a future shift uses → 422; add/rename/recolour; a duplicate live name → 422; the default-rate form is absent without `pay.edit`.
- **Certifications**: adding an expired card makes `cert_required` warn when that person is scheduled; removing it clears the warning; the staff list flags expiry.
- **Rights**: staff → 403 on `/staff/`; a manager of Downtown → 404 on an Airport person.
- **375 px and 1280 px**: no horizontal scroll; the staff cards stack one column on a phone; the pay form is readable and confirms.

## Open questions
- Certification kinds beyond the two seeded (a small admin screen — later).
