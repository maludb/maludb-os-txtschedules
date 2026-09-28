# Build spec: people, positions and pay (slice 4)

Replicates slice 1's files, gates, flow and proof style. Positions per restaurant, each person's profile (the **main
restaurant**, limits, the minor flag, the positions they work), **certifications** (the restaurant's own kinds, a person's
cards with expiry, a manager who verifies — the owner's D15), and **pay**: a **default
hourly rate per position** and a **rate of a person's own that overrides it** (the owner's D10). The person themself is the
kernel's (`members`); these are txtSchedules' own facts about them.
Schema: `positions` (`default_wage_rate`), `staff_profiles`, `staff_positions` (`wage_override`), `certification_kinds`,
`certifications` (with `verified_by`, `verified_at`), `position_certifications`, `ts_ensure_staff_profile()`,
`ts_effective_rate()` (db/006); the `cert_required` rule in `ts_assignment_warnings()` (db/009); the views `mcp_positions`,
`mcp_staff`, `mcp_staff_positions`, `mcp_certification_kinds`, `mcp_certifications`, `mcp_certifications_due`, `mcp_members`
(db/013). Never modify them. Kinds are **per restaurant** (`certification_kinds.scope_id`), seeded with the site (food handler,
alcohol service).

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `staff-list` | `/staff/?site=&q=&position=` | The restaurant's staff as cards: name, positions, main restaurant, an expired-certification flag |
| `staff-view` | `/staff/{id}` | One person: positions, certifications, hours, time off; **pay for whoever may see it** |
| `staff-edit` | `/staff/{id}/edit` | Main restaurant, limits, minor flag, positions, active, notes |
| `positions-list` | `/positions/?site=` | The restaurant's positions as cards with colour and area; the default rate for `labor.view` |
| `position-add` / `position-edit` | `/positions/new?site=`, `/positions/{id}/edit` | Add or change a position |
| `certifications` | `/certifications/?site=&state=&position=` | A restaurant's certification kinds (each with the positions that need it, whether it expires, its warning days) and **who is expired, due, missing one or has one to verify** — the manager's list |
| `certification-kind-add` / `certification-kind-edit` | `/certifications/kinds/new?site=`, `/certifications/kinds/{id}/edit` | Add or change a kind: name, expiry tracked (yes/no), warning days (0–365), the positions that need it |
| `my-certifications` | `/certifications/mine` | The signed-in person's own cards, on the phone: see them (expired in danger, due soon in warning, **"Verified by a manager"** or **"Waiting for a manager to check"**), add one, correct one |

