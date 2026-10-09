# The record picker (2026-10-09)

The owner's decision (the builder plugin's `design-system/references/record-picker.md`; the plan in the plugin's `docs/record-picker-plan.md`): a form that
chooses a **record** — here, a person — uses one searchable modal, never a `<select>` that lists every row. ProcessCore's purchase order was the exemplar,
the cidery the first retrofit; this is the mechanical retrofit of txtSchedules. The record picker is the second exemption to "no modals" (beside `hx-confirm`):
it only chooses and closes, shows no record and holds no form.

## What was added
- `app/picker.php` (the helper: `picker_field()`, `picker_label()`, `picker_source()`; the app writes its form rows by hand, so there is no `form_picker()`),
  `app/pickers.php` (the registry), `html/pick/index.php` (the endpoint), `app/views/shared/picker-rows.php` (the rows),
  `html/assets/js/record-picker.js`, the RECORD PICKER section of `app-overrides.css`, the `#record-picker` modal and the script in `app/views/layout.php`
  (after `</main>`; the script after `common-init.min.js`). This shell has no unsaved-changes guard, so nothing there admits the hidden input.
- **A site-scoped gate.** Every right here is asked AT a site, so a source carries `allow` — a closure over `has_right()` / `held_sites()` that sees the declared
  params — instead of the skeleton's flat `roles` list. The endpoint reads only the declared params (integers), asks `allow`, and answers a 403/404 *fragment*
  (never a page) when it fails. A site the caller does not hold fails every right, so its people are never listed.
- Sources (all over the screens' own queries, filtered by the typed text and paged 25 at a time in PHP — a restaurant's staff is a short list):
  - `person` — the people on the schedule at `site_id` (`find_schedulable_people`), detail = the positions they work; `schedule.build` there.
    Param `self=1` puts the caller first, marked "You", even when they are not on the schedule: the old select's "Me"/"Mine" first option.
  - `person_approver` — the same list for `requests.approve` there (time off for someone, whose balances).
  - `colleague` — who could take a shift as a gift or swap (`shift_id`; `find_give_candidates`): `market.trade` at the shift's site **and** the caller holds the shift,
    exactly the shift page's own condition.
- `/pick/` is not an action: nothing is logged, nothing is in the registry. The manifest's URL conventions say so.

## Converted (the hidden input keeps each select's name and id, so no handler, `hx-include`, smoke or manifest row changed)
| Field | Screen | Source |
|---|---|---|
| `member` (`availability-person-field-member`) "Whose availability" | `/availability` | `person` (self) |
| `member` (`balances-person-field-member`) "Whose balances" | `/time-off/balances` | `person_approver` (self) |
| `member` (`time-off-form-field-member`) "For" | `/time-off/new` | `person_approver` (self) |
| `assignee` (`shift-form-field-assignee`) "Who", optional (Leave open) | `/shifts/new`, `/shifts/{id}/edit` | `person` |
| `assignee` (`{prefix}-move-{id}-person`) "Move to…", optional | the builder's list and the shift page | `person` |
| `colleague` (`give-form-field-colleague`) | the shift page, Give | `colleague` |
| `swap_with` (`swap-form-field-with`) | the shift page, Swap | `colleague` |

## Still selects, by the rule (D7)
Positions, restaurants (sites), weekdays, kinds of availability / time off / certification, breaks, severities, reminder lead, area, week start, report filters:
a restaurant's own short configuration lists. **Left alone:** the announcement's "named people" — a list of checkboxes (a multi-choice, outside the picker's contract).

## Notes
- The app's forms are `hx-post` to `#flash`; a refused write never re-renders the form, so there is no 422 re-render to keep a label through. A form's first render
  from the query (`?assignee=`, `?member=`, `?swap_with=`) shows the chosen label, and that is what the proof checks.
- The old person selects were built from `$people` / `$give` in the handlers; they are still computed and passed (the views no longer list them), left so no handler changed.
- Proof: `tests/picker/run.sh` (scratch `txtschedules_dev11`; `TS_PORT` moves the application and its two fakes) — `picker.php` (the endpoint's refusals, search, escaping, paging, the site / shift params,
  every converted form, three writes through the hidden inputs) and `browser.mjs` (Chromium: keyboard, the live check hearing the pick, Show more, a phone at 375 px, dark mode).
  `tests/phase2/lib.php` (`TS_BASE`), `servers.sh` and `setup_dev.sh` (`TS_PORT`) learned to run on other ports so this proof can run beside another session's.
  Earlier proofs updated for what the picker legitimately changes: slice 1's `giveswap.php` (the colleague list is the endpoint's now) and slice 2's `browser.mjs` (the "Who" field is picked, not selected).
