r"""
Automated system test for the KIU Complaints & Requests Desk (optional).

It logs in as the demo users, submits cases, works them as officers, forces deadlines to pass and checks
escalation, the admin pages and the security rules. Run it on a FRESH install (right after install.php).
It creates test data, so run  php install.php --reset  afterwards (delete config/installed.lock first).

You need Python 3 and:   pip install requests

1. Start the site and note its address, for example  http://localhost/kiu-crms
2. Tell the test where the mysql program is (it reads the database to check results):
     Windows:  set MYSQL_CLI=C:\xampp\mysql\bin\mysql.exe
     Linux:    export MYSQL_CLI=mysql
3. Run:
     Windows:  set CRMS_URL=http://localhost/kiu-crms
               python tests\e2e_test.py
     Linux:    CRMS_URL=http://localhost/kiu-crms python3 tests/e2e_test.py

Each line says PASS or FAIL. The last line lists any failures.
"""
import re, subprocess, time, sys, base64, json, os
import requests

BASE = os.environ.get("CRMS_URL", "http://127.0.0.1:8080").rstrip("/")
PW = "Demo@1234"
fails = []
def check(name, cond, extra=""):
    print(("PASS " if cond else "FAIL ") + name + ((" :: " + str(extra)) if (extra and not cond) else ""))
    if not cond: fails.append(name)

def csrf(html):
    m = re.search(r'name="_csrf" value="([0-9a-f]+)"', html) or re.search(r'<meta name="csrf" content="([0-9a-f]+)"', html)
    return m.group(1) if m else ""

def php_errors(html):
    return re.findall(r'(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)[:\s]', html)

def sess(): 
    s = requests.Session(); s.allow_redirects = False; return s
def get(s, path, **kw): return s.get(BASE + path, allow_redirects=False, **kw)
def post(s, path, data=None, token_from=None, **kw):
    data = dict(data or {})
    if "_csrf" not in data:
        page = s.get(BASE + (token_from or "/"), allow_redirects=True).text
        data["_csrf"] = csrf(page)
    return s.post(BASE + path, data=data, allow_redirects=False, **kw)
def login(email, pw=PW):
    s = sess()
    r = post(s, "/login.php", {"email": email, "password": pw}, token_from="/login.php")
    return s, r

def sql(q):
    out = subprocess.run([os.environ.get("MYSQL_CLI", "mariadb"), "-uroot", "-N", "-B", "kiu_crms", "-e", q], capture_output=True, text=True)
    return out.stdout.strip()

# ---------- public pages ----------
s = sess()
r = get(s, "/"); check("home 200", r.status_code == 200 and "owner and a deadline" in r.text, r.status_code)
check("home has no php errors", not php_errors(r.text), php_errors(r.text))
check("home shows 4 ladder rungs", r.text.count('class="rung"') == 4)
for p in ["/login.php", "/register.php", "/submit.php", "/track.php"]:
    r = get(s, p); check(f"{p} 200 no errors", r.status_code == 200 and not php_errors(r.text), (r.status_code, php_errors(r.text)))
r = get(s, "/my-cases.php"); check("my-cases redirects to login when logged out", r.status_code == 302 and "login.php" in r.headers.get("Location",""))
r = get(s, "/admin/index.php"); check("admin redirects when logged out", r.status_code == 302)
r = get(s, "/officer.php"); check("officer redirects when logged out", r.status_code == 302)
r = get(s, "/case.php?id=1"); check("case page needs login", r.status_code == 302)
r = get(s, "/files.php?id=1"); check("file download blocked when logged out", r.status_code == 404)

# ---------- CSRF + login ----------
s = sess()
r = s.post(BASE + "/login.php", data={"email": "student@kiu.test", "password": PW}, allow_redirects=False)
check("login without csrf token is rejected (419)", r.status_code == 419, r.status_code)
s, r = login("student@kiu.test", "wrong")
check("wrong password shows error", r.status_code == 200 and "not correct" in r.text)
st, r = login("student@kiu.test")
check("student login redirects to my-cases", r.status_code == 302 and "my-cases.php" in r.headers["Location"], r.headers.get("Location"))
r = get(st, "/my-cases.php"); check("my-cases lists demo cases", r.status_code == 200 and "CRM-" in r.text and not php_errors(r.text), php_errors(r.text))
check("student nav shows alerts count", "Alerts" in r.text)