## Staff
- **List**: cards (`RecordCard`), search by name, filters restaurant/position/`on_schedule`; a person with an expired certification carries a danger chip; managers (`schedule.build`) see the restaurants they manage; staff without it do not have this screen (the team schedule names people; profiles are managers').
- **View**: header (name, main restaurant, active, "minor until …" for a minor), **Positions** (name, primary star; for `labor.view` each row's **effective rate** with its source — *"Own rate"* or *"Default for the position"* — and the person's own rate; for the person themself their own effective rate and nothing else; for everyone else **no pay at all, not even a dash that reveals it**), **Certifications** (kind, issued, expires; expired in danger, inside the kind's warning days in warning; verified or waiting; add/edit/remove and **Verify** for `schedule.build`), **Hours** (this week against their limit), **Time off** (balances; approvers) and **Restaurants held** with roles (from the kernel; read-only — grants are the super-admin's in the kernel).
- **Edit** (`staff_save`): **main restaurant** (a select of the restaurants they hold — *"They can pick up shifts only here."*), max hours a week, minor (yes + an end date; no date of birth is kept), active (off = off the schedule without leaving the directory), notes (managers only), positions (multi-select from the restaurant's positions, one primary). Pay is **not** on this form.
- **Pay** (`wage_update`, `pay.edit` only): a small separate form on the position row: the person's **own hourly rate**, empty to remove it so the position's default applies; confirm; an agent's call is paused (`other`). The effective rate after the change is shown back to the person who holds `labor.view`.

## Positions
- **Cards**: name, colour dot, area (front/kitchen/bar/management/other), how many people work it, certifications it needs, and — `labor.view` only — the **default hourly rate**. Add/edit form (`position_save`): name (unique among live positions), area, colour, sort, certifications the position needs. **Archive** (`position_archive`, confirm): stays on old shifts, leaves the pickers; refused while a future scheduled shift uses it.
- **Default rate** (`position_rate_update`, `pay.edit`): one hourly figure for everyone at the position who has no rate of their own; empty clears it. The page says how many people it applies to (those without an override) — *"Applies to 6 people; 2 have their own rate."* Changing it changes the cost of their shifts from then on (cost is always computed at the current effective rate). Confirm; agent-paused (`other`); the log says THAT it changed (`wage.update`, `after.scope = 'position_default'`).

## Certifications (the owner's D15)
- **Kinds are the restaurant's own** (`certification_kind_save`, `settings.manage`; `certification_kind_archive`, confirm): a name, **whether an expiry is tracked** (First aid may not), **days of warning** (default 30), and the **positions that need it** (a multi-select of that restaurant's positions; a position never needs another restaurant's kind — the database refuses). Archived kinds leave the pickers and the rule and stay on cards already entered.
- **A person's cards** — `certification_add` (kind, issued, expires, reference), `certification_update` (any of the three), `certification_remove` (confirm). A person acts on **their own** cards, a manager (`schedule.build` at the kind's restaurant) on anyone's. **Verification (D15):** a card **a person enters is unverified** — it shows "Waiting for a manager to check" and counts as held for the rule, but is on the manager's list as *to verify*; a card **a manager enters is verified at once**; **`certification_verify`** (`schedule.build`, an agent's call pauses — `other`) marks a card verified or not; a person **editing a verified card clears its verification**. A card is entered per kind (a kind belongs to one restaurant): the form adds it for **each restaurant the person works at where a kind of that name exists**, so one food-handler card is not typed twice. No document upload (out of scope).
- **The due list** (`certifications`, managers): four states per row — **expired** (a tracked expiry in the past), **due** (within the kind's warning days), **missing** (the person works a position that needs the kind and holds no card), **to verify** — most urgent first, a filter by state and position, a link to the person; each row's action is *Verify* or *Add the card*. The staff list's danger chip reads from it.
- **The rule (`cert_required`, db/009):** when someone is scheduled or takes a shift, the position's required kinds **of that restaurant** are checked; a kind is satisfied by a live card (no expiry check when the kind does not track it); the message names what is missing or expired (*"Certification: Food handler expired"*); hard or soft is the restaurant's choice in `rule_save`. An unverified card satisfies it.
- **Notifications:** when a card enters its warning window a manager is told once (the notifications timer, slice 6, reads `mcp_certifications_due`); the person is told too (their `by_email`/`by_sms` choices).

## Files (exactly these)
- `html/staff/index.php` · `view.php` · `form.php` · `save.php` · `wage.php` · `html/staff/certifications/add.php` · `update.php` · `remove.php` · `verify.php`
- `html/certifications/index.php` · `mine.php` · `kinds/form.php` · `kinds/save.php` · `kinds/archive.php`
- `html/positions/index.php` · `form.php` · `save.php` · `rate.php` · `archive.php`
- `app/features/staff/queries.php` · `present.php` — `app/features/positions/queries.php` · `present.php`
- `app/views/staff/list.php` · `view.php` · `form.php` · `partials/card.php` · `positions-block.php` · `certifications.php` — `app/views/positions/list.php` · `form.php` · `partials/card.php` — `app/views/certifications/list.php` · `mine.php` · `kind-form.php` · `partials/due-row.php`

## Query functions (signatures fixed)
- `find_staff(PDO, array $filters, int $page = 1, int $per = 24): array` (mcp_members/mcp_staff) · `find_staff_member(PDO, int $memberId): ?array` (profile, positions with pay **as the view gives it**, certifications, hours) · `find_staff_for_edit(PDO, int $memberId): ?array` (base tables, behind `schedule.build`)
- `save_staff(PDO, int $memberId, array $f, int $by): array` (upserts the profile through `ts_ensure_staff_profile()` for a new person; positions replaced whole, one primary; a change of main restaurant needs `schedule.build` at the new one) · `set_wage_override(PDO, int $memberId, int $positionId, ?float $rate, int $by): void` (writes `staff_positions.wage_override`; the caller has `pay.edit`)
- `find_positions(PDO, int $siteId, bool $archived = false): array` (mcp_positions) · `find_position_for_edit(PDO, int $id): ?array` · `save_position(PDO, int $siteId, ?int $id, array $f, int $by): array` · `set_position_rate(PDO, int $positionId, ?float $rate, int $by): void` · `archive_position(PDO, int $id, int $by): void` · `people_at_position(PDO, int $positionId): array` (counts: with and without their own rate)
- `find_certification_kinds(PDO, int $siteId, bool $archived = false): array` (mcp_certification_kinds) · `save_certification_kind(PDO, int $siteId, ?int $id, array $f, int $by): array` (name, track_expiry, warn_days, positions replaced whole) · `archive_certification_kind(PDO, int $id, int $by): void`
- `find_certifications_due(PDO, int $siteId, ?string $state, ?int $positionId): array` (mcp_certifications_due) · `find_certifications(PDO, int $memberId): array` (mcp_certifications)
- `add_certification(PDO, int $memberId, string|int $kind, ?string $issued, ?string $expires, ?string $reference, int $by): array` (one row for each of the person's restaurants that has the kind; verified when `$by` holds `schedule.build` there) · `update_certification(PDO, int $id, array $f, int $by): array` (clears verification when the person edits) · `remove_certification(PDO, int $id, int $by): void` · `verify_certification(PDO, int $id, bool $verified, int $by): void`

## Handlers (every one: `require_post(); verify_csrf();` the gate at the site; one transaction; `log_activity` with the site; `emit_action_status`)
- `staff/save.php` (`staff_save`): `require_right('schedule.build', $site)`; validate; `save_staff()`; log `staff.save` (`before`/`after`: main_scope_id, max_hours_week, is_minor, minor_until, active, position ids — **never a rate**); `saved_go("/staff/$id")`; `HX-Trigger: staffChanged`.
- `staff/wage.php` (`wage_update`): `require_right('pay.edit', $site)`; `set_wage_override()`; log `wage.update` (`after`: `scope = 'employee'`, member_id, position_id, `cleared` true when emptied — **never the rate**); the rate is never echoed in the JSON reply either.
- `staff/certifications/add.php` / `update.php` / `remove.php`: **own card**, or `schedule.build` at the kind's restaurant for anyone's; log `certification.add` (`after`: member_id, kind_id, expires_on, verified) / `certification.update` (`before`/`after` of the dates and reference) / `certification.remove`. `staff/certifications/verify.php` (`certification_verify`): `schedule.build`; log `certification.verify` (`after`: member_id, kind_id, verified). `certifications/kinds/save.php` / `archive.php`: `settings.manage`; log `certification_kind.save` (`before`/`after`: name, track_expiry, warn_days, position ids) / `certification_kind.archive`.
- `positions/save.php` (`position_save`): `settings.manage`; log `position.save` (`before`/`after`: name, area, color, sort, certification kinds — no rate). `positions/rate.php` (`position_rate_update`): `pay.edit`; log `wage.update` (`after`: `scope = 'position_default'`, position_id, `cleared`). `positions/archive.php`: `settings.manage`; log `position.archive`.

## Action manifest entries
- Screens: `staff-list`, `staff-view`, `staff-edit`, `positions-list`, `position-add`, `position-edit`, `certifications`, `certification-kind-add`, `certification-kind-edit`, `my-certifications`.
- Actions: `staff_save`, `wage_update`, `certification_add`, `certification_update`, `certification_remove`, `certification_verify`, `certification_kind_save`, `certification_kind_archive`, `position_save`, `position_rate_update`, `position_archive`. Agent approvals: `wage_update` (`other` — its log event `wage.update` also covers `position_rate_update`), `certification_verify` (`other`).

## Activity log events
- `screen.view`, `staff.save`, `certification.add`, `certification.update`, `certification.remove`, `certification.verify`, `certification_kind.save`, `certification_kind.archive`, `position.save`, `position.archive`, `wage.update`. Every row carries `scope_id`; **`wage.update` carries no amount**.

## Out of scope for this slice
- Uploading a photo or a document of a card; bulk pay changes; pay history (a rate change is logged, not versioned — labor cost always uses the current effective rate); the kernel's directory (names, emails, phones are the kernel's).

## Proof (live as members, headless Chromium at 375 × 740 and 1280 × 800)
- **The wage model, through the screens** — Airport Server default $14.00; Priya's own $15.00; Sam has none: the owner sees Priya *"$15.00 — own rate"*, Sam *"$14.00 — default for the position"*; raising the default to $20 shows Sam $20 and Priya still $15 and the page says *"Applies to 1 person; 1 has their own rate."*; clearing Priya's own rate makes her $20; the shift costs in the builder move accordingly (cost = paid hours × the effective rate).
- **Pay is invisible to everyone else**: Priya sees her own effective rate and nothing of Sam's; Sam sees neither a default nor any cost; a manager without `labor.view` sees no rate, no default and no cost anywhere (grep every rendered page and every JSON reply for the fixture rates — none); a manager with `labor.view` but not `pay.edit` sees rates and cannot change them (403 on `wage.php` and `rate.php`); an agent's `wage_update` is `pending_approval` (Phase 4).
- **The log never carries pay**: after every change the `after` of `wage.update` holds scope, ids and `cleared` only; no `activity_log` row and no notification body contains a fixture rate.
- **Main restaurant**: changing it moves where the person may pick up shifts (their marketplace at the old restaurant empties, the new one fills); a manager without `schedule.build` at the new restaurant → 403; a person who holds one restaurant cannot be given another as main.
- **Positions**: archiving one a future shift uses → 422; add/rename/recolour; a duplicate live name → 422; the default-rate form is absent without `pay.edit`.
- **Certification kinds**: the admin adds *First aid* (no expiry tracked, 0 warning days) to Airport and makes Host need it — Downtown does not have it; a position cannot be given another restaurant's kind; a duplicate kind name in one restaurant → 422; archiving a kind removes it from the pickers and from the rule, and old cards stay.
- **Cards and the rule**: Sam has no food-handler card and works Server (which needs one) → the due list shows *missing* and scheduling him warns *"Certification: Food handler missing"*; adding a card that expired yesterday → *expired* and the warning says so; adding a current one clears both; a *First aid* card with a date in the past does **not** warn (the kind tracks no expiry); set the rule hard in `rule_save` → the same shift is refused, soft again → a warning.
- **Staff on the phone (375 px)**: Sam opens `/certifications/mine`, sees Airport's kinds, adds *Food handler* with an expiry → it shows *Waiting for a manager to check* and appears to the owner as *to verify*; Sam cannot see Priya's cards (grep the page: none); Sam editing it after verification clears the verification.
- **Verify**: the owner verifies Sam's card → it leaves *to verify* and reads *Verified by a manager*; a manager who enters a card gets it verified at once; a staff member's call to `verify.php` → 403; an agent's `certification_verify` is `pending_approval` (Phase 4).
- **The due list**: expiring in 10 days with 30 warned → *due* (10 days left); expired yesterday → *expired*; the list is a manager's (staff → 403; a manager of Downtown → nothing of Airport); the staff list's danger chip agrees with it.
- **Rights**: staff → 403 on `/staff/`; a manager of Downtown → 404 on an Airport person.
- **375 px and 1280 px**: no horizontal scroll; the staff cards stack one column on a phone; the pay form is readable and confirms.

## Open questions
- None from the owner's answers. (Decided: an unverified card counts as held; a manager entering a card verifies it; editing a verified card clears it — D15.)
