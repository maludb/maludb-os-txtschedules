-- 015: an overlap is refused when a shift is TAKEN, not only when it is carried out (slice 1, shifts and the marketplace).
-- db/010's ts_exchange_check_taker() refused the cutoff, one's own shift, the main restaurant and a hard rule — but two shifts at
-- once was caught only by the exclusion constraint when the exchange was APPLIED, so a claim that overlapped one's own shift could sit
-- with a manager until approval and fail there. The marketplace must say why not BEFORE the button (FR-S5): "That overlaps your shift
-- on Friday." Additive: the same function, one more check (after the main-restaurant rule, before the rules engine). For a swap the
-- colleague's own swapped-away shift does not count (it leaves them in the same exchange). Nothing else changes.
BEGIN;

CREATE OR REPLACE FUNCTION ts_exchange_check_taker(p_exchange exchanges, p_member bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_shift     shifts%ROWTYPE;
    v_settings  site_settings%ROWTYPE;
    v_main      bigint;
    v_warn      jsonb;
    v_hard      text;
    v_clash     timestamptz;
    v_tz        text;
BEGIN
    SELECT * INTO v_shift FROM shifts WHERE id = p_exchange.shift_id;
    SELECT * INTO v_settings FROM site_settings WHERE scope_id = p_exchange.scope_id;
    IF v_shift.status <> 'scheduled' OR v_shift.published_at IS NULL THEN
        RAISE EXCEPTION 'That shift is no longer on the schedule.' USING ERRCODE = 'P0001';
    END IF;
    IF v_shift.starts_at - make_interval(mins => v_settings.cutoff_minutes) < now() THEN
        RAISE EXCEPTION 'Too close to the start of the shift to change hands.' USING ERRCODE = 'P0001';
    END IF;
    IF p_member = v_shift.assignee_member_id THEN
        RAISE EXCEPTION 'That is already your shift.' USING ERRCODE = 'P0001';
    END IF;
    SELECT main_scope_id INTO v_main FROM staff_profiles WHERE member_id = p_member AND active;
    IF v_main IS DISTINCT FROM p_exchange.scope_id THEN
        RAISE EXCEPTION 'You can pick up shifts only at your main restaurant.' USING ERRCODE = 'P0001';
    END IF;
    SELECT o.starts_at INTO v_clash FROM shifts o
     WHERE o.assignee_member_id = p_member AND o.status = 'scheduled' AND o.id <> v_shift.id
       AND o.id IS DISTINCT FROM p_exchange.swap_shift_id
       AND tstzrange(o.starts_at, o.ends_at) && tstzrange(v_shift.starts_at, v_shift.ends_at)
     ORDER BY o.starts_at LIMIT 1;
    IF v_clash IS NOT NULL THEN
        SELECT timezone INTO v_tz FROM sites WHERE scope_id = p_exchange.scope_id;
        RAISE EXCEPTION 'That overlaps your shift on %.', trim(to_char(v_clash AT TIME ZONE COALESCE(v_tz, 'UTC'), 'FMDay')) USING ERRCODE = 'P0001';
    END IF;
    v_warn := ts_warnings_json(p_member, v_shift.id);
    SELECT w->>'message' INTO v_hard FROM jsonb_array_elements(v_warn) w WHERE w->>'severity' = 'hard' LIMIT 1;
    IF v_hard IS NOT NULL THEN
        RAISE EXCEPTION '%', v_hard USING ERRCODE = 'P0001';
    END IF;
    RETURN v_warn;
END$$;

COMMIT;
