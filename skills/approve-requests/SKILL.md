---
name: approve-requests
description: The runbook for going through what waits for a manager in txtSchedules — time off, availability changes and trades — with the facts each needs; the manager decides, the agent only prepares.
kind: runbook
---

# Approve requests (prepare, do not decide)

1. `pending_requests` for the restaurant: time off, availability, exchanges waiting for a manager.
2. For each, the facts the manager needs in one line:
   - time off: who, when, hours, the balance before and after, who else is off then (`time_off`), shifts it hits;
   - availability: what changes from when, and which scheduled shifts it now conflicts with;
   - a trade: who gives, who takes, the shift, every warning attached (overtime, rest…) in the rule's words.
3. Order: the soonest first. Say which look routine and which do not, and why.
4. Never approve or decline yourself — deciding a trade pauses for a person anyway; time off and availability are
   the manager's.
