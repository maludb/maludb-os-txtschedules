# Build spec: announcements and notifications (slice 6)

Replicates slice 1's files, gates, flow and proof style. Announcements (no staff chat, D7), each person's notification
choices, **the outbox and the worker that empties it** — email through MaluMail, **text through the kernel's SMS service
(K6)**, shift **reminders by both text and email** (D12) — and the private calendar feed. Slices 1–5 queue rows into
`notification_outbox`; this slice sends them.
Schema: `announcements`, `announcement_reads`, `notification_prefs` (email **and** text on by default), `notification_outbox`,
`calendar_feeds` (db/012); the views `mcp_announcements`; `ts_exchanges_expire()` (db/010). Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `announcements-list` | `/announcements/?site=` | The live announcements for my restaurant, pinned first; the poster sees read counts |
| `announcement-add` | `/announcements/new?site=&audience=` | Post an announcement (`announce.post`) |
| `settings` | `/settings/?tab=` | **How I am told** (email, text, which events, the reminder lead time) and **my calendar link** |

## Announcements
- Cards: title, body (plain text with line breaks and links — no HTML), who posted and when, a **Pinned until** chip; unread ones marked; opening one records the read (`announcement_read`). Staff see the announcements whose **audience** includes them: the whole restaurant, a position, or named people. A poster (`announce.post`) also sees **who has read it** (a count and, on tap, the names) and can **Remove** it (confirm).
- **Post** (`announcement_post`): restaurant, title (≤ 120), body (≤ 4,000), audience (restaurant / a position / named people), pinned until (optional); a **confirm** page says *"This will be sent to 23 people by email and text."* — the recipient count and channels (`external_send` for an agent: paused in the kernel's approvals). Recipients are resolved server-side; no one outside the restaurant's staff is ever included.
- No replies, no reactions, no chat.

## My notification settings
- **Channels**: *Email* and *Text*, both on for a new person; **Which events**: schedule published, my shift changed, trades and requests, decisions, announcements, shift reminders (each on/off); **Remind me before a shift**: *2 hours* (the restaurant's default), or 30 min – 48 h. A person with **no verified phone in the operating system** sees *"Texts need a phone number verified in the operating system"* with a link to `/settings/channels` on `app.<domain>` — txtSchedules never sees a number.
- **Calendar link** (`calendar_feed_rotate`): *"Add my shifts to my phone's calendar"* — a private URL (`/calendar/{token}.ics`); shown once when made, only its hash is kept; **Make a new link** revokes the old one (confirm). The feed carries **only the person's own published shifts** for the next 60 days: title *"Server — Airport"*, times in UTC with the restaurant's name in the location — no other person's name, no pay.

## The outbox and its worker (`bin/notifications.php`, the timer every minute)
1. **Expire** — `ts_exchanges_expire()`; for each expired offer or ask, queue the holder's notice and log `exchange.expire`.
2. **Remind** — for each published, scheduled, assigned shift starting within the person's lead time (their `reminder_minutes`, else the restaurant's `reminder_minutes_before`) and not yet reminded, queue **one reminder per channel the person has on**: `dedupe_key = reminder:{shift}:{member}:{channel}` (unique — a second minute never repeats it).
3. **Send** — for each queued row whose `not_before` has come:
   - **email**: MaluMail API (`MALUMAIL_API_KEY`, sender `MAIL_FROM`), the person's kernel email; one retry per minute up to five attempts, then `failed` with the error.
   - **text**: `POST {OS_INTERNAL_URL}/api/v1/notify/sms.php {member_id, text ≤ 480, reference}` with the application token. `202` → `sent` (`kernel_notification_id` kept). **Every refusal is a skip, never a stop**: `no_sender`, `no_verified_phone`, `opted_out`, `rate_limited`, `not_held` → the row is `skipped` with the code, **and the person's email row is untouched** (email is an independent row, so a reminder still arrives by email — D12); a person with no email and no text on file is noted `failed`. The 30-a-day limit counts every text, so a person with many shifts gets their email always and their texts until the limit.
4. **Forecast** — once a day, `forecast_fill` for each restaurant's next 14 days (slice 5).
- A notification carries **the shift's facts and a link** — restaurant, day, time, position — and, for an exchange, the other person's first name; **never another person's pay, phone or email**. A reminder text reads *"txtSchedules: Server at Airport today 5:00–11:00 pm. https://…/shifts/412"* (the kernel puts the application's name in front).
- Nothing is sent for a person who turned that event off, for an inactive person, or from an evaluation run.

