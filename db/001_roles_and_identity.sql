-- 001: roles, extensions, the acting member, the directory mirror and the SITE mirror.
--
-- txtSchedules is an application from us beside the Business OS kernel (maludb-os-integration 0.4.2).
-- It owns SCHEDULES (shifts, availability, time off, trades); the kernel owns the DIRECTORY and the SITES.
-- The identity and site tables below are a MIRROR of the kernel with the kernel's ids -- written only
-- from a hand-off token's claims or the directory change feed, never generated here. There is no password
-- and no account. The application is scoped by location (scoped-applications.md): each restaurant is a
-- kernel site, and a member's roles are held PER SITE (member_site_roles).
--
-- Run in order as postgres on an empty database named <tenant>_txtschedules:
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d subello_txtschedules -f db/001_roles_and_identity.sql
-- The installer sets the three role passwords afterwards (ALTER ROLE ... PASSWORD).
BEGIN;

CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gist;          -- one person, no overlapping shifts (db/008)

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'txtschedules_rw') THEN
        CREATE ROLE txtschedules_rw LOGIN;                 -- PHP: the only writer
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'txtschedules_records_ro') THEN
        CREATE ROLE txtschedules_records_ro LOGIN;         -- records MCP: mcp_* views only
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'txtschedules_activity_ro') THEN
        CREATE ROLE txtschedules_activity_ro LOGIN;        -- activity MCP: mcp_activity_* views only
    END IF;
END$$;

GRANT USAGE ON SCHEMA public TO txtschedules_rw, txtschedules_records_ro, txtschedules_activity_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO txtschedules_rw;

-- ---------------------------------------------------------------------------------------------
-- The acting member. PHP sets it connection-wide from the session (or from a verified action
-- token); the MCP servers set it transaction-locally from the verified bearer. Unset = anonymous
-- and every rule below denies.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION app_current_member_id() RETURNS bigint
    LANGUAGE sql STABLE AS $$
    SELECT NULLIF(current_setting('app.member_id', true), '')::bigint;
$$;

CREATE OR REPLACE FUNCTION touch_updated_at() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The directory mirror (sign-on-and-directory.md §3-4), the feed's own facts (os.directory/1).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE members (
    id              bigint PRIMARY KEY,                       -- the kernel's member id
    member_kind     text NOT NULL CHECK (member_kind IN ('human', 'agent')),
    display_name    text NOT NULL,
    email           citext,
    business_role   text NOT NULL CHECK (business_role IN ('super_admin', 'dept_admin', 'user')),
    is_external     boolean NOT NULL DEFAULT false,
    status          text NOT NULL CHECK (status IN ('active', 'inactive')),
    capability      text CHECK (capability IN ('read', 'write', 'admin')),  -- the highest grant here; NULL = none
    roles           text[] NOT NULL DEFAULT '{}',              -- every txtSchedules role held, at any site (the union)
    job_title       text,
    phone           text,                                      -- the directory's; txtSchedules never texts it (K6 uses the kernel's verified phone)
    timezone        text NOT NULL DEFAULT 'UTC',
    directory_updated_at timestamptz,
    synced_at       timestamptz NOT NULL DEFAULT now(),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX members_name_trgm_idx ON members USING gin (display_name gin_trgm_ops);
CREATE INDEX members_status_idx ON members (status, member_kind);
CREATE TRIGGER members_touch BEFORE UPDATE ON members FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
COMMENT ON COLUMN members.roles IS 'The union of the roles the kernel says this member holds here, at any site. Per-site roles: member_site_roles.';

CREATE TABLE departments (
    id                 bigint PRIMARY KEY,                    -- the kernel's department id
    name               text NOT NULL,
    description        text,
    parent_id          bigint REFERENCES departments(id) ON DELETE SET NULL,
    manager_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,
    is_system          boolean NOT NULL DEFAULT false,
    system_key         text,
    archived_at        timestamptz,
    directory_updated_at timestamptz,
    synced_at          timestamptz NOT NULL DEFAULT now(),
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER departments_touch BEFORE UPDATE ON departments FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE department_members (
    member_id       bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    department_id   bigint NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
    is_admin        boolean NOT NULL DEFAULT false,
    is_primary      boolean NOT NULL DEFAULT false,
    joined_at       timestamptz,
    left_at         timestamptz,
    synced_at       timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, department_id)
);