# ---------- student submits a case with attachment ----------
png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==")
page = st.get(BASE + "/submit.php").text
tok = csrf(page)
r = st.post(BASE + "/submit.php", data={"_csrf": tok, "type": "complaint", "title": "Bank slip not confirmed", "description": "I paid my tuition at the bank last week but the finance office has not confirmed my payment slip yet.", "category_id": "0"},
            files={"attachment": ("slip.png", png, "image/png")}, allow_redirects=False)
check("submit redirects to submitted.php", r.status_code == 302 and "submitted.php" in r.headers.get("Location", ""), (r.status_code, r.text[:300]))
r = st.get(BASE + "/submitted.php"); 
ref = re.search(r'slip-ref">(CRM-\d{4}-\d{5})', r.text)
check("receipt shows reference", bool(ref), r.text[:300])
ref = ref.group(1) if ref else ""
cid = sql(f"SELECT id FROM cases WHERE reference='{ref}'")
print("   new case id", cid, "category:", sql(f"SELECT cat.name FROM cases c JOIN categories cat ON cat.id=c.category_id WHERE c.id={cid}"), "| handler:", sql(f"SELECT u.email FROM cases c JOIN users u ON u.id=c.assigned_to WHERE c.id={cid}"))
check("AI routed to Fees and payments", sql(f"SELECT cat.name FROM cases c JOIN categories cat ON cat.id=c.category_id WHERE c.id={cid}") == "Fees and payments")
check("assigned to fees.l1", sql(f"SELECT u.email FROM cases c JOIN users u ON u.id=c.assigned_to WHERE c.id={cid}") == "fees.l1@kiu.test")
check("attachment stored", sql(f"SELECT COUNT(*) FROM attachments WHERE case_id={cid}") == "1")
r = st.get(BASE + f"/case.php?id={cid}")
check("student can open own case", r.status_code == 200 and "Escalation ladder" in r.text and not php_errors(r.text), php_errors(r.text))
aid = sql(f"SELECT id FROM attachments WHERE case_id={cid}")
r = st.get(BASE + f"/files.php?id={aid}"); check("student can download own attachment", r.status_code == 200 and r.content[:4] == b"\x89PNG", r.status_code)

# bad uploads
tok = csrf(st.get(BASE + "/submit.php").text)
r = st.post(BASE + "/submit.php", data={"_csrf": tok, "type": "complaint", "title": "Test bad file", "description": "This file is not really a picture at all, it is a php script.", "category_id": "0"},
            files={"attachment": ("evil.php", b"<?php echo 1;", "image/png")}, allow_redirects=False)
check("php upload rejected", r.status_code == 200 and "allowed" in r.text, r.status_code)
r = st.post(BASE + "/submit.php", data={"_csrf": tok, "type": "complaint", "title": "Test disguised", "description": "This file claims to be a png but its content is just plain text data.", "category_id": "0"},
            files={"attachment": ("fake.png", b"just text", "image/png")}, allow_redirects=False)
check("disguised file rejected", r.status_code == 200 and "does not match" in r.text, r.status_code)
r = st.post(BASE + "/submit.php", data={"_csrf": tok, "type": "complaint", "title": "x", "description": "short", "category_id": "0"}, allow_redirects=False)
check("short title/description rejected", r.status_code == 200 and "short title" in r.text)

# ---------- other student cannot see it ----------
s2, _ = login("student2@kiu.test")
r = get(s2, f"/case.php?id={cid}"); check("other student gets 404", r.status_code == 404, r.status_code)
r = get(s2, f"/files.php?id={aid}"); check("other student cannot download file", r.status_code == 404)
r = post(s2, "/case-action.php", {"id": cid, "action": "comment", "message": "hack"}, token_from="/my-cases.php"); check("other student cannot act on the case", r.status_code == 403, r.status_code)

