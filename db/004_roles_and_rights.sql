-- 004: txtSchedules' roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles
-- PER SITE; the claims and the feed carry them back into member_site_roles. What a role lets its holder do is
-- txtSchedules' to enforce: ts_has_right(right, site) answers from the catalogue and the mirror. Rights are
-- always asked AT A SITE — there is no business-wide right here.
BEGIN;

CREATE TABLE ts_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE ts_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX ts_roles_one_admin ON ts_roles (is_admin) WHERE is_admin;
CREATE TABLE ts_role_rights (
    role_key  text NOT NULL REFERENCES ts_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES ts_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO ts_rights (right_key, description, sort_order) VALUES
 ('schedule.view_own',   'See their own shifts, the published team schedule and announcements at the site', 1),
 ('availability.edit',   'Set their own availability and request time off', 2),
 ('market.trade',        'Offer, drop, pick up, swap and give their shifts (at their main restaurant, within the site''s trade settings)', 3),
 ('coverage.fill',       'Fill a gap in the current day from the list of eligible, free staff', 4),
 ('market.approve_day',  'Approve trades for today and tomorrow (when the site lets shift leads)', 5),
 ('schedule.build',      'Build, edit, publish and template the schedule; see drafts', 6),
 ('requests.approve',    'Approve or decline availability changes, time off and trades', 7),
 ('labor.view',          'See wage rates, scheduled labor cost and the budget', 8),
 ('announce.post',       'Post announcements', 9),
 ('settings.manage',     'Set the site''s positions, rules, trade settings, time-off types, blackout dates, budgets, day-parts and ratios', 10),
 ('pay.edit',            'Change wage rates and time-off balances', 11);

INSERT INTO ts_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('staff',      'Staff',              'Works shifts: sees their schedule, sets availability, requests time off, trades shifts.', 'write', false, 1),
 ('shift_lead', 'Shift lead',         'Runs the floor: everything Staff does, fills same-day gaps and approves same-day trades.', 'write', false, 2),
 ('manager',    'Manager',            'Runs the schedule: builds and publishes it, approves requests and trades, sees labor cost, posts announcements.', 'write', false, 3),
 ('admin',      'txtSchedules admin', 'Runs txtSchedules at the site: everything a Manager does, plus settings and pay. A super-admin holds this role at every site.', 'admin', true, 4);

INSERT INTO ts_role_rights (role_key, right_key)
SELECT 'staff', r FROM unnest(ARRAY['schedule.view_own','availability.edit','market.trade']) r
UNION ALL SELECT 'shift_lead', r FROM unnest(ARRAY['schedule.view_own','availability.edit','market.trade','coverage.fill','market.approve_day']) r
UNION ALL SELECT 'manager', r FROM unnest(ARRAY['schedule.view_own','availability.edit','market.trade','coverage.fill','market.approve_day',
                                                 'schedule.build','requests.approve','labor.view','announce.post']) r
UNION ALL SELECT 'admin', right_key FROM ts_rights;

-- The rule: a right the member's roles AT THIS SITE give. The site must be live and held; the member active
-- and admitted. A member the kernel has not yet sent roles for at a site (roles = '{}') is read from that
-- site's capability: admin = admin, write = staff, read = staff (read-only capability never writes: PHP gates).
CREATE OR REPLACE FUNCTION ts_has_right(p_right text, p_scope_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM member_site_roles r
          JOIN sites s ON s.scope_id = r.scope_id AND s.removed_at IS NULL
          JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.capability IS NOT NULL
          JOIN ts_role_rights rr ON rr.right_key = p_right
               AND (rr.role_key = ANY (r.roles)
                    OR (cardinality(r.roles) = 0 AND rr.role_key = CASE r.capability WHEN 'admin' THEN 'admin' ELSE 'staff' END))
         WHERE r.member_id = app_current_member_id() AND r.scope_id = p_scope_id);
END$$;

-- The sites where the caller holds a right — the uncorrelated set a view tests once per statement
-- ("WHERE scope_id IN (SELECT ts_scopes_with_right('labor.view'))").
CREATE OR REPLACE FUNCTION ts_scopes_with_right(p_right text) RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        SELECT DISTINCT r.scope_id FROM member_site_roles r
          JOIN sites s ON s.scope_id = r.scope_id AND s.removed_at IS NULL
          JOIN members m ON m.id = r.member_id AND m.status = 'active' AND m.capability IS NOT NULL
          JOIN ts_role_rights rr ON rr.right_key = p_right
               AND (rr.role_key = ANY (r.roles)
                    OR (cardinality(r.roles) = 0 AND rr.role_key = CASE r.capability WHEN 'admin' THEN 'admin' ELSE 'staff' END))
         WHERE r.member_id = app_current_member_id();
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM ts_role_rights rr
                   JOIN ts_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM ts_roles r;

GRANT SELECT ON ts_rights, ts_roles, ts_role_rights, mcp_app_roles TO txtschedules_rw, txtschedules_records_ro;
GRANT EXECUTE ON FUNCTION ts_has_right(text, bigint), ts_scopes_with_right(text)
    TO txtschedules_rw, txtschedules_records_ro, txtschedules_activity_ro;

COMMIT;
