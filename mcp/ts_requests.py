"""txtSchedules — availability, time off and requests (tool surface, "Availability, time off, requests").
Reads mcp_availability, mcp_time_off_requests, mcp_time_off_types, mcp_time_off_balances, mcp_time_off_ledger, mcp_blackout_dates,
mcp_exchanges, mcp_exchange_claims, mcp_members, mcp_sites. A request carries the type, dates, hours and status — the person's own
note only to them and to those who decide it (the view). Never a pay rate.
"""
from __future__ import annotations

import datetime
import re

from pydantic import Field

import db
from business_common import RO, _Base, _iso_date, dumps, err, local


class AvailabilityIn(_Base):
    member_id: int | None = Field(None, description="The person (a manager may ask); omit for yourself unless you pass `at`")
    site_id: int | None = None
    weekday: int | None = Field(None, ge=0, le=6, description="0 = Sunday ... 6 = Saturday")
    status: str | None = Field(None, description="approved or pending")
    at: str | None = Field(None, description="A local date-time YYYY-MM-DDTHH:MM: who is UNAVAILABLE then (a manager)")
    q: str | None = Field(None, description="A person's name, or a kind (available, unavailable, preferred) or a weekday — looks entries up across the people you may see")


class TimeOffIn(_Base):
    member_id: int | None = Field(None, description="One person; omit for everyone you may see (yourself, or your restaurant's if you approve)")
    site_id: int | None = None
    status: str | None = Field(None, description="pending, approved, declined or cancelled")
    on: str | None = Field(None, description="YYYY-MM-DD: who is off that day (pending and approved unless you name a status)")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    q: str | None = Field(None, description="A person's name or a type ('Priya vacation') — how an action resolves a request")
    limit: int = Field(25, ge=1, le=100)


class BalancesIn(_Base):
    member_id: int | None = Field(None, description="The person; omit for yourself. Another's need requests.approve at their restaurant")
    site_id: int | None = None
    ledger: bool = Field(False, description="Also the ledger that made each balance (grants, approvals, cancellations, adjustments)")
    limit: int = Field(50, ge=1, le=200)


class MyRequestsIn(_Base):
    state: str | None = Field("all", description="waiting, decided or all")
    limit: int = Field(25, ge=1, le=100)


class PendingIn(_Base):
    site_id: int | None = None
    kind: str | None = Field(None, description="time_off, availability or exchange")
    limit: int = Field(25, ge=1, le=100)


async def _resolve(fetch, q: str, limit: int) -> str:
    """Resolve mode (`q` alone — how the kernel's entity resolver calls): a plain LIST of rows carrying an id — requests
    (request_id) matching a person or a type, and blackout dates (blackout_id) matching a date. Only what the caller may see."""
    words = q.split()
    rows: list[dict] = []
    if not (words and all(_iso_date(x) and len(x) == 10 for x in words)):
        w, a = ["q.status IN ('pending', 'approved')"], []
        for word in words:
            a.append("\\m" + re.escape(word[:40])); w.append(f"(q.member_name ~* ${len(a)} OR q.type_name ~* ${len(a)})")
        a.append(limit)
        rows += await fetch(f"""SELECT q.request_id, q.member_id, q.member_name, q.site_id, q.type_name, q.status, q.hours,
                   q.member_name || ' ' || lower(q.type_name) || ' ' || to_char(q.starts_at AT TIME ZONE COALESCE(st.timezone, 'UTC'), 'Mon DD') AS label
              FROM mcp_time_off_requests q LEFT JOIN mcp_sites st ON st.site_id = q.site_id
             WHERE {' AND '.join(w)} ORDER BY q.starts_at DESC LIMIT ${len(a)}""", *a)
    d = _iso_date(words[0]) if words and len(words[0]) == 10 else None
    if d:
        rows += await fetch("SELECT blackout_id, site_id, on_date, reason, to_char(on_date, 'YYYY-MM-DD') || ' — ' || reason AS label FROM mcp_blackout_dates WHERE on_date = $1 LIMIT $2", d, limit)
    return dumps(rows)


