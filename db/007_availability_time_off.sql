-- 007: availability and time off. Time off LIVES HERE for the staff txtSchedules schedules and replaces HR's
-- leave desk for them (D5): types per site, balances per person per type, a ledger every change goes through,
-- requests a manager decides, and blackout dates. HR learns what was taken through the kernel's application
-- read (K7: txtSchedules shares time_off_taken) — never through a shared table.
BEGIN;

-- Recurring weekly availability. A change takes effect from a date and, when the site requires it (site_settings.
-- availability_needs_approval), waits for a manager: status pending → approved | declined.
CREATE TABLE availability_rules (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id       bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    scope_id        bigint REFERENCES sites(scope_id) ON DELETE CASCADE,       -- NULL = every site the person works
    weekday         smallint NOT NULL CHECK (weekday BETWEEN 0 AND 6),        -- 0 Sunday
    starts_at       time NOT NULL,
    ends_at         time NOT NULL,                                            -- before starts_at = past midnight
    kind            text NOT NULL CHECK (kind IN ('available', 'unavailable', 'preferred')),
    effective_from  date NOT NULL DEFAULT current_date,
    effective_to    date,
    status          text NOT NULL DEFAULT 'approved' CHECK (status IN ('pending', 'approved', 'declined', 'replaced')),
    decided_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at      timestamptz,
    decision_note   text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (effective_to IS NULL OR effective_to >= effective_from)
);
CREATE INDEX availability_member_idx ON availability_rules (member_id, weekday) WHERE status IN ('pending', 'approved');

-- Time-off types per site (FR-A5): vacation/PTO, sick, unpaid, other — the site's admin keeps them.
CREATE TABLE time_off_types (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id        bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    key             text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,29}$'),
    name            text NOT NULL,
    paid            boolean NOT NULL DEFAULT false,
    tracks_balance  boolean NOT NULL DEFAULT false,
    allow_negative  boolean NOT NULL DEFAULT false,      -- may a request take the balance below zero
    sort_order      integer NOT NULL DEFAULT 0,
    archived_at     timestamptz,
    UNIQUE (scope_id, key)
);

-- The balance is the sum of its ledger, kept here for reading; only ts_time_off_post() changes either.
CREATE TABLE time_off_balances (
    member_id       bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    type_id         bigint NOT NULL REFERENCES time_off_types(id) ON DELETE CASCADE,
    balance_hours   numeric(7,2) NOT NULL DEFAULT 0,
    updated_at      timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, type_id)
);

CREATE TABLE time_off_requests (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id       bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    scope_id        bigint NOT NULL REFERENCES sites(scope_id),              -- the site deciding (the type's)
    type_id         bigint NOT NULL REFERENCES time_off_types(id),
    starts_at       timestamptz NOT NULL,
    ends_at         timestamptz NOT NULL,
    hours           numeric(6,2) NOT NULL CHECK (hours > 0),                 -- what it draws from the balance
    note            text,                                                    -- the person's; a category is the type
    status          text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'declined', 'cancelled')),
    decided_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at      timestamptz,
    decision_note   text,
    cancelled_at    timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (ends_at > starts_at)
);
CREATE INDEX time_off_requests_member_idx ON time_off_requests (member_id, starts_at);
CREATE INDEX time_off_requests_scope_idx  ON time_off_requests (scope_id, status, starts_at);

