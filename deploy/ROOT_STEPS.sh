#!/usr/bin/env bash
# What the owner runs as root, at the end, for txtSchedules. Commented per step; nothing here has been run.
set -euo pipefail

# Phase 3 slice 1 (2026-09-29): db/015_exchange_overlap.sql — an overlap is refused when a shift is TAKEN (ts_exchange_check_taker).
# The installer's plan (2026-09-29) shows txtschedules ALREADY installed (application 56), so subello_txtschedules exists at db/014: apply the one file by hand
# (a fresh database from db/*.sql would already have it):
#   sudo -u postgres psql -v ON_ERROR_STOP=1 -d subello_txtschedules -f /srv/apps/txtschedules/db/015_exchange_overlap.sql
# Nothing else in slice 1 needs root: no new port, unit, vhost line or environment key (the handlers and screens are files under html/;
# the vhost's canonical-URL rewrites — /shifts/{id}, /exchanges/{id} — already exist).

# Phase 3 slice 2 (2026-09-29) — the week builder: NO root step. No migration (db/015 is still the last), no port, unit, vhost line or environment key: the new
# handlers and screens are files under html/ (/builder, /builder/day, /shifts/new, /shifts/{id}/edit, /templates/, /templates/{id}, /weeks/publish-confirm
# all resolve through the vhost's existing canonical-URL rules), SortableJS is a static file under html/assets/vendors/sortablejs/. After the owner's deploy the
# registry refresh (mcp/action_registry.json, 30 of 69 actions built) is the same one step the installer's plan already lists.

# Phase 3 slice 3 (2026-09-29) — availability and time off: NO root step. No migration (db/015 is still the last), no port, unit, vhost line or environment key: the thirteen handlers
# and six screens are files under html/ (/availability, /time-off, /time-off/new, /time-off/{id}, /time-off/balances, /site/time-off resolve through the vhost's existing canonical-URL
# rules; the stub html/time-off.php was removed so /time-off is html/time-off/index.php). After the owner's deploy the registry refresh (mcp/action_registry.json, 43 of 69 actions
# built) is the same one step the installer's plan already lists.

# Phase 3 slice 4 (2026-09-29) — people, positions and certifications: NO root step. No migration (db/015 is still the last), no port, unit, vhost line or environment key: the eleven handlers and ten screens are files
# under html/ (/staff/, /staff/{id}, /staff/{id}/edit, /positions/, /positions/new, /positions/{id}/edit, /certifications/, /certifications/mine, /certifications/kinds/new and /certifications/kinds/{id}/edit resolve through the
# vhost's existing canonical-URL rules). After the owner's deploy the registry refresh (mcp/action_registry.json, 54 of 69 actions built) is the same one step the installer's plan already lists.

# Phase 3 slice 5 (2026-09-29) — labor and forecast: NO root step. No migration (db/015 is still the last), no port, unit, vhost line or environment key: the five handlers (/labor/*.php) and two
# screens (/forecast, /budget — the stubs html/forecast.php and html/budget.php are now the screens) are files under html/. After the owner's deploy the registry refresh (mcp/action_registry.json,
# 59 of 69 actions built) is the same one step the installer's plan already lists. "Fill from Reservations" answers "Reservations is not connected" until a super-admin approves the connection in the
# operating system (`php /var/www/bin/app_connection.php list` then `approve` — the kernel's, not ours); nothing else is needed on this side (OS_INTERNAL_URL and OS_APPLICATION_TOKEN are already in config/.env).
# tests/setup_dev.sh no longer changes the cluster roles' passwords when config/.env exists (it reads them), so a proof run cannot take the installed application's database login away.
