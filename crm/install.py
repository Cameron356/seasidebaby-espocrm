#!/usr/bin/env python3
"""Install the Seaside data model (model.py) into an EspoCRM container. Run on fTower.

Dry run by default (shows what would change); --apply to do it:
  1. backs up the container's custom/ to ~/seaside-custom-backup-<time>.tgz
  2. writes every file model.py generates (overwrites those files; touches nothing else)
  3. rebuilds EspoCRM
  4. sets the left menu (tab list) and quick-create list

Edit fields in model.py, never in EspoCRM's Entity Manager or Layout Manager: the next
install overwrites those files.

Environment (defaults are the staging copy; the live office will get its own values):
  SBN_CONTAINER   EspoCRM container                 (espocrm-dev)
  SBN_ESPO_URL    API base                          (http://127.0.0.1:8220/api/v1/)
  SBN_ENV         .env holding ADMIN_PASSWORD       (~/seaside-staging/.env)
  SBN_ROBOT_KEY   where to write the robot's API key (~/seaside-staging/robot.key)

The office robot (crm/robot.py) signs in as API user "office-robot", role "Office robot": read and
edit Requests; create, read and edit Families, Parents, Children, Bookings, Postpartum plans; read Users (to assign
bookings to Amanda). No delete anywhere. Its key is written once, mode 600.
"""
import argparse, base64, io, json, os, subprocess, sys, tarfile, time, urllib.parse, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import model  # noqa: E402

C = os.environ.get("SBN_CONTAINER", "espocrm-dev")
ESPO = os.environ.get("SBN_ESPO_URL", "http://127.0.0.1:8220/api/v1/")
ENV = os.path.expanduser(os.environ.get("SBN_ENV", "~/seaside-staging/.env"))
ROBOT_KEY = os.path.expanduser(os.environ.get("SBN_ROBOT_KEY", "~/seaside-staging/robot.key"))
ALL = {"create": "yes", "read": "all", "edit": "all", "delete": "no"}
# Amanda's role: everything she uses day to day, no admin settings (so nothing breaks by accident).
FULL = {"create": "yes", "read": "all", "edit": "all", "delete": "all"}
OWNER_ROLE = {"name": "Owner (Amanda)", "assignmentPermission": "all", "userPermission": "all", "portalPermission": "no",
              "exportPermission": "yes", "massUpdatePermission": "yes",
              "data": {s: FULL for s in ("CarePlan", "Lead", "Account", "Contact", "Child", "RequestChild", "CareBooking", "ShiftLog",
                                         "Meeting", "Task", "Email", "Attachment")} | {"Calendar": True, "User": {"read": "all"}},
              "fieldData": {}}
ROBOT_ROLE = {"name": "Office robot", "assignmentPermission": "all", "userPermission": "all",
              "data": {"Lead": dict(ALL, create="no"), "RequestChild": dict(ALL, create="no"), "Account": ALL, "Contact": ALL,
                       "Child": ALL, "CareBooking": ALL, "CarePlan": ALL, "ShiftLog": dict(ALL, create="no"), "User": {"read": "all"}, "Meeting": {"create": "no", "read": "all", "edit": "no", "delete": "no"}},
              "fieldData": {}}
APP = "/var/www/html/"


def sh(*cmd, stdin=None):
    r = subprocess.run(cmd, input=stdin, capture_output=True)
    if r.returncode:
        sys.exit("command failed: %s\n%s" % (" ".join(cmd), r.stderr.decode(errors="replace")))
    return r.stdout


def auth():
    env = dict(l.strip().split("=", 1) for l in open(ENV) if "=" in l and not l.startswith("#"))
    pw = env["ADMIN_PASSWORD"].strip().strip('"').strip("'")
    return {"Espo-Authorization": base64.b64encode(("admin:" + pw).encode()).decode(), "Content-Type": "application/json"}


def api(method, path, body=None):
    r = urllib.request.Request(ESPO + path, method=method, headers=auth(),
                               data=json.dumps(body).encode() if body is not None else None)
    with urllib.request.urlopen(r, timeout=60) as x:
        b = x.read()
    return json.loads(b) if b.strip() else None


