"""txtschedules_records_mcp — txtSchedules' record-memory server (PostgreSQL, the mcp_* views, read-only).

Tool surface: docs/txtschedules-mcp-tool-surface.md. Every tool reads the views as the asking member, so a person sees what they
see on the screens and — the wage rule — a pay rate only where the views hand one over (labor.view at the restaurant, or their
own effective rate). An agent sees only the tools the kernel granted it (server_common), and the restaurants it holds
(member_site_roles, the same per-site rule as a person). The kernel's own token reaches app_roles and time_off_taken only.
"""
from __future__ import annotations

import datetime

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from business_common import RO, _Base, _iso_date, dumps, err

mcp = FastMCP("txtschedules_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


class SearchIn(_Base):
    sql: str = Field(..., description="A single read-only SELECT over the mcp_* views (see below).")


class TimeOffTakenIn(_Base):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD (at most 366 days after `from`)")
    scope_id: int = Field(..., description="txtSchedules' own id for the restaurant — set by the kernel")


@mcp.tool(name="records_search", annotations={"title": "Records search (SQL)", **RO})
async def records_search(params: SearchIn) -> str:
    """The long tail: one read-only SELECT over txtSchedules' views — restaurants (mcp_sites: site_id, name, timezone,
    week_start, trade switches, my_role), people (mcp_members: member_id, display_name, main_site_id, on_schedule, site_ids;
    mcp_member_site_roles; mcp_staff; mcp_staff_positions; mcp_positions; mcp_certification_kinds; mcp_certifications;
    mcp_certifications_due), availability and time off (mcp_availability; mcp_time_off_types; mcp_time_off_balances;
    mcp_time_off_ledger; mcp_time_off_requests; mcp_blackout_dates), the schedule (mcp_schedule_weeks; mcp_shifts: shift_id,
    site_id, week_id, position_name, starts_at, ends_at, paid_hours, assignee_name, is_open, status; mcp_templates;
    mcp_template_shifts), trades (mcp_exchanges; mcp_exchange_claims; mcp_exchange_invitees), rules (mcp_site_rules;
    mcp_rule_overrides), labor and forecast (mcp_hours_weekly; mcp_labor_weekly; mcp_labor_budgets; mcp_day_parts;
    mcp_forecast_covers; mcp_staffing_ratios) and mcp_announcements. Already scoped to the restaurants you hold and the
    rights you have there: pay columns are null unless you hold labor.view. Times are UTC (timestamptz); use AT TIME ZONE with
    mcp_sites.timezone. One statement, 5 s timeout, 200 rows."""
    return await db.run_search(await _pool(), params.sql)


@mcp.tool(name="app_roles", annotations={"title": "txtSchedules' roles and rights", **RO})
async def app_roles() -> str:
    """The roles txtSchedules offers and the rights each gives AT A RESTAURANT — what the Business OS kernel reads to let a
    super-admin grant them (schema os.app-roles/1, maludb-os-integration 0.4.x). The catalogue is not about anyone, so the
    kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM ts_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, rights FROM mcp_app_roles ORDER BY sort_order")
    return db.json.dumps({
        "schema": "os.app-roles/1",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"],
                   "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


@mcp.tool(name="time_off_taken", annotations={"title": "Approved time off per person (for HR)", **RO})
async def time_off_taken(params: TimeOffTakenIn) -> str:
    """A shared tool — the Business OS kernel's own token only, for HR (an application with directory writes) over a
    super-admin-approved connection. Approved time off per person for a period at ONE restaurant, keyed by the KERNEL's member
    id: per request the type (key and name, paid or not), start, end (the restaurant's local time and UTC), hours, days counted.
    Never a reason, a note, a balance or a pay rate; only people at that restaurant. Nobody else may call it."""
    if not db.request_is_kernel.get():
        return err("time_off_taken is for the Business OS kernel's own token only.")
    f, t = _iso_date(params.from_), _iso_date(params.to)
    if f is None or t is None or t < f:
        return err("from and to are YYYY-MM-DD dates, from not after to.")
    if (t - f).days > 366:
        return err("Ask for at most 366 days at a time.")
    rows = await fetch("SELECT member_id, type_key, type_name, paid, starts_at, ends_at, hours, days, timezone FROM ts_time_off_taken($1, $2, $3)", params.scope_id, f, t)
    people: dict[int, dict] = {}
    for r in rows:
        tz = r["timezone"]
        loc = lambda x: x.astimezone(__import__("zoneinfo").ZoneInfo(tz)).strftime("%Y-%m-%dT%H:%M")
        p = people.setdefault(int(r["member_id"]), {"member_id": int(r["member_id"]), "total_hours": 0.0, "total_days": 0, "requests": []})
        p["total_hours"] = round(p["total_hours"] + float(r["hours"]), 2)
        p["total_days"] += int(r["days"])
        p["requests"].append({"type": r["type_key"], "type_name": r["type_name"], "paid": r["paid"], "starts_local": loc(r["starts_at"]), "ends_local": loc(r["ends_at"]),
                              "starts_utc": r["starts_at"], "ends_utc": r["ends_at"], "timezone": tz, "hours": float(r["hours"]), "days": int(r["days"])})
    return dumps({"schema": "txtschedules.time-off-taken/1", "scope_id": params.scope_id, "from": f, "to": t, "people": list(people.values())})


import ts_people
import ts_schedule
import ts_requests
import ts_labor

ts_people.register(mcp, fetch)
ts_schedule.register(mcp, fetch)
ts_requests.register(mcp, fetch)
ts_labor.register(mcp, fetch)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
