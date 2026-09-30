"""txtSchedules — restaurants, people, positions, certifications (docs/txtschedules-mcp-tool-surface.md, "Restaurants, people, positions").
Reads mcp_sites, mcp_members, mcp_staff, mcp_staff_positions, mcp_positions, mcp_member_site_roles, mcp_certification_kinds,
mcp_certifications, mcp_certifications_due, mcp_hours_weekly. Every query runs as the asking member: the views decide the rows and
blank the pay (default rate, a person's rate) unless labor.view at the site — or the person's own effective rate. Nothing here
filters a wage after the fact; nothing selects an email or a phone that is not the caller's own.
"""
from __future__ import annotations

from pydantic import Field

import db
from business_common import NOT_A_SITE, RO, _Base, dumps, err, site_row


class FindSitesIn(_Base):
    q: str | None = Field(None, description="Words of the restaurant's name")
    limit: int = Field(25, ge=1, le=100)


class FindStaffIn(_Base):
    q: str | None = Field(None, description="Words of the person's name, as the user said it")
    site_id: int | None = Field(None, description="Only people who work at this restaurant (find_sites gives ids)")
    position_id: int | None = Field(None, description="Only people who work this position (find_positions gives ids)")
    main_site_id: int | None = Field(None, description="Only people whose MAIN restaurant is this one")
    on_schedule: bool | None = Field(None, description="true = staff who are scheduled; false = people with a role here who are not on the schedule")
    limit: int = Field(25, ge=1, le=100)


class FindPositionsIn(_Base):
    site_id: int | None = Field(None, description="One restaurant's positions; omit for every restaurant you hold")
    q: str | None = Field(None, description="Words of the position's name (server, host, line cook ...)")
    include_archived: bool = Field(False)
    limit: int = Field(50, ge=1, le=100)


class StaffProfileIn(_Base):
    member_id: int | None = Field(None, description="The person (find_staff gives ids); omit for yourself")


class CertKindsIn(_Base):
    site_id: int | None = Field(None, description="One restaurant; omit for every restaurant you hold")
    q: str | None = Field(None, description="Words of the kind's name (Food handler ...)")
    include_archived: bool = Field(False)


class CertificationsIn(_Base):
    member_id: int | None = Field(None, description="The person; omit for yourself (unless you pass `q`)")
    q: str | None = Field(None, description="A person's name and/or a kind ('Priya food handler') — looks cards up across the people you may see")
    site_id: int | None = Field(None, description="Only cards of this restaurant's kinds")
    limit: int = Field(50, ge=1, le=100)


