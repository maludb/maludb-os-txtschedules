"""txtSchedules — the schedule, the marketplace and the rules (tool surface, "Schedule").
Reads mcp_shifts, mcp_schedule_weeks, mcp_templates, mcp_template_shifts, mcp_exchanges, mcp_exchange_claims, mcp_exchange_invitees,
mcp_rule_overrides, mcp_hours_weekly, mcp_day_parts, mcp_sites, and the four gated functions (ts_coverage_candidates,
ts_check_assignment, ts_week_warnings) which check the caller's right inside. A shift's cost is the view's: null without labor.view.
Times are the restaurant's local wall time (starts_local, timezone) and UTC (starts_utc).
"""
from __future__ import annotations

import datetime
import re

from pydantic import Field

import db
from business_common import NOT_A_SITE, RO, _Base, _iso_date, dumps, err, i_can, local, shift_cols, site_row, week_row


class WhoIsOnIn(_Base):
    site_id: int = Field(..., description="The restaurant (find_sites gives ids)")
    date: str | None = Field(None, description="YYYY-MM-DD in the restaurant's zone; default today")
    at: str | None = Field(None, description="'now', or a time HH:MM on that date, or YYYY-MM-DDTHH:MM — who is working at that moment")
    position_id: int | None = Field(None, description="Only this position")


class MyShiftsIn(_Base):
    member_id: int | None = Field(None, description="Another person (a manager may ask); omit for yourself")
    from_: str | None = Field(None, alias="from", description="YYYY-MM-DD; default today")
    to: str | None = Field(None, description="YYYY-MM-DD; default 14 days from the start")
    limit: int = Field(25, ge=1, le=100)


class WeekScheduleIn(_Base):
    site_id: int | None = Field(None, description="The restaurant. Omit it with `q` to LOOK A SHIFT OR A WEEK UP across your restaurants (answers a plain list)")
    week_start: str | None = Field(None, description="Any date in the week (YYYY-MM-DD); default this week")
    q: str | None = Field(None, description="Narrow the shifts: a person and/or a day and/or a position, e.g. 'Priya Friday'; a date picks that date's week")
    position_id: int | None = None
    assignee_member_id: int | None = None
    include_cancelled: bool = False
    limit: int = Field(200, ge=1, le=300)


class GetShiftIn(_Base):
    shift_id: int = Field(..., description="The shift (week_schedule or my_shifts gives ids)")


class FindTemplatesIn(_Base):
    site_id: int | None = Field(None, description="One restaurant; omit for every restaurant you build for")
    template_id: int | None = Field(None, description="One template, with its shifts")
    q: str | None = Field(None, description="Words of the template's name")


class MarketplaceIn(_Base):
    site_id: int | None = None
    kind: str | None = Field(None, description="offer, open, give, swap or coverage")
    mine: bool = Field(False, description="Only exchanges you started, were asked, invited to or claimed")
    status: str | None = Field(None, description="open (the default), pending_acceptance, pending_approval, approved, declined, cancelled, expired or all; with `q` and no status: every LIVE exchange (open, pending_acceptance, pending_approval)")
    q: str | None = Field(None, description="Words of the holder's name or the position")
    limit: int = Field(25, ge=1, le=100)


class CoverageIn(_Base):
    shift_id: int
    limit: int = Field(15, ge=1, le=50)


class CheckAssignmentIn(_Base):
    member_id: int = Field(..., description="The person the shift would go to")
    shift_id: int | None = Field(None, description="An existing shift ... ")
    site_id: int | None = Field(None, description="... or one being planned: the restaurant,")
    position_id: int | None = Field(None, description="the position,")
    starts_at: str | None = Field(None, description="the start (ISO with offset, or the restaurant's local time YYYY-MM-DDTHH:MM),")
    ends_at: str | None = Field(None, description="the end,")
    break_minutes: int = Field(0, ge=0, le=480)


class WeekWarningsIn(_Base):
    week_id: int | None = None
    site_id: int | None = None
    week_start: str | None = Field(None, description="With site_id: any date in the week")
    severity: str | None = Field(None, description="hard or soft")


class OverridesIn(_Base):
    site_id: int | None = None
    member_id: int | None = None
    rule_key: str | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    limit: int = Field(50, ge=1, le=100)


