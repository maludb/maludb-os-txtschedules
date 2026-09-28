# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks txtSchedules on the launcher — or types `txtschedules.<domain>` on a phone —
and lands, signed in, at their restaurant on a home screen that looks finished though it holds no work yet; the mirror
and the sites refresh every minute; every request is logged. The kit is in the repo already (copied from Projects in
Phase 0 and adapted for sites); this phase wires it, installs it beside the kernel and proves it, as Projects' Phase 2 did.

## Files
- `app/bootstrap.php` (env from `config/.env`, session cookie `TSSID`, `json_mode_begin()`, the acting member set on the PDO connection, `os_gate()`), `app/db.php`, `app/http.php`, `app/auth.php` (**`held_sites()`, `current_site_id()`, `has_right($right, $scopeId)`, `require_site()`, `require_right()`** — every right is asked at a site), `app/directory.php` (the mirror appliers; **`mirror_apply_scope()` calls `ts_site_materialise()`**), `app/activity.php` (`log_activity()` writes the site: `scope_id`), `app/partial_update.php`.
- `html/settings/tokens/index.php` (screen `tokens`: my MCP tokens — mint shown once, revoke) · `mint.php` (`token_mint`) · `revoke.php` (`token_revoke`, confirm) · `html/activity.php` (screen `activity`).
- `html/sso.php` (`/sso`), `html/sso/logout.php` (`/sso/logout`, 204), `html/logout.php` (own sign-out, POST + CSRF, back to the launcher), `html/login.php` (redirects to `OS_LAUNCHER_URL?app=txtschedules`), `html/index.php`, `html/api/v1/health.php`.
- `app/views/layout.php` (the nxl shell, phone first: a bottom tab bar on a phone — Home, Schedule, Marketplace, Requests, More — and the sidebar from 992 px; `#page-content`; CSRF meta and listener; `htmx:afterSwap` re-init), `app/views/shared/assistant-bar.php`, `app/views/shared/site-switcher.php`, `app/views/sso/refused.php`.
- `html/assets/` = the design system's `examples/assets/` verbatim (+ `htmx.min.js`), plus `html/manifest.webmanifest` and two icons so the application installs to a phone's home screen (D3); **no service worker and no push in version 1**. SortableJS (1.15, MIT) vendored under `html/assets/vendors/sortablejs/` for the week builder (slice 2) only.
- `bin/directory_sync.php` (the change-feed timer, adapted for sites: `scopes[]` → `ts_site_materialise()`, `access[]` → `member_site_roles`, a member with nothing left has their sessions ended; `--full` on the first run, then `scopes.php`), `bin/mint_mcp_token.php`, `bin/build_action_registry.php`, `bin/dev_handoff.php` + `bin/dev_directory.json` (proofs without a kernel).
- `mcp/activity_ingest.py` (payload `application: "txtschedules"`); `deploy/` templates as they are.

## txtSchedules is not Projects
- **Scoped.** The claims' `scopes[]` are the restaurants the person holds, each with `roles[]`; `/sso` writes `member_site_roles` from them and **opens the session at the site chosen on the launcher (`claims.scope`), else the only one held, else the first by name**; a person who holds none is refused (one page, reason `no_site`). Every request re-checks that the session's site is still held (`ts_holds_scope()`); if not, another held site, else the launcher.
- **No directory writes** (`directory.writes: false`). A screen never edits a member or a department; a person's name and email are the kernel's.
- **Roles from db/004**, not Projects': `roles[]` are matched against `ts_roles`; `member_site_roles.roles` is written from the kernel's word alone. The shell's badge shows the highest role held **at the current site**.
- **The launcher's `?app=txtschedules`** brings a signed-out visitor straight back after sign-in (kernel bc23820).
- **Time zones.** Everything a person reads is in the site's time zone (`sites.timezone`); the shell shows the zone name when a person holds sites in two zones.

## The receiver (`/sso`) — in order
1. `verify_sso_token($token, APP_KEY)` and `verify_sso_claims($claims)` — constant-time, both must pass, member ids must match.
2. Expiry, audience (`txtschedules`), then the nonce: `INSERT INTO sso_nonces … ON CONFLICT DO NOTHING`; zero rows = replay → refuse.
3. In one transaction: upsert the mirror row (`members` with `capability` and `roles`), then each claimed scope through `ts_site_materialise()` (a site never seen is created here, seeded — settings, day-parts, time-off types, the generic rules), then `member_site_roles` replaced whole from `scopes[]`, then `ts_ensure_staff_profile(member, site)` for a person holding a staff-or-higher role at a site (the first site is their **main restaurant**, D4).
4. Open the session: `session_regenerate_id(true)`, `$_SESSION['member_id']`, `$_SESSION['site_id']`, fresh CSRF token; `INSERT INTO member_sessions (sha256(session_id), member_id)`.
5. `log_activity('member.sign_on', 'member', $id, ['source' => 'web', 'scope_id' => $site])`; redirect to `/`.
6. Any failure: one page `sso/refused.php` (*"This sign-on link has expired. Open txtSchedules from app.<domain> again."*), `member.sign_on.refused` logged with the reason in `after.reason` — never shown.

