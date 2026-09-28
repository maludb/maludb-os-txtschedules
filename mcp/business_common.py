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
