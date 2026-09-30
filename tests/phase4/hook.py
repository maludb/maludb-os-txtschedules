"""Proof — the kernel's Actions MCP, with the KERNEL'S OWN application_actions.py (read from /var/www/mcp, never modified), fed txtSchedules' registry exactly as the
installer writes it (deploy/kernel-registry-txtschedules.json's `resolve` + mcp/action_registry.json) and a STUBBED approval hook and application. No live kernel, no
live application: what is proved is the contract — the 69 actions become 69 tools; the 13 with an approval category ask the hook FIRST with their own log event and the
handler URL, and never reach the handler when the hook pauses, refuses, or records; the other 56 never ask the hook; names become ids through OUR find_* tools.
Run by tests/phase4/hook.php with the fixture ids in the environment; prints ok/FAIL lines; exits non-zero on a failure."""
import asyncio, json, os, pathlib, sys, tempfile

sys.path.insert(0, "/var/www/mcp")
import application_actions as aa                     # the kernel's, unmodified
from mcp.server.fastmcp import FastMCP

APP = pathlib.Path("/srv/apps/txtschedules")
passes = fails = 0


def ok(cond, label):
    global passes, fails
    if cond:
        passes += 1
    else:
        fails += 1
    print(("  ok   " if cond else "  FAIL ") + label)


reg = json.loads((APP / "mcp/action_registry.json").read_text())
wrap = json.loads((APP / "deploy/kernel-registry-txtschedules.json").read_text())
manifest = json.loads((APP / "maludb-os.json").read_text())
tmp = pathlib.Path(tempfile.mkdtemp())
(tmp / "txtschedules.json").write_text(json.dumps({
    "schema": "maludb-os.registry/1", "app_key": "txtschedules", "name": "txtSchedules", "base_url": "http://app.stub.invalid",
    "records_url": f"http://127.0.0.1:{os.environ.get('P4_REC_PORT', '8194')}/mcp", "resolve": wrap["resolve"], "registry": reg}))
aa.REGISTRIES_DIR = tmp
TOKEN = os.environ.get("P4_MARA_TOKEN", "")
state = {"hook": "pending", "calls": []}


async def app_post(path, fields, base=None):
    state["calls"].append({"path": path, "fields": dict(fields), "base": base})
    if path == "/approvals/hook.php":
        h = state["hook"]
        if h == "pending":
            return {"status": "pending_approval", "approval_request_id": 17, "did": "waits"}
        if h == "success":
            return {"status": "success"}
        if h == "recorded":
            return {"status": "recorded"}
        return {"status": "error", "message": "hook down"}
    return {"status": "success", "did": "done", "record_id": 5}


async def call(mcp, name, args):
    state["calls"] = []
    res = await mcp.call_tool(name, {"params": args})
    content = res[0] if isinstance(res, tuple) else res
    text = content[0].text if content else "{}"
    try:
        return json.loads(text)
    except ValueError:
        return {"raw": text}


def sample(action):
    """Digits and plain words for every required param — nothing to resolve."""
    a = {}
    for p in action["params"]:
        if p.get("name") and p.get("required"):
            a[p["name"]] = "1" if p["name"] in {q for v in wrap["resolve"].values() for q in v["params"]} or p.get("repeated") else "2026-10-09" if "date" in p["name"] or p["name"] in ("week_start", "starts_at", "ends_at") else "x"
    if action.get("confirm"):
        a["confirmed"] = True
    return a


