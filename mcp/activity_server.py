"""txtschedules_activity_mcp — txtSchedules' activity-memory server (activity_log through mcp_activity_log, read-only).

Tool surface: docs/txtschedules-mcp-tool-surface.md, "Activity server". Every query runs as the asking member; the view decides
which rows they see (what happened at the restaurants where they build schedules, and what they did themselves). The activity
role reads mcp_activity_log ONLY (db/013) — names come from the view's own actor_name; no other view is needed. The trail never
carries a wage (the handlers' rule: wage.update says THAT a rate changed), so nothing here can return one.
"""
from __future__ import annotations

import datetime

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from business_common import RO, _Base, _iso_date, _period_bounds, dumps, err

mcp = FastMCP("txtschedules_activity_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


COLS = "l.activity_id, l.occurred_at, l.actor_member_id, l.actor_name, l.source, l.action, l.entity_type, l.entity_id, l.site_id, l.before, l.after, l.agent_run_id"


class SearchIn(_Base):
    sql: str = Field(..., description="A single read-only SELECT over mcp_activity_log (see below).")


class ShiftHistoryIn(_Base):
    shift_id: int
    action_prefix: str | None = Field(None, description="e.g. 'shift.change', 'exchange.'")
    limit: int = Field(100, ge=1, le=200)


class ExchangeHistoryIn(_Base):
    exchange_id: int
    limit: int = Field(100, ge=1, le=200)


class WhoDidIn(_Base):
    member_id: int | None = Field(None, description="The person or agent; omit for yourself")
    period: str | None = Field("this_week", description="today, this_week, last_week, this_month, last_month, YYYY-MM ...")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    site_id: int | None = None
    action_prefix: str | None = None
    limit: int = Field(100, ge=1, le=200)


class SiteActivityIn(_Base):
    site_id: int
    period: str | None = Field("this_week", description="today, this_week, last_week, this_month ... or YYYY-MM; 'yesterday' too")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    action_prefix: str | None = Field(None, description="publishes 'week.publish', changes 'shift.change', trades 'exchange.', requests 'timeoff.', settings 'settings.'")
    limit: int = Field(100, ge=1, le=200)


class DraftHistoryIn(_Base):
    week_id: int | None = None
    site_id: int | None = None
    week_start: str | None = Field(None, description="With site_id: the week's start date (YYYY-MM-DD)")


def _clean(rows: list[dict]) -> list[dict]:
    """Skip screen views — they are not what happened (screen.view rows carry no entity)."""
    return [r for r in rows if r.get("action") != "screen.view"]


def _sentence(r: dict) -> str:
    who = r.get("actor_name") or "Someone"
    who += " (agent)" if r.get("source") == "agent" else ""
    return f"{who}: {str(r.get('action') or '').replace('.', ' ').replace('_', ' ')}"


def _period(period: str | None, f: str | None, t: str | None) -> tuple[datetime.date, datetime.date]:
    if (period or "").lower() == "yesterday" and not (f or t):
        y = datetime.date.today() - datetime.timedelta(days=1)
        return y, y
    return _period_bounds(period, f, t)


@mcp.tool(name="shift_history", annotations={"title": "Everything that happened to a shift", **RO})
async def shift_history(params: ShiftHistoryIn) -> str:
    """Everything that happened to one shift, oldest first: created, assigned, changed after publishing (before and after),
    offered, claimed, traded, cancelled — with who and when. Includes the trades made on it. You see what happened at
    restaurants where you build schedules, and what you did yourself."""
    w = "((l.entity_type = 'shift' AND l.entity_id = $1) OR (l.entity_type = 'exchange' AND ((l.after->>'shift_id') = $2 OR l.entity_id IN "
    w += "(SELECT e.entity_id FROM mcp_activity_log e WHERE e.entity_type = 'exchange' AND (e.after->>'shift_id') = $2))))"
    a = [params.shift_id, str(params.shift_id)]
    if params.action_prefix:
        a.append(params.action_prefix.replace("%", "") + "%"); w += f" AND l.action LIKE ${len(a)}"
    a.append(params.limit)
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w} ORDER BY l.occurred_at, l.activity_id LIMIT ${len(a)}", *a)
    rows = _clean(rows)
    for r in rows:
        r["sentence"] = _sentence(r)
    return dumps({"shift_id": params.shift_id, "events": rows})


@mcp.tool(name="exchange_history", annotations={"title": "One trade's story", **RO})
async def exchange_history(params: ExchangeHistoryIn) -> str:
    """One trade's story, oldest first: offered by whom, who claimed, accepted or refused, the warnings, who approved or
    declined and the note, expiry."""
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE l.entity_type = 'exchange' AND l.entity_id = $1 ORDER BY l.occurred_at, l.activity_id LIMIT $2", params.exchange_id, params.limit)
    rows = _clean(rows)
    for r in rows:
        r["sentence"] = _sentence(r)
    return dumps({"exchange_id": params.exchange_id, "events": rows})


