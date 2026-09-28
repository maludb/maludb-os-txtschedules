-- 009: rules and warnings (FR-C1–C5) — compliance as HELP, not enforcement: txtSchedules does not claim legal
-- compliance (FR-C4). Each site keeps its rules (from a preset it then adjusts), each rule hard (refused), soft
-- (a warning a manager overrides with a reason) or off. Fair workweek is deferred (D8): no rule here.
-- ts_assignment_warnings() is the ONE engine the builder, publishing, the marketplace and the MCP tools ask.
BEGIN;

CREATE TABLE rule_presets (
    key         text PRIMARY KEY CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    rules       jsonb NOT NULL          -- {rule_key: {"severity": …, "params": {…}}}
);

-- The rule keys the engine knows, with what their params mean. A site row names one of these.
CREATE TABLE rule_kinds (
    key         text PRIMARY KEY,
    name        text NOT NULL,
    explains    text NOT NULL,          -- the sentence a person reads beside the warning
    params_help text NOT NULL,
    sort_order  integer NOT NULL
);
INSERT INTO rule_kinds (key, name, explains, params_help, sort_order) VALUES
 ('position_not_held', 'Position not held',   'The person does not work this position here.', 'none', 1),
 ('time_off',          'On approved time off', 'The shift overlaps the person''s approved time off.', 'none', 2),
 ('unavailable',       'Marked unavailable',  'The person said they are unavailable then.', 'none', 3),
 ('min_rest',          'Rest between shifts', 'Too little time between the end of one shift and the start of the next ("clopening").', 'hours', 4),
 ('max_hours_day',     'Hours in a day',      'More hours in one day than the limit.', 'hours', 5),
 ('overtime_week',     'Overtime',            'The week''s hours go past the overtime threshold (site_settings.overtime_weekly_hours).', 'none', 6),
 ('max_hours_person',  'Person''s weekly limit', 'More hours in the week than the person''s own maximum.', 'none', 7),
 ('break_required',    'Break planned',       'A shift this long needs a planned break of at least the given minutes.', 'after_hours, minutes', 8),
 ('minor_hours_day',   'Minor: hours a day',  'A minor may work at most these hours a day.', 'hours', 9),
 ('minor_hours_week',  'Minor: hours a week', 'A minor may work at most these hours a week.', 'hours', 10),
 ('minor_latest_end',  'Minor: latest end',   'A minor may not work past this time.', 'time (HH:MM)', 11),
 ('cert_required',     'Certification',       'The position needs a certification the person does not hold, or it has expired.', 'none', 12);

INSERT INTO rule_presets (key, name, description, rules) VALUES
 ('generic', 'Generic', 'A starting point with no jurisdiction''s law behind it — adjust to your own.',
  '{"position_not_held": {"severity": "hard"},
    "time_off":          {"severity": "hard"},
    "unavailable":       {"severity": "soft"},
    "min_rest":          {"severity": "soft", "params": {"hours": 10}},
    "max_hours_day":     {"severity": "soft", "params": {"hours": 12}},
    "overtime_week":     {"severity": "soft"},
    "max_hours_person":  {"severity": "soft"},
    "break_required":    {"severity": "soft", "params": {"after_hours": 6, "minutes": 30}},
    "minor_hours_day":   {"severity": "hard", "params": {"hours": 8}},
    "minor_hours_week":  {"severity": "hard", "params": {"hours": 40}},
    "minor_latest_end":  {"severity": "hard", "params": {"time": "22:00"}},
    "cert_required":     {"severity": "soft"}}');

CREATE TABLE site_rules (
    scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    rule_key    text NOT NULL REFERENCES rule_kinds(key),
    severity    text NOT NULL CHECK (severity IN ('hard', 'soft', 'off')),
    params      jsonb NOT NULL DEFAULT '{}'::jsonb,
    updated_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (scope_id, rule_key)
);

