# Testing

Use this table in **Chapter 4 (unit, integration and system testing)**. The "Result" column shows what happened when these tests were run on PHP 8.3 with MariaDB 10.11, in October 2026, using `tests/e2e_test.py` (113 automated checks, all passed) plus the manual timing run described under TC-28. Run them again on your own computer and take screenshots. Your own results are the ones that count in your report.

## How to run the automated test

See the instructions at the top of `tests/e2e_test.py`. Run it on a fresh install, then reinstall.

## System test cases

| ID | Area | Test | Expected result | Result |
|---|---|---|---|---|
| TC-01 | Access | Open the home page without logging in | Home page loads and shows the four escalation levels | Passed |
| TC-02 | Access | Open My cases, the officer queue, the admin dashboard and a case while logged out | Redirected to the login page | Passed |
| TC-03 | Access | A student and an officer open the admin dashboard | Access refused (403) | Passed |
| TC-04 | Registration | Register with valid details | Student account created and logged in | Passed |
| TC-05 | Registration | Register with a used email, a short password, or two different passwords | Rejected with a clear message | Passed |
| TC-06 | Login | Log in with a wrong password | Error shown, no login | Passed |
| TC-07 | Login | Enter a wrong password five times for one email | Login blocked for 15 minutes | Passed |
| TC-08 | Security | Send a form without its secret token (CSRF) | Request rejected (419) | Passed |
| TC-09 | Submit | A student submits a complaint with a PNG attachment | Case created, reference number given, file stored | Passed |
| TC-10 | Validation | Submit with a very short title or description | Rejected with a message | Passed |
| TC-11 | Upload safety | Upload a `.php` file, and a text file renamed to `.png` | Both rejected | Passed |
| TC-12 | AI routing | Complaint about a bank slip not confirmed by finance | Routed to Fees and payments, handler `fees.l1` | Passed |
| TC-13 | AI urgency | Complaint saying a guard threatened the student at night | Marked Urgent, response limit of 4 hours | Passed |
| TC-14 | AI hint | Send complaint text to the suggestion endpoint (used by the form), and send it with a bad token. The form script was also run in a simulated browser | Right office and urgency returned, bad token refused, hint text shown while typing (not tested in a real browser, so check it yourself) | Passed |
| TC-15 | Privacy | Another student opens a case, or downloads its file | Case not found, file refused | Passed |
| TC-16 | Privacy | An officer from an unrelated office opens the case | Case not found | Passed |
| TC-17 | Officer | Acknowledge a case | Status In Progress, the resolution clock starts | Passed |
| TC-18 | Officer | Reply to the student and add an internal note | Student sees the reply but not the note | Passed |
| TC-19 | Officer | Mark the case resolved | Status Resolved, student asked to confirm | Passed |
| TC-20 | Student | Reopen a resolved case | Status Reopened, new response clock | Passed |
| TC-21 | Student | Confirm a resolved case with a rating of 4 | Status Closed, rating stored | Passed |
| TC-22 | Rules | Reply to a closed case | Refused | Passed |
| TC-23 | Anonymous | Submit without logging in, using the anonymous box | Case saved with no student, tracking code shown | Passed |
| TC-24 | Anonymous | Track with the correct reference and code (in any letter case) | Case opens, submitter shown as Anonymous | Passed |
| TC-25 | Anonymous | Track with a wrong code, then guess nine times | Rejected, then throttled | Passed |
| TC-26 | Anonymous | Another visitor tries to open the anonymous case | Refused | Passed |
| TC-27 | Spam | Fill the hidden trap field on the form | Submission ignored | Passed |
| TC-28 | Escalation | Let the Level 1 deadline pass, then open any page | Case moves to Level 2, priority rises, event logged, new handler alerted. In a timed run with the clock in seconds, a case climbed Level 1 to 2 to 3 to 4 and then raised a critical alert | Passed |
| TC-29 | Escalation | `install.php` creates demo cases that miss deadlines | Cases reach Levels 2, 3 and 4 and one gets a critical alert, all through the real engine | Passed |
| TC-30 | Escalation | A resolved case that the student never confirms | Closed automatically with an event in the history | Passed |
| TC-31 | Re-routing | An officer moves a case to another category | New handler in that category, and the case is added to the AI's examples | Passed |
| TC-32 | Admin | Reassign a case to a named officer, or to a student | Moves to that officer's level. A student is refused | Passed |
| TC-33 | Admin | Create, edit and deactivate a user | Saved. A deactivated user cannot log in | Passed |
| TC-34 | Admin | Edit the levels, including a negative time limit | Valid values saved, invalid ones rejected | Passed |
| TC-35 | Admin | Add and edit a category | Saved | Passed |
| TC-36 | AI | Classify text in Admin > AI classifier | Chances shown for each category | Passed |
| TC-37 | AI | Press Measure accuracy | Leave-one-out accuracy shown (about 80% on the starting data) | Passed |
| TC-38 | Reports | Open the dashboard and export the CSV | Figures shown. CSV opens in Excel and formulas are neutralised | Passed |
| TC-39 | Alerts | Open the Alerts page, press Mark all as read | Count drops to zero. Emails are listed in Admin > Emails | Passed |

## Accuracy of the AI

| Test | Examples | Correct | Accuracy |
|---|---|---|---|
| Leave-one-out on the starting examples | 245 | 196 | 79.7% |
| 45 new complaints written separately | 45 | 41 | 91.1% |

The second test is small and the sentences were written by the developer, so treat it as a rough guide. For your report, collect 50 to 100 real or realistic complaints from students, label them by hand, and measure again.

## Evaluation of the system (your Objective 5)

The dashboard gives the figures for your two measures: **average time to first response and to resolution**, and **average satisfaction**. The demo cases are fake. To evaluate properly:

1. In Chapter 3, measure the manual process (how long suggestion-box and walk-in cases take, from a short survey or interviews).
2. Run a pilot with real students and officers, then export the cases from **Admin > All cases > Export**.
3. Compare the two in Chapter 4, and report the limits of a small pilot.