def register(mcp, fetch) -> None:
    @mcp.tool(name="availability", annotations={"title": "Availability", **RO})
    async def availability(params: AvailabilityIn) -> str:
        """A person's recurring availability (available, unavailable, preferred by weekday and time) and the changes waiting for
        approval; or, with `at`, who is unavailable at a moment (a manager). Yourself by default; others need schedule.build."""
        me = db.request_member_id.get()
        w, a = ["1 = 1"], []
        if params.at:
            try:
                t = datetime.datetime.fromisoformat(params.at)
            except ValueError:
                return err("at is YYYY-MM-DDTHH:MM (the restaurant's local time).")
            a.append((t.weekday() + 1) % 7); w.append(f"v.weekday = ${len(a)}")
            a.append(t.time()); n = len(a)
            w.append(f"v.kind = 'unavailable' AND ((v.starts_at <= v.ends_at AND ${n}::time >= v.starts_at AND ${n}::time < v.ends_at) OR (v.starts_at > v.ends_at AND (${n}::time >= v.starts_at OR ${n}::time < v.ends_at)))")
            a.append(t.date()); w.append(f"v.effective_from <= ${len(a)} AND (v.effective_to IS NULL OR v.effective_to >= ${len(a)})")
            if params.member_id:
                a.append(params.member_id); w.append(f"v.member_id = ${len(a)}")
        elif params.q and not params.member_id:
            pass                                            # look-up by words: the views decide whose entries this caller may see
        else:
            a.append(params.member_id or me); w.append(f"v.member_id = ${len(a)}")
        for word in (params.q or "").split():
            a.append(f"%{word[:40]}%"); n = len(a)
            a.append("\\m" + re.escape(word[:40])); k = len(a)
            w.append(f"(m.display_name ~* ${k} OR v.kind ILIKE ${n} OR (ARRAY['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'])[v.weekday + 1] ILIKE ${n})")
        if params.site_id:
            a.append(params.site_id); w.append(f"(v.site_id = ${len(a)} OR v.site_id IS NULL)")
        if params.weekday is not None and not params.at:
            a.append(params.weekday); w.append(f"v.weekday = ${len(a)}")
        if params.status:
            a.append(params.status); w.append(f"v.status = ${len(a)}")
        rows = await fetch(f"""SELECT v.availability_id, v.member_id, m.display_name AS member_name, v.site_id, v.weekday, v.starts_at, v.ends_at, v.kind, v.effective_from, v.effective_to, v.status,
                   COALESCE(m.display_name, 'Someone') || ' ' || v.kind || ' ' || (ARRAY['Sun','Mon','Tue','Wed','Thu','Fri','Sat'])[v.weekday + 1] || ' ' || to_char(v.starts_at, 'HH24:MI') AS label
              FROM mcp_availability v LEFT JOIN mcp_members m ON m.member_id = v.member_id
             WHERE {' AND '.join(w)} ORDER BY v.member_id, v.weekday, v.starts_at LIMIT 200""", *a)
        return dumps(rows)

    @mcp.tool(name="time_off", annotations={"title": "Time off", **RO})
    async def time_off(params: TimeOffIn) -> str:
        """Time off requested, approved or declined, by person, restaurant, status or period; who is off on a date (`on`);
        the restaurant's blackout dates. CALL THIS BEFORE an action that needs a request_id. You see your own requests; a
        manager (schedule.build or requests.approve) sees the restaurant's."""
        me = db.request_member_id.get()
        if params.q and not (params.member_id or params.site_id or params.status or params.on or params.from_ or params.to):
            return await _resolve(fetch, params.q, params.limit)
        w, a = ["1 = 1"], []
        if params.member_id:
            a.append(params.member_id); w.append(f"q.member_id = ${len(a)}")
        if params.site_id:
            a.append(params.site_id); w.append(f"q.site_id = ${len(a)}")
        if params.status:
            if params.status not in ("pending", "approved", "declined", "cancelled"):
                return err("status is pending, approved, declined or cancelled.")
            a.append(params.status); w.append(f"q.status = ${len(a)}")
        if params.on:
            d = _iso_date(params.on)
            if d is None:
                return err("on is YYYY-MM-DD.")
            a.append(d); n = len(a)
            w.append(f"(q.starts_at AT TIME ZONE COALESCE(st.timezone, 'UTC'))::date <= ${n} AND ((q.ends_at AT TIME ZONE COALESCE(st.timezone, 'UTC')) - interval '1 microsecond')::date >= ${n}")
            if not params.status:
                w.append("q.status IN ('pending', 'approved')")
        f, t = _iso_date(params.from_), _iso_date(params.to)
        if f:
            a.append(f); w.append(f"((q.ends_at AT TIME ZONE COALESCE(st.timezone, 'UTC')) - interval '1 microsecond')::date >= ${len(a)}")
        if t:
            a.append(t); w.append(f"(q.starts_at AT TIME ZONE COALESCE(st.timezone, 'UTC'))::date <= ${len(a)}")
        for word in (params.q or "").split():
            a.append("\\m" + re.escape(word[:40])); w.append(f"(q.member_name ~* ${len(a)} OR q.type_name ~* ${len(a)})")
        a.append(params.limit)
        rows = await fetch(f"""SELECT q.request_id, q.member_id, q.member_name, q.site_id, q.type_id, q.type_name,
                   {local('q.starts_at')} AS starts_local, {local('q.ends_at')} AS ends_local, COALESCE(st.timezone, 'UTC') AS timezone, q.starts_at AS starts_utc, q.ends_at AS ends_utc,
                   q.hours, q.note, q.status, q.decided_by, q.decided_at, q.decision_note, q.created_at,
                   q.member_name || ' ' || lower(q.type_name) || ' ' || to_char(q.starts_at AT TIME ZONE COALESCE(st.timezone, 'UTC'), 'Mon DD') AS label
              FROM mcp_time_off_requests q LEFT JOIN mcp_sites st ON st.site_id = q.site_id
             WHERE {' AND '.join(w)} ORDER BY q.starts_at DESC LIMIT ${len(a)}""", *a)
        # A note is the person's: only they and those who decide it read it (the view gives it to schedule.build too — trim to the rule).
        deciders = {r["site_id"] for r in await fetch("SELECT s AS site_id FROM ts_scopes_with_right('requests.approve') s")}
        for r in rows:
            if r["member_id"] != me and r["site_id"] not in deciders:
                r["note"] = None
                r["decision_note"] = None
        bo_w, bo_a = ["1 = 1"], []
        if params.site_id:
            bo_a.append(params.site_id); bo_w.append(f"site_id = ${len(bo_a)}")
        bo_a.append(_iso_date(params.on) or _iso_date(params.from_) or datetime.date.today())
        blackouts = await fetch(f"SELECT blackout_id, site_id, on_date, reason, to_char(on_date, 'YYYY-MM-DD') AS label FROM mcp_blackout_dates WHERE {' AND '.join(bo_w)} AND on_date >= ${len(bo_a)} ORDER BY on_date LIMIT 20", *bo_a)
        return dumps({"requests": rows, "blackout_dates": blackouts})

    @mcp.tool(name="time_off_balances", annotations={"title": "Time-off balances", **RO})
    async def time_off_balances(params: BalancesIn) -> str:
        """A person's balance per time-off type in HOURS, and with ledger=true the ledger that made it. Yourself by default;
        another person's need requests.approve at their restaurant."""
        me = db.request_member_id.get()
        mid = params.member_id or me
        w, a = ["b.member_id = $1"], [mid]
        if params.site_id:
            a.append(params.site_id); w.append(f"b.site_id = ${len(a)}")
        rows = await fetch(f"SELECT b.member_id, b.type_id, b.site_id, b.type_name, b.balance_hours, b.updated_at FROM mcp_time_off_balances b WHERE {' AND '.join(w)} ORDER BY b.site_id, b.type_name", *a)
        out = {"member_id": mid, "balances": rows}
        if params.ledger:
            lw, la = ["l.member_id = $1"], [mid]
            if params.site_id:
                la.append(params.site_id); lw.append(f"l.site_id = ${len(la)}")
            la.append(params.limit)
            out["ledger"] = await fetch(f"""SELECT l.ledger_id, l.type_id, l.site_id, l.type_name, l.delta_hours, l.reason, l.request_id, l.note, l.recorded_by, l.created_at
                                              FROM mcp_time_off_ledger l WHERE {' AND '.join(lw)} ORDER BY l.created_at DESC, l.ledger_id DESC LIMIT ${len(la)}""", *la)
        return dumps(out)

    @mcp.tool(name="my_requests", annotations={"title": "My requests", **RO})
    async def my_requests(params: MyRequestsIn) -> str:
        """The caller's own requests and what became of them: time off, availability changes, offers, swaps, gives and claims
        — waiting or decided. Only ever the caller's own."""
        me = db.request_member_id.get()
        items = []
        for r in await fetch("""SELECT request_id AS id, type_name, starts_at, ends_at, hours, status, decision_note, created_at, site_id FROM mcp_time_off_requests WHERE member_id = $1 ORDER BY created_at DESC LIMIT 100""", me):
            items.append({"kind": "time_off", "id": r["id"], "site_id": r["site_id"], "state": "waiting" if r["status"] == "pending" else "decided", "status": r["status"],
                          "summary": f"{r['type_name']} {r['starts_at']:%Y-%m-%d} to {r['ends_at']:%Y-%m-%d} ({r['hours']} h)", "note": r["decision_note"], "at": r["created_at"]})
        for r in await fetch("""SELECT availability_id AS id, site_id, weekday, starts_at, ends_at, kind, status, effective_from FROM mcp_availability WHERE member_id = $1 ORDER BY effective_from DESC LIMIT 50""", me):
            items.append({"kind": "availability", "id": r["id"], "site_id": r["site_id"], "state": "waiting" if r["status"] == "pending" else "decided", "status": r["status"],
                          "summary": f"{r['kind']} {['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][r['weekday']]} {r['starts_at']:%H:%M}-{r['ends_at']:%H:%M} from {r['effective_from']}", "at": r["effective_from"]})
        for r in await fetch("""SELECT exchange_id AS id, site_id, kind, status, decision_note, created_at, shift_starts_at FROM mcp_exchanges WHERE from_member_id = $1 ORDER BY created_at DESC LIMIT 50""", me):
            items.append({"kind": r["kind"], "id": r["id"], "site_id": r["site_id"], "state": "waiting" if r["status"] in ("open", "pending_acceptance", "pending_approval") else "decided",
                          "status": r["status"], "summary": f"your shift on {r['shift_starts_at']:%Y-%m-%d %H:%M} UTC ({r['kind']})", "note": r["decision_note"], "at": r["created_at"]})
        for r in await fetch("""SELECT c.claim_id AS id, c.exchange_id, c.status, c.created_at, x.site_id, x.kind FROM mcp_exchange_claims c JOIN mcp_exchanges x ON x.exchange_id = c.exchange_id WHERE c.member_id = $1 ORDER BY c.created_at DESC LIMIT 50""", me):
            items.append({"kind": "claim", "id": r["id"], "exchange_id": r["exchange_id"], "site_id": r["site_id"], "state": "waiting" if r["status"] == "pending" else "decided",
                          "status": r["status"], "summary": f"your claim on a {r['kind']}", "at": r["created_at"]})
        if params.state in ("waiting", "decided"):
            items = [i for i in items if i["state"] == params.state]
        items.sort(key=lambda i: str(i["at"]), reverse=True)
        return dumps(items[: params.limit])

    @mcp.tool(name="pending_requests", annotations={"title": "What waits for a manager", **RO})
    async def pending_requests(params: PendingIn) -> str:
        """What waits for a manager at a restaurant, oldest first: time off, availability changes, exchanges pending approval
        (each with its warnings), claims to choose among. Needs requests.approve at the restaurant; a shift lead sees the
        same-day (today and tomorrow) trades when the restaurant lets shift leads approve them."""
        kinds = [params.kind] if params.kind else ["time_off", "availability", "exchange"]
        if any(k not in ("time_off", "availability", "exchange") for k in kinds):
            return err("kind is time_off, availability or exchange.")
        items = []
        sw = ""
        sa: list = []
        if params.site_id:
            sa.append(params.site_id); sw = f" AND x.site_id = ${len(sa)}"
        if "time_off" in kinds:
            for r in await fetch(f"""SELECT x.request_id, x.member_id, x.member_name, x.site_id, x.type_name, x.hours, x.starts_at, x.ends_at, x.note, x.created_at,
                                            {local('x.starts_at')} AS starts_local, {local('x.ends_at')} AS ends_local
                                       FROM mcp_time_off_requests x LEFT JOIN mcp_sites st ON st.site_id = x.site_id
                                      WHERE x.status = 'pending' AND x.site_id IN (SELECT ts_scopes_with_right('requests.approve')){sw} ORDER BY x.created_at LIMIT 100""", *sa):
                items.append({"kind": "time_off", "id": r["request_id"], "site_id": r["site_id"], "member_id": r["member_id"], "member_name": r["member_name"],
                              "summary": f"{r['member_name']} asks for {r['type_name']} {r['starts_local'][:10]} to {r['ends_local'][:10]} ({r['hours']} h)", "note": r["note"], "created_at": r["created_at"]})
        if "availability" in kinds:
            aw = (" AND site_id = $1" if params.site_id else "")
            for r in await fetch(f"""SELECT v.availability_id, v.member_id, m.display_name, COALESCE(v.site_id, (SELECT min(r.site_id) FROM mcp_member_site_roles r WHERE r.member_id = v.member_id
                                          AND r.site_id IN (SELECT ts_scopes_with_right('requests.approve')))) AS site_id, v.weekday, v.starts_at, v.ends_at, v.kind, v.effective_from
                                       FROM mcp_availability v LEFT JOIN mcp_members m ON m.member_id = v.member_id WHERE v.status = 'pending' ORDER BY v.effective_from LIMIT 100""", *[]):
                if r["site_id"] is None:
                    continue
                if params.site_id and r["site_id"] != params.site_id:
                    continue
                appr = await fetch("SELECT ts_has_right('requests.approve', $1) AS ok", r["site_id"])
                if not appr[0]["ok"]:
                    continue
                items.append({"kind": "availability", "id": r["availability_id"], "site_id": r["site_id"], "member_id": r["member_id"], "member_name": r["display_name"],
                              "summary": f"{r['display_name']} wants {r['kind']} {['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][r['weekday']]} {r['starts_at']:%H:%M}-{r['ends_at']:%H:%M} from {r['effective_from']}",
                              "created_at": r["effective_from"]})
        if "exchange" in kinds:
            for r in await fetch(f"""SELECT x.exchange_id, x.site_id, x.kind, x.status, x.from_name, x.to_name, x.warnings, x.note, x.created_at, x.shift_id,
                                            {local('x.shift_starts_at')} AS starts_local
                                       FROM mcp_exchanges x JOIN mcp_sites st ON st.site_id = x.site_id
                                      WHERE (x.status = 'pending_approval' OR (x.status = 'open' AND x.claims > 0 AND st.claim_mode = 'manager_chooses'))
                                        AND (x.site_id IN (SELECT ts_scopes_with_right('requests.approve'))
                                             OR (x.site_id IN (SELECT ts_scopes_with_right('market.approve_day')) AND st.shift_lead_approves_same_day
                                                 AND (x.shift_starts_at AT TIME ZONE st.timezone)::date <= (now() AT TIME ZONE st.timezone)::date + 1)){sw}
                                      ORDER BY x.created_at LIMIT 100""", *sa):
                claims = await fetch("SELECT member_id, member_name, warnings FROM mcp_exchange_claims WHERE exchange_id = $1 AND status = 'pending' ORDER BY created_at", r["exchange_id"])
                items.append({"kind": "exchange", "id": r["exchange_id"], "site_id": r["site_id"], "exchange_kind": r["kind"], "status": r["status"], "shift_id": r["shift_id"], "warnings": r["warnings"],
                              "claims_to_choose_among": claims if r["status"] == "open" else None,
                              "summary": f"{r['kind']} of {r['from_name'] or 'an open shift'} on {r['starts_local']}" + (f" to {r['to_name']}" if r["to_name"] else ""),
                              "note": r["note"], "created_at": r["created_at"]})
        items.sort(key=lambda i: str(i["created_at"]))
        return dumps(items[: params.limit])