# ---------- officer workflow ----------
off, r = login("fees.l1@kiu.test")
check("officer login redirects to queue", r.status_code == 302 and "officer.php" in r.headers["Location"])
r = get(off, "/officer.php"); check("officer queue shows the case", ref in r.text and not php_errors(r.text), php_errors(r.text))
r = get(off, f"/case.php?id={cid}"); check("officer sees acknowledge button", "Acknowledge and start working" in r.text)
r = post(off, "/case-action.php", {"id": cid, "action": "acknowledge"}, token_from=f"/case.php?id={cid}")
check("acknowledge works", r.status_code == 302 and sql(f"SELECT status FROM cases WHERE id={cid}") == "In Progress" and sql(f"SELECT stage FROM cases WHERE id={cid}") == "resolution")
r = post(off, "/case-action.php", {"id": cid, "action": "note", "message": "Checking with the bank."}, token_from=f"/case.php?id={cid}")
r = post(off, "/case-action.php", {"id": cid, "action": "respond", "message": "We are matching your slip now."}, token_from=f"/case.php?id={cid}")
r = st.get(BASE + f"/case.php?id={cid}")
check("student sees reply but not internal note", "matching your slip" in r.text and "Checking with the bank" not in r.text)
r = get(off, f"/case.php?id={cid}"); check("officer sees internal note", "Checking with the bank" in r.text)
r = post(off, "/case-action.php", {"id": cid, "action": "resolve", "message": "Payment confirmed and cleared."}, token_from=f"/case.php?id={cid}")
check("resolve works", sql(f"SELECT status FROM cases WHERE id={cid}") == "Resolved")
r = st.get(BASE + f"/case.php?id={cid}"); check("student sees confirm/reopen options", "Is the problem solved?" in r.text)
r = post(st, "/case-action.php", {"id": cid, "action": "reopen", "message": "Still blocked on the portal."}, token_from=f"/case.php?id={cid}")
check("reopen works", sql(f"SELECT status FROM cases WHERE id={cid}") == "Reopened" and sql(f"SELECT reopen_count FROM cases WHERE id={cid}") == "1")
post(off, "/case-action.php", {"id": cid, "action": "resolve", "message": "Portal block removed."}, token_from=f"/case.php?id={cid}")
r = post(st, "/case-action.php", {"id": cid, "action": "confirm", "rating": "4", "comment": "ok"}, token_from=f"/case.php?id={cid}")
check("confirm closes the case with rating", sql(f"SELECT status FROM cases WHERE id={cid}") == "Closed" and sql(f"SELECT rating FROM cases WHERE id={cid}") == "4")
r = post(off, "/case-action.php", {"id": cid, "action": "respond", "message": "late"}, token_from=f"/case.php?id={cid}")
check("cannot act on a closed case", sql(f"SELECT COUNT(*) FROM case_events WHERE case_id={cid} AND message='late'") == "0")

# officer of another office cannot see/act
off2, _ = login("hostel.l1@kiu.test")
r = get(off2, f"/case.php?id={cid}"); check("unrelated officer gets 404", r.status_code == 404, r.status_code)

