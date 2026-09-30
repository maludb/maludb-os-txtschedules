"""txtSchedules — labor, the forecast, settings, rules, announcements, the trade report (tool surface, "Labor, forecast, settings, talking").
Reads mcp_labor_weekly, mcp_labor_budgets, mcp_shifts, mcp_forecast_covers, mcp_staffing_ratios, mcp_day_parts, mcp_sites,
mcp_time_off_types, mcp_site_rules, rule_presets, mcp_announcements, mcp_exchanges, mcp_exchange_claims, and ts_staffing_needs()
(which checks schedule.build inside). LABOR: cost and the budget are read only with labor.view at the restaurant — this module checks
it again before it asks (ts_has_right) and the views blank it regardless; without it the answer is an error in words, never a rate.
"""
from __future__ import annotations

import datetime

from pydantic import Field

import db
from business_common import NOT_A_SITE, RO, _Base, _iso_date, dumps, err, i_can, site_row, week_row


class LaborIn(_Base):
    site_id: int = Field(..., description="The restaurant")
    week_start: str | None = Field(None, description="Any date in the week; default this week (and the three after it when by='week')")
    by: str = Field("week", description="'week' (by week and area) or 'day' (by day for one week)")
    area: str | None = Field(None, description="front, bar, kitchen ... or 'all'")


class NeedsIn(_Base):
    site_id: int
    from_: str | None = Field(None, alias="from", description="YYYY-MM-DD; default the start of this week")
    to: str | None = Field(None, description="YYYY-MM-DD; default six days after `from`")


class SiteIn(_Base):
    site_id: int | None = Field(None, description="The restaurant. Omit it with `q` to LOOK A TIME-OFF TYPE OR A DAY-PART UP across your restaurants (answers a plain list)")
    q: str | None = Field(None, description="Words of a time-off type's or a day-part's name")


class RulesIn(_Base):
    site_id: int | None = Field(None, description="The restaurant; omit with `q` to look a rule up across your restaurants (a plain list)")
    rule_key: str | None = None
    q: str | None = Field(None, description="Words of the rule's key or name (min_rest, 'rest between shifts')")


class AnnouncementsIn(_Base):
    site_id: int | None = None
    mine: bool = Field(False, description="Only what was addressed to you (site-wide, your positions, or you by name) — not what you see only because you post")
    q: str | None = Field(None, description="Words of the title — how an action resolves an announcement")
    limit: int = Field(25, ge=1, le=100)


class ExchangeReportIn(_Base):
    site_id: int
    from_: str | None = Field(None, alias="from", description="Default 28 days ago")
    to: str | None = None
    group_by: str = Field("kind", description="kind, person or week")


