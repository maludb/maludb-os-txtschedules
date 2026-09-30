#!/usr/bin/env bash
# txtSchedules — EVERYTHING that root or the owner must do to take Phase 3 (slices 1-7) and Phase 4 (the two MCP servers) live, in order.
# Written 2026-09-29 at the end of Phase 3 (slice 7); Phase 4 (2026-09-30) added step 1b, the MCP units and the vhost's proxy lines to step 3, and the notes on
# steps 3 and 6 to 9 — all NEW lines are marked PHASE 4. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database,
# php -S / a private Apache and stubs, and never touch the installed application's database, Apache or systemd.
# Read it top to bottom; every step says who runs it, what it does and how to see that it worked. Steps 1-3 are the
# order that matters; 4-9 are the owner's keys and decisions and can come any time after.
#
# Two ways to run the file itself: `bash deploy/ROOT_STEPS.sh` prints this plan and stops (the default, `plan`);
# `sudo bash deploy/ROOT_STEPS.sh apply` runs steps 1 and 3 (the two commands with no decision in them; 2 is a git pull).
# Steps 4-6 and 8-9 need the owner's keys, numbers or approval and are left to be run by hand, as written; 7 is only for
# someone who runs step 3's work by hand.
set -euo pipefail
APP=/srv/apps/txtschedules
KERNEL=/var/www
MODE=${1:-plan}
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }

echo "== 0. Look first (read-only, any user)"
echo "   php $KERNEL/bin/app_install.php plan $APP --scheme https        # what is done and what is not"
echo "   curl -s http://127.0.0.1:8101/api/v1/health.php                  # the installed application's health: database ok"

# ------------------------------------------------------------------------------------------------------------------
# 1. The one migration since the application was installed: db/015 (slice 1). ROOT (postgres).
#    An overlap is refused when a shift is TAKEN (ts_exchange_check_taker). The installed database subello_txtschedules
#    is at db/014 — the installer never re-runs migrations on a database that exists ("an upgrade applies the new ones by
#    hand, in order"), so this file is applied by hand. It only replaces one function; it is safe to run twice.
#    Nothing in slices 2-7 needs a migration: db/015 is the last file.
echo "== 1. Migration db/015 (as postgres)"
run sudo -u postgres psql -v ON_ERROR_STOP=1 -d subello_txtschedules -f "$APP/db/015_exchange_overlap.sql"
#    See that it worked:  sudo -u postgres psql -d subello_txtschedules -Atc "select prosrc like '%overlap%' from pg_proc where proname = 'ts_exchange_check_taker'"

# ------------------------------------------------------------------------------------------------------------------
# 1b. PHASE 4 — the second (and last) migration: db/016. ROOT (postgres). MUST come BEFORE step 3: the MCP servers call mcp_member_kind() and
#    mcp_admit_agent() the moment an agent or the command bar reaches them, and the `time_off_taken` share reads ts_time_off_taken().
#    Additive (two views, three functions, grants), safe to run twice. It gives the records role EXECUTE on the three functions and SELECT on two new
#    views (mcp_time_off_ledger, mcp_exchange_invitees); it changes no existing object.
echo "== 1b. Migration db/016 (as postgres) — PHASE 4"
run sudo -u postgres psql -v ON_ERROR_STOP=1 -d subello_txtschedules -f "$APP/db/016_mcp_servers.sql"
#    See that it worked:  sudo -u postgres psql -d subello_txtschedules -Atc "select count(*) from pg_proc where proname in ('mcp_admit_agent','mcp_member_kind','ts_time_off_taken')"   -> 3

# ------------------------------------------------------------------------------------------------------------------
# 2. Web/application files: NOTHING to install. Slices 2-7 are files under html/ and app/ (the vhost's canonical-URL
#    rewrites already cover /builder, /shifts/{id}, /time-off/{id}, /staff/{id}, /site/, /site/day-parts, /rules/,
#    /reports/ …); SortableJS is a static file under html/assets/vendors/sortablejs/. If the repository is deployed by
#    `git pull` in $APP, that IS the deploy — Apache serves PHP as it finds it; no reload is needed.
echo "== 2. Deploy the code (git pull in $APP — nothing else to install)"

