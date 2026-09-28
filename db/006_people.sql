-- 006: the people who work shifts — positions per site, each person's profile (the MAIN restaurant, D4),
-- the positions they hold with their wage (pay is protected: only labor.view reads it, only pay.edit writes it),
-- and certifications with their expiry. The person themselves is the kernel's (members); these are
-- txtSchedules' own facts about them, keyed by member_id.
BEGIN;

CREATE TABLE positions (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    name        text NOT NULL CHECK (length(name) BETWEEN 1 AND 60),
    color       text NOT NULL DEFAULT '#6c757d' CHECK (color ~ '^#[0-9a-fA-F]{6}$'),
    area        text NOT NULL DEFAULT 'front' CHECK (area IN ('front', 'kitchen', 'bar', 'management', 'other')),
    sort_order  integer NOT NULL DEFAULT 0,
    archived_at timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX positions_scope_name_live ON positions (scope_id, lower(name)) WHERE archived_at IS NULL;
CREATE TRIGGER positions_touch BEFORE UPDATE ON positions FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One row per person who works shifts. Made the first time a manager sets someone up (or the first time the
-- person arrives with a role at a site — main_scope_id is then that site).
CREATE TABLE staff_profiles (
    member_id            bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    main_scope_id        bigint NOT NULL REFERENCES sites(scope_id),     -- D4: shifts are picked up only here
    max_hours_week       numeric(5,2) CHECK (max_hours_week IS NULL OR max_hours_week BETWEEN 0 AND 100),
    is_minor             boolean NOT NULL DEFAULT false,                 -- no date of birth is kept (NF-3)
    minor_until          date,                                           -- when the minor rules stop applying
    notes                text,                                           -- a manager's note; never shown to other staff
    active               boolean NOT NULL DEFAULT true,                  -- off the schedule without leaving the directory
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    CHECK (NOT is_minor OR minor_until IS NOT NULL)
);
CREATE INDEX staff_profiles_main_idx ON staff_profiles (main_scope_id);
CREATE TRIGGER staff_profiles_touch BEFORE UPDATE ON staff_profiles FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The positions a person works, with the wage for each. The wage is read only through mcp_staff_positions,
-- which blanks it without labor.view at the position's site.
CREATE TABLE staff_positions (
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    position_id  bigint NOT NULL REFERENCES positions(id) ON DELETE CASCADE,
    is_primary   boolean NOT NULL DEFAULT false,
    wage_rate    numeric(8,2) CHECK (wage_rate IS NULL OR wage_rate >= 0),      -- per hour, in the site's currency
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, position_id)
);
CREATE UNIQUE INDEX staff_positions_one_primary ON staff_positions (member_id) WHERE is_primary;
CREATE TRIGGER staff_positions_touch BEFORE UPDATE ON staff_positions FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE certification_kinds (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key         text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    archived_at timestamptz
);
INSERT INTO certification_kinds (key, name) VALUES ('food_handler', 'Food handler'), ('alcohol_service', 'Alcohol service');

CREATE TABLE certifications (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind_id     bigint NOT NULL REFERENCES certification_kinds(id),
    issued_on   date,
    expires_on  date,
    reference   text,
    recorded_by bigint REFERENCES members(id) ON DELETE SET NULL,
    removed_at  timestamptz,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX certifications_member_idx ON certifications (member_id) WHERE removed_at IS NULL;

-- A position that needs a certification (the cert_required rule, db/010).
CREATE TABLE position_certifications (
    position_id bigint NOT NULL REFERENCES positions(id) ON DELETE CASCADE,
    kind_id     bigint NOT NULL REFERENCES certification_kinds(id),
    PRIMARY KEY (position_id, kind_id)
);

GRANT SELECT, INSERT, UPDATE ON positions, staff_profiles, certifications TO txtschedules_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON staff_positions, position_certifications TO txtschedules_rw;
GRANT SELECT, INSERT, UPDATE ON certification_kinds TO txtschedules_rw;

COMMIT;
