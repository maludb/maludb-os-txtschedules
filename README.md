# txtSchedules — restaurant staff scheduling, an application from us for the Business OS

Managers at each restaurant build and publish a week's schedule from their staff's availability and the restaurant's
rules; staff see their shifts on their phones and offer, pick up, swap and give shifts among themselves, within the
trade settings each restaurant chooses; time off (with balances) lives here; labor cost is kept against a budget and
a covers forecast. One installation serves every restaurant of the business — each a kernel site, shared with
Reservations.

- People reach it from the launcher (`app.<domain>`) at `txtschedules.<domain>`, on the web or as a mobile web app
  installed on the phone; there is no login form. Staff get it through the kernel's grant to a site's residents.
- Agents reach it through its two read MCP servers; writes go through the kernel's Actions MCP running txtSchedules'
  action registry. It ships an expert and a scheduling assistant (drafts only); it never calls a model and holds no
  Twilio key — texts go through the kernel (K6); Reservations' covers come through the kernel (K7).
- Stack: Ubuntu 24.04, Apache, PHP 8.3, HTMX, Bootstrap 5.3 (the nxl design system), PostgreSQL 17 (btree_gist for
  the no-overlap rule), MaluDB for activity memory. Built with `htmx-php-builder`; fitted with `maludb-os-integration`.

## Layout
```
maludb-os.json                 what the kernel's installer reads (registration.md)
db/                            numbered, additive migrations — run in order as postgres; db/proof/ the schema's proofs
docs/txtschedules-design.md    Phase 0: purpose, actors, the one rule, memory, questions, screens, agents, decisions, state
docs/txtschedules-mcp-tool-surface.md, docs/txtschedules-action-manifest.md, docs/build-specs/   Phase 1: the checkpoint set (tools, actions, one spec per slice)
mcp/action_registry.json       built from the manifest by bin/build_action_registry.php
os/                            each shipped agent's job description (expert.md, scheduler.md)
skills/                        txtschedules-basics, scheduling-rules, shift-marketplace (skills); build-next-week,
                               cover-a-gap, approve-requests (runbooks, kind: runbook)
html/ app/ config/ storage/    the application (Phase 2 onward)
mcp/ bin/ deploy/              the read servers, the CLI jobs (directory sync, dev hand-off), the vhost and units (templates)
```

## Install
With the kernel's installer: `php /var/www/bin/app_install.php plan /srv/apps/txtschedules`, then (root, the owner's)
`sudo php /var/www/bin/app_install.php apply /srv/apps/txtschedules --scheme https`. Then the owner's: DNS and the proxy
entry for `txtschedules.<domain>`, the restaurants added on the application's Scopes tab in the kernel (the same
locations as Reservations), the residents grant as Staff, managers per restaurant, and the K7 connection to
Reservations' `covers_by_service` (`bin/app_connection.php approve` on the kernel).

## Proving the schema
```
sudo -u postgres psql -v ON_ERROR_STOP=1 -d subello_txtschedules -f db/proof/phase0_proof.sql   # 129 checks, rolled back
bash db/proof/claim_race.sh                                                                        # two sessions, one winner
```