## Files (exactly these)
- `html/announcements/index.php` · `form.php` · `save.php` · `remove.php` · `read.php` · `html/settings/index.php` · `prefs.php` · `calendar-feed.php` · `html/calendar/feed.php` (the `.ics`, a public path checked by token hash) · `bin/notifications.php`
- `app/features/announcements/queries.php` · `present.php` — `app/features/notify/queue.php` (`notify()`, shared by every slice) · `send.php` (the worker's email and K6 calls) · `remind.php` · `app/features/calendar/ics.php`
- `app/views/announcements/list.php` · `form.php` · `confirm.php` — `app/views/settings/index.php` · `partials/prefs.php` · `calendar.php`

## Query functions (signatures fixed)
- `find_announcements(PDO, int $siteId, int $memberId, int $limit = 25): array` (mcp_announcements) · `announcement_recipients(PDO, array $announcement): array` · `post_announcement(PDO, array $f, int $by): array` (queues one notice per recipient and channel) · `remove_announcement(PDO, int $id, int $by): void` · `mark_read(PDO, int $id, int $memberId): void`
- `notify(PDO, int $memberId, int $siteId, string $kind, string $subject, string $body, ?string $reference = null, ?string $dedupe = null): int` (one row per channel the person has on and the kind allows; returns the count) · `queued_batch(PDO, int $limit = 100): array` · `mark_sent(PDO, int $id, ?int $kernelId): void` · `mark_skipped(PDO, int $id, string $why): void` · `mark_failed(PDO, int $id, string $error): void`
- `find_prefs(PDO, int $memberId): array` · `save_prefs(PDO, int $memberId, array $f): array` · `rotate_feed(PDO, int $memberId): string` (returns the token once) · `feed_for_token(PDO, string $token): ?array` · `ics_for(PDO, int $memberId): string`
- `due_reminders(PDO, int $limit = 200): array` · `send_email(array $row): string` · `send_sms(array $row): array` (returns `['ok'|'skipped'|'error', code|message]`)

## Handlers (every one: `require_post(); verify_csrf();` the gate at the site; one transaction; `log_activity` with the site; `emit_action_status`)
- `announcements/save.php` (`announcement_post`): `announce.post`; log `announcement.post` (`after`: audience, position_id, recipients = count, pinned_until). `remove.php`: the poster or `settings.manage`; log `announcement.remove`. `read.php` (`announcement_read`): own; log `announcement.read` (quiet: no `screen.view` beside it).
- `settings/prefs.php` (`prefs_save`): own; log `prefs.save` (`before`/`after`: by_email, by_sms, kinds, reminder_minutes). `settings/calendar-feed.php` (`calendar_feed_rotate`): own; log `calendar_feed.rotate`.
- The worker logs `notification.send` per row (`after`: kind, channel, outcome, code — no body) and `exchange.expire`.

## Action manifest entries
- Screens: `announcements-list`, `announcement-add`, `settings`.
- Actions: `announcement_post`, `announcement_remove`, `announcement_read`, `prefs_save`, `calendar_feed_rotate`. Agent approval: `announcement_post` (`external_send`).

## Activity log events
- `screen.view`, `announcement.post`, `announcement.remove`, `announcement.read`, `prefs.save`, `calendar_feed.rotate`, `notification.send`, `exchange.expire`. Every row carries `scope_id` where there is one.

## Out of scope for this slice
- Push notifications (the web app is installable; push later); staff chat (D7); a texting service of txtSchedules' own (never — K6); replies to a text.

## Proof (live as members; the worker run by hand and by its timer; headless Chromium at 375 × 740 and 1280 × 800)
- **Announcements**: a manager posts to the restaurant, to one position, to two named people — each audience sees exactly its own (a staff member of another position sees nothing of the position one; a person of another restaurant sees nothing); the confirm page counts the recipients; read receipts show for the poster only; an agent's `announcement_post` is `pending_approval` (Phase 4).
- **Both channels for a reminder (D12)**: a person with both on and a verified phone gets one reminder email and one text for a shift inside their lead time and **not a second** on the next minute (the dedupe key); with the kernel returning `rate_limited` (the 31st text of the day), `no_verified_phone`, `no_sender` or `opted_out` **the email is still sent** and the text row is `skipped` with the code; with text off in preferences only the email is queued; a person with both off gets nothing.
- **K6 seen from the kernel**: the texts appear in the kernel's `application_notifications` with `application.notify` rows carrying no text; txtSchedules' `config/.env` holds no Twilio key and its tables no phone number (grep both).
- **Email**: a message reaches a fresh `example.invalid`-style test address through MaluMail's test path (MaluMail suppresses one after its first bounce — use a **fresh** address per run, per the kernel's smoke rule).
- **Nothing sensitive in a notice**: grep every queued body for the fixture wages, phones and emails — none; an exchange notice names the other person's first name only.
- **Expiry**: an offer nobody took expires at the shift's start (or the cutoff, per the setting): status `expired`, the shift stays with its holder, the holder is told once.
- **Calendar feed**: the `.ics` validates (parsed by a calendar library), holds only the person's own published shifts, no other names, no pay; **Make a new link** kills the old (404); a wrong token → 404.
- **375 px and 1280 px**: no horizontal scroll; the settings toggles are ≥ 44 px; the announcement cards stack.

## Open questions
- (none)