def current(path):
    r = subprocess.run(["docker", "exec", C, "cat", APP + path], capture_output=True)
    return r.stdout.decode() if r.returncode == 0 else None


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--apply", action="store_true")
    a = ap.parse_args()

    files = model.build()
    new = [p for p in files if current(p) is None]
    changed = [p for p in files if p not in new and current(p) != files[p]]
    print("install: %d files from model.py into %s (%d new, %d changed, %d unchanged)"
          % (len(files), C, len(new), len(changed), len(files) - len(new) - len(changed)))
    for p in new: print("  + " + p)
    for p in changed: print("  ~ " + p)
    if not a.apply:
        print("dry run; --apply to install"); return

    owner = sh("docker", "exec", C, "stat", "-c", "%U", APP + "custom").decode().strip()
    bk = os.path.expanduser("~/seaside-custom-backup-%s.tgz" % time.strftime("%Y%m%d-%H%M%S"))
    with open(bk, "wb") as fh:
        fh.write(sh("docker", "exec", C, "tar", "czf", "-", "-C", APP, "custom"))
    os.chmod(bk, 0o600)
    print("backup:", bk)

    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w") as t:
        for p, c in files.items():
            data = c.encode()
            ti = tarfile.TarInfo(p); ti.size = len(data); ti.mode = 0o644; ti.mtime = int(time.time())
            t.addfile(ti, io.BytesIO(data))
    sh("docker", "exec", "-i", "-u", owner, C, "tar", "xf", "-", "-C", APP, stdin=buf.getvalue())
    print(sh("docker", "exec", "-u", owner, C, "bin/command", "rebuild").decode().strip() or "rebuild ok")

    api("PUT", "Settings", {"theme": "Seaside", "dateFormat": "MM/DD/YYYY", "timeFormat": "h:mm a", "weekStart": 0,
                            "tabList": model.TAB_LIST, "calendarEntityList": model.CALENDAR_LIST,
                            "quickCreateList": ["CarePlan", "Lead", "CareBooking", "ShiftLog", "Child", "Meeting", "Task"]})
    print("menu:", ", ".join(t if isinstance(t, str) else t["text"] for t in model.TAB_LIST))

    q = "?where[0][type]=equals&where[0][attribute]=name&where[0][value]=" + urllib.parse.quote(OWNER_ROLE["name"])
    roles = api("GET", "Role" + q)["list"]
    (api("PUT", "Role/" + roles[0]["id"], OWNER_ROLE) if roles else api("POST", "Role", OWNER_ROLE))
    print("role:", OWNER_ROLE["name"], "(give it to Amanda's user)")

    # The robot's role and API user (created once; the role is kept up to date).
    q = "?where[0][type]=equals&where[0][attribute]=name&where[0][value]=Office%20robot"
    roles = api("GET", "Role" + q)["list"]
    role = api("PUT", "Role/" + roles[0]["id"], ROBOT_ROLE) if roles else api("POST", "Role", ROBOT_ROLE)
    users = api("GET", "User?where[0][type]=equals&where[0][attribute]=userName&where[0][value]=office-robot")["list"]
    if users:
        print("robot: API user office-robot exists (role %s)" % role["name"])
    else:
        u = api("POST", "User", {"userName": "office-robot", "type": "api", "authMethod": "ApiKey", "rolesIds": [role["id"]]})
        key = api("GET", "User/" + u["id"]).get("apiKey") or u.get("apiKey")
        old = os.umask(0o077)
        with open(ROBOT_KEY, "w") as fh: fh.write(key + "\n")
        os.umask(old)
        print("robot: API user office-robot created, key ->", ROBOT_KEY)
    # Smoke check: every new record type answers through the API.
    for scope in list(model.NEW) + ["Lead", "Account"]:
        n = api("GET", scope + "?maxSize=1")["total"]
        print("  ok %-12s (%d records)" % (scope, n))


if __name__ == "__main__":
    main()