## Every request (bootstrap)
- Session cookie `SameSite=Lax`, `Secure` when HTTPS, `HttpOnly`, name `TSSID`, strict mode.
- A signed-in request re-checks the mirror row (`status = 'active'`, `capability IS NOT NULL`), that `member_sessions` has an unended row for this session, and that the session's site is still held; otherwise the session is destroyed and the visitor sent to the launcher.
- Action token (`X-Action-Token` + `X-Action-Relay`, or `X-Approval-Replay`): acts as that member for one request; `Set-Cookie` removed; refuses a member id with no mirror row; **the site of an action comes from its parameters, never from a session** (an agent has none) and is checked with `require_right($right, $site)`.
- `SET app.member_id` on the PDO connection before any query; `log_screen_view()` on every GET that renders a screen.

## `/sso/logout` (POST from the kernel)
`verify_sso_logout_notice` → end every session of the member (`ended_by = 'kernel'`); log `member.sign_out`; 204. An invalid notice: 204 and `member.sign_out.refused`.

## The shell
- Design-system skeleton, exactly. **Phone first**: 375 px is the design; the bottom tab bar carries the five staff screens; the header shows the current restaurant with the switcher (only for a person who holds several) and the person's name; `#assistant-bar` per chat-actions posting to the kernel's chat endpoint with `OS_APPLICATION_TOKEN` and `X-Acting-Member`.
- Sidebar groups (from 992 px, and as **More** on a phone): **Schedule** (My schedule, Team schedule, Marketplace, My requests, Availability, Time off, Announcements), **Manage** (Builder, Approvals, Coverage, Templates, Staff, Positions, Forecast, Budget, Reports — by right), **Restaurant** (Settings, Rules — admin), **Me** (Settings, Tokens, Activity). A menu item shows only if the person holds its right at the current site.
- Home (`/`, screen `dashboard`): the empty version in this phase — **My next shift** ("Nothing scheduled yet"), **My week**, **Waiting for me**, **Announcements**, each an empty state naming the slice that fills it.
- `/activity` (screen `activity`): the member's own trail from `mcp_activity_log`, 50 a page.

## Action manifest entries
- Screens: `activity`, `tokens` (the `dashboard` is slice 1's to fill).
- Actions: `token_mint`, `token_revoke`. No agent approvals.

## Query functions (`app/features/home/queries.php`)
- `home_next_shift(PDO, int $memberId): ?array` · `home_my_week(PDO, int $memberId, int $siteId): array` · `home_waiting(PDO, int $memberId): array` — empty until slice 1; written now so the dashboard's shape is fixed.

## Activity log events
- `member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.sign_out.refused`, `directory.sync`, `screen.view` (dashboard, activity, tokens), `token.mint`, `token.revoke`, `assistant.ask` (the utterance's length and the run id, never the text).

## Proof before Phase 3
- [ ] A token minted by the kernel's own `sso_launch_url()` for member 1 on the txtSchedules application opens a session once (302 → `/`, dashboard 200); the same token again → 403 (`replay`); a token for `app_key = 'hr'` → 403 (`token`); an expired one → 403.
- [ ] **A site not held is refused**: `claims.scope` naming a restaurant the person does not hold opens the one they hold; a person holding none → 403 (`no_site`) and no session; a request after the kernel revokes their only site → 302 to the launcher.
- [ ] The switcher lists exactly the sites held; switching to one not held → 403.
- [ ] An action token for an id with no mirror row → 401 and NO row created; a run token without the relay → 401; with a relay but a run the kernel does not vouch for → 401.
- [ ] `bin/directory_sync.php --full` creates a restaurant for each kernel site (seeded: settings, two day-parts, three time-off types, twelve rules), the incremental run applies 0 and advances the cursor; a new site becomes a restaurant within a minute; a removed one closes with its data kept; a suspended member's sessions end in the same pass.
- [ ] `/sso/logout` with the kernel's notice → 204 and the next request → 302 to the launcher; an invalid notice → 204 and `member.sign_out.refused`.
- [ ] The ingest ships rows tagged `txtschedules` and advances the checkpoint; `/api/v1/health` answers with `ingest_lag` and the sync state.
- [ ] Typing `txtschedules.<domain>` signed out goes to the launcher with `?app=txtschedules` and, after sign-in, straight back (on the deployed kernel).
- [ ] The web app manifest is served and the page is installable to a phone's home screen (Chromium's installability check); there is no service worker.
- [ ] Headless Chromium at 375 × 740 and 1280 × 800: `scrollWidth` = viewport, the bottom tab bar on the phone and the sidebar on the desktop, the bar thumb-reachable, HTMX navigation pushing URL and title, no console errors; the dashboard whole with JavaScript off.
- [ ] `php /var/www/bin/app_install.php plan /srv/apps/txtschedules` — no stop; kernel side: catalog `txtschedules`, the application row with `scope_kind location`, its scopes (the same sites as Reservations), grants (a member as Staff at a site through the residents grant).

## Open questions
- The theme's logo: txtSchedules needs its own mark (the owner's artwork).
