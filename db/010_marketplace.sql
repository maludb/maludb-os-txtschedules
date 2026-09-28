-- 010: the shift marketplace — the heart of the brief (FR-S1–S9). An EXCHANGE moves one shift (two, for a swap)
-- from its holder to someone else:
--   offer      the holder puts their shift up; eligible staff claim it                     (FR-S1, S2)
--   open       an unassigned shift a manager opened; eligible staff claim it               (FR-S2)
--   give       the holder offers it to one named colleague, who accepts or declines        (FR-S4)
--   swap       the holder's shift for a named colleague's shift; the colleague accepts     (FR-S3)
--   coverage   a manager or shift lead offers a gap to one or several; first accept wins  (FR-S9)
-- Whether a kind exists, and whether it needs a manager, are the SITE's trade settings (D2, db/005). Only the
-- site's MAIN staff take a shift (D4). Hard rules refuse; soft rules travel with the exchange to its approver.
-- Two claims at once: exactly one wins (NF-5) — the exchange row is locked, and a unique index backs it.
-- Every step is an activity_log event on the exchange and the shift (no second history table; FR-S8).
BEGIN;

CREATE TABLE exchanges (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id        bigint NOT NULL REFERENCES sites(scope_id),
    kind            text NOT NULL CHECK (kind IN ('offer', 'open', 'give', 'swap', 'coverage')),
    shift_id        bigint NOT NULL REFERENCES shifts(id),
    from_member_id  bigint REFERENCES members(id),            -- the holder; NULL for open and coverage
    to_member_id    bigint REFERENCES members(id),            -- give/swap: the named colleague; else the winner, once there is one
    swap_shift_id   bigint REFERENCES shifts(id),             -- swap: the colleague's shift that comes back
    status          text NOT NULL DEFAULT 'open'
                    CHECK (status IN ('open', 'pending_acceptance', 'pending_approval', 'approved', 'declined', 'cancelled', 'expired')),
    needs_approval  boolean NOT NULL DEFAULT false,           -- decided when someone takes it (site setting + warnings)
    warnings        jsonb NOT NULL DEFAULT '[]'::jsonb,        -- the soft warnings the approver sees
    note            text,
    expires_at      timestamptz NOT NULL,
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at      timestamptz,
    decision_note   text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'swap') = (swap_shift_id IS NOT NULL)),
    CHECK (kind NOT IN ('give', 'swap') OR to_member_id IS NOT NULL),
    CHECK (kind IN ('open', 'coverage') OR from_member_id IS NOT NULL)
);
-- One live exchange per shift at a time.
CREATE UNIQUE INDEX exchanges_one_live_per_shift ON exchanges (shift_id)
    WHERE status IN ('open', 'pending_acceptance', 'pending_approval');
CREATE INDEX exchanges_scope_idx ON exchanges (scope_id, status, expires_at);
CREATE TRIGGER exchanges_touch BEFORE UPDATE ON exchanges FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE exchange_claims (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    exchange_id  bigint NOT NULL REFERENCES exchanges(id) ON DELETE CASCADE,
    member_id    bigint NOT NULL REFERENCES members(id),
    status       text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'won', 'lost', 'withdrawn')),
    warnings     jsonb NOT NULL DEFAULT '[]'::jsonb,
    created_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (exchange_id, member_id)
);
CREATE UNIQUE INDEX exchange_claims_one_winner ON exchange_claims (exchange_id) WHERE status = 'won';

-- Coverage asks several people at once; each is invited, the first to accept wins.
CREATE TABLE exchange_invitees (
    exchange_id  bigint NOT NULL REFERENCES exchanges(id) ON DELETE CASCADE,
    member_id    bigint NOT NULL REFERENCES members(id),
    PRIMARY KEY (exchange_id, member_id)
);

