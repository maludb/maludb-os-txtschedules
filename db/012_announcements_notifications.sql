-- 012: announcements (FR-N1; no staff chat, D7), each person's notification choices (FR-O1), the outbox every
-- notification goes through (email with the application's MaluMail sender; SMS through the KERNEL's service,
-- K6 — txtSchedules never holds a Twilio key and never sees a phone number), and the private calendar feed
-- (FR-M3). A notification carries the shift's facts and a link, never another person's pay or contact details.
BEGIN;

CREATE TABLE announcements (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id      bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    title         text NOT NULL CHECK (length(title) BETWEEN 1 AND 120),
    body          text NOT NULL CHECK (length(body) BETWEEN 1 AND 4000),
    audience      text NOT NULL DEFAULT 'site' CHECK (audience IN ('site', 'position', 'people')),
    position_id   bigint REFERENCES positions(id) ON DELETE SET NULL,
    member_ids    bigint[] NOT NULL DEFAULT '{}',
    pinned_until  date,
    posted_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    removed_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    CHECK (audience <> 'position' OR position_id IS NOT NULL),
    CHECK (audience <> 'people' OR cardinality(member_ids) > 0)
);
CREATE INDEX announcements_scope_idx ON announcements (scope_id, created_at DESC) WHERE removed_at IS NULL;

CREATE TABLE announcement_reads (
    announcement_id bigint NOT NULL REFERENCES announcements(id) ON DELETE CASCADE,
    member_id       bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    read_at         timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (announcement_id, member_id)
);

-- What each person is told, and how — email AND text both on by default (the owner, 2026-09-28: shift reminders go
-- by both). SMS is only ever a request to the kernel (K6), which checks the person's verified phone, their opt-out
-- and the 30-a-day limit itself; when it refuses (rate_limited, no_verified_phone, no_sender, opted_out) the email
-- still goes, and a person with no text on file is simply emailed.
CREATE TABLE notification_prefs (
    member_id         bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    by_email          boolean NOT NULL DEFAULT true,
    by_sms            boolean NOT NULL DEFAULT true,
    kinds             text[] NOT NULL DEFAULT '{schedule_published,shift_changed,exchange,request_decided,announcement,reminder}',
    reminder_minutes  integer CHECK (reminder_minutes IS NULL OR reminder_minutes BETWEEN 0 AND 2880),   -- NULL = the site's default
    updated_at        timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE notification_outbox (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id         bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    scope_id          bigint REFERENCES sites(scope_id) ON DELETE SET NULL,
    channel           text NOT NULL CHECK (channel IN ('email', 'sms')),
    kind              text NOT NULL CHECK (kind IN ('schedule_published', 'shift_changed', 'exchange', 'request_decided', 'announcement', 'reminder')),
    subject           text,                                      -- email only
    body              text NOT NULL CHECK (length(body) BETWEEN 1 AND 4000),   -- an SMS body is cut to the kernel's 480 at send
    reference         text,                                      -- e.g. exchange:412 — carried to the kernel for its record
    dedupe_key        text,                                      -- one reminder per shift and person
    status            text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'failed', 'skipped')),
    kernel_notification_id bigint,                               -- K6's id for an SMS
    attempts          integer NOT NULL DEFAULT 0,
    error             text,
    not_before        timestamptz NOT NULL DEFAULT now(),
    created_at        timestamptz NOT NULL DEFAULT now(),
    sent_at           timestamptz
);
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (not_before) WHERE status = 'queued';
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;

-- The private calendar feed (FR-M3): a token in the URL, only its hash here, revocable; the feed carries only the
-- person's own shifts.
CREATE TABLE calendar_feeds (
    member_id    bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    token_hash   text NOT NULL UNIQUE,
    created_at   timestamptz NOT NULL DEFAULT now(),
    last_used_at timestamptz,
    revoked_at   timestamptz
);

GRANT SELECT, INSERT, UPDATE ON announcements, notification_prefs, notification_outbox, calendar_feeds TO txtschedules_rw;
GRANT SELECT, INSERT ON announcement_reads TO txtschedules_rw;

COMMIT;