class HoursIn(_Base):
    site_id: int | None = None
    week_start: str | None = Field(None, description="Any date in the week; default this week")
    member_id: int | None = Field(None, description="One person; omit (and near_overtime) for yourself")
    near_overtime: bool = Field(False, description="Everyone at or over 90% of the overtime line (a manager)")
    limit: int = Field(50, ge=1, le=100)


def _dt(text: str | None, tz_name: str) -> datetime.datetime | None:
    """An ISO instant (with offset) or a local wall time in the restaurant's zone -> an aware datetime."""
    if not text:
        return None
    import zoneinfo
    try:
        d = datetime.datetime.fromisoformat(text.strip())
    except ValueError:
        return None
    return d if d.tzinfo else d.replace(tzinfo=zoneinfo.ZoneInfo(tz_name))


async def _day_parts(fetch, site_id: int) -> list[dict]:
    return await fetch("SELECT name, starts_at, ends_at FROM mcp_day_parts WHERE site_id = $1 ORDER BY sort_order", site_id)


def _parts_of(parts: list[dict], starts_local: str, ends_local: str) -> list[str]:
    """The day-parts a shift overlaps, from its local wall times (strings 'YYYY-MM-DDTHH:MM')."""
    s = datetime.datetime.fromisoformat(starts_local)
    e = datetime.datetime.fromisoformat(ends_local)
    out = []
    for dp in parts:
        for base in (s.date() - datetime.timedelta(days=1), s.date()):
            a = datetime.datetime.combine(base, dp["starts_at"])
            b = datetime.datetime.combine(base, dp["ends_at"])
            if b <= a:
                b += datetime.timedelta(days=1)
            if s < b and e > a:
                out.append(dp["name"])
                break
    return out


def _q_filters(words: list[str], a: list, w: list[str]) -> None:
    """Narrow shift rows (alias s, st) by a person, a position, a weekday or month name, or a local date."""
    for word in words:
        if re.fullmatch(r"\d{4}-\d{2}-\d{2}", word):
            a.append(word); w.append(f"to_char(s.starts_at AT TIME ZONE COALESCE(st.timezone, 'UTC'), 'YYYY-MM-DD') = ${len(a)}")
            continue
        a.append(f"%{word[:40]}%"); n = len(a)
        a.append("\\m" + re.escape(word[:40])); m = len(a)          # a NAME matches at the start of a word: "Ana" is not "Dana"
        w.append(f"(COALESCE(s.assignee_name, 'open') ~* ${m} OR s.position_name ~* ${m} "
                 f"OR to_char(s.starts_at AT TIME ZONE COALESCE(st.timezone, 'UTC'), 'FMDay') ILIKE ${n} OR to_char(s.starts_at AT TIME ZONE COALESCE(st.timezone, 'UTC'), 'FMMonth') ILIKE ${n})")


async def _resolve_shift_or_week(fetch, q: str, limit: int) -> str:
    """Resolve mode (no site_id): the kernel's entity resolver sends only `q` and reads a plain LIST of rows carrying the id.
    A date alone names a WEEK (rows with week_id); anything else names SHIFTS in the next four weeks (rows with shift_id, week_id).
    Only what the caller may see: the views decide."""
    words = [x for x in re.split(r"\s+", q.strip()) if x]
    if words and all(re.fullmatch(r"\d{4}-\d{2}-\d{2}", x) for x in words):
        d = _iso_date(words[0])
        rows = await fetch("""SELECT w.week_id, w.site_id, st.name AS site_name, w.week_start, w.status AS state,
                   st.name || ' week of ' || to_char(w.week_start, 'YYYY-MM-DD') || ' (' || w.status || ')' AS label
              FROM mcp_schedule_weeks w JOIN mcp_sites st ON st.site_id = w.site_id
             WHERE w.week_start <= $1 AND w.week_start > $1 - 7 ORDER BY st.name LIMIT $2""", d, limit)
        return dumps(rows)
    w, a = ["s.status = 'scheduled'", "s.ends_at > now() - interval '1 day'", "s.starts_at < now() + interval '28 days'"], []
    _q_filters(words, a, w)
    a.append(limit)
    rows = await fetch(f"""SELECT {shift_cols()} FROM mcp_shifts s JOIN mcp_sites st ON st.site_id = s.site_id
                            WHERE {' AND '.join(w)} ORDER BY s.starts_at, s.position_name, s.assignee_name LIMIT ${len(a)}""", *a)
    return dumps(rows)