# ---------- anonymous + tracking ----------
an = sess()
tok = csrf(an.get(BASE + "/submit.php").text)
r = an.post(BASE + "/submit.php", data={"_csrf": tok, "type": "complaint", "title": "Guard threatened me", "description": "A security guard at the main gate threatened me last night when I came back late and I feel unsafe.", "category_id": "0", "anonymous": "1"}, allow_redirects=False)
check("anonymous submit works without login", r.status_code == 302, (r.status_code, r.text[:200]))
page = an.get(BASE + "/submitted.php").text
aref = re.search(r'slip-ref">(CRM-\d{4}-\d{5})', page).group(1)
acode = re.search(r'letter-spacing:\.12em">([A-Z0-9]{8})', page).group(1)
acid = sql(f"SELECT id FROM cases WHERE reference='{aref}'")
check("anonymous case has no student id", sql(f"SELECT student_id FROM cases WHERE id={acid}") == "NULL")
check("urgent words flagged as urgent", sql(f"SELECT base_priority FROM cases WHERE id={acid}") == "Urgent", sql(f"SELECT base_priority FROM cases WHERE id={acid}"))
check("urgent case got the short 4-hour deadline", sql(f"SELECT TIMESTAMPDIFF(MINUTE, created_at, due_at) FROM cases WHERE id={acid}") == "240", sql(f"SELECT TIMESTAMPDIFF(MINUTE, created_at, due_at) FROM cases WHERE id={acid}"))
r = get(an, f"/case.php?id={acid}"); check("anonymous owner session can open case", r.status_code == 200)
fresh = sess()
r = post(fresh, "/track.php", {"reference": aref, "code": "WRONG123"}, token_from="/track.php"); check("wrong tracking code rejected", r.status_code == 302 and "track.php" in r.headers["Location"])
r = post(fresh, "/track.php", {"reference": aref.lower(), "code": acode.lower()}, token_from="/track.php"); check("right tracking code opens case (case-insensitive)", r.status_code == 302 and f"case.php?id={acid}" in r.headers["Location"], r.headers.get("Location"))
r = get(fresh, f"/case.php?id={acid}"); check("tracking visitor sees the case", r.status_code == 200 and aref in r.text)
check("tracking page hides who submitted", "Anonymous" in r.text)
r = post(fresh, "/case-action.php", {"id": acid, "action": "comment", "message": "Adding details: it was 9pm."}, token_from=f"/case.php?id={acid}")
check("anonymous visitor can add a message", sql(f"SELECT COUNT(*) FROM case_events WHERE case_id={acid} AND event_type='STUDENT_COMMENT'") == "1")
# honeypot
tok = csrf(an.get(BASE + "/submit.php").text)
before = sql("SELECT COUNT(*) FROM cases")
r = an.post(BASE + "/submit.php", data={"_csrf": tok, "type": "complaint", "title": "Spam spam", "description": "This is spam spam spam spam spam spam", "category_id": "0", "anonymous": "1", "website": "http://spam"}, allow_redirects=False)
check("honeypot blocks bots", sql("SELECT COUNT(*) FROM cases") == before)

# ---------- escalation through the web ----------
sql(f"UPDATE cases SET due_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id={acid}")
sql("UPDATE app_state SET state_value='2000-01-01 00:00:00' WHERE state_key='last_escalation_run'")
get(sess(), "/")   # any page view triggers the lazy escalation check
check("page view triggered escalation", sql(f"SELECT current_level FROM cases WHERE id={acid}") == "2", sql(f"SELECT current_level FROM cases WHERE id={acid}"))
check("priority raised to Urgent (kept by base urgency)", sql(f"SELECT priority FROM cases WHERE id={acid}") in ("Urgent",))
check("ESCALATED event logged", sql(f"SELECT COUNT(*) FROM case_events WHERE case_id={acid} AND event_type='ESCALATED'") == "1")
check("new handler is the level-2 safety officer", sql(f"SELECT u.email FROM cases c JOIN users u ON u.id=c.assigned_to WHERE c.id={acid}") == "safety.l2@kiu.test", sql(f"SELECT u.email FROM cases c JOIN users u ON u.id=c.assigned_to WHERE c.id={acid}"))
check("new handler got an alert", int(sql(f"SELECT COUNT(*) FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.email='safety.l2@kiu.test' AND n.case_id={acid}")) >= 1)
check("emails were logged", int(sql("SELECT COUNT(*) FROM email_outbox WHERE status='logged'")) > 5)