CREATE TABLE time_off_ledger (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id       bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    type_id         bigint NOT NULL REFERENCES time_off_types(id),
    delta_hours     numeric(7,2) NOT NULL CHECK (delta_hours <> 0),
    reason          text NOT NULL CHECK (reason IN ('grant', 'adjustment', 'request_approved', 'request_cancelled')),
    request_id      bigint REFERENCES time_off_requests(id),
    note            text,
    recorded_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX time_off_ledger_member_idx ON time_off_ledger (member_id, type_id, created_at);

-- Days no time off may be taken (FR-A4): a request touching one is refused with the reason.
CREATE TABLE blackout_dates (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    on_date     date NOT NULL,
    reason      text NOT NULL,
    UNIQUE (scope_id, on_date)
);

-- The one way a balance moves: a ledger row and the balance together, refusing below zero unless the type allows.
CREATE OR REPLACE FUNCTION ts_time_off_post(p_member bigint, p_type bigint, p_delta numeric, p_reason text,
                                            p_request bigint, p_note text, p_by bigint) RETURNS numeric
    LANGUAGE plpgsql AS $$
DECLARE
    v_type time_off_types%ROWTYPE;
    v_new  numeric;
BEGIN
    SELECT * INTO v_type FROM time_off_types WHERE id = p_type;
    IF NOT v_type.tracks_balance THEN
        RETURN NULL;                                    -- nothing to keep
    END IF;
    INSERT INTO time_off_balances (member_id, type_id, balance_hours) VALUES (p_member, p_type, 0)
        ON CONFLICT (member_id, type_id) DO NOTHING;
    UPDATE time_off_balances SET balance_hours = balance_hours + p_delta, updated_at = now()
     WHERE member_id = p_member AND type_id = p_type
    RETURNING balance_hours INTO v_new;
    IF v_new < 0 AND NOT v_type.allow_negative THEN
        RAISE EXCEPTION 'Not enough % left: this needs % hours.', lower(v_type.name), -p_delta USING ERRCODE = 'P0001';
    END IF;
    INSERT INTO time_off_ledger (member_id, type_id, delta_hours, reason, request_id, note, recorded_by)
    VALUES (p_member, p_type, p_delta, p_reason, p_request, p_note, p_by);
    RETURN v_new;
END$$;

-- A request is refused on insert when it touches a blackout date of its site.
CREATE OR REPLACE FUNCTION ts_time_off_request_check() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE
    v_tz text;
    v_reason text;
BEGIN
    SELECT timezone INTO v_tz FROM sites WHERE scope_id = NEW.scope_id;
    SELECT b.reason INTO v_reason FROM blackout_dates b
     WHERE b.scope_id = NEW.scope_id
       AND b.on_date BETWEEN (NEW.starts_at AT TIME ZONE v_tz)::date AND ((NEW.ends_at - interval '1 second') AT TIME ZONE v_tz)::date
     LIMIT 1;
    IF v_reason IS NOT NULL THEN
        RAISE EXCEPTION 'No time off on that date: %.', v_reason USING ERRCODE = 'P0001';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM time_off_types t WHERE t.id = NEW.type_id AND t.scope_id = NEW.scope_id AND t.archived_at IS NULL) THEN
        RAISE EXCEPTION 'That kind of time off is not offered here.' USING ERRCODE = 'P0001';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER time_off_requests_check BEFORE INSERT ON time_off_requests FOR EACH ROW EXECUTE FUNCTION ts_time_off_request_check();

-- Decide a pending request (approve draws the balance down); cancel an approved or pending one (restores it).
CREATE OR REPLACE FUNCTION ts_time_off_decide(p_request bigint, p_approve boolean, p_by bigint, p_note text) RETURNS text
    LANGUAGE plpgsql AS $$
DECLARE r time_off_requests%ROWTYPE;
BEGIN
    SELECT * INTO r FROM time_off_requests WHERE id = p_request FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Request not found.' USING ERRCODE = 'P0001'; END IF;
    IF r.status <> 'pending' THEN RAISE EXCEPTION 'This request was already %.', r.status USING ERRCODE = 'P0001'; END IF;
    IF p_approve THEN
        PERFORM ts_time_off_post(r.member_id, r.type_id, -r.hours, 'request_approved', r.id, NULL, p_by);
    END IF;
    UPDATE time_off_requests SET status = CASE WHEN p_approve THEN 'approved' ELSE 'declined' END,
           decided_by = p_by, decided_at = now(), decision_note = p_note WHERE id = p_request;
    RETURN CASE WHEN p_approve THEN 'approved' ELSE 'declined' END;
END$$;

CREATE OR REPLACE FUNCTION ts_time_off_cancel(p_request bigint, p_by bigint) RETURNS text
    LANGUAGE plpgsql AS $$
DECLARE r time_off_requests%ROWTYPE;
BEGIN
    SELECT * INTO r FROM time_off_requests WHERE id = p_request FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Request not found.' USING ERRCODE = 'P0001'; END IF;
    IF r.status NOT IN ('pending', 'approved') THEN RAISE EXCEPTION 'This request was already %.', r.status USING ERRCODE = 'P0001'; END IF;
    IF r.status = 'approved' THEN
        PERFORM ts_time_off_post(r.member_id, r.type_id, r.hours, 'request_cancelled', r.id, NULL, p_by);
    END IF;
    UPDATE time_off_requests SET status = 'cancelled', cancelled_at = now() WHERE id = p_request;
    RETURN 'cancelled';
END$$;

GRANT SELECT, INSERT, UPDATE ON availability_rules, time_off_types, time_off_requests, blackout_dates TO txtschedules_rw;
GRANT DELETE ON blackout_dates TO txtschedules_rw;
GRANT SELECT, INSERT, UPDATE ON time_off_balances TO txtschedules_rw;
GRANT SELECT, INSERT ON time_off_ledger TO txtschedules_rw;
GRANT EXECUTE ON FUNCTION ts_time_off_post(bigint, bigint, numeric, text, bigint, text, bigint),
    ts_time_off_decide(bigint, boolean, bigint, text), ts_time_off_cancel(bigint, bigint) TO txtschedules_rw;

COMMIT;