-- Soft warnings as JSON, and the first hard one's sentence (or NULL).
CREATE OR REPLACE FUNCTION ts_warnings_json(p_member bigint, p_shift bigint) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE(jsonb_agg(jsonb_build_object('rule', w.rule_key, 'severity', w.severity, 'message', w.message)), '[]'::jsonb)
      FROM ts_shift_warnings(p_shift, p_member) w;
$$;

-- May this person take this exchange's shift? Raises with the reason a person reads when they may not (FR-S5);
-- answers the soft warnings when they may.
CREATE OR REPLACE FUNCTION ts_exchange_check_taker(p_exchange exchanges, p_member bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_shift     shifts%ROWTYPE;
    v_settings  site_settings%ROWTYPE;
    v_main      bigint;
    v_warn      jsonb;
    v_hard      text;
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
    v_warn := ts_warnings_json(p_member, v_shift.id);
    SELECT w->>'message' INTO v_hard FROM jsonb_array_elements(v_warn) w WHERE w->>'severity' = 'hard' LIMIT 1;
    IF v_hard IS NOT NULL THEN
        RAISE EXCEPTION '%', v_hard USING ERRCODE = 'P0001';
    END IF;
    RETURN v_warn;
END$$;

-- Put a shift on the market: offer / give / swap by its holder, open / coverage by a manager or shift lead.
CREATE OR REPLACE FUNCTION ts_exchange_create(p_kind text, p_shift bigint, p_by bigint, p_to_member bigint DEFAULT NULL,
                                              p_swap_shift bigint DEFAULT NULL, p_note text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql AS $$
DECLARE
    v_shift    shifts%ROWTYPE;
    v_settings site_settings%ROWTYPE;
    v_id       bigint;
BEGIN
    SELECT * INTO v_shift FROM shifts WHERE id = p_shift FOR UPDATE;
    IF NOT FOUND OR v_shift.status <> 'scheduled' OR v_shift.published_at IS NULL THEN
        RAISE EXCEPTION 'That shift is not on the published schedule.' USING ERRCODE = 'P0001';
    END IF;
    SELECT * INTO v_settings FROM site_settings WHERE scope_id = v_shift.scope_id;
    IF (p_kind = 'offer' AND NOT v_settings.allow_offer) OR (p_kind = 'give' AND NOT v_settings.allow_give)
       OR (p_kind = 'swap' AND NOT v_settings.allow_swap) OR (p_kind = 'open' AND NOT v_settings.allow_pickup) THEN
        RAISE EXCEPTION 'This restaurant does not allow that kind of trade.' USING ERRCODE = 'P0001';
    END IF;
    IF p_kind IN ('offer', 'give', 'swap') AND v_shift.assignee_member_id IS DISTINCT FROM p_by THEN
        RAISE EXCEPTION 'Only the person working a shift can offer it.' USING ERRCODE = 'P0001';
    END IF;
    IF p_kind IN ('open', 'coverage') AND v_shift.assignee_member_id IS NOT NULL AND p_kind = 'open' THEN
        RAISE EXCEPTION 'That shift already has someone.' USING ERRCODE = 'P0001';
    END IF;
    IF v_shift.starts_at - make_interval(mins => v_settings.cutoff_minutes) < now() THEN
        RAISE EXCEPTION 'Too close to the start of the shift to change hands.' USING ERRCODE = 'P0001';
    END IF;
    IF p_kind = 'swap' AND NOT EXISTS (SELECT 1 FROM shifts s WHERE s.id = p_swap_shift AND s.assignee_member_id = p_to_member
                                          AND s.status = 'scheduled' AND s.published_at IS NOT NULL AND s.scope_id = v_shift.scope_id) THEN
        RAISE EXCEPTION 'The shift to swap for is not that colleague''s at this restaurant.' USING ERRCODE = 'P0001';
    END IF;
    INSERT INTO exchanges (scope_id, kind, shift_id, from_member_id, to_member_id, swap_shift_id, status, note, expires_at, created_by)
    VALUES (v_shift.scope_id, p_kind, p_shift,
            CASE WHEN p_kind IN ('offer', 'give', 'swap') THEN p_by END,
            CASE WHEN p_kind IN ('give', 'swap') THEN p_to_member END,
            p_swap_shift,
            CASE WHEN p_kind IN ('give', 'swap') THEN 'pending_acceptance' ELSE 'open' END,
            p_note,
            CASE v_settings.offer_expires WHEN 'at_cutoff' THEN v_shift.starts_at - make_interval(mins => v_settings.cutoff_minutes)
                                          ELSE v_shift.starts_at END,
            p_by)
    RETURNING id INTO v_id;
    RETURN v_id;
END$$;

-- Carry out an approved exchange: the shift (and, for a swap, the colleague's shift) change hands. The overlap
-- constraint still stands guard; a swap frees both shifts first so the exchange itself is not an overlap.
CREATE OR REPLACE FUNCTION ts_exchange_apply(p_exchange bigint) RETURNS void
    LANGUAGE plpgsql AS $$
DECLARE x exchanges%ROWTYPE;
BEGIN
    SELECT * INTO x FROM exchanges WHERE id = p_exchange;
    IF x.kind = 'swap' THEN
        UPDATE shifts SET assignee_member_id = NULL WHERE id IN (x.shift_id, x.swap_shift_id);
        UPDATE shifts SET assignee_member_id = x.to_member_id WHERE id = x.shift_id;
        UPDATE shifts SET assignee_member_id = x.from_member_id WHERE id = x.swap_shift_id;
    ELSE
        UPDATE shifts SET assignee_member_id = x.to_member_id WHERE id = x.shift_id;
    END IF;
    UPDATE exchanges SET status = 'approved' WHERE id = p_exchange;
EXCEPTION WHEN exclusion_violation THEN
    RAISE EXCEPTION 'That would give someone two shifts at once.' USING ERRCODE = 'P0001';
END$$;

-- After someone takes it: to a manager, or straight through (site setting per kind + the soft warnings).
CREATE OR REPLACE FUNCTION ts_exchange_settle(p_exchange bigint, p_warnings jsonb) RETURNS text
    LANGUAGE plpgsql AS $$
DECLARE
    x exchanges%ROWTYPE;
    v_settings site_settings%ROWTYPE;
    v_rule text;
BEGIN
    SELECT * INTO x FROM exchanges WHERE id = p_exchange;
    SELECT * INTO v_settings FROM site_settings WHERE scope_id = x.scope_id;
    v_rule := CASE x.kind WHEN 'give' THEN v_settings.approval_give WHEN 'swap' THEN v_settings.approval_swap
                          WHEN 'coverage' THEN 'never'            -- a manager or shift lead made it
                          ELSE v_settings.approval_pickup END;
    UPDATE exchanges SET warnings = p_warnings,
           needs_approval = (v_rule = 'always' OR (v_rule = 'on_warning' AND jsonb_array_length(p_warnings) > 0))
     WHERE id = p_exchange RETURNING * INTO x;
    IF x.needs_approval THEN
        UPDATE exchanges SET status = 'pending_approval' WHERE id = p_exchange;
        RETURN 'pending_approval';
    END IF;
    PERFORM ts_exchange_apply(p_exchange);
    RETURN 'approved';
END$$;

-- A claim on an offer, an open shift or a coverage request. The exchange row is locked, so two claims at the same
-- moment queue here: in 'first' mode the first wins and the second is told it is gone (NF-5).
CREATE OR REPLACE FUNCTION ts_exchange_claim(p_exchange bigint, p_member bigint) RETURNS text
    LANGUAGE plpgsql AS $$
DECLARE
    x exchanges%ROWTYPE;
    v_mode text;
    v_warn jsonb;
BEGIN
    SELECT * INTO x FROM exchanges WHERE id = p_exchange FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'Not found.' USING ERRCODE = 'P0001'; END IF;
    IF x.kind NOT IN ('offer', 'open', 'coverage') THEN
        RAISE EXCEPTION 'That trade is for a named colleague.' USING ERRCODE = 'P0001';
    END IF;
    IF x.status <> 'open' OR x.expires_at <= now() THEN
        RAISE EXCEPTION 'Someone else already took this shift.' USING ERRCODE = 'P0001';
    END IF;
    IF x.kind = 'coverage' AND NOT EXISTS (SELECT 1 FROM exchange_invitees i WHERE i.exchange_id = x.id AND i.member_id = p_member) THEN
        RAISE EXCEPTION 'This shift was offered to others.' USING ERRCODE = 'P0001';
    END IF;
    v_warn := ts_exchange_check_taker(x, p_member);
    SELECT claim_mode INTO v_mode FROM site_settings WHERE scope_id = x.scope_id;
    INSERT INTO exchange_claims (exchange_id, member_id, status, warnings)
    VALUES (x.id, p_member, CASE WHEN v_mode = 'first' OR x.kind = 'coverage' THEN 'won' ELSE 'pending' END, v_warn);
    IF v_mode = 'first' OR x.kind = 'coverage' THEN
        UPDATE exchange_claims SET status = 'lost' WHERE exchange_id = x.id AND member_id <> p_member AND status = 'pending';
        UPDATE exchanges SET to_member_id = p_member WHERE id = x.id;
        RETURN ts_exchange_settle(x.id, v_warn);
    END IF;
    RETURN 'claimed';                                   -- the manager chooses among the claims
END$$;

-- Manager-chooses mode: pick one claim.
CREATE OR REPLACE FUNCTION ts_exchange_choose(p_exchange bigint, p_member bigint, p_by bigint) RETURNS text
    LANGUAGE plpgsql AS $$
DECLARE
    x exchanges%ROWTYPE;
    v_warn jsonb;
BEGIN
    SELECT * INTO x FROM exchanges WHERE id = p_exchange FOR UPDATE;
    IF x.status <> 'open' THEN RAISE EXCEPTION 'This trade is no longer open.' USING ERRCODE = 'P0001'; END IF;
    IF NOT EXISTS (SELECT 1 FROM exchange_claims c WHERE c.exchange_id = x.id AND c.member_id = p_member AND c.status = 'pending') THEN
        RAISE EXCEPTION 'That person has not asked for this shift.' USING ERRCODE = 'P0001';
    END IF;
    v_warn := ts_exchange_check_taker(x, p_member);
    UPDATE exchange_claims SET status = CASE WHEN member_id = p_member THEN 'won' ELSE 'lost' END
     WHERE exchange_id = x.id AND status = 'pending';
    UPDATE exchanges SET to_member_id = p_member, decided_by = p_by WHERE id = x.id;
    -- the manager chose: that is the approval
    UPDATE exchanges SET warnings = v_warn, needs_approval = false WHERE id = x.id;
    PERFORM ts_exchange_apply(x.id);
    RETURN 'approved';
END$$;

-- Give / swap: the named colleague answers.
CREATE OR REPLACE FUNCTION ts_exchange_accept(p_exchange bigint, p_member bigint, p_accept boolean) RETURNS text
    LANGUAGE plpgsql AS $$
DECLARE
    x exchanges%ROWTYPE;
    v_warn jsonb;
    v_back jsonb := '[]'::jsonb;
    v_hard text;
BEGIN
    SELECT * INTO x FROM exchanges WHERE id = p_exchange FOR UPDATE;
    IF NOT FOUND OR x.kind NOT IN ('give', 'swap') OR x.to_member_id IS DISTINCT FROM p_member THEN
        RAISE EXCEPTION 'Not found.' USING ERRCODE = 'P0001';
    END IF;
    IF x.status <> 'pending_acceptance' OR x.expires_at <= now() THEN
        RAISE EXCEPTION 'This trade is no longer waiting for you.' USING ERRCODE = 'P0001';
    END IF;
    IF NOT p_accept THEN
        UPDATE exchanges SET status = 'declined', decided_by = p_member, decided_at = now() WHERE id = x.id;
        RETURN 'declined';
    END IF;
    v_warn := ts_exchange_check_taker(x, p_member);
    IF x.kind = 'swap' THEN
        -- the holder takes the colleague's shift back: the same checks, the other way round
        v_back := ts_warnings_json(x.from_member_id, x.swap_shift_id);
        SELECT w->>'message' INTO v_hard FROM jsonb_array_elements(v_back) w WHERE w->>'severity' = 'hard' LIMIT 1;
        IF v_hard IS NOT NULL THEN RAISE EXCEPTION 'The swap back: %', v_hard USING ERRCODE = 'P0001'; END IF;
    END IF;
    RETURN ts_exchange_settle(x.id, v_warn || v_back);
END$$;

-- A manager (or, same-day, a shift lead — the handler checks the right) decides a pending exchange.
CREATE OR REPLACE FUNCTION ts_exchange_decide(p_exchange bigint, p_approve boolean, p_by bigint, p_note text) RETURNS text
    LANGUAGE plpgsql AS $$
DECLARE x exchanges%ROWTYPE;
BEGIN
    SELECT * INTO x FROM exchanges WHERE id = p_exchange FOR UPDATE;
    IF NOT FOUND OR x.status <> 'pending_approval' THEN
        RAISE EXCEPTION 'This trade is not waiting for a decision.' USING ERRCODE = 'P0001';
    END IF;
    UPDATE exchanges SET decided_by = p_by, decided_at = now(), decision_note = p_note WHERE id = x.id;
    IF p_approve THEN
        PERFORM ts_exchange_apply(x.id);
        RETURN 'approved';
    END IF;
    UPDATE exchanges SET status = 'declined' WHERE id = x.id;
    RETURN 'declined';
END$$;

-- The holder withdraws; the timer expires what nobody took (the shift stays with its holder).
CREATE OR REPLACE FUNCTION ts_exchange_cancel(p_exchange bigint, p_by bigint) RETURNS text
    LANGUAGE plpgsql AS $$
BEGIN
    UPDATE exchanges SET status = 'cancelled', decided_by = p_by, decided_at = now()
     WHERE id = p_exchange AND status IN ('open', 'pending_acceptance', 'pending_approval');
    IF NOT FOUND THEN RAISE EXCEPTION 'This trade can no longer be withdrawn.' USING ERRCODE = 'P0001'; END IF;
    RETURN 'cancelled';
END$$;

CREATE OR REPLACE FUNCTION ts_exchanges_expire() RETURNS integer
    LANGUAGE plpgsql AS $$
DECLARE n integer;
BEGIN
    UPDATE exchanges SET status = 'expired' WHERE status IN ('open', 'pending_acceptance') AND expires_at <= now();
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, INSERT, UPDATE ON exchanges, exchange_claims TO txtschedules_rw;
GRANT SELECT, INSERT, DELETE ON exchange_invitees TO txtschedules_rw;
GRANT EXECUTE ON FUNCTION ts_warnings_json(bigint, bigint), ts_exchange_check_taker(exchanges, bigint),
    ts_exchange_create(text, bigint, bigint, bigint, bigint, text), ts_exchange_apply(bigint), ts_exchange_settle(bigint, jsonb),
    ts_exchange_claim(bigint, bigint), ts_exchange_choose(bigint, bigint, bigint), ts_exchange_accept(bigint, bigint, boolean),
    ts_exchange_decide(bigint, boolean, bigint, text), ts_exchange_cancel(bigint, bigint), ts_exchanges_expire()
    TO txtschedules_rw;

COMMIT;
