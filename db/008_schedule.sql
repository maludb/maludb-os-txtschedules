-- 008: the schedule — a week per site (draft until published), its shifts (open when unassigned, overnight
-- allowed, a cancelled shift kept), and templates. One person never holds two overlapping shifts: an exclusion
-- constraint, not a promise.
BEGIN;

CREATE TABLE schedule_weeks (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id      bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    week_start    date NOT NULL,                              -- the site's first day of the week (site_settings.week_start)
    status        text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
    published_at  timestamptz,
    published_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    notes         text,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scope_id, week_start)
);
CREATE TRIGGER schedule_weeks_touch BEFORE UPDATE ON schedule_weeks FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE shifts (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id           bigint NOT NULL REFERENCES sites(scope_id),
    week_id            bigint NOT NULL REFERENCES schedule_weeks(id) ON DELETE CASCADE,
    position_id        bigint NOT NULL REFERENCES positions(id),
    starts_at          timestamptz NOT NULL,
    ends_at            timestamptz NOT NULL,
    break_minutes      integer NOT NULL DEFAULT 0 CHECK (break_minutes BETWEEN 0 AND 240),   -- planned, unpaid
    assignee_member_id bigint REFERENCES members(id),             -- NULL = an open shift, to be picked up
    status             text NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'cancelled')),
    note               text,
    published_at       timestamptz,                               -- set when its week is published (or when added to a published week)
    changed_after_publish_at timestamptz,                          -- the last change people were told about
    cancelled_at       timestamptz,
    cancel_reason      text,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (ends_at > starts_at AND ends_at - starts_at <= interval '16 hours'),
    CHECK (status = 'scheduled' OR cancelled_at IS NOT NULL),
    -- NF: one person, one place at a time — a cancelled shift no longer counts.
    CONSTRAINT shifts_no_overlap EXCLUDE USING gist (
        assignee_member_id WITH =, tstzrange(starts_at, ends_at) WITH &&
    ) WHERE (assignee_member_id IS NOT NULL AND status = 'scheduled')
);
CREATE INDEX shifts_scope_time_idx ON shifts (scope_id, starts_at);
CREATE INDEX shifts_assignee_idx ON shifts (assignee_member_id, starts_at) WHERE status = 'scheduled';
CREATE INDEX shifts_week_idx ON shifts (week_id);
CREATE TRIGGER shifts_touch BEFORE UPDATE ON shifts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A shift's position and week belong to its site; a shift added to or changed in a published week is published
-- at once and marked changed (people are told: FR-B4).
CREATE OR REPLACE FUNCTION ts_shift_check() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE v_week schedule_weeks%ROWTYPE;
BEGIN
    SELECT * INTO v_week FROM schedule_weeks WHERE id = NEW.week_id;
    IF v_week.scope_id <> NEW.scope_id THEN
        RAISE EXCEPTION 'The shift and its week are at different restaurants.' USING ERRCODE = 'P0001';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM positions p WHERE p.id = NEW.position_id AND p.scope_id = NEW.scope_id) THEN
        RAISE EXCEPTION 'That position is not one of this restaurant''s.' USING ERRCODE = 'P0001';
    END IF;
    IF v_week.status = 'published' THEN
        IF TG_OP = 'INSERT' THEN
            NEW.published_at := now();
            NEW.changed_after_publish_at := now();
        ELSIF (NEW.starts_at, NEW.ends_at, NEW.position_id, NEW.assignee_member_id, NEW.status, NEW.break_minutes)
              IS DISTINCT FROM (OLD.starts_at, OLD.ends_at, OLD.position_id, OLD.assignee_member_id, OLD.status, OLD.break_minutes) THEN
            NEW.changed_after_publish_at := now();
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER shifts_check BEFORE INSERT OR UPDATE ON shifts FOR EACH ROW EXECUTE FUNCTION ts_shift_check();

-- NF-4: nothing ever published is deleted — a published shift is cancelled and kept.
CREATE OR REPLACE FUNCTION ts_shift_no_delete_published() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.published_at IS NOT NULL THEN
        RAISE EXCEPTION 'A published shift is cancelled, never deleted.' USING ERRCODE = 'P0001';
    END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER shifts_no_delete_published BEFORE DELETE ON shifts FOR EACH ROW EXECUTE FUNCTION ts_shift_no_delete_published();

-- Publish a week: drafts become visible to staff. Answers how many shifts were published. The PHP handler
-- sends the notifications (db/012 outbox) and logs week.publish with the warnings overridden.
CREATE OR REPLACE FUNCTION ts_publish_week(p_week bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql AS $$
DECLARE n integer;
BEGIN
    UPDATE schedule_weeks SET status = 'published', published_at = COALESCE(published_at, now()), published_by = p_by
     WHERE id = p_week;
    IF NOT FOUND THEN RAISE EXCEPTION 'Week not found.' USING ERRCODE = 'P0001'; END IF;
    UPDATE shifts SET published_at = now() WHERE week_id = p_week AND published_at IS NULL AND status = 'scheduled';
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

CREATE TABLE schedule_templates (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    name        text NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    from_week_id bigint REFERENCES schedule_weeks(id) ON DELETE SET NULL,
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    archived_at timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE template_shifts (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    template_id        bigint NOT NULL REFERENCES schedule_templates(id) ON DELETE CASCADE,
    position_id        bigint NOT NULL REFERENCES positions(id),
    weekday            smallint NOT NULL CHECK (weekday BETWEEN 0 AND 6),
    starts_at          time NOT NULL,
    ends_at            time NOT NULL,                  -- before starts_at = past midnight
    break_minutes      integer NOT NULL DEFAULT 0 CHECK (break_minutes BETWEEN 0 AND 240),
    assignee_member_id bigint REFERENCES members(id) ON DELETE SET NULL   -- kept when the person still works there
);

GRANT SELECT, INSERT, UPDATE ON schedule_weeks, shifts, schedule_templates TO txtschedules_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON template_shifts TO txtschedules_rw;
GRANT DELETE ON shifts TO txtschedules_rw;       -- a DRAFT shift only (the trigger refuses a published one)
GRANT EXECUTE ON FUNCTION ts_publish_week(bigint, bigint) TO txtschedules_rw;

COMMIT;