# ---------- admin pages ----------
ad, r = login("admin@kiu.test")
check("admin login redirects to dashboard", r.status_code == 302 and "admin/index.php" in r.headers["Location"])
for p in ["/admin/index.php", "/admin/cases.php", "/admin/cases.php?status=open&escalated=1&q=CRM", "/admin/cases.php?page=2", "/admin/escalations.php", "/admin/escalations.php?type=CRITICAL_ALERT", "/admin/users.php", "/admin/users.php?new=1", "/admin/users.php?edit=2", "/admin/users.php?role=officer&q=fees", "/admin/settings.php", "/admin/classifier.php", "/admin/outbox.php", f"/case.php?id={acid}", "/notifications.php"]:
    r = get(ad, p); check(f"admin page {p}", r.status_code == 200 and not php_errors(r.text), (r.status_code, php_errors(r.text)))
r = get(ad, "/admin/export.php"); check("csv export", r.status_code == 200 and "Reference,Type,Title" in r.text and "text/csv" in r.headers.get("Content-Type",""), r.status_code)
r = get(ad, f"/case.php?id={acid}"); check("admin sees reassign form", "Reassign to a named officer" in r.text)
for role_email in ["student@kiu.test", "fees.l1@kiu.test"]:
    s_, _ = login(role_email)
    r = get(s_, "/admin/index.php"); check(f"{role_email} blocked from admin", r.status_code == 403, r.status_code)
# admin run check (POST)
r = post(ad, "/admin/index.php", {}, token_from="/admin/index.php"); check("run check button", r.status_code == 302)
# classifier
page = ad.get(BASE + "/admin/classifier.php").text
r = ad.post(BASE + "/admin/classifier.php", data={"_csrf": csrf(page), "do": "test", "text": "My hostel room has no water and the toilet is blocked"}, allow_redirects=False)
check("classifier test page shows hostel", r.status_code == 200 and "Hostel and accommodation" in r.text and not php_errors(r.text))
r = ad.post(BASE + "/admin/classifier.php", data={"_csrf": csrf(page), "do": "evaluate"}, allow_redirects=False)
check("classifier evaluate shows accuracy", r.status_code == 200 and "examples correct" in r.text and not php_errors(r.text))
r = ad.post(BASE + "/admin/classifier.php", data={"_csrf": csrf(page), "do": "add", "category_id": "1", "sample": "My exam script was lost by the department"}, allow_redirects=False)
check("add training example", r.status_code == 302 and sql("SELECT COUNT(*) FROM training_samples WHERE source='admin'") == "1")
# settings
page = ad.get(BASE + "/admin/settings.php").text
data = {"_csrf": csrf(page), "do": "levels"}
for n, t in {1: "Responsible office officer", 2: "Head of department or section", 3: "Dean of school or Dean of Students", 4: "Registrar or DVC office"}.items():
    data[f"title[{n}]"] = t; data[f"response_hours[{n}]"] = "24"; data[f"urgent_response_hours[{n}]"] = "4"; data[f"resolution_hours[{n}]"] = "72"