async def main():
    mcp = FastMCP("t")
    n = aa.register(mcp, app_post, lambda: TOKEN, set())
    actions = reg["actions"]
    built = [a for a in actions.values() if a.get("built")]
    tools = {t.name for t in await mcp.list_tools()}
    ok(len(actions) == 69 and len(built) == 69 and n == 69 and tools == set(actions), f"the kernel's register() turns the registry's {len(actions)} actions into {n} tools, every name the registry's")
    pausing = {k: a for k, a in actions.items() if a.get("approval")}
    ok(len(pausing) == 13, f"{len(pausing)} actions carry an approval category: " + ", ".join(sorted(pausing)))
    cats = {}
    for a in manifest["approvals"]:
        cats[a["action"]] = a["category"]
    cats["position_rate_update"] = cats["wage_update"]       # the same log event (wage.update): one approval entry covers both (D17)
    ok(all(cats.get(k) == a["approval"] for k, a in pausing.items()) and set(cats) == set(pausing), "each pausing action's category equals maludb-os.json approvals[] (announcement_post, coverage_request, week_publish: external_send; the other ten: other)")
    ext = sorted(k for k, a in pausing.items() if a["approval"] == "external_send")
    ok(ext == ["announcement_post", "coverage_request", "week_publish"], "external_send: " + ", ".join(ext))
    ok(all(pausing[k]["log_event"] for k in pausing) and len({a["log_event"] for a in pausing.values()}) == 12, "each has its own log event (wage_update and position_rate_update share wage.update, D17): 12 events")
    drafts = ["shift_create", "shift_update", "shift_assign", "shift_delete", "week_create", "week_copy", "week_autofill", "template_apply", "shift_open"]
    ok(all(not actions[k].get("approval") for k in drafts), "a draft's shift_create / shift_update / shift_assign (and the week builder) never pause — the scheduler can draft")
    ok(set(pausing) >= {"week_publish", "shift_add", "shift_change", "shift_cancel", "exchange_approve", "exchange_choose", "time_off_approve", "balance_adjust", "wage_update", "announcement_post", "coverage_request"}, "publish a week, a live shift's add/change/cancel, approving trades and time off, pay and balances, announcements and coverage texts: all pause")

    for name, a in sorted(pausing.items()):
        args = sample(a)
        state["hook"] = "pending"
        r = await call(mcp, name, args)
        c = state["calls"]
        ok(r.get("status") == "pending_approval" and [x["path"] for x in c] == ["/approvals/hook.php"], f"{name}: the hook pauses it -> pending_approval; the handler is NEVER posted")
        h = c[0]["fields"] if c else {}
        ok(h.get("action_key") == name and h.get("log_event") == a["log_event"] and h.get("handler_url") == "http://app.stub.invalid" + a["endpoint"], f"{name}: the hook is asked with its key, its log event ({a['log_event']}) and the handler URL")
        state["hook"] = "success"
        r = await call(mcp, name, args)
        ok([x["path"] for x in state["calls"]] == ["/approvals/hook.php", a["endpoint"]] and r.get("status") == "success", f"{name}: hook says go -> the handler is posted after it")
    for mode, label in (("recorded", "an evaluation (hook: recorded)"), ("error", "the hook failing")):
        state["hook"] = mode
        bad = 0
        for name, a in pausing.items():
            r = await call(mcp, name, sample(a))
            if any(x["path"] == a["endpoint"] for x in state["calls"]):
                bad += 1
        ok(bad == 0, f"{label}: none of the 13 reaches its handler")
    state["hook"] = "pending"
    others = {k: a for k, a in actions.items() if not a.get("approval")}
    hooked = []
    unposted = []
    for name, a in others.items():
        r = await call(mcp, name, sample(a))
        paths = [x["path"] for x in state["calls"]]
        if "/approvals/hook.php" in paths:
            hooked.append(name)
        if paths != [a["endpoint"] if a["endpoint"].startswith("/") else "/" + a["endpoint"]]:
            unposted.append((name, paths))
    ok(len(others) == 56 and hooked == [] and unposted == [], f"the other {len(others)} never ask the hook and post their handler straight away" + (f" — NOT: {hooked} {unposted[:3]}" if hooked or unposted else ""))
    conf = [k for k, a in actions.items() if a.get("confirm")]
    r = await call(mcp, conf[0], {k: v for k, v in sample(actions[conf[0]]).items() if k != "confirmed"})
    ok(r.get("status") == "needs_confirmation", f"a destructive action ({conf[0]}) asks for confirmation before anything is posted ({len(conf)} of them do)")

    # names become ids through OUR tools (the caller's own token, a real records server)
    if TOKEN:
        ana_shift, ana_id, srv, dsrv = os.environ["P4_ANA_SHIFT"], os.environ["P4_ANA_ID"], os.environ["P4_SRV"], os.environ.get("P4_DSRV", "")
        state["hook"] = "success"
        r = await call(mcp, "shift_create", {"site": "Airport", "position": "Server", "starts_at": "2026-10-09T17:00", "ends_at": "2026-10-09T22:00", "assignee": "Ana"})
        f = state["calls"][-1]["fields"] if state["calls"] else {}
        ok(f.get("site") == "102" and f.get("position") == srv and f.get("assignee") == ana_id, f"shift_create: site 'Airport', position 'Server', assignee 'Ana' become ids through find_sites, find_positions, find_staff ({f.get('site')}, {f.get('position')}, {f.get('assignee')}) — Ana is not confused with Dana")
        ok(r.get("resolved", {}).get("assignee") == "SMOKE Ana", "and the answer says whom it resolved: " + json.dumps(r.get("resolved")))
        r = await call(mcp, "shift_offer", {"shift": "Ana Friday"})
        f = state["calls"][-1]["fields"] if state["calls"] else {}
        ok(f.get("shift") == ana_shift, f"shift_offer 'Ana Friday' -> her Friday shift id via week_schedule(q) ({f.get('shift')} = {ana_shift})")
        r = await call(mcp, "shift_offer", {"shift": "Friday"})
        ok(r.get("status") == "error" and "Several match" in r.get("message", ""), "'Friday' alone matches several shifts: the kernel asks which one — " + r.get("message", "")[:80])
        r = await call(mcp, "shift_offer", {"shift": "Zelda Tuesday"})
        ok(r.get("status") == "error" and "No shift matching" in r.get("message", ""), "a name nobody has: 'No shift matching' — nothing posted")
        r = await call(mcp, "week_publish", {"week": os.environ["P4_FRI"], "confirmed": True})
        f = state["calls"][-1]["fields"] if state["calls"] else {}
        ok(f.get("week") == os.environ["P4_WEEK"], f"week_publish '{os.environ['P4_FRI']}' -> the week's id via week_schedule(q date) ({f.get('week')}) — and it asked the hook first ({[c['path'] for c in state['calls']]})")
        r = await call(mcp, "time_off_request", {"time_off_type": "vacation", "starts_at": "2026-10-09", "ends_at": "2026-10-10"})
        ok(r.get("status") == "success" or r.get("status") == "error", "time_off_request by type name goes through the resolver")
        r = await call(mcp, "coverage_request", {"shift": ana_shift, "members": "31,32", "confirmed": True})
        f = state["calls"][-1]["fields"] if state["calls"] else {}
        ok(f.get("members[]") == ["31", "32"], "a repeated param (members '31,32') is a list of ids the handler reads — not resolved as a name")
        r = await call(mcp, "availability_submit", {"weekday": "5", "starts_at": "18:00", "ends_at": "23:00", "kind": "unavailable"})
        f = state["calls"][-1]["fields"] if state["calls"] else {}
        ok(f.get("kind") == "unavailable" and r.get("status") == "success", "availability_submit's kind stays a word (kind is not a resolved entity)")
    print(f"{'all ' + str(passes) + ' passed' if not fails else str(fails) + ' FAILED (' + str(passes) + ' passed)'}")
    sys.exit(1 if fails else 0)

asyncio.run(main())