def register(mcp, fetch) -> None:
    @mcp.tool(name="who_is_on", annotations={"title": "Who is working", **RO})
    async def who_is_on(params: WhoIsOnIn) -> str:
        """Who is working at a restaurant on a date, at a time, or right now — by position, with the day-parts; open
        (unassigned) shifts listed apart. 'Who is on Friday?' -> site_id + date. Published shifts are 'working'; a manager
        also sees the week's unpublished draft shifts, marked as draft. Times are the restaurant's local time and UTC."""
        st = await site_row(fetch, params.site_id)
        if st is None:
            return err(NOT_A_SITE)
        tz = st["timezone"]
        if params.date:
            day = _iso_date(params.date)
            if day is None:
                return err("date is YYYY-MM-DD.")
        else:
            day = (await fetch("SELECT (now() AT TIME ZONE $1)::date AS d", tz))[0]["d"]
        a = [params.site_id, day, tz]
        cond = "s.starts_at < (($2::date + 1)::timestamp AT TIME ZONE $3) AND s.ends_at > ($2::date::timestamp AT TIME ZONE $3)"
        moment = None
        if params.at:
            if params.at.strip().lower() == "now":
                moment = (await fetch("SELECT now() AS t"))[0]["t"]
                cond = "s.starts_at <= $4 AND s.ends_at > $4"
                a.append(moment)
            else:
                t = params.at.strip()
                if re.fullmatch(r"\d{1,2}:\d{2}", t):
                    t = f"{day.isoformat()}T{int(t.split(':')[0]):02d}:{t.split(':')[1]}"
                moment = _dt(t, tz)
                if moment is None:
                    return err("at is 'now', HH:MM, or YYYY-MM-DDTHH:MM.")
                cond = "s.starts_at <= $4 AND s.ends_at > $4"
                a.append(moment)
        if moment is not None:
            cond += " AND $2::date IS NOT NULL AND $3::text IS NOT NULL"     # every parameter must be used in the statement (asyncpg types them from it)
        if params.position_id:
            a.append(params.position_id); cond += f" AND s.position_id = ${len(a)}"
        rows = await fetch(f"""SELECT {shift_cols()} FROM mcp_shifts s JOIN mcp_sites st ON st.site_id = s.site_id
                                WHERE s.site_id = $1 AND s.status = 'scheduled' AND {cond}
                                ORDER BY s.starts_at, s.position_name, s.assignee_name""", *a)
        parts = await _day_parts(fetch, params.site_id)
        for r in rows:
            r["day_parts"] = _parts_of(parts, r["starts_local"], r["ends_local"])
        working = [r for r in rows if r["assignee_member_id"] is not None and r["published"]]
        by_position: dict[str, list[str]] = {}
        for r in working:
            by_position.setdefault(r["position_name"], []).append(r["assignee_name"])
        return dumps({"site_id": params.site_id, "site": st["name"], "date": day, "timezone": tz, "at": moment,
                      "working": working, "by_position": by_position,
                      "open": [r for r in rows if r["assignee_member_id"] is None and r["published"]],
                      "draft": [r for r in rows if not r["published"]]})

    @mcp.tool(name="my_shifts", annotations={"title": "My shifts", **RO})
    async def my_shifts(params: MyShiftsIn) -> str:
        """The caller's next shifts, or their week: when (restaurant's local time and UTC), where, position, who else is on,
        and the exchange on each if there is one. A manager (schedule.build) may ask for another person's. Published shifts only."""
        me = db.request_member_id.get()
        mid = params.member_id or me
        if mid != me:
            allowed = await fetch("SELECT 1 FROM mcp_staff WHERE member_id = $1", mid)
            if not allowed:
                return err("Only a manager of that person's restaurant (schedule.build) may list another person's shifts.")
        start = _iso_date(params.from_) or datetime.date.today()
        end = _iso_date(params.to) or start + datetime.timedelta(days=14)
        rows = await fetch(f"""SELECT {shift_cols()},
                   COALESCE((SELECT jsonb_agg(o.assignee_name ORDER BY o.assignee_name) FROM mcp_shifts o
                              WHERE o.site_id = s.site_id AND o.shift_id <> s.shift_id AND o.status = 'scheduled' AND o.published_at IS NOT NULL
                                AND o.assignee_member_id IS NOT NULL AND o.assignee_member_id <> s.assignee_member_id
                                AND o.starts_at < s.ends_at AND o.ends_at > s.starts_at), '[]'::jsonb) AS also_on,
                   (SELECT jsonb_build_object('exchange_id', x.exchange_id, 'kind', x.kind, 'status', x.status) FROM mcp_exchanges x
                     WHERE x.shift_id = s.shift_id AND x.status IN ('open', 'pending_acceptance', 'pending_approval') LIMIT 1) AS exchange
              FROM mcp_shifts s JOIN mcp_sites st ON st.site_id = s.site_id
             WHERE s.assignee_member_id = $1 AND s.status = 'scheduled' AND s.published_at IS NOT NULL
               AND s.ends_at > ($2::date::timestamp AT TIME ZONE COALESCE(st.timezone, 'UTC'))
               AND s.starts_at < (($3::date + 1)::timestamp AT TIME ZONE COALESCE(st.timezone, 'UTC'))
             ORDER BY s.starts_at LIMIT $4""", mid, start, end, params.limit)
        return dumps({"member_id": mid, "from": start, "to": end, "shifts": rows})

    @mcp.tool(name="week_schedule", annotations={"title": "A restaurant's week", **RO})
    async def week_schedule(params: WeekScheduleIn) -> str:
        """A restaurant's week as the builder shows it: shifts by day and position, who, paid hours, open and cancelled
        shifts, the week's state (draft or published) and which shifts changed since publishing. Returns the week_id. Everyone
        who holds the restaurant sees a published week; only a manager sees a draft. A shift's cost appears only with
        labor.view. `q` narrows the shifts ('Priya Friday'): it is also how an action resolves a shift."""
        if params.site_id is None:
            if not params.q:
                return err("Give a site_id (find_sites), or q to look a shift or a week up.")
            return await _resolve_shift_or_week(fetch, params.q, min(params.limit, 25))
        st = await site_row(fetch, params.site_id)
        if st is None:
            return err(NOT_A_SITE)
        on = _iso_date(params.week_start)
        q_words = []
        if params.q:
            q_words = [w for w in re.split(r"\s+", params.q.strip()) if w]
            dates = [d for d in (_iso_date(w) for w in q_words if re.fullmatch(r"\d{4}-\d{2}-\d{2}", w)) if d]
            if dates and on is None:
                on = dates[0]
        wk = await week_row(fetch, params.site_id, on)
        if wk is None:
            return dumps({"site_id": params.site_id, "week": None, "note": "No schedule week exists for that date (or it is an unpublished draft you may not see)."})
        w, a = ["s.week_id = $1"], [wk["week_id"]]
        if not params.include_cancelled:
            w.append("s.status = 'scheduled'")
        if params.position_id:
            a.append(params.position_id); w.append(f"s.position_id = ${len(a)}")
        if params.assignee_member_id:
            a.append(params.assignee_member_id); w.append(f"s.assignee_member_id = ${len(a)}")
        _q_filters([x for x in q_words if not (re.fullmatch(r'\d{4}-\d{2}-\d{2}', x) and on is not None and _iso_date(x) == on)], a, w)
        a.append(params.limit)
        rows = await fetch(f"""SELECT {shift_cols()} FROM mcp_shifts s JOIN mcp_sites st ON st.site_id = s.site_id
                                WHERE {' AND '.join(w)} ORDER BY s.starts_at, s.position_name, s.assignee_name LIMIT ${len(a)}""", *a)
        days: dict[str, list[dict]] = {}
        for r in rows:
            days.setdefault(r["starts_local"][:10], []).append(r)
        live = [r for r in rows if r["status"] == "scheduled"]
        costs = [r["cost"] for r in live if r["cost"] is not None]
        out = {"site_id": params.site_id, "site": st["name"], "timezone": st["timezone"],
               "week_id": wk["week_id"], "week_start": wk["week_start"], "state": wk["status"], "published_at": wk["published_at"],
               "totals": {"shifts": len(live), "open_shifts": sum(1 for r in live if r["is_open"]),
                          "paid_hours": round(sum(float(r["paid_hours"]) for r in live), 2)},
               "changed_since_publishing": [r["shift_id"] for r in live if r["changed_after_publish"]],
               "days": [{"date": d, "shifts": v} for d, v in days.items()]}
        if costs:
            out["totals"]["cost"] = round(sum(float(c) for c in costs), 2)   # only ever present when the view gave cost (labor.view)
        return dumps(out)

    @mcp.tool(name="get_shift", annotations={"title": "One shift in full", **RO})
    async def get_shift(params: GetShiftIn) -> str:
        """One shift in full: times in the restaurant's zone, position, holder, note, whether it changed after publishing, its
        live exchange with the claims the caller may see, and the rule warnings if it were assigned as it is (for the holder
        or a manager)."""
        rows = await fetch(f"SELECT {shift_cols()} FROM mcp_shifts s JOIN mcp_sites st ON st.site_id = s.site_id WHERE s.shift_id = $1", params.shift_id)
        if not rows:
            return err("No such shift among the ones you can see.")
        s = rows[0]
        xs = await fetch("""SELECT exchange_id, kind, status, from_name, to_name, swap_shift_id, needs_approval, warnings, note, expires_at, claims, decided_at, decision_note
                              FROM mcp_exchanges WHERE shift_id = $1 ORDER BY created_at DESC LIMIT 5""", params.shift_id)
        for x in xs:
            x["claim_list"] = await fetch("SELECT member_id, member_name, status, warnings FROM mcp_exchange_claims WHERE exchange_id = $1 ORDER BY created_at", x["exchange_id"])
        warnings = None
        if s["assignee_member_id"] is not None and s["status"] == "scheduled":
            warnings = await fetch("""SELECT rule_key, severity, message FROM ts_check_assignment($1, $2, $3, $4, $5, $6, $7)""",
                                   s["assignee_member_id"], s["site_id"], s["position_id"], s["starts_utc"], s["ends_utc"], int(s["break_minutes"]), s["shift_id"])
        return dumps({"shift": s, "exchanges": xs, "rule_warnings": warnings,
                      "rule_warnings_note": None if warnings is not None else "Only for an assigned, scheduled shift."})

    @mcp.tool(name="find_templates", annotations={"title": "Week templates", **RO})
    async def find_templates(params: FindTemplatesIn) -> str:
        """The saved week templates of a restaurant with their shift counts; with template_id, what the template holds
        (weekday, times, position, person). CALL THIS FIRST whenever an action needs a template_id. Managers only."""
        w, a = ["1 = 1"], []
        if params.site_id:
            a.append(params.site_id); w.append(f"t.site_id = ${len(a)}")
        if params.template_id:
            a.append(params.template_id); w.append(f"t.template_id = ${len(a)}")
        if params.q:
            a.append(f"%{params.q[:100]}%"); w.append(f"t.name ILIKE ${len(a)}")
        rows = await fetch(f"SELECT t.template_id, t.site_id, t.name, t.shift_count, t.created_at FROM mcp_templates t WHERE {' AND '.join(w)} ORDER BY t.site_id, t.name LIMIT 50", *a)
        if params.template_id and rows:
            rows[0]["shifts"] = await fetch("""SELECT x.template_shift_id, x.weekday, x.starts_at, x.ends_at, x.break_minutes, x.position_id, p.name AS position, x.assignee_member_id, m.display_name AS assignee_name
                                                 FROM mcp_template_shifts x LEFT JOIN mcp_positions p ON p.position_id = x.position_id LEFT JOIN mcp_members m ON m.member_id = x.assignee_member_id
                                                WHERE x.template_id = $1 ORDER BY x.weekday, x.starts_at""", params.template_id)
        return dumps(rows)

    @mcp.tool(name="marketplace", annotations={"title": "The shift marketplace", **RO})
    async def marketplace(params: MarketplaceIn) -> str:
        """Which shifts are up: offered, open, given to me, swaps waiting for me, coverage asked of me — and, for each open one,
        whether THE CALLER may take it and why not (own shift, not their main restaurant, position they do not work, time off,
        overlap, a hard rule, inside the cutoff, the restaurant's switch). An offer shows only to the staff of its main
        restaurant. Use status='all' for history."""
        me = db.request_member_id.get()
        w, a = ["1 = 1"], []
        if params.site_id:
            a.append(params.site_id); w.append(f"x.site_id = ${len(a)}")
        if params.kind:
            if params.kind not in ("offer", "open", "give", "swap", "coverage"):
                return err("kind is offer, open, give, swap or coverage.")
            a.append(params.kind); w.append(f"x.kind = ${len(a)}")
        if params.status == "all":
            pass
        elif params.status:
            a.append(params.status); w.append(f"x.status = ${len(a)}")
        elif params.q:
            w.append("x.status IN ('open', 'pending_acceptance', 'pending_approval')")     # resolving an exchange by words: what can still be acted on
        else:
            w.append("x.status = 'open'")
        if params.q:
            a.append("\\m" + re.escape(params.q[:100])); w.append(f"(COALESCE(x.from_name, '') ~* ${len(a)} OR p.name ~* ${len(a)})")
        if params.mine:
            a.append(me)
            n = len(a)
            w.append(f"""(x.from_member_id = ${n} OR x.to_member_id = ${n}
                          OR EXISTS (SELECT 1 FROM mcp_exchange_claims c WHERE c.exchange_id = x.exchange_id AND c.member_id = ${n})
                          OR EXISTS (SELECT 1 FROM mcp_exchange_invitees i WHERE i.exchange_id = x.exchange_id AND i.member_id = ${n}))""")
        a.append(params.limit)
        rows = await fetch(f"""SELECT x.exchange_id, x.site_id, st.name AS site_name, x.kind, x.status, x.shift_id, x.from_member_id, x.from_name, x.to_member_id, x.to_name,
                   x.swap_shift_id, x.needs_approval, x.warnings, x.note, x.expires_at, x.claims, x.created_at,
                   x.position_id, p.name AS position_name, {local('x.shift_starts_at')} AS starts_local, {local('x.shift_ends_at')} AS ends_local, COALESCE(st.timezone, 'UTC') AS timezone,
                   x.shift_starts_at AS starts_utc, x.shift_ends_at AS ends_utc, sh.break_minutes,
                   COALESCE(x.from_name, 'Open') || ' ' || x.kind || ' ' || to_char(x.shift_starts_at AT TIME ZONE COALESCE(st.timezone, 'UTC'), 'Dy Mon DD HH24:MI') AS label,
                   EXISTS (SELECT 1 FROM mcp_exchange_claims c WHERE c.exchange_id = x.exchange_id AND c.member_id = {me if me else 'NULL'}) AS i_claimed,
                   EXISTS (SELECT 1 FROM mcp_exchange_invitees i WHERE i.exchange_id = x.exchange_id AND i.member_id = {me if me else 'NULL'}) AS i_am_invited
              FROM mcp_exchanges x
              JOIN mcp_sites st ON st.site_id = x.site_id
              LEFT JOIN mcp_positions p ON p.position_id = x.position_id
              LEFT JOIN mcp_shifts sh ON sh.shift_id = x.shift_id
             WHERE {' AND '.join(w)} ORDER BY x.shift_starts_at LIMIT ${len(a)}""", *a)
        prof = await fetch("SELECT main_site_id FROM mcp_members WHERE member_id = $1", me)
        main = prof[0]["main_site_id"] if prof else None
        held = {r["position_id"] for r in await fetch("SELECT position_id FROM mcp_staff_positions WHERE member_id = $1", me)}
        now = (await fetch("SELECT now() AS t"))[0]["t"]
        for r in rows:
            r["can_take"] = None
            r["why_not"] = []
            if r["status"] != "open" and not (r["kind"] in ("give", "swap") and r["status"] == "pending_acceptance"):
                continue
            why = r["why_not"]
            if r["kind"] in ("offer", "open"):
                site = await site_row(fetch, r["site_id"])
                if r["from_member_id"] == me:
                    why.append("It is your own shift.")
                if main != r["site_id"]:
                    why.append("Shifts are picked up at your main restaurant only.")
                if site and not site["allow_pickup"]:
                    why.append("This restaurant does not allow pick-ups.")
                if r["position_id"] not in held:
                    why.append("You do not work that position.")
                cutoff = site["cutoff_minutes"] if site else 0
                if r["starts_utc"] - datetime.timedelta(minutes=int(cutoff or 0)) < now:
                    why.append("It is inside the cutoff (or already started).")
                if not why:
                    tor = await fetch("SELECT 1 FROM mcp_time_off_requests WHERE member_id = $1 AND status = 'approved' AND starts_at < $3 AND ends_at > $2", me, r["starts_utc"], r["ends_utc"])
                    if tor:
                        why.append("You have approved time off then.")
                    over = await fetch("SELECT 1 FROM mcp_shifts WHERE assignee_member_id = $1 AND status = 'scheduled' AND published_at IS NOT NULL AND starts_at < $3 AND ends_at > $2", me, r["starts_utc"], r["ends_utc"])
                    if over:
                        why.append("It overlaps a shift you already have.")
                if not why:
                    hard = [x for x in await fetch("SELECT rule_key, severity, message FROM ts_check_assignment($1, $2, $3, $4, $5, $6)", me, r["site_id"], r["position_id"], r["starts_utc"], r["ends_utc"], int(r["break_minutes"] or 0))
                            if x["severity"] == "hard"]
                    why.extend(x["message"] for x in hard)
                r["can_take"] = not why
            elif r["kind"] in ("give", "swap"):
                r["can_take"] = r["to_member_id"] == me
                if not r["can_take"]:
                    why.append("It was given or offered in a swap to someone else.")
            elif r["kind"] == "coverage":
                r["can_take"] = bool(r["i_am_invited"])
                if not r["can_take"]:
                    why.append("Coverage was asked of other people.")
            r.pop("break_minutes", None)
        return dumps(rows)

    @mcp.tool(name="coverage_candidates", annotations={"title": "Who could cover a shift", **RO})
    async def coverage_candidates(params: CoverageIn) -> str:
        """Who could cover this shift: staff whose MAIN restaurant is the shift's, free, no hard rule broken, the soft warnings
        beside each, fewest hours that week first. Never someone whose main restaurant is elsewhere. For a shift lead or a
        manager (coverage.fill / schedule.build) at that restaurant; anyone else gets no candidates."""
        rows = await fetch("SELECT shift_id, site_id FROM mcp_shifts WHERE shift_id = $1", params.shift_id)
        if not rows:
            return err("No such shift among the ones you can see.")
        cands = await fetch("SELECT member_id, display_name, hours_this_week, warnings FROM ts_coverage_candidates($1) LIMIT $2", params.shift_id, params.limit)
        note = None
        if not cands and not (await i_can(fetch, "coverage.fill", rows[0]["site_id"]) or await i_can(fetch, "schedule.build", rows[0]["site_id"])):
            note = "You need coverage.fill or schedule.build at this shift's restaurant to ask who could cover it."
        return dumps({"shift_id": params.shift_id, "candidates": cands, "note": note})

    @mcp.tool(name="check_assignment", annotations={"title": "What the rules say about an assignment", **RO})
    async def check_assignment(params: CheckAssignmentIn) -> str:
        """What the rules say about giving this person this shift — an existing shift (shift_id), or one being planned
        (site_id, position_id, starts_at, ends_at, break_minutes). Hard warnings would refuse it; soft ones a manager may
        override with a reason. For the person themself, or a manager / shift lead of that restaurant."""
        if params.shift_id:
            rows = await fetch("SELECT site_id, position_id, starts_at, ends_at, break_minutes, shift_id FROM mcp_shifts WHERE shift_id = $1", params.shift_id)
            if not rows:
                return err("No such shift among the ones you can see.")
            s = rows[0]
            site_id, pos, starts, ends, brk, ignore = s["site_id"], s["position_id"], s["starts_at"], s["ends_at"], int(s["break_minutes"]), s["shift_id"]
        else:
            if not (params.site_id and params.position_id and params.starts_at and params.ends_at):
                return err("Give a shift_id, or site_id + position_id + starts_at + ends_at.")
            st = await site_row(fetch, params.site_id)
            if st is None:
                return err(NOT_A_SITE)
            starts, ends = _dt(params.starts_at, st["timezone"]), _dt(params.ends_at, st["timezone"])
            if starts is None or ends is None or ends <= starts:
                return err("starts_at and ends_at are ISO times (the restaurant's local time unless an offset is given), and the end is after the start.")
            site_id, pos, brk, ignore = params.site_id, params.position_id, params.break_minutes, None
        me = db.request_member_id.get()
        if not (params.member_id == me or await i_can(fetch, "schedule.build", site_id) or await i_can(fetch, "coverage.fill", site_id)):
            return err("You may check your own assignments, or another's with schedule.build or coverage.fill at that restaurant.")
        w = await fetch("SELECT rule_key, severity, message FROM ts_check_assignment($1, $2, $3, $4, $5, $6, $7)", params.member_id, site_id, pos, starts, ends, brk, ignore)
        return dumps({"member_id": params.member_id, "site_id": site_id, "allowed": not any(x["severity"] == "hard" for x in w),
                      "hard": [x for x in w if x["severity"] == "hard"], "soft": [x for x in w if x["severity"] != "hard"]})

    @mcp.tool(name="week_warnings", annotations={"title": "A week's rule warnings", **RO})
    async def week_warnings(params: WeekWarningsIn) -> str:
        """Every warning a week has — each assigned shift against the rules, hard first — and, beside each, the override
        already recorded (who, why, when). By week_id, or site_id + week_start. Managers only (schedule.build)."""
        wid = params.week_id
        if wid is None:
            if not params.site_id:
                return err("Give a week_id, or site_id and week_start.")
            wk = await week_row(fetch, params.site_id, _iso_date(params.week_start))
            wid = wk["week_id"] if wk else None
        if wid is None:
            return err("No such week among the ones you can see.")
        rows = await fetch("SELECT shift_id, member_id, display_name, rule_key, severity, message FROM ts_week_warnings($1)", wid)
        if params.severity:
            rows = [r for r in rows if r["severity"] == params.severity]
        rows.sort(key=lambda r: (0 if r["severity"] == "hard" else 1))
        ov = await fetch("SELECT shift_id, member_id, rule_key, reason, overridden_by, created_at FROM mcp_rule_overrides WHERE shift_id IN (SELECT shift_id FROM mcp_shifts WHERE week_id = $1)", wid)
        for r in rows:
            r["override"] = next(({"reason": o["reason"], "overridden_by": o["overridden_by"], "at": o["created_at"]} for o in ov
                                   if o["shift_id"] == r["shift_id"] and o["member_id"] == r["member_id"] and o["rule_key"] == r["rule_key"]), None)
        note = None if rows else "No warnings — or you lack schedule.build at this week's restaurant."
        return dumps({"week_id": wid, "warnings": rows, "count": len(rows), "note": note})

    @mcp.tool(name="overrides", annotations={"title": "Overridden warnings", **RO})
    async def overrides(params: OverridesIn) -> str:
        """Which rule warnings were overridden, by whom and why — over a period, for a person or a rule (the compliance
        report). Managers only (schedule.build)."""
        w, a = ["1 = 1"], []
        for col, val in (("o.site_id", params.site_id), ("o.member_id", params.member_id), ("o.rule_key", params.rule_key)):
            if val:
                a.append(val); w.append(f"{col} = ${len(a)}")
        f, t = _iso_date(params.from_), _iso_date(params.to)
        if f:
            a.append(f); w.append(f"o.created_at >= ${len(a)}")
        if t:
            a.append(t + datetime.timedelta(days=1)); w.append(f"o.created_at < ${len(a)}")
        a.append(params.limit)
        rows = await fetch(f"""SELECT o.override_id, o.site_id, o.shift_id, o.member_id, m.display_name AS member_name, o.rule_key, o.message, o.reason,
                   o.overridden_by, b.display_name AS overridden_by_name, o.created_at
              FROM mcp_rule_overrides o LEFT JOIN mcp_members m ON m.member_id = o.member_id LEFT JOIN mcp_members b ON b.member_id = o.overridden_by
             WHERE {' AND '.join(w)} ORDER BY o.created_at DESC LIMIT ${len(a)}""", *a)
        return dumps(rows)

    @mcp.tool(name="hours_this_week", annotations={"title": "Hours against the limits", **RO})
    async def hours_this_week(params: HoursIn) -> str:
        """A person's paid hours in a week against their own limit and the restaurant's overtime line; or, with
        near_overtime, everyone at or over 90% of it (a manager). Yourself by default; others need schedule.build. Hours,
        never pay."""
        me = db.request_member_id.get()
        w, a = [], []
        on = _iso_date(params.week_start)
        if on:
            a.append(on); w.append(f"h.week_start <= ${len(a)} AND h.week_start > ${len(a)}::date - 7")
        else:
            w.append("h.week_start <= current_date AND h.week_start > current_date - 7")
        if params.site_id:
            a.append(params.site_id); w.append(f"h.site_id = ${len(a)}")
        if params.near_overtime:
            w.append("(h.near_overtime OR h.over_overtime)")
        else:
            a.append(params.member_id or me); w.append(f"h.member_id = ${len(a)}")
        a.append(params.limit)
        rows = await fetch(f"""SELECT h.site_id, h.week_start, h.member_id, h.display_name, h.shifts, h.scheduled_hours, h.max_hours_week, h.overtime_weekly_hours,
                   h.over_overtime, h.near_overtime, h.over_own_limit
              FROM mcp_hours_weekly h WHERE {' AND '.join(w)} ORDER BY h.scheduled_hours DESC, h.display_name LIMIT ${len(a)}""", *a)
        return dumps(rows)