class CertsDueIn(_Base):
    site_id: int | None = Field(None, description="One restaurant; omit for every restaurant you hold")
    state: str | None = Field(None, description="expired, due, missing or to_verify; omit for all four")
    position_id: int | None = Field(None, description="Only people who work this position")
    limit: int = Field(50, ge=1, le=100)


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_sites", annotations={"title": "Find restaurants", **RO})
    async def find_sites(params: FindSitesIn) -> str:
        """The restaurants the caller holds, each with its time zone, week start, the caller's role there and the
        restaurant's trade switches (which exchanges exist, which need a manager, the cutoff). CALL THIS FIRST whenever an
        action or another tool needs a site_id — never invent one. A restaurant the caller does not hold is simply absent."""
        w, a = ["1 = 1"], []
        if params.q:
            a.append(f"%{params.q[:100]}%"); w.append(f"name ILIKE ${len(a)}")
        a.append(params.limit)
        rows = await fetch(f"""SELECT site_id, name, address, timezone, week_start, currency, my_role, my_roles,
                   allow_offer, allow_pickup, allow_swap, allow_give, approval_pickup, approval_swap, approval_give,
                   cutoff_minutes, shift_lead_approves_same_day, claim_mode, offer_expires, availability_needs_approval,
                   reminder_minutes_before, overtime_weekly_hours, time_off_day_hours
              FROM mcp_sites WHERE {' AND '.join(w)} ORDER BY name LIMIT ${len(a)}""", *a)
        return dumps(rows)

    @mcp.tool(name="find_staff", annotations={"title": "Find staff", **RO})
    async def find_staff(params: FindStaffIn) -> str:
        """Listing or looking up the staff of the restaurants the caller holds by name, restaurant, position, main
        restaurant, on or off the schedule. CALL THIS FIRST whenever an action needs a person (member_id) — pass the name as
        the user said it. Returns names, main restaurant and positions — never an email, a phone or a pay rate.
        People who are agents are not listed."""
        w, a = ["m.member_kind = 'human'"], []
        if params.q:
            a.append(f"%{params.q[:100]}%"); w.append(f"m.display_name ILIKE ${len(a)}")
        if params.site_id:
            a.append(params.site_id); w.append(f"${len(a)} = ANY (m.site_ids)")
        if params.position_id:
            a.append(params.position_id); w.append(f"EXISTS (SELECT 1 FROM mcp_staff_positions sp WHERE sp.member_id = m.member_id AND sp.position_id = ${len(a)})")
        if params.main_site_id:
            a.append(params.main_site_id); w.append(f"m.main_site_id = ${len(a)}")
        if params.on_schedule is not None:
            a.append(params.on_schedule); w.append(f"COALESCE(m.on_schedule, false) = ${len(a)}")
        a.append(params.limit)
        rows = await fetch(f"""SELECT m.member_id, m.display_name, m.main_site_id, COALESCE(m.on_schedule, false) AS on_schedule, m.site_ids,
                   COALESCE((SELECT jsonb_agg(jsonb_build_object('position_id', sp.position_id, 'position', sp.position_name, 'site_id', sp.site_id, 'primary', sp.is_primary)
                                              ORDER BY sp.is_primary DESC, sp.position_name)
                               FROM mcp_staff_positions sp WHERE sp.member_id = m.member_id), '[]'::jsonb) AS positions
              FROM mcp_members m WHERE {' AND '.join(w)} ORDER BY m.display_name LIMIT ${len(a)}""", *a)
        return dumps(rows)

    @mcp.tool(name="find_positions", annotations={"title": "Find positions", **RO})
    async def find_positions(params: FindPositionsIn) -> str:
        """A restaurant's positions (server, host, line cook ...) with their area and colour. CALL THIS FIRST whenever an
        action needs a position_id. default_wage_rate is filled only for a caller who holds labor.view at that restaurant
        and is null for everyone else."""
        w, a = ["1 = 1"], []
        if params.site_id:
            a.append(params.site_id); w.append(f"site_id = ${len(a)}")
        if params.q:
            a.append(f"%{params.q[:100]}%"); w.append(f"name ILIKE ${len(a)}")
        if not params.include_archived:
            w.append("archived_at IS NULL")
        a.append(params.limit)
        rows = await fetch(f"""SELECT position_id, site_id, name, color, area, sort_order, archived_at, default_wage_rate
              FROM mcp_positions WHERE {' AND '.join(w)} ORDER BY site_id, sort_order, name LIMIT ${len(a)}""", *a)
        return dumps(rows)

    @mcp.tool(name="staff_profile", annotations={"title": "One person in full", **RO})
    async def staff_profile(params: StaffProfileIn) -> str:
        """One person in full: main restaurant, positions (which is primary; the pay rate of each only for a caller with
        labor.view at that restaurant, and always the person's own effective rate for themself), maximum weekly hours, minor
        flag and its end date, certifications (expiring marked), the restaurants they hold with roles, and this week's and
        next week's hours. Yourself by default; another person's profile needs schedule.build at their restaurant (a
        colleague gets the name and positions only)."""
        me = db.request_member_id.get()
        mid = params.member_id or me
        head = await fetch("SELECT member_id, display_name, member_kind, main_site_id, on_schedule, site_ids FROM mcp_members WHERE member_id = $1", mid)
        if not head:
            return err("No such person among the staff you can see — call find_staff.")
        out = dict(head[0])
        prof = await fetch("SELECT max_hours_week, is_minor, minor_until, active, notes FROM mcp_staff WHERE member_id = $1", mid)
        out["profile_visible"] = bool(prof)
        if prof:
            out.update(prof[0])
        else:
            out["note"] = "Their profile (hours limit, minor flag, notes, certifications) needs schedule.build at their restaurant."
        out["positions"] = await fetch("""SELECT position_id, site_id, position_name AS position, is_primary, wage_rate AS effective_rate, wage_override, wage_source
                                            FROM mcp_staff_positions WHERE member_id = $1 ORDER BY site_id, is_primary DESC, position_name""", mid)
        out["sites"] = await fetch("""SELECT r.site_id, st.name AS site_name, r.role_key, r.roles FROM mcp_member_site_roles r
                                        LEFT JOIN mcp_sites st ON st.site_id = r.site_id WHERE r.member_id = $1 ORDER BY st.name""", mid)
        out["certifications"] = await fetch("""SELECT certification_id, site_id, kind_name AS kind, issued_on, expires_on, expired, due_soon, verified
                                                 FROM mcp_certifications WHERE member_id = $1 ORDER BY expires_on NULLS LAST""", mid)
        out["hours"] = await fetch("""SELECT site_id, week_start, shifts, scheduled_hours, max_hours_week, overtime_weekly_hours, over_overtime, near_overtime, over_own_limit
                                        FROM mcp_hours_weekly WHERE member_id = $1 AND week_start > current_date - 7 AND week_start <= current_date + 7
                                       ORDER BY site_id, week_start""", mid)
        return dumps(out)

    @mcp.tool(name="certification_kinds", annotations={"title": "Certification kinds", **RO})
    async def certification_kinds(params: CertKindsIn) -> str:
        """This restaurant's certification kinds — name, whether an expiry is tracked, the days of warning, and the positions
        that need each. CALL THIS BEFORE an action that names a kind (certification_add, certification_kind_save)."""
        w, a = ["1 = 1"], []
        if params.site_id:
            a.append(params.site_id); w.append(f"k.site_id = ${len(a)}")
        if not params.include_archived:
            w.append("k.archived_at IS NULL")
        if params.q:
            a.append(f"%{params.q[:100]}%"); w.append(f"k.name ILIKE ${len(a)}")
        rows = await fetch(f"""SELECT k.kind_id, k.site_id, k.key, k.name, k.track_expiry, k.warn_days, k.archived_at, k.required_position_ids,
                   COALESCE((SELECT jsonb_agg(p.name ORDER BY p.name) FROM mcp_positions p WHERE p.position_id = ANY (k.required_position_ids)), '[]'::jsonb) AS required_positions
              FROM mcp_certification_kinds k WHERE {' AND '.join(w)} ORDER BY k.site_id, k.name""", *a)
        return dumps(rows)

    @mcp.tool(name="certifications", annotations={"title": "A person's certifications", **RO})
    async def certifications(params: CertificationsIn) -> str:
        """A person's certifications: kind, issued, expires, expired or due soon, and whether a manager has verified the
        card. Yourself by default; another person's need schedule.build at the kind's restaurant."""
        a: list = []
        if params.q and not params.member_id:
            w = "1 = 1"                                     # look-up by words: across the people the views let this caller see
        else:
            a.append(params.member_id or db.request_member_id.get())
            w = "c.member_id = $1"
        if params.site_id:
            a.append(params.site_id); w += f" AND c.site_id = ${len(a)}"
        for word in (params.q or "").split():
            a.append(f"%{word[:40]}%"); w += f" AND (m.display_name ILIKE ${len(a)} OR c.kind_name ILIKE ${len(a)})"
        a.append(params.limit)
        rows = await fetch(f"""SELECT c.certification_id, c.member_id, m.display_name AS member_name, c.site_id, c.kind_id, c.kind_name AS kind, c.issued_on, c.expires_on, c.expired, c.due_soon, c.verified, c.verified_at,
                   COALESCE(m.display_name, 'Someone') || ' ' || c.kind_name || ' — ' || COALESCE(to_char(c.expires_on, 'YYYY-MM-DD'), 'no expiry') AS label
              FROM mcp_certifications c LEFT JOIN mcp_members m ON m.member_id = c.member_id WHERE {w} ORDER BY c.expires_on NULLS LAST LIMIT ${len(a)}""", *a)
        return dumps(rows)

    @mcp.tool(name="certifications_due", annotations={"title": "Certifications that need attention", **RO})
    async def certifications_due(params: CertsDueIn) -> str:
        """Who at a restaurant has a certification that is EXPIRED, DUE within its warning days, MISSING for a position they
        work that needs it, or TO VERIFY — most urgent first, so a manager acts before a shift is refused. A manager
        (schedule.build) sees the restaurant; anyone else sees their own rows."""
        w, a = ["1 = 1"], []
        if params.site_id:
            a.append(params.site_id); w.append(f"d.site_id = ${len(a)}")
        if params.state:
            if params.state not in ("expired", "due", "missing", "to_verify"):
                return err("state is one of expired, due, missing, to_verify.")
            a.append(params.state); w.append(f"d.state = ${len(a)}")
        if params.position_id:
            a.append(params.position_id); w.append(f"EXISTS (SELECT 1 FROM mcp_staff_positions sp WHERE sp.member_id = d.member_id AND sp.position_id = ${len(a)})")
        a.append(params.limit)
        rows = await fetch(f"""SELECT d.certification_id, d.member_id, d.display_name, d.site_id, d.kind_id, d.kind_name AS kind, d.expires_on, d.state, d.days_left
              FROM mcp_certifications_due d WHERE {' AND '.join(w)}
             ORDER BY CASE d.state WHEN 'expired' THEN 0 WHEN 'missing' THEN 1 WHEN 'due' THEN 2 ELSE 3 END, d.days_left NULLS LAST, d.display_name LIMIT ${len(a)}""", *a)
        return dumps(rows)