# ------------------------------------------------------------------------------------------------------------------
# 3. The installer's apply (ROOT). It is idempotent and does exactly the steps the plan lists as `todo`:
#      - installs txtschedules-notifications.service and .timer (the notifications worker, bin/notifications.php — one
#        pass a minute: expires offers, queues shift reminders and certification warnings, sends the outbox (email through
#        MaluMail, texts through the kernel's K6), and once a day fills each restaurant's next 14 days of covers from
#        Reservations), runs `systemctl daemon-reload`, and enables --now the three timers (activity ingest, directory
#        sync, notifications);
#      - registers the Calendar feed endpoint (/api/v1/calendar/{token}.ics) with the kernel;
#      - REFRESHES the kernel's registry file mcp/registries/txtschedules.json from this repository's
#        mcp/action_registry.json (69 of 69 actions built, 43 of 43 screens) and restarts certstudy-actions-mcp so the
#        Actions MCP serves them. (Step 7 does the same two things by hand if you would rather not run apply.)
#      - PHASE 4: installs txtschedules-records-mcp.service and txtschedules-activity-mcp.service (deploy/, FastMCP from mcp/venv, loopback ports
#        MCP_RECORDS_PORT / MCP_ACTIVITY_PORT the installer picks and writes to config/.env), re-renders the Apache vhost (deploy/apache-txtschedules.conf now
#        proxies https://txtschedules.<domain>/mcp/records and /mcp/activity to them, Host not preserved), `apache2ctl configtest` and reload, and registers the
#        two endpoints ('Records MCP', 'Activity MCP' — the names the agents' tool grants and the run-facts gate use) once they answer;
#      - reads the roles and rights from the application (app_roles): the kernel calls the records MCP with its own 60-second token. That step needs the
#        endpoints registered first, so THE FIRST `apply` may say 'roles: not yet' — run `apply` a SECOND time (it is idempotent) and it reads the four roles
#        and eleven rights. See them in the kernel: Applications > txtSchedules > Roles.
#    The units are rendered from deploy/ ({{APP_DIR}}). To install the two notifications units by hand instead:
#      sed "s#{{APP_DIR}}#$APP#g" $APP/deploy/txtschedules-notifications.service > /etc/systemd/system/txtschedules-notifications.service
#      install -m 644 $APP/deploy/txtschedules-notifications.timer /etc/systemd/system/txtschedules-notifications.timer
#      systemctl daemon-reload && systemctl enable --now txtschedules-notifications.timer
#    See that it worked:  systemctl status txtschedules-notifications.timer ; journalctl -u txtschedules-notifications -n 5
#                         (the report is one JSON line a pass)   and   php $KERNEL/bin/app_install.php plan $APP --scheme https   (no `todo` left)
echo "== 3. The installer's apply"
run php "$KERNEL/bin/app_install.php" apply "$APP" --scheme https
echo "== 3b. PHASE 4 — the second apply (roles read after the endpoints are registered; nothing else changes)"
run php "$KERNEL/bin/app_install.php" apply "$APP" --scheme https
#    See that the MCP servers are up:  systemctl status txtschedules-records-mcp txtschedules-activity-mcp ; journalctl -u txtschedules-records-mcp -n 5
#    and that the front answers 401 to an anonymous caller:  curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:<MCP_RECORDS_PORT>/mcp    -> 401

if [ "$MODE" != apply ]; then
cat <<'OWNER'

== The owner's steps (need a key, a number or a decision — run by hand)

4. MaluMail (email). config/.env needs, from the business's MaluMail account (a verified sending domain and an API key,
   shown once in its portal); add these three lines to /srv/apps/txtschedules/config/.env (group www-data keeps read):
       MALUMAIL_API_KEY=mm_...
       MAIL_FROM=noreply@<the verified domain>
       MAIL_FROM_NAME="txtSchedules"
   Until they are set the worker leaves every email QUEUED (nothing is lost, no attempt is counted) and still sends the
   texts; the moment they are set the emails go at the next pass (within a minute, no restart).

5. The text sender (K6). Texts go only through the kernel: a super-admin sets the business's notification number
       php /var/www/bin/notify_endpoint_set.php --address +1XXXXXXXXXX --account-sid AC... (--secret-id N | --token-stdin)
       php /var/www/bin/notify_endpoint_set.php --show
   Until then the kernel answers no_sender, every text is skipped with that word and the person is emailed. No Twilio
   key ever lives in txtSchedules.

6. Reservations' covers (K7), and — PHASE 4 — HR's read of approved time off (D5, D11). "Fill from Reservations" and the daily 14-day fill answer "Reservations is not connected"
   until a super-admin approves the connection:
       php /var/www/bin/app_connection.php list
       php /var/www/bin/app_connection.php approve <connection id>
   Until then the worker logs a forecast.fill row saying so, once a day per restaurant; typed forecasts stand.
   PHASE 4: the SAME command approves the other connection txtSchedules now serves — HR reading `time_off_taken` (a people share: only an application with
   directory writes, i.e. HR, may be connected; the kernel refuses any other consumer). It shows in `app_connection.php list` once HR declares the read in its
   own maludb-os.json `reads[]` and the installer records it (HR's side is HR's, not built here). The tool itself is live on the records MCP and answers the
   kernel's token alone.

7. If you do not run `apply` (step 3): refresh the kernel's registry and restart its Actions MCP by hand.
       # the file is the application's own registry wrapped in the header the kernel already holds; the installer writes it
       # with `php /var/www/bin/app_install.php apply /srv/apps/txtschedules --scheme https` (the 'registry' step) —
       # after it: systemctl restart certstudy-actions-mcp
   See that it worked:  the kernel's mcp/registries/txtschedules.json holds "built": true for rule_save and 69 built actions.

8. The agents (a decision). The plan proposes two; hire them when you want them. PHASE 4: their READS now exist (the records and activity MCP servers, gated
   by the kernel's run-facts call), so both can answer and act; hire AFTER step 3b so the tools are registered with the kernel when the grants are made:
       php /var/www/bin/hire_application_agent.php --app txtschedules --agent expert
       php /var/www/bin/hire_application_agent.php --app txtschedules --agent scheduler
   D14: an agent approving time off pauses for a person; announcement_post is an external send and pauses too. PHASE 4 checked with the kernel's own
   Actions-MCP code and a stubbed hook: the 13 pausing actions (week_publish, coverage_request, announcement_post external_send; ten more `other`) ask the
   approval hook FIRST; the scheduler's draft actions never pause. The LIVE pause (a real agent run, a real approval card) is Phase 5's proof.
   Also PHASE 4 (do once, after hiring): grant each agent its restaurants and role in the operating system (the expert: Staff at every restaurant, or as you decide;
   the scheduler: Manager at the restaurants it drafts for) — an agent sees only the restaurants it holds, exactly as a person does.

9. DNS and TLS for txtschedules.subello.com at the proxy in front (the owner's, as for hr. and projects.), and the sites:
   each restaurant is a kernel site (location kind `site`); grant people their role at their restaurant in the operating
   system (a resident of a site gets the application by that grant). After the first sign-on the mirror fills itself.

Afterwards, any time:  curl -s http://127.0.0.1:8101/api/v1/health.php   -> "database":"ok"
OWNER
fi
