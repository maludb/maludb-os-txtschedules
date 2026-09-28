---
name: scheduling-rules
description: The rules txtSchedules checks when a shift is built, assigned or traded — position held, time off, unavailability, rest between shifts, hours per day and week, overtime, a person's own limit, breaks, minors' hours and latest end, certifications — hard (refused) or soft (a warning a manager overrides with a reason), per restaurant. Use when explaining a warning or a refusal, or before assigning someone.
---

# Scheduling rules

Each restaurant keeps its own rules (from the "generic" preset, adjusted). Each rule is **hard** (the change is
refused, with the rule's sentence), **soft** (a warning; a manager may go ahead with a written reason, which is
recorded) or **off**. They are the restaurant's settings, **not the law** — never say txtSchedules guarantees
compliance.

| Rule | Fires when |
|---|---|
| position_not_held | the person does not work the shift's position here |
| time_off | the shift overlaps their approved time off |
| unavailable | they marked themselves unavailable then |
| min_rest | fewer than N hours between one shift's end and the next's start ("clopening") |
| max_hours_day | more than N paid hours that day |
| overtime_week | the week's paid hours pass the restaurant's overtime threshold |
| max_hours_person | the week's hours pass the person's own maximum |
| break_required | a shift longer than N hours has less than M minutes of break planned |
| minor_hours_day / minor_hours_week | a minor over the day or week limit |
| minor_latest_end | a minor working past the latest time |
| cert_required | the position needs one of **this restaurant's** certification kinds (its own list, not a fixed two) that the person lacks or that has expired — a kind that tracks no expiry never expires; an unverified card counts, a manager sees it to verify |

Paid hours are the shift's length minus its planned break. `check_assignment` answers every rule for a person and a
shift before anything is done; say the rule's sentence exactly, and for a soft one, say a manager may still approve.
Fair-workweek notice rules are not in txtSchedules yet.