-- A manager went ahead despite a soft warning: who, when, why (FR-C3, FR-C5).
CREATE TABLE rule_overrides (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    shift_id    bigint REFERENCES shifts(id) ON DELETE SET NULL,
    member_id   bigint REFERENCES members(id) ON DELETE SET NULL,        -- whom the warning was about
    rule_key    text NOT NULL REFERENCES rule_kinds(key),
    message     text NOT NULL,
    reason      text NOT NULL CHECK (length(reason) BETWEEN 3 AND 500),
    overridden_by bigint REFERENCES members(id) ON DELETE SET NULL,
    context     text NOT NULL CHECK (context IN ('build', 'publish', 'exchange', 'coverage')),
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX rule_overrides_scope_idx ON rule_overrides (scope_id, created_at DESC);

-- The engine. What the rules say about giving THIS person THIS shift (a proposed one: position, start, end, break),
-- at THIS site, ignoring one existing shift (the one being moved or re-assigned). Answers one row per rule that
-- fires, with its severity and the sentence to show. Hours are paid hours (the planned break taken off).
CREATE OR REPLACE FUNCTION ts_assignment_warnings(p_member bigint, p_scope bigint, p_position bigint,
                                                  p_starts timestamptz, p_ends timestamptz, p_break integer,
                                                  p_ignore_shift bigint DEFAULT NULL)
    RETURNS TABLE (rule_key text, severity text, message text)
    LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_tz        text;
    v_settings  site_settings%ROWTYPE;
    v_profile   staff_profiles%ROWTYPE;
    v_day       date;
    v_week0     date;
    v_hours     numeric := EXTRACT(EPOCH FROM (p_ends - p_starts)) / 3600 - COALESCE(p_break, 0) / 60.0;
    v_day_hours numeric;
    v_week_hours numeric;
    v_prev_end  timestamptz;
    v_next_start timestamptz;
    v_minor     boolean;
    r           record;
    v_p         jsonb;
BEGIN
    SELECT timezone INTO v_tz FROM sites WHERE scope_id = p_scope;
    SELECT * INTO v_settings FROM site_settings WHERE scope_id = p_scope;
    SELECT * INTO v_profile FROM staff_profiles WHERE member_id = p_member;
    v_day := (p_starts AT TIME ZONE v_tz)::date;
    v_week0 := v_day - ((EXTRACT(DOW FROM v_day)::int - COALESCE(v_settings.week_start, 1) + 7) % 7);
    v_minor := COALESCE(v_profile.is_minor, false) AND v_profile.minor_until >= v_day;

    SELECT COALESCE(sum(EXTRACT(EPOCH FROM (s.ends_at - s.starts_at)) / 3600 - s.break_minutes / 60.0), 0) INTO v_day_hours
      FROM shifts s WHERE s.assignee_member_id = p_member AND s.status = 'scheduled' AND s.id IS DISTINCT FROM p_ignore_shift
       AND (s.starts_at AT TIME ZONE v_tz)::date = v_day;
    SELECT COALESCE(sum(EXTRACT(EPOCH FROM (s.ends_at - s.starts_at)) / 3600 - s.break_minutes / 60.0), 0) INTO v_week_hours
      FROM shifts s WHERE s.assignee_member_id = p_member AND s.status = 'scheduled' AND s.id IS DISTINCT FROM p_ignore_shift
       AND (s.starts_at AT TIME ZONE v_tz)::date >= v_week0 AND (s.starts_at AT TIME ZONE v_tz)::date < v_week0 + 7;
    SELECT max(s.ends_at) INTO v_prev_end FROM shifts s
     WHERE s.assignee_member_id = p_member AND s.status = 'scheduled' AND s.id IS DISTINCT FROM p_ignore_shift AND s.ends_at <= p_starts;
    SELECT min(s.starts_at) INTO v_next_start FROM shifts s
     WHERE s.assignee_member_id = p_member AND s.status = 'scheduled' AND s.id IS DISTINCT FROM p_ignore_shift AND s.starts_at >= p_ends;

    FOR r IN SELECT sr.rule_key AS k, sr.severity AS sev, sr.params AS params FROM site_rules sr
              WHERE sr.scope_id = p_scope AND sr.severity <> 'off' LOOP
        v_p := r.params;
        IF r.k = 'position_not_held' AND NOT EXISTS (SELECT 1 FROM staff_positions sp WHERE sp.member_id = p_member AND sp.position_id = p_position) THEN
            rule_key := r.k; severity := r.sev; message := 'Does not work this position here.'; RETURN NEXT;
        ELSIF r.k = 'time_off' AND EXISTS (SELECT 1 FROM time_off_requests t WHERE t.member_id = p_member AND t.status = 'approved'
                                              AND tstzrange(t.starts_at, t.ends_at) && tstzrange(p_starts, p_ends)) THEN
            rule_key := r.k; severity := r.sev; message := 'On approved time off then.'; RETURN NEXT;
        ELSIF r.k = 'unavailable' AND EXISTS (
                SELECT 1 FROM availability_rules a
                 WHERE a.member_id = p_member AND a.status = 'approved' AND a.kind = 'unavailable'
                   AND (a.scope_id IS NULL OR a.scope_id = p_scope)
                   AND a.weekday = EXTRACT(DOW FROM v_day)::int
                   AND a.effective_from <= v_day AND (a.effective_to IS NULL OR a.effective_to >= v_day)
                   AND tstzrange((v_day + a.starts_at) AT TIME ZONE v_tz,
                                 (v_day + a.ends_at + CASE WHEN a.ends_at <= a.starts_at THEN interval '1 day' ELSE interval '0' END) AT TIME ZONE v_tz)
                       && tstzrange(p_starts, p_ends)) THEN
            rule_key := r.k; severity := r.sev; message := 'Marked unavailable then.'; RETURN NEXT;
        ELSIF r.k = 'min_rest' AND (
                (v_prev_end IS NOT NULL AND p_starts - v_prev_end < make_interval(hours => COALESCE((v_p->>'hours')::int, 10)))
             OR (v_next_start IS NOT NULL AND v_next_start - p_ends < make_interval(hours => COALESCE((v_p->>'hours')::int, 10)))) THEN
            rule_key := r.k; severity := r.sev;
            message := 'Less than ' || COALESCE(v_p->>'hours', '10') || ' hours between shifts.'; RETURN NEXT;
        ELSIF r.k = 'max_hours_day' AND v_day_hours + v_hours > COALESCE((v_p->>'hours')::numeric, 12) THEN
            rule_key := r.k; severity := r.sev;
            message := round(v_day_hours + v_hours, 1) || ' hours that day (limit ' || COALESCE(v_p->>'hours', '12') || ').'; RETURN NEXT;
        ELSIF r.k = 'overtime_week' AND v_week_hours + v_hours > COALESCE(v_settings.overtime_weekly_hours, 40) THEN
            rule_key := r.k; severity := r.sev;
            message := round(v_week_hours + v_hours, 1) || ' hours that week — overtime past ' || COALESCE(v_settings.overtime_weekly_hours, 40) || '.'; RETURN NEXT;
        ELSIF r.k = 'max_hours_person' AND v_profile.max_hours_week IS NOT NULL AND v_week_hours + v_hours > v_profile.max_hours_week THEN
            rule_key := r.k; severity := r.sev;
            message := round(v_week_hours + v_hours, 1) || ' hours that week — their own limit is ' || v_profile.max_hours_week || '.'; RETURN NEXT;
        ELSIF r.k = 'break_required' AND v_hours + COALESCE(p_break, 0) / 60.0 > COALESCE((v_p->>'after_hours')::numeric, 6)
              AND COALESCE(p_break, 0) < COALESCE((v_p->>'minutes')::int, 30) THEN
            rule_key := r.k; severity := r.sev;
            message := 'A shift this long needs a ' || COALESCE(v_p->>'minutes', '30') || '-minute break planned.'; RETURN NEXT;
        ELSIF r.k = 'minor_hours_day' AND v_minor AND v_day_hours + v_hours > COALESCE((v_p->>'hours')::numeric, 8) THEN
            rule_key := r.k; severity := r.sev; message := 'A minor: more than ' || COALESCE(v_p->>'hours', '8') || ' hours that day.'; RETURN NEXT;
        ELSIF r.k = 'minor_hours_week' AND v_minor AND v_week_hours + v_hours > COALESCE((v_p->>'hours')::numeric, 40) THEN
            rule_key := r.k; severity := r.sev; message := 'A minor: more than ' || COALESCE(v_p->>'hours', '40') || ' hours that week.'; RETURN NEXT;
        ELSIF r.k = 'minor_latest_end' AND v_minor AND (
                (p_ends AT TIME ZONE v_tz)::date > v_day
             OR (p_ends AT TIME ZONE v_tz)::time > COALESCE((v_p->>'time')::time, time '22:00')) THEN
            rule_key := r.k; severity := r.sev; message := 'A minor may not work past ' || COALESCE(v_p->>'time', '22:00') || '.'; RETURN NEXT;
        ELSIF r.k = 'cert_required' AND EXISTS (
                SELECT 1 FROM position_certifications pc
                 WHERE pc.position_id = p_position
                   AND NOT EXISTS (SELECT 1 FROM certifications c WHERE c.member_id = p_member AND c.kind_id = pc.kind_id
                                     AND c.removed_at IS NULL AND (c.expires_on IS NULL OR c.expires_on >= v_day))) THEN
            rule_key := r.k; severity := r.sev; message := 'Missing or expired certification for this position.'; RETURN NEXT;
        END IF;
    END LOOP;
END$$;

-- The same, for an existing shift and its assignee (or a named person, for a proposed re-assignment).
CREATE OR REPLACE FUNCTION ts_shift_warnings(p_shift bigint, p_member bigint DEFAULT NULL)
    RETURNS TABLE (rule_key text, severity text, message text)
    LANGUAGE plpgsql STABLE AS $$
DECLARE s shifts%ROWTYPE;
BEGIN
    SELECT * INTO s FROM shifts WHERE id = p_shift;
    IF NOT FOUND OR COALESCE(p_member, s.assignee_member_id) IS NULL THEN
        RETURN;
    END IF;
    RETURN QUERY SELECT w.rule_key, w.severity, w.message
                   FROM ts_assignment_warnings(COALESCE(p_member, s.assignee_member_id), s.scope_id, s.position_id,
                                               s.starts_at, s.ends_at, s.break_minutes, s.id) w;
END$$;

GRANT SELECT ON rule_presets, rule_kinds TO txtschedules_rw, txtschedules_records_ro;
GRANT SELECT, INSERT, UPDATE ON site_rules TO txtschedules_rw;
GRANT SELECT, INSERT ON rule_overrides TO txtschedules_rw;
GRANT EXECUTE ON FUNCTION ts_assignment_warnings(bigint, bigint, bigint, timestamptz, timestamptz, integer, bigint),
    ts_shift_warnings(bigint, bigint) TO txtschedules_rw;

COMMIT;
