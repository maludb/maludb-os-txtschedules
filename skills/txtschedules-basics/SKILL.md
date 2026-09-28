---
name: txtschedules-basics
description: How txtSchedules (restaurant staff scheduling) is organised and which tool answers which question — restaurants as sites, weeks drafted and published, shifts and open shifts, positions, the marketplace (offer, pickup, swap, give, coverage), the main restaurant, time off and balances, who may see pay. Use before any question or action about schedules, shifts, trades or time off.
---

# txtSchedules basics

## The shape
- A **restaurant** is a site of the business; everything is per restaurant, in its own time zone.
- A **week** starts on the restaurant's week-start day; it is a **draft** (managers only) until **published**.
- A **shift**: position, start, end, planned unpaid break, and a person — or none (**open**). Published shifts are
  cancelled, never deleted; a change after publishing is marked and people are told.
- A person has a **main restaurant**; they may be scheduled elsewhere, but they **pick up** shifts only there.
- **Positions** belong to a restaurant; a person works some of them, each with a rate only managers with pay rights see: the position has a restaurant-wide default hourly rate, and a person may have a rate of their own that overrides it (the effective rate is the person's own when set, else the default).

## Which tool
| Question | Tool |
|---|---|
| Who is on (a date, now, a position) | `who_is_on` |
| My shifts | `my_shifts` |
| A restaurant's week | `week_schedule` |
| What can I pick up / why not | `marketplace`, `check_assignment` |
| Who could cover | `coverage_candidates` |
| Hours, overtime | `hours_this_week` |
| Waiting for a manager | `pending_requests` |
| Warnings, overrides | `week_warnings`, `overrides` |
| Time off, balances | `time_off`, `time_off_balances` |
| Forecast vs scheduled | `staffing_needs` |
| Labor cost vs budget (pay rights only) | `labor_vs_budget` |
| What happened | `shift_history`, `exchange_history`, `who_did`, `site_activity` |

## Who sees what
Everyone sees their own restaurant's published schedule and their own everything. Drafts, other people's requests
and profiles: managers. Pay (wages, cost, budget): managers with pay rights, and each person their own wage. Nobody
sees another person's email or phone.