r = ad.post(BASE + "/admin/settings.php", data=data, allow_redirects=False); check("save levels", r.status_code == 302, r.status_code)
data["response_hours[1]"] = "-3"
r = ad.post(BASE + "/admin/settings.php", data=data, allow_redirects=False); check("bad level values rejected", r.status_code == 200 and "time limits between" in r.text)
r = ad.post(BASE + "/admin/settings.php", data={"_csrf": csrf(page), "do": "category_add", "name": "Transport", "department": "Transport Office"}, allow_redirects=False); check("add category", r.status_code == 302 and sql("SELECT COUNT(*) FROM categories WHERE name='Transport'") == "1")
# users
page = ad.get(BASE + "/admin/users.php?new=1").text
r = ad.post(BASE + "/admin/users.php", data={"_csrf": csrf(page), "do": "save", "id": "0", "full_name": "Test Officer", "email": "new.officer@kiu.test", "role": "officer", "officer_level": "2", "category_id": "0", "password": "Passw0rd!x"}, allow_redirects=False)
check("create officer", r.status_code == 302 and sql("SELECT officer_level FROM users WHERE email='new.officer@kiu.test'") == "2")
r = ad.post(BASE + "/admin/users.php", data={"_csrf": csrf(page), "do": "save", "id": "0", "full_name": "Dup", "email": "new.officer@kiu.test", "role": "student", "password": "Passw0rd!x"}, allow_redirects=False)
check("duplicate email rejected", r.status_code == 200 and "already uses" in r.text)
nid = sql("SELECT id FROM users WHERE email='new.officer@kiu.test'")
r = ad.post(BASE + "/admin/users.php", data={"_csrf": csrf(page), "do": "toggle", "id": nid}, allow_redirects=False)
check("deactivate user", sql(f"SELECT is_active FROM users WHERE id={nid}") == "0")
nl, r = login("new.officer@kiu.test", "Passw0rd!x"); check("deactivated user cannot log in", r.status_code == 200)
# admin reassign + category change
r = post(ad, "/case-action.php", {"id": acid, "action": "reassign", "officer_id": sql("SELECT id FROM users WHERE email='dean.students@kiu.test'")}, token_from=f"/case.php?id={acid}")
check("admin reassign", sql(f"SELECT current_level FROM cases WHERE id={acid}") == "3")

# API suggest
tok = csrf(st.get(BASE + "/submit.php").text)
r = st.post(BASE + "/api/suggest.php", json={"text": "The lecturer never comes to class and exams are near"}, headers={"X-CSRF-Token": tok})
j = r.json(); check("suggest API", j.get("ok") and j.get("category") == "Lecturers and teaching", j)
r = st.post(BASE + "/api/suggest.php", json={"text": "x"}, headers={"X-CSRF-Token": "bad"}); check("suggest API rejects bad token", r.status_code == 419)


# ---------- registration ----------
rg = sess()
newstu = {"full_name": "Test Student", "email": "reg.test@kiu.test", "reg_no": "2026/DIT/777", "password": "Passw0rd!x", "password2": "Passw0rd!x"}
r = post(rg, "/register.php", newstu, token_from="/register.php")
check("registration creates a student and logs in", r.status_code == 302 and "submit.php" in r.headers.get("Location", "") and sql("SELECT role FROM users WHERE email='reg.test@kiu.test'") == "student", r.status_code)
r = post(sess(), "/register.php", newstu, token_from="/register.php"); check("duplicate email registration rejected", r.status_code == 200 and "already exists" in r.text)
r = post(sess(), "/register.php", dict(newstu, email="x1@kiu.test", password="short", password2="short"), token_from="/register.php"); check("short password rejected", r.status_code == 200 and "at least 8" in r.text)
r = post(sess(), "/register.php", dict(newstu, email="x2@kiu.test", password2="Different1!"), token_from="/register.php"); check("password mismatch rejected", r.status_code == 200 and "do not match" in r.text)

# ---------- alerts page ----------
r = get(st, "/notifications.php"); check("alerts page lists alerts", r.status_code == 200 and "Mark all as read" in r.text)
r = post(st, "/notifications.php", {}, token_from="/notifications.php")
check("mark all alerts as read", sql("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email='student@kiu.test' AND n.is_read = 0") == "0")

# ---------- CSV safety ----------
tok = csrf(st.get(BASE + "/submit.php").text)
st.post(BASE + "/submit.php", data={"_csrf": tok, "type": "complaint", "title": "=1+1 formula test", "description": "A title that starts with an equals sign must not run as a spreadsheet formula.", "category_id": "0"}, allow_redirects=False)
r = get(ad, "/admin/export.php"); check("csv export neutralises formulas", "'=1+1 formula test" in r.text)

# ---------- another anonymous visitor cannot open someone else's anonymous case ----------
an2 = sess()
r = get(an2, f"/case.php?id={acid}"); check("other visitor cannot open anonymous case", r.status_code in (302, 404), r.status_code)

# ---------- tracking guesses are throttled ----------
thr = sess()
for i in range(9):
    last = post(thr, "/track.php", {"reference": "CRM-2026-00001", "code": "AAAAAA%02d" % i}, token_from="/track.php")