-- ---------------------------------------------------------------------------------------------
-- The sites (scoped-applications.md §3): one row per kernel scope this installation serves, keyed by the
-- kernel's scope id. A site IS a restaurant here: txtSchedules keeps its own facts about it in site_settings
-- (db/005). Name, address and time zone follow the kernel's; a removed scope is CLOSED — nobody reaches it,
-- its history stays.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE sites (
    scope_id       bigint PRIMARY KEY,                     -- the kernel's application_scopes.id
    location_id    bigint NOT NULL,                        -- the kernel's location (kind site) — shared with Reservations
    name           text NOT NULL,
    address        text,
    timezone       text NOT NULL DEFAULT 'UTC',            -- every time here is shown in the site's zone (NF-2)
    removed_at     timestamptz,
    synced_at      timestamptz NOT NULL DEFAULT now(),
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX sites_location_live ON sites (location_id) WHERE removed_at IS NULL;

-- What each member holds, per site. Replaced whole from the claims or a feed access[] row.
CREATE TABLE member_site_roles (
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    role_key    text,                                      -- the highest role held there
    roles       text[] NOT NULL DEFAULT '{}',              -- every role held there
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    synced_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, scope_id)
);
CREATE INDEX member_site_roles_scope_idx ON member_site_roles (scope_id);

CREATE TABLE directory_sync_state (
    id           smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    next_cursor  text,
    full_at      timestamptz,
    scopes_at    timestamptz,                               -- last scopes.php read (the first run reads it)
    last_run_at  timestamptz,
    last_error   text,
    updated_at   timestamptz NOT NULL DEFAULT now()
);
INSERT INTO directory_sync_state (id) VALUES (1) ON CONFLICT DO NOTHING;

CREATE TABLE sso_nonces (
    nonce       text PRIMARY KEY,
    member_id   bigint NOT NULL,
    expires_at  timestamptz NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX sso_nonces_expires_idx ON sso_nonces (expires_at);

CREATE TABLE member_sessions (
    session_hash  text PRIMARY KEY,
    member_id     bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    scope_id      bigint REFERENCES sites(scope_id) ON DELETE SET NULL,    -- the session's current site
    created_at    timestamptz NOT NULL DEFAULT now(),
    last_seen_at  timestamptz NOT NULL DEFAULT now(),
    ended_at      timestamptz,
    ended_by      text CHECK (ended_by IN ('member', 'kernel', 'expired', 'directory'))
);
CREATE INDEX member_sessions_member_idx ON member_sessions (member_id) WHERE ended_at IS NULL;

-- ---------------------------------------------------------------------------------------------
-- Who is asking. PL/pgSQL, one query each (the kernel's db/160 lesson: SQL-language SECURITY DEFINER
-- functions are re-planned per call and cost a view one plan per row).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION app_member_kind() RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE k text;
BEGIN
    SELECT member_kind INTO k FROM members WHERE id = app_current_member_id();
    RETURN k;
END$$;

-- Active in the directory AND admitted here (a live capability).
CREATE OR REPLACE FUNCTION app_is_active_member() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = app_current_member_id()
                     AND m.status = 'active' AND m.capability IS NOT NULL);
END$$;

CREATE OR REPLACE FUNCTION app_is_super_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = app_current_member_id() AND m.status = 'active'
                     AND m.member_kind = 'human' AND m.business_role = 'super_admin');
END$$;

-- The live sites the caller holds any role at. A super-admin is here like anyone else: the kernel lists
-- the admin role in every scope for them (scoped-applications.md §4.1) — never special-cased.
CREATE OR REPLACE FUNCTION ts_held_scope_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        SELECT r.scope_id FROM member_site_roles r
          JOIN sites s ON s.scope_id = r.scope_id AND s.removed_at IS NULL
          JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.capability IS NOT NULL
         WHERE r.member_id = app_current_member_id();
END$$;

CREATE OR REPLACE FUNCTION ts_holds_scope(p_scope_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM member_site_roles r
                     JOIN sites s ON s.scope_id = r.scope_id AND s.removed_at IS NULL
                     JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.capability IS NOT NULL
                    WHERE r.member_id = app_current_member_id() AND r.scope_id = p_scope_id);
END$$;

GRANT EXECUTE ON FUNCTION app_current_member_id(), app_member_kind(), app_is_active_member(), app_is_super_admin(),
    ts_held_scope_ids(), ts_holds_scope(bigint)
TO txtschedules_rw, txtschedules_records_ro, txtschedules_activity_ro;

-- PHP is the only writer of the mirror and the session tables; the read roles see none of it directly.
GRANT SELECT, INSERT, UPDATE, DELETE ON members, departments, department_members, sites, member_site_roles,
    directory_sync_state, sso_nonces, member_sessions TO txtschedules_rw;

COMMIT;
