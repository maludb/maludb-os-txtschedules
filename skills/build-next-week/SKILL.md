---
name: build-next-week
description: The runbook for drafting a restaurant's next week in txtSchedules — needs from the forecast, start from the template or last week, fill open shifts fairly within the rules, leave what cannot be filled open, and hand the manager a short note. Drafts only; publishing waits for a manager.
kind: runbook
---

# Build next week (a draft)

1. `site_settings`, `site_rules`, and `staffing_needs` for the week — recommended vs scheduled per day-part and position.
2. The draft: `template_apply` (the restaurant's usual template) or `week_create` and copy last week. If a draft
   already exists, edit it; never replace it.
3. For each open shift, candidates who hold the position, are available and not on time off, fewest hours first;
   `check_assignment` each; `shift_assign` the first with no warning. A soft warning only when nobody else fits.
   Never against a hard rule.
4. Add or remove open shifts where `staffing_needs` says the day-part is short or over (only in the draft).
5. `week_warnings`; write the manager: filled, still open, warnings left (with who and why), hours per person
   against their limits, and the covers the week was built for.
6. Do not publish unless asked; if asked, `week_publish` — it waits for the manager's approval.
