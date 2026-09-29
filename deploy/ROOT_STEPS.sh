#!/usr/bin/env bash
# What the owner runs as root, at the end, for txtSchedules. Commented per step; nothing here has been run.
set -euo pipefail

# Phase 3 slice 1 (2026-09-29): db/015_exchange_overlap.sql — an overlap is refused when a shift is TAKEN (ts_exchange_check_taker).
# The installer's plan (2026-09-29) shows txtschedules ALREADY installed (application 56), so subello_txtschedules exists at db/014: apply the one file by hand
# (a fresh database from db/*.sql would already have it):
#   sudo -u postgres psql -v ON_ERROR_STOP=1 -d subello_txtschedules -f /srv/apps/txtschedules/db/015_exchange_overlap.sql
# Nothing else in slice 1 needs root: no new port, unit, vhost line or environment key (the handlers and screens are files under html/;
# the vhost's canonical-URL rewrites — /shifts/{id}, /exchanges/{id} — already exist).
