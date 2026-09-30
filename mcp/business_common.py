"""Shared pieces for txtSchedules' tool modules: annotations, the input base, period parsing."""
from __future__ import annotations

import datetime

from pydantic import BaseModel, ConfigDict

RO = {"readOnlyHint": True, "openWorldHint": False}


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid")


def _iso_date(s: str | None) -> datetime.date | None:
    if not s:
        return None
    try:
        return datetime.date.fromisoformat(s[:10])
    except ValueError:
        return None


def _period_bounds(period: str | None, date_from: str | None = None, date_to: str | None = None) -> tuple[datetime.date, datetime.date]:
    """'today', 'this_week', 'last_week', 'this_month', 'next_month', 'last_month', 'this_quarter', 'this_year',
    'last_year', 'next_30_days', 'YYYY-MM', or explicit dates (which win)."""
    today = datetime.date.today()
    f, t = _iso_date(date_from), _iso_date(date_to)
    if f or t:
        return f or today, t or (f or today)
    p = (period or "this_month").lower()
    if len(p) == 7 and p[4] == "-" and p[:4].isdigit() and p[5:].isdigit():
        y, m = int(p[:4]), int(p[5:])
        start = datetime.date(y, m, 1)
        end = (datetime.date(y + (m == 12), (m % 12) + 1, 1) - datetime.timedelta(days=1))
        return start, end
    if p == "today":
        return today, today
    if p == "this_week":
        s = today - datetime.timedelta(days=today.weekday()); return s, s + datetime.timedelta(days=6)
    if p == "last_week":
        s = today - datetime.timedelta(days=today.weekday() + 7); return s, s + datetime.timedelta(days=6)
    if p == "next_30_days":
        return today, today + datetime.timedelta(days=30)
    if p == "last_month":
        first = today.replace(day=1); end = first - datetime.timedelta(days=1); return end.replace(day=1), end
    if p == "next_month":
        first = (today.replace(day=1) + datetime.timedelta(days=32)).replace(day=1)
        end = (first + datetime.timedelta(days=32)).replace(day=1) - datetime.timedelta(days=1); return first, end
    if p == "this_quarter":
        q = (today.month - 1) // 3; s = datetime.date(today.year, q * 3 + 1, 1)
        e = (datetime.date(today.year + (q == 3), ((q + 1) % 4) * 3 + 1, 1) - datetime.timedelta(days=1)); return s, e
    if p == "this_year":
        return datetime.date(today.year, 1, 1), datetime.date(today.year, 12, 31)
    if p == "last_year":
        return datetime.date(today.year - 1, 1, 1), datetime.date(today.year - 1, 12, 31)
    first = today.replace(day=1)
    return first, (first + datetime.timedelta(days=32)).replace(day=1) - datetime.timedelta(days=1)


# ---------------------------------------------------------------------------------------------------------------
# txtSchedules' shared read helpers (Phase 4). Every time is answered in the restaurant's zone AND in UTC (tool-surface conventions).
# ---------------------------------------------------------------------------------------------------------------
import json as _json

LOCAL_FMT = "YYYY-MM-DD\"T\"HH24:MI"


def tz_of(st: str = "st") -> str:
    return f"COALESCE({st}.timezone, 'UTC')"


def local(expr: str, st: str = "st") -> str:
    """SQL: a timestamptz as the restaurant's local wall time text, 'YYYY-MM-DDTHH24:MI'."""
    return f"to_char(({expr}) AT TIME ZONE {tz_of(st)}, '{LOCAL_FMT}')"


def shift_cols(a: str = "s", st: str = "st") -> str:
    """The columns every shift answer carries. `cost` is what the view gave: null unless labor.view at the site."""
    return f"""{a}.shift_id, {a}.site_id, {st}.name AS site_name, {a}.week_id, {a}.position_id, {a}.position_name,
       {a}.assignee_member_id, {a}.assignee_name, {a}.is_open, {a}.status,
       {local(a + '.starts_at', st)} AS starts_local, {local(a + '.ends_at', st)} AS ends_local, {tz_of(st)} AS timezone,
       {a}.starts_at AS starts_utc, {a}.ends_at AS ends_utc, {a}.break_minutes, {a}.paid_hours,
       ({a}.published_at IS NOT NULL) AS published, ({a}.changed_after_publish_at IS NOT NULL) AS changed_after_publish,
       {a}.cancel_reason, {a}.note, {a}.cost,
       COALESCE({a}.assignee_name, 'Open') || ' ' || to_char(({a}.starts_at) AT TIME ZONE {tz_of(st)}, 'Dy Mon DD HH24:MI') || ' ' || {a}.position_name AS label"""


def dumps(obj) -> str:
    import db
    return db.to_json(obj)


def err(message: str, **extra) -> str:
    return dumps({"error": message, **extra})


def parse_json_cols(rows: list[dict], *cols: str) -> list[dict]:
    for r in rows:
        for c in cols:
            v = r.get(c)
            if isinstance(v, str):
                try:
                    r[c] = _json.loads(v)
                except ValueError:
                    pass
    return rows


NOT_A_SITE = "No such restaurant among yours — call find_sites for the ones you hold."


async def site_row(fetch, site_id: int | None) -> dict | None:
    """A restaurant the caller holds (mcp_sites), or None — a site not held does not exist to the caller."""
    if not site_id:
        return None
    rows = await fetch("SELECT * FROM mcp_sites WHERE site_id = $1", site_id)
    return rows[0] if rows else None


async def week_row(fetch, site_id: int, on: "datetime.date | None") -> dict | None:
    """The schedule week (mcp_schedule_weeks) of a restaurant that contains a date (default: today in the restaurant's zone)."""
    if on is None:
        rows = await fetch("""SELECT w.* FROM mcp_schedule_weeks w JOIN mcp_sites st ON st.site_id = w.site_id
                               WHERE w.site_id = $1 AND w.week_start <= (now() AT TIME ZONE st.timezone)::date
                                 AND w.week_start > (now() AT TIME ZONE st.timezone)::date - 7 ORDER BY w.week_start DESC LIMIT 1""", site_id)
    else:
        rows = await fetch("SELECT w.* FROM mcp_schedule_weeks w WHERE w.site_id = $1 AND w.week_start <= $2 AND w.week_start > $2 - 7 ORDER BY w.week_start DESC LIMIT 1", site_id, on)
    return rows[0] if rows else None


async def i_can(fetch, right: str, site_id: int) -> bool:
    """Does the caller hold this right at this restaurant? (ts_has_right — the app's own rule, granted to the read role.)"""
    rows = await fetch("SELECT ts_has_right($1, $2) AS ok", right, site_id)
    return bool(rows and rows[0]["ok"])