r = thr.get(BASE + "/track.php"); check("tracking guesses are throttled", "Too many wrong tries" in r.text or "Too many wrong tries" in thr.get(BASE + "/track.php").text)

# ---------- auto-close of resolved cases nobody confirmed ----------
sql("UPDATE cases SET resolved_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE id = 4 AND status = 'Resolved'")
post(ad, "/admin/index.php", {}, token_from="/admin/index.php")
check("unconfirmed resolved case closes by itself", sql("SELECT status FROM cases WHERE id = 4") == "Closed" and sql("SELECT COUNT(*) FROM case_events WHERE case_id = 4 AND event_type = 'CLOSED'") == "1")

# ---------- admin: edit user, edit category, remove AI example ----------
page = ad.get(BASE + "/admin/users.php?edit=3").text
r = ad.post(BASE + "/admin/users.php", data={"_csrf": csrf(page), "do": "save", "id": "3", "full_name": "Brian Renamed", "email": "student2@kiu.test", "role": "student", "password": ""}, allow_redirects=False)
check("admin can edit a user", r.status_code == 302 and sql("SELECT full_name FROM users WHERE id = 3") == "Brian Renamed")
page = ad.get(BASE + "/admin/settings.php").text
r = ad.post(BASE + "/admin/settings.php", data={"_csrf": csrf(page), "do": "category_save", "id": "1", "name": "Results and marks", "department": "Faculty Registry", "is_active": "1"}, allow_redirects=False)
check("admin can edit a category", r.status_code == 302 and sql("SELECT department FROM categories WHERE id = 1") == "Faculty Registry")
sid = sql("SELECT id FROM training_samples WHERE source='admin' LIMIT 1")
page = ad.get(BASE + "/admin/classifier.php").text
r = ad.post(BASE + "/admin/classifier.php", data={"_csrf": csrf(page), "do": "delete", "id": sid}, allow_redirects=False)
check("admin can remove an AI example", r.status_code == 302 and sql("SELECT COUNT(*) FROM training_samples WHERE source='admin'") == "0")
r = post(ad, "/case-action.php", {"id": acid, "action": "reassign", "officer_id": sql("SELECT id FROM users WHERE email='student@kiu.test'")}, token_from=f"/case.php?id={acid}")
check("reassigning to a non-officer is refused", sql(f"SELECT current_level FROM cases WHERE id={acid}") == "3")
# officer moves a case to another category and the AI learns from it
cid2 = sql("SELECT id FROM cases WHERE status='Submitted' AND assigned_to=(SELECT id FROM users WHERE email='hostel.l1@kiu.test') ORDER BY id LIMIT 1")
before = int(sql("SELECT COUNT(*) FROM training_samples"))
r = post(off2, "/case-action.php", {"id": cid2, "action": "category", "category_id": sql("SELECT id FROM categories WHERE name='ICT and student portal'")}, token_from=f"/case.php?id={cid2}")
check("officer can move a case to another category", sql(f"SELECT cat.name FROM cases c JOIN categories cat ON cat.id=c.category_id WHERE c.id={cid2}") == "ICT and student portal")
check("the correction is added to the AI training data", int(sql("SELECT COUNT(*) FROM training_samples")) == before + 1)
check("moved case goes to an ICT officer", sql(f"SELECT u.email FROM cases c JOIN users u ON u.id=c.assigned_to WHERE c.id={cid2}").startswith("ict."))

# login throttle
for i in range(5): login("throttle@kiu.test", "bad%d" % i)
_, r = login("throttle@kiu.test", "bad")
check("login throttle after 5 failures", "Too many failed attempts" in r.text)

# logout
r = post(st, "/logout.php", {}, token_from="/my-cases.php"); check("logout", r.status_code == 302)
r = get(st, "/my-cases.php"); check("logged out afterwards", r.status_code == 302)

print("\nFAILED:", fails if fails else "none")
sys.exit(1 if fails else 0)