@mcp.tool(name="who_did", annotations={"title": "What a person or agent did", **RO})
async def who_did(params: WhoDidIn) -> str:
    """What one person or agent did in txtSchedules over a period, newest first. Yourself by default; another's actions need
    schedule.build at the restaurant they acted at. source 'agent' marks what an agent did."""
    mid = params.member_id or db.request_member_id.get()
    f, t = _period(params.period, params.from_, params.to)
    w, a = ["l.actor_member_id = $1", "l.occurred_at >= $2", "l.occurred_at < $3", "l.action <> 'screen.view'"], [mid, datetime.datetime.combine(f, datetime.time.min, datetime.timezone.utc), datetime.datetime.combine(t + datetime.timedelta(days=1), datetime.time.min, datetime.timezone.utc)]
    if params.site_id:
        a.append(params.site_id); w.append(f"l.site_id = ${len(a)}")
    if params.action_prefix:
        a.append(params.action_prefix.replace("%", "") + "%"); w.append(f"l.action LIKE ${len(a)}")
    a.append(params.limit)
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {' AND '.join(w)} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT ${len(a)}", *a)
    for r in rows:
        r["sentence"] = _sentence(r)
    return dumps({"member_id": mid, "from": f, "to": t, "count": len(rows), "events": rows})


@mcp.tool(name="site_activity", annotations={"title": "What happened at a restaurant", **RO})
async def site_activity(params: SiteActivityIn) -> str:
    """What happened at a restaurant — yesterday, this week, since a date — with a count by kind of event (publishes,
    changes, trades, requests, settings) and the newest events. For a manager (schedule.build); anyone else sees only what they
    did there."""
    f, t = _period(params.period, params.from_, params.to)
    w, a = ["l.site_id = $1", "l.occurred_at >= $2", "l.occurred_at < $3", "l.action <> 'screen.view'"], [params.site_id, datetime.datetime.combine(f, datetime.time.min, datetime.timezone.utc), datetime.datetime.combine(t + datetime.timedelta(days=1), datetime.time.min, datetime.timezone.utc)]
    if params.action_prefix:
        a.append(params.action_prefix.replace("%", "") + "%"); w.append(f"l.action LIKE ${len(a)}")
    where = " AND ".join(w)
    kinds = await fetch(f"SELECT split_part(l.action, '.', 1) AS kind, count(*)::int AS events FROM mcp_activity_log l WHERE {where} GROUP BY 1 ORDER BY events DESC", *a)
    a2 = a + [params.limit]
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {where} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT ${len(a2)}", *a2)
    for r in rows:
        r["sentence"] = _sentence(r)
    return dumps({"site_id": params.site_id, "from": f, "to": t, "by_kind": kinds, "events": rows})


@mcp.tool(name="draft_history", annotations={"title": "What the scheduling assistant drafted", **RO})
async def draft_history(params: DraftHistoryIn) -> str:
    """What the scheduling assistant (an agent) drafted for a week — shifts created and assigned, the run that did it — and
    what the manager changed after it, until and after publishing. By week_id, or site_id + week_start. Managers only
    (schedule.build)."""
    wid = params.week_id
    if wid is None:
        d = _iso_date(params.week_start)
        if not (params.site_id and d):
            return err("Give a week_id, or site_id and week_start (YYYY-MM-DD).")
        r = await fetch("SELECT entity_id FROM mcp_activity_log l WHERE l.action = 'week.create' AND l.site_id = $1 AND (l.after->>'week_start') = $2 ORDER BY l.activity_id DESC LIMIT 1", params.site_id, d.isoformat())
        if not r:
            return err("No record of that week being created among the restaurants you build for — pass its week_id.")
        wid = int(r[0]["entity_id"])
    rows = await fetch(f"""SELECT {COLS} FROM mcp_activity_log l
                            WHERE l.action <> 'screen.view' AND ((l.entity_type = 'week' AND l.entity_id = $1)
                               OR (l.entity_type = 'shift' AND (l.after->>'week_id') = $2))
                            ORDER BY l.occurred_at, l.activity_id LIMIT 300""", wid, str(wid))
    for r in rows:
        r["by"] = "agent" if r["source"] == "agent" else "person"
        r["sentence"] = _sentence(r)
    agent_rows = [r for r in rows if r["by"] == "agent"]
    first_agent = agent_rows[0]["occurred_at"] if agent_rows else None
    return dumps({"week_id": wid,
                  "drafted_by_agent": [r for r in agent_rows],
                  "runs": sorted({r["agent_run_id"] for r in agent_rows if r["agent_run_id"] is not None}),
                  "changed_by_people_after": [r for r in rows if r["by"] == "person" and first_agent is not None and r["occurred_at"] >= first_agent and r["action"].startswith(("shift.", "week."))],
                  "all": rows})


@mcp.tool(name="activity_search", annotations={"title": "Activity search (SQL)", **RO})
async def activity_search(params: SearchIn) -> str:
    """The long tail: one read-only SELECT over mcp_activity_log (activity_id, occurred_at, actor_member_id, actor_name, source
    ['web', 'assistant', 'agent', 'cron', 'application'], action ['shift.create', 'shift.assign', 'shift.change', 'shift.cancel',
    'week.publish', 'exchange.offer', 'exchange.claim', 'exchange.approve', 'timeoff.request', 'timeoff.approve', 'wage.update',
    'settings.update' ...], entity_type, entity_id, site_id, before, after (jsonb, changed fields only — never a rate),
    agent_run_id, request_id). Already scoped to what you may see. One statement, 5 s timeout, 200 rows."""
    return await db.run_search(await _pool(), params.sql)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