def register(mcp, fetch) -> None:
    @mcp.tool(name="labor_vs_budget", annotations={"title": "Labor against the budget", **RO})
    async def labor_vs_budget(params: LaborIn) -> str:
        """Scheduled hours and COST against the budget, by week and area — or by day for one week. Needs labor.view at the
        restaurant (managers and admins); anyone else is told so and gets no numbers. Cost is paid hours x the effective
        hourly rate, assigned shifts only; overtime premium is not applied (the answer says so)."""
        st = await site_row(fetch, params.site_id)
        if st is None:
            return err(NOT_A_SITE)
        if not await i_can(fetch, "labor.view", params.site_id):
            return err("Cost and budget need labor.view at this restaurant.")
        note = "Cost = paid hours x the effective hourly rate of assigned shifts; overtime premium is not applied; open shifts add hours but no cost."
        on = _iso_date(params.week_start)
        if params.by == "day":
            wk = await week_row(fetch, params.site_id, on)
            if wk is None:
                return err("No schedule week exists for that date.")
            rows = await fetch("""SELECT to_char(s.starts_at AT TIME ZONE st.timezone, 'YYYY-MM-DD') AS date, count(*)::int AS shifts, count(*) FILTER (WHERE s.is_open)::int AS open_shifts,
                                         round(sum(s.paid_hours), 2) AS scheduled_hours, round(COALESCE(sum(s.cost), 0), 2) AS scheduled_cost
                                    FROM mcp_shifts s JOIN mcp_sites st ON st.site_id = s.site_id
                                   WHERE s.week_id = $1 AND s.status = 'scheduled' GROUP BY 1 ORDER BY 1""", wk["week_id"])
            return dumps({"site_id": params.site_id, "week_start": wk["week_start"], "currency": st["currency"], "by": "day", "days": rows, "note": note})
        if params.by != "week":
            return err("by is 'week' or 'day'.")
        w, a = ["l.site_id = $1"], [params.site_id]
        if on:
            a.append(on); w.append(f"l.week_start <= ${len(a)} AND l.week_start > ${len(a)}::date - 7")
        else:
            w.append("l.week_start > current_date - 7 AND l.week_start <= current_date + 21")
        if params.area:
            a.append(params.area); w.append(f"l.area = ${len(a)}")
        rows = await fetch(f"""SELECT l.week_start, l.area, l.scheduled_hours, l.scheduled_cost, l.budget_hours, l.budget_amount,
                   CASE WHEN l.budget_hours IS NOT NULL THEN round(l.scheduled_hours - l.budget_hours, 2) END AS hours_over_budget,
                   CASE WHEN l.budget_amount IS NOT NULL THEN round(l.scheduled_cost - l.budget_amount, 2) END AS cost_over_budget
              FROM mcp_labor_weekly l WHERE {' AND '.join(w)} ORDER BY l.week_start, (l.area = 'all') DESC, l.area""", *a)
        return dumps({"site_id": params.site_id, "currency": st["currency"], "by": "week", "rows": rows, "note": note})

    @mcp.tool(name="staffing_needs", annotations={"title": "Staffing needs against the forecast", **RO})
    async def staffing_needs(params: NeedsIn) -> str:
        """The covers expected per day-part, the headcount they call for at the restaurant's ratios, the number scheduled and
        the open shifts to fill — the GAPS first. For a manager (schedule.build); anyone else is told so."""
        st = await site_row(fetch, params.site_id)
        if st is None:
            return err(NOT_A_SITE)
        if not await i_can(fetch, "schedule.build", params.site_id):
            return err("Staffing needs are for schedule.build at this restaurant.")
        start = _iso_date(params.from_)
        if start is None:
            start = (await fetch("SELECT ((now() AT TIME ZONE $1)::date - (extract(isodow FROM (now() AT TIME ZONE $1)::date)::int - 1)) AS d", st["timezone"]))[0]["d"]
        end = _iso_date(params.to) or start + datetime.timedelta(days=6)
        if end < start or (end - start).days > 60:
            return err("Give a period of at most 61 days.")
        rows = await fetch("SELECT on_date, day_part, position_id, position_name, expected_covers, recommended, scheduled, open_shifts FROM ts_staffing_needs($1, $2, $3)", params.site_id, start, end)
        for r in rows:
            r["short_by"] = max(0, int(r["recommended"] or 0) - int(r["scheduled"] or 0))
        gaps = sorted((r for r in rows if r["short_by"] > 0), key=lambda r: (-r["short_by"], r["on_date"]))
        return dumps({"site_id": params.site_id, "from": start, "to": end, "gaps": gaps, "rows": rows,
                      "note": None if rows else "No rows: the restaurant has no staffing ratios or no forecast for this period."})

    @mcp.tool(name="site_settings", annotations={"title": "A restaurant's settings", **RO})
    async def site_settings(params: SiteIn) -> str:
        """A restaurant's trade settings (which exchanges exist, which need a manager, the cutoff, claim mode, offer expiry),
        reminders, availability approval, overtime, the hours a day of time off counts, its day-parts and its time-off types.
        Anyone who works there may read the trade rules — they affect everyone. No rates. CALL THIS to resolve a time-off
        type or a day-part."""
        if params.site_id is None:
            if not params.q:
                return err("Give a site_id (find_sites), or q to look a time-off type or a day-part up.")
            like = f"%{params.q[:100]}%"
            rows = await fetch("SELECT type_id, site_id, name, key, paid, tracks_balance FROM mcp_time_off_types WHERE archived_at IS NULL AND (name ILIKE $1 OR key ILIKE $1) ORDER BY site_id, name LIMIT 25", like)
            rows += await fetch("SELECT day_part_id, site_id, name, starts_at, ends_at, service_name FROM mcp_day_parts WHERE name ILIKE $1 OR service_name ILIKE $1 ORDER BY site_id, sort_order LIMIT 25", like)
            return dumps(rows)
        st = await site_row(fetch, params.site_id)
        if st is None:
            return err(NOT_A_SITE)
        day_parts = await fetch("SELECT day_part_id, name, starts_at, ends_at, service_name FROM mcp_day_parts WHERE site_id = $1 ORDER BY sort_order", params.site_id)
        types = await fetch("SELECT type_id, key, name, paid, tracks_balance, allow_negative FROM mcp_time_off_types WHERE site_id = $1 AND archived_at IS NULL ORDER BY name", params.site_id)
        st.pop("location_id", None)
        return dumps({"settings": st, "day_parts": day_parts, "time_off_types": types})

    @mcp.tool(name="site_rules", annotations={"title": "A restaurant's scheduling rules", **RO})
    async def site_rules(params: RulesIn) -> str:
        """The rules a restaurant runs — each rule's severity (hard refuses, soft warns a manager, off) and its parameters —
        the preset they came from, and what each rule means. Anyone who works there may read them."""
        if params.site_id is None:
            if not (params.q or params.rule_key):
                return err("Give a site_id (find_sites), or q to look a rule up.")
            like = f"%{(params.q or params.rule_key)[:100]}%"
            return dumps(await fetch("SELECT rule_key, site_id, name, severity, params FROM mcp_site_rules WHERE rule_key ILIKE $1 OR name ILIKE $1 ORDER BY site_id, name LIMIT 25", like))
        st = await site_row(fetch, params.site_id)
        if st is None:
            return err(NOT_A_SITE)
        a = [params.site_id]
        w = "site_id = $1"
        if params.rule_key:
            a.append(params.rule_key); w += f" AND rule_key = ${len(a)}"
        if params.q:
            a.append(f"%{params.q[:100]}%"); w += f" AND (rule_key ILIKE ${len(a)} OR name ILIKE ${len(a)})"
        rows = await fetch(f"SELECT rule_key, name, explains, severity, params FROM mcp_site_rules WHERE {w} ORDER BY name", *a)
        return dumps({"site_id": params.site_id, "preset": st["rule_preset"], "rules": rows})

    @mcp.tool(name="announcements", annotations={"title": "Announcements", **RO})
    async def announcements(params: AnnouncementsIn) -> str:
        """The live announcements for a restaurant, pinned first: title, body, audience, who posted. Anyone who works there sees
        those addressed to them; someone who posts sees them all, with how many have read each."""
        me = db.request_member_id.get()
        w, a = ["1 = 1"], []
        if params.site_id:
            a.append(params.site_id); w.append(f"n.site_id = ${len(a)}")
        if params.q:
            a.append(f"%{params.q[:100]}%"); w.append(f"n.title ILIKE ${len(a)}")
        if params.mine:
            a.append(me); n = len(a)
            w.append(f"""(n.audience = 'site' OR (n.audience = 'position' AND EXISTS (SELECT 1 FROM mcp_staff_positions sp WHERE sp.member_id = ${n} AND sp.position_id = n.position_id))
                          OR (n.audience = 'people' AND n.posted_by <> ${n} AND n.read_count IS NULL))""")
        a.append(params.limit)
        rows = await fetch(f"""SELECT n.announcement_id, n.site_id, n.title, n.body, n.audience, n.position_id, n.pinned_until, (n.pinned_until IS NOT NULL AND n.pinned_until > now()) AS pinned,
                   n.posted_by, n.posted_by_name, n.created_at, n.read_by_me, n.read_count
              FROM mcp_announcements n WHERE {' AND '.join(w)}
             ORDER BY (n.pinned_until IS NOT NULL AND n.pinned_until > now()) DESC, n.created_at DESC LIMIT ${len(a)}""", *a)
        return dumps(rows)

    @mcp.tool(name="exchange_report", annotations={"title": "Trades over a period", **RO})
    async def exchange_report(params: ExchangeReportIn) -> str:
        """Trades over a period: how many of each kind, how many were taken (approved), declined, cancelled or expired; the
        average hours to a decision; who offers most and who picks up most; open shifts nobody took. Grouped by kind, person
        or week. Managers only (schedule.build)."""
        st = await site_row(fetch, params.site_id)
        if st is None:
            return err(NOT_A_SITE)
        if not await i_can(fetch, "schedule.build", params.site_id):
            return err("The trade report is for schedule.build at this restaurant.")
        if params.group_by not in ("kind", "person", "week"):
            return err("group_by is kind, person or week.")
        today = (await fetch("SELECT (now() AT TIME ZONE $1)::date AS d", st["timezone"]))[0]["d"]
        f = _iso_date(params.from_) or today - datetime.timedelta(days=28)
        t = _iso_date(params.to) or today
        base = """FROM mcp_exchanges x WHERE x.site_id = $1 AND (x.created_at AT TIME ZONE $4)::date >= $2 AND (x.created_at AT TIME ZONE $4)::date <= $3"""
        tz = st["timezone"]
        agg = """count(*)::int AS total, count(*) FILTER (WHERE x.status = 'approved')::int AS taken, count(*) FILTER (WHERE x.status = 'declined')::int AS declined,
                 count(*) FILTER (WHERE x.status = 'cancelled')::int AS cancelled, count(*) FILTER (WHERE x.status = 'expired')::int AS expired,
                 count(*) FILTER (WHERE x.status IN ('open', 'pending_acceptance', 'pending_approval'))::int AS still_open,
                 round((avg(extract(epoch FROM (x.decided_at - x.created_at)) / 3600) FILTER (WHERE x.decided_at IS NOT NULL))::numeric, 2) AS avg_hours_to_decision"""
        if params.group_by == "kind":
            groups = await fetch(f"SELECT x.kind AS \"group\", {agg} {base} GROUP BY x.kind ORDER BY x.kind", params.site_id, f, t, tz)
        elif params.group_by == "week":
            groups = await fetch(f"SELECT to_char(date_trunc('week', x.created_at AT TIME ZONE $4), 'YYYY-MM-DD') AS \"group\", {agg} {base} GROUP BY 1 ORDER BY 1", params.site_id, f, t, tz)
        else:
            groups = await fetch(f"SELECT COALESCE(x.from_name, 'open shift') AS \"group\", {agg} {base} GROUP BY 1 ORDER BY total DESC, 1", params.site_id, f, t, tz)
        top_offer = await fetch(f"SELECT x.from_member_id AS member_id, x.from_name AS name, count(*)::int AS offers {base} AND x.from_member_id IS NOT NULL AND x.kind IN ('offer', 'give', 'swap') GROUP BY 1, 2 ORDER BY offers DESC, 2 LIMIT 5", params.site_id, f, t, tz)
        top_pick = await fetch(f"""SELECT c.member_id, c.member_name AS name, count(*)::int AS pickups FROM mcp_exchange_claims c JOIN mcp_exchanges x ON x.exchange_id = c.exchange_id
                                    WHERE x.site_id = $1 AND c.status = 'won' AND (x.created_at AT TIME ZONE $4)::date >= $2 AND (x.created_at AT TIME ZONE $4)::date <= $3
                                    GROUP BY 1, 2 ORDER BY pickups DESC, 2 LIMIT 5""", params.site_id, f, t, tz)
        unfilled = await fetch(f"SELECT count(*)::int AS n {base} AND x.kind IN ('open', 'coverage') AND x.status IN ('expired', 'cancelled', 'declined')", params.site_id, f, t, tz)
        return dumps({"site_id": params.site_id, "from": f, "to": t, "group_by": params.group_by, "groups": groups,
                      "most_offers": top_offer, "most_pickups": top_pick, "open_or_coverage_shifts_nobody_took": unfilled[0]["n"]})
