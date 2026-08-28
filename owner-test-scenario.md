# Owner Role — Test Scenarios

## Purpose & scope

This document enumerates test scenarios for the **Owner** role — the broadest-permission role in the system (Owner, Coach, Staff, Member). It covers every feature area where Owner is an actor, plus the permission boundaries that define Owner-exclusivity (i.e. scenarios where Coach/Staff/Member are correctly denied something Owner can do — these define the edge of Owner's scope just as much as the "can do" scenarios do).

Grounded in: `gym-management-system-functional-requirements.md`, `gym-management-system-development-roadmap.md`, `gym-management-system-architecture.md` §9.1 (Voters), and the existing backend test suite (`backend/tests/Functional/*`, `backend/tests/Security/Voter/*`, `backend/tests/Command/*`) — every scenario below has a corresponding automated test already passing in that suite unless marked **(manual only)**.

**Legend:** ✅ = Owner-allowed scenario · 🚫 = permission-boundary scenario (someone other than Owner is correctly denied, or Owner is correctly denied something outside their own gym).

---

## 1. Authentication & Session

| ID | Scenario | Expected Result |
|---|---|---|
| AUTH-01 | Owner logs in with correct email + password | ✅ Receives JWT access + refresh token pair, lands on Owner dashboard |
| AUTH-02 | Owner logs in with incorrect password | 🚫 Generic "invalid credentials" error (never reveals which field was wrong) |
| AUTH-03 | Owner fails login 5 times within 15 minutes | 🚫 Rate-limited on the 6th attempt, even with the correct password |
| AUTH-04 | Owner requests an OTP login code via registered email/phone | ✅ 6-digit code sent, 5-minute expiry countdown shown |
| AUTH-05 | Owner submits correct OTP before expiry | ✅ Receives JWT pair |
| AUTH-06 | Owner submits incorrect OTP | 🚫 Error shown, remaining-attempts counter decrements |
| AUTH-07 | Owner submits 5 wrong OTP codes in a row | 🚫 Code invalidated; must request a new one |
| AUTH-08 | Owner submits an expired OTP code | 🚫 Clear "code expired" message (not a generic error) |
| AUTH-09 | Owner reuses an already-used OTP code | 🚫 Clear "code already used" message |
| AUTH-10 | Owner requests OTP more than 3 times in 10 minutes | 🚫 Rate-limited |
| AUTH-11 | Owner's access token expires mid-session | ✅ Silently refreshed via refresh token, no visible interruption |
| AUTH-12 | Owner's refresh token is invalid/expired/missing | 🚫 401, redirected to login, intended destination preserved for after re-login |
| AUTH-13 | Owner logs out, then reuses the old refresh token | 🚫 401 — refresh tokens are rotated/revoked, not silently re-honored |
| AUTH-14 | Owner requests a password reset for a forgotten password | ✅ Generic response regardless of whether the identifier exists (no account enumeration); reset email link issued if it does |
| AUTH-15 | Owner clicks an expired password-reset link | 🚫 Rejected with a clear message |
| AUTH-16 | Owner replays an already-used password-reset link | 🚫 Rejected the second time |
| AUTH-17 | Owner requests a second password reset before using the first | ✅ First token invalidated; only the newest is valid |
| AUTH-18 | Owner completes a password reset | ✅ All existing refresh tokens revoked (forces re-login everywhere) |

---

## 2. Onboarding & Invitations

| ID | Scenario | Expected Result |
|---|---|---|
| INV-01 | Owner invites a new person by email/phone as Coach or Member | ✅ Invitation created `pending`, invitee notified (email/SMS + in-app if account exists) |
| INV-02 | Owner re-invites someone who already has a pending invitation | ✅ Returns the existing pending invitation, does not create a duplicate |
| INV-03 | An invitation goes unanswered for 7 days | ✅ Auto-marked `expired`; Owner can send a new one |
| INV-04 | Invitee approves the invitation | ✅ Account becomes `active`, profile created, **Owner is notified** |
| INV-05 | Invitee declines the invitation | ✅ No profile created, invitation closed, **Owner is notified of the decline** (not the reason unless invitee chooses to share it) |
| INV-06 | A user who isn't the invitee tries to approve/decline someone else's invitation (even knowing its ID) | 🚫 403, even for the inviting Owner |
| INV-07 | Owner (or anyone) tries to respond to an already-responded invitation again | 🚫 409 Conflict |
| INV-08 | Owner bulk-imports members via CSV with some malformed rows | ✅ Accurate per-row success/failure report; malformed rows don't block valid ones |
| INV-09 | Bulk-imported rows | ✅ Never create an active account directly — go through the same invite/approve flow |
| INV-10 | Bulk import targets a destination that already has a pending invitation | ✅ Reuses the existing pending invitation, no duplicate |
| INV-11 | Coach, Staff, or Member attempts to send an invitation | 🚫 403 (Owner-only action) |
| INV-12 | Coach, Staff, or Member attempts a bulk CSV import | 🚫 403 |
| INV-13 | A different Owner attempts to send an invitation "on behalf of" another Owner's gym | 🚫 403 |
| INV-14 | Owner or Staff registers a walk-in Member at the front desk (name + email/phone) | ✅ Member created immediately `active` — no OTP, no pending-approval step, gets a `memberId` |
| INV-15 | Two walk-in members are created back-to-back | ✅ Distinct, sequential `memberId`s — no collision even under concurrency |
| INV-16 | Coach attempts to create a walk-in member | 🚫 403 |
| INV-17 | Owner or Staff submits a `memberId` value in the walk-in-creation payload while the gym is in **auto** mode | 🚫 Rejected — `memberId` is system-generated in auto mode, not client-suppliable |

### Member ID mode configuration

| ID | Scenario | Expected Result |
|---|---|---|
| MID-01 | Owner switches Member ID mode from auto to manual (no members exist yet) | ✅ Succeeds; front-desk creation now requires an Owner/Staff-entered `memberId` |
| MID-02 | Owner attempts to switch modes after the gym already has members | 🚫 Validation error — mode is locked once real data depends on it |
| MID-03 | Owner resubmits the *same* mode the gym is already in | ✅ Not blocked by the existing-members rule (only an actual mode *change* is blocked) |
| MID-04 | Owner sets a gym code while configuring manual mode | ✅ Accepted if unique and correctly formatted |
| MID-05 | Owner sets a gym code that's already in use | 🚫 Rejected — must be unique |
| MID-06 | Owner sets a gym code in an invalid format | 🚫 Rejected with a specific validation error |
| MID-07 | Owner, in manual mode, creates a member with a duplicate hand-entered `memberId` | 🚫 Rejected |
| MID-08 | Staff reads Member ID settings | ✅ Allowed (read-only) |
| MID-09 | Staff attempts to change Member ID mode or gym code | 🚫 403 — only Owner can change this setting |
| MID-10 | Coach or Member attempts to view or change Member ID settings via any route | 🚫 403 |

---

## 3. Membership Plans

| ID | Scenario | Expected Result |
|---|---|---|
| PLAN-01 | Owner creates a plan (name, price, duration, features) | ✅ Immediately available for enrollment |
| PLAN-02 | Owner deletes a plan that has active members enrolled | 🚫 Blocked/warned (409) — existing memberships aren't silently broken |
| PLAN-03 | Owner deletes a plan with zero enrolled members | ✅ Succeeds |
| PLAN-04 | Coach, Staff, or Member attempts to create/edit/delete a plan | 🚫 403 |
| PLAN-05 | A different Owner attempts to manage this Owner's plan | 🚫 403 |
| PLAN-06 | Owner creates a plan without specifying a branch (single-branch gym) | ✅ Defaults to the primary branch |
| PLAN-07 | Owner (multi-branch) creates a plan for a specific branch | ✅ Plan is scoped to that branch only |
| PLAN-08 | Owner specifies a `branchId` that doesn't belong to their gym | 🚫 400 Bad Request |

---

## 4. Membership Lifecycle & Enrollment

| ID | Scenario | Expected Result |
|---|---|---|
| MEM-01 | Owner enrolls an active Member into a plan | ✅ Membership created `active`, `membership.created` event dispatched |
| MEM-02 | Owner enrolls a Member whose account isn't yet `active` (still pending approval) | 🚫 Conflict — only an approved, active Member can be enrolled |
| MEM-03 | Owner enrolls a Member who already has an active or paused membership | 🚫 Conflict — no duplicate concurrent memberships |
| MEM-04 | Owner suspends an existing Member account | ✅ Status → `suspended`; audit log entry created |
| MEM-05 | Owner reactivates a suspended Member | ✅ Status → `active` |
| MEM-06 | Owner sets a member's status to the same value twice in a row | ✅ No duplicate audit-log entry created |
| MEM-07 | Owner sets a member's status to `pending_approval` (an invalid target for this action) | 🚫 Rejected |
| MEM-08 | Owner sets an invalid/unknown status value | 🚫 Rejected |
| MEM-09 | Owner updates status for a non-existent member ID | 🚫 404 |
| MEM-10 | A just-suspended member attempts to check in | 🚫 Blocked (`account_suspended`) |
| MEM-11 | A Member attempts to suspend themselves | 🚫 403 |
| MEM-12 | A Coach attempts to suspend a Member | 🚫 403 |
| MEM-13 | Staff attempts to suspend or reactivate a Member | 🚫 403 — this stayed Owner-only even after walk-in creation was widened to Staff |

---

## 5. Attendance Visibility & Front-Desk Check-in

| ID | Scenario | Expected Result |
|---|---|---|
| ATT-01 | A Member checks in while Owner is viewing the dashboard | ✅ Owner's live check-in counter updates in real time (Mercure), no manual refresh |
| ATT-02 | Owner filters attendance history by a date range | ✅ Only matching entries returned |
| ATT-03 | Owner checks a member in on their behalf at the front desk | ✅ Succeeds, same validation as self check-in applies |
| ATT-04 | Owner fetches any member's active (open) attendance session | ✅ Allowed — Owner has unrestricted `VIEW_ALL` |
| ATT-05 | Coach or Member attempts to view attendance data gym-wide | 🚫 403 (`VIEW_ALL` is Owner-only) |
| ATT-06 | Staff attempts to view attendance gym-wide (across all branches) | 🚫 403 — Staff never gets `VIEW_ALL`, only branch-scoped visibility |

---

## 6. Announcements

| ID | Scenario | Expected Result |
|---|---|---|
| ANN-01 | Owner publishes a gym-wide announcement | ✅ Every active Member and Coach at the gym is notified |
| ANN-02 | Owner targets an announcement at one specific branch | ✅ Only that branch's people are notified |
| ANN-03 | Owner omits a branch filter | ✅ Announcement goes out gym-wide, across all branches |
| ANN-04 | Owner specifies a `branchId` belonging to a different gym | 🚫 400 |
| ANN-05 | An announcement from one gym | ✅ Never reaches another gym's people, even indirectly |
| ANN-06 | Coach attempts to post a gym-wide announcement (not scoped to own clients) | 🚫 403 |
| ANN-07 | Member attempts to post any announcement | 🚫 403 — Member can never post, under any circumstance |

---

## 7. Billing — One-Time Enrollment Invoice (Manual)

| ID | Scenario | Expected Result |
|---|---|---|
| BILL-01 | A Member enrolls in a plan | ✅ A `pending` invoice is created automatically for the plan's price |
| BILL-02 | Owner marks a pending invoice as paid, specifying payment method (cash/bank transfer) | ✅ Invoice → `paid`, membership stays/becomes active, Member is notified |
| BILL-03 | Member attempts to mark their own invoice as paid | 🚫 403 — enforced at the API level, not just hidden in the UI |
| BILL-04 | Coach attempts to mark any invoice as paid | 🚫 403 |
| BILL-05 | Owner marks an already-`paid` invoice as paid again | 🚫 409 Conflict |
| BILL-06 | Owner submits an invalid payment method (e.g. `venmo`) | 🚫 400 |
| BILL-07 | Owner attempts to submit `gateway` or `referral_credit` as the payment method | 🚫 Rejected — those are system/internal-only values, never client-suppliable |
| BILL-08 | An invoice sits pending for an extended period (e.g. 90 days) | ✅ Remains visible as outstanding — no automatic payment assumption or auto-activation |
| BILL-09 | Marking an invoice paid | ✅ Creates a correct, complete audit-log entry (actor, action, amount, method) |
| BILL-10 | Owner has an unredeemed referral credit when a new member enrolls | ✅ The new invoice is automatically covered (marked paid via `referral_credit`), and this is itself audit-logged |
| BILL-11 | The referral credit is consumed by one enrollment | ✅ The *next* enrollment pays normally — the credit doesn't apply twice |
| BILL-12 | Owner lists all invoices for their gym | ✅ Returns every invoice, including other members' |
| BILL-13 | Non-Owner (Coach, Staff, Member) attempts to list all invoices | 🚫 403 |
| BILL-14 | Member views their own invoice history | ✅ Sees only their own invoices, never another member's |

---

## 8. Billing — Recurring Subscriptions & Check-in Gating

*(Applies to memberships enrolled after the recurring-billing phase shipped — see `gym-management-billing-v1.md`.)*

| ID | Scenario | Expected Result |
|---|---|---|
| RBILL-01 | Owner records a full payment on a `pending` recurring invoice | ✅ Invoice → `paid` |
| RBILL-02 | Owner (or Staff) submits a payment `amount` that doesn't exactly match the invoice amount (over or under) | 🚫 422 — "Partial payments are not supported"; invoice status unchanged |
| RBILL-03 | Owner (or Staff) attempts to pay an already-`paid` invoice | 🚫 409 |
| RBILL-04 | Owner checks the **"reset billing cycle"** box while recording a payment | ✅ `billingAnchorDay`/`nextBillingDate` update to the payment date — a **permanent** change (the cycle *after* the next one also lands on the new day) |
| RBILL-05 | Owner pays a late (`ABSENT`) invoice with reset checked | ✅ Marked `paid` directly — never silently reopened to `pending` first |
| RBILL-06 | **Staff** submits `resetBillingCycle: true` — even via a direct API call bypassing the UI | 🚫 403 — the **whole request fails**, no silent coercion to `false`, no partial mutation of invoice or membership state |
| RBILL-07 | Staff assigned to the invoice's branch records a payment (without reset) | ✅ Succeeds |
| RBILL-08 | Staff **not** assigned to the invoice's branch attempts to record a payment | 🚫 403 |
| RBILL-09 | Owner suspends an active subscription | ✅ Status → `suspended`; the generation command stops picking it up; existing invoices are left untouched (not waived, not auto-paid) |
| RBILL-10 | Staff not assigned to the membership's branch attempts to suspend/reactivate it | 🚫 403 |
| RBILL-11 | Owner reactivates a subscription that was suspended for 3 months | ✅ `nextBillingDate` resets to the reactivation date; exactly **one** new invoice is generated going forward — the 3 missed cycles are never backfilled |
| RBILL-12 | The nightly `app:billing:generate-invoices` job runs twice on the same day | ✅ No duplicate invoices — idempotent via the `(membership, periodStart)` unique constraint |
| RBILL-13 | A subscription's `billingAnchorDay = 31` rolls into a 30-day month | ✅ Clamps to the last day of that month, no crash |
| RBILL-14 | ...and into a 28-day (non-leap) or 29-day (leap) February | ✅ Clamps correctly to 28 or 29 |
| RBILL-15 | A member checks in with a `SUSPENDED` subscription | 🚫 Blocked, reason `subscription_inactive` |
| RBILL-16 | A member checks in with an `ABSENT` invoice on record | 🚫 Blocked, reason `absent_invoice` |
| RBILL-17 | A member checks in with a `PENDING` invoice **past** its due date | 🚫 Blocked, reason `overdue` — evaluated live, even before the nightly job has formally marked it `ABSENT` |
| RBILL-18 | A member checks in with a `PENDING` invoice **before** its due date | ✅ Allowed — current until the due date actually passes |
| RBILL-19 | Owner views `GET /branches/{id}/invoices?status=absent,overdue` (the "needs attention" list) | ✅ Returns absent + overdue invoices, oldest-due-first |
| RBILL-20 | Owner/Staff/Coach/Member views `GET /members/{id}/billing-status` | ✅ Owner: any member; Staff: own branch; Coach: own clients only; Member: self only |

---

## 9. Reporting & Analytics *(Owner-exclusive)*

| ID | Scenario | Expected Result |
|---|---|---|
| REP-01 | Owner views the live dashboard | ✅ Shows today's check-ins, today's revenue, current active-member count, updating live |
| REP-02 | Owner views the attendance trend for a date range | ✅ Per-day chart; correctly reflects pre-reporting-feature historical data too (no gap) |
| REP-03 | Owner views revenue forecast with fewer than 14 days of history | ✅ Explicit "not enough data yet" state — never a fabricated number |
| REP-04 | Owner views revenue forecast with sufficient history | ✅ 30/60/90-day projection shown with its calculation method, explicitly not presented as guaranteed |
| REP-05 | A membership is expiring soon with no auto-renew | ✅ Reduces the forecast projection accordingly |
| REP-06 | Owner views the retention/churn-risk report | ✅ At-risk members shown with a **specific reason** (e.g. exact days since last check-in), never a bare "at risk" label |
| REP-07 | A member with normal recent activity | ✅ Does not appear on the at-risk list |
| REP-08 | A cancelled membership | ✅ Excluded from the at-risk list entirely |
| REP-09 | Owner exports a report as CSV | ✅ Succeeds, scoped to the chosen range |
| REP-10 | Owner exports a report as PDF | ✅ Succeeds |
| REP-11 | Owner requests an export with an invalid report type or format | 🚫 Rejected |
| REP-12 | Exporting a report | ✅ Creates an audit-log entry |
| REP-13 | Owner (multi-branch) filters dashboard/reports to one specific branch | ✅ Numbers scoped to that branch only |
| REP-14 | Owner omits the branch filter / selects "all branches" | ✅ Business-wide rollup across all branches |
| REP-15 | Owner supplies an unknown/non-existent `branchId` on a report | 🚫 400 |
| REP-16 | Coach, Staff, or Member attempts to view the dashboard, attendance report, forecast, retention report, or export | 🚫 403 on every single one — these are Owner-only regardless of how directly they're reached |
| REP-17 | A different Owner attempts to view or export this Owner's gym's reports | 🚫 403 — reports are visible only to the gym's own Owner, never another gym's Owner |

---

## 10. Staff Role Management *(Owner-exclusive)*

| ID | Scenario | Expected Result |
|---|---|---|
| STF-01 | Owner invites a new Staff member (same invite/approve flow as Coach/Member) | ✅ Standard invitation created, invitee approves like any other role |
| STF-02 | Owner manages a Staff account (e.g. removes/edits it) | ✅ Allowed |
| STF-03 | A Staff member attempts to manage another Staff account | 🚫 403 |
| STF-04 | Coach or Member attempts to manage a Staff account | 🚫 403 |
| STF-05 | Staff attempts to access revenue reports, plan pricing, or staff management via any route | 🚫 403 — these are Owner-only "regardless of how directly" they try |
| STF-06 | Staff attempts to create a membership plan | 🚫 403 |
| STF-07 | Staff attempts to mark an invoice paid or view invoices | 🚫 403 |
| STF-08 | Staff attempts to respond to (accept/decline) a PT session | 🚫 403 |
| STF-09 | Staff attempts to send an announcement | 🚫 403 |
| STF-10 | Staff attempts to view the dashboard, attendance report, revenue forecast, retention report, or export reports | 🚫 403 on every one |

---

## 11. White-Label Branding *(Owner-exclusive)*

| ID | Scenario | Expected Result |
|---|---|---|
| BRND-01 | Owner sets the gym's display name | ✅ Saved and reflected wherever the gym name appears |
| BRND-02 | Owner submits an empty gym name | 🚫 400 |
| BRND-03 | Owner uploads a logo | ✅ Appears on nav header + membership badge, no separate publish step |
| BRND-04 | Owner sets a brand color | ✅ Applied to nav header/badge |
| BRND-05 | Owner submits an invalid brand color value | 🚫 Rejected |
| BRND-06 | No logo/color has been set yet | ✅ A sensible default renders — never a broken image or empty swatch |
| BRND-07 | Owner's brand color is set | ✅ Primary action buttons (check-in, "send request," etc.) **still** show the standard `hivis` color — brand color never overrides CTA color, even via a crafted API request setting an unexpected field |
| BRND-08 | Owner submits unrecognized extra fields in the branding payload | ✅ Ignored, not persisted |
| BRND-09 | Coach, Staff, or Member attempts to set gym name or branding | 🚫 403 |
| BRND-10 | Any authenticated role reads branding, even before any gym-level branding is set | ✅ Allowed (read is universal; write is Owner-only) |
| BRND-11 | A Member views the gym once the Owner has set branding | ✅ Sees the Owner's branding correctly |

---

## 12. WhatsApp Notification Settings *(Owner-exclusive configuration)*

| ID | Scenario | Expected Result |
|---|---|---|
| WA-01 | Owner views WhatsApp settings before any gym exists | ✅ Shows unconfigured defaults, not an error |
| WA-02 | Owner configures WhatsApp credentials | ✅ Saved; the access token is **never echoed back** in any subsequent response |
| WA-03 | Owner enables WhatsApp once credentials are configured | ✅ Succeeds |
| WA-04 | Owner attempts to enable WhatsApp without credentials configured | 🚫 Rejected |
| WA-05 | Owner disables WhatsApp | ✅ Succeeds and clears stored credentials |
| WA-06 | Coach attempts to view WhatsApp settings | 🚫 403 |
| WA-07 | Staff attempts to update WhatsApp settings | 🚫 403 |
| WA-08 | Member attempts to view or update WhatsApp settings | 🚫 403 |
| WA-09 | An opted-in user with a phone number, gym WhatsApp enabled | ✅ Receives the WhatsApp message |
| WA-10 | An opted-out user | ✅ Receives no WhatsApp message (in-app notification still applies) |
| WA-11 | Gym-level WhatsApp master switch is off | ✅ No WhatsApp delivery to anyone, even opted-in users |

---

## 13. Branch Management *(Owner-exclusive)*

| ID | Scenario | Expected Result |
|---|---|---|
| BR-01 | Owner creates a second branch | ✅ Immediately usable for plans, assignment, and pickers |
| BR-02 | Owner assigns a Coach to a branch | ✅ Coach now sees that branch's sessions, branch-labeled |
| BR-03 | Owner assigns the same Coach to a second branch | ✅ Coach sees sessions from both, correctly labeled |
| BR-04 | Owner assigns the same Coach to the same branch twice | 🚫 409 Conflict — no duplicate assignment |
| BR-05 | Owner attempts to "assign" a Member (assignment is Coach/Staff-only) | 🚫 409 |
| BR-06 | Owner unassigns a Coach from a branch | ✅ Immediately loses view/access to that branch's members/attendance — enforced at the API level, a lingering token doesn't still grant access |
| BR-07 | Owner deactivates a branch | ✅ Stops new check-ins/bookings there; historical data stays intact and reportable |
| BR-08 | Owner deletes an unused, non-primary branch | ✅ Succeeds, and removes its coach assignments too |
| BR-09 | Owner attempts to delete the **primary** branch | 🚫 409 — blocked |
| BR-10 | Owner attempts to delete a branch that has a membership plan attached | 🚫 409 |
| BR-11 | Owner attempts to delete a branch with attendance history | 🚫 409 |
| BR-12 | Owner sets a different price for the same plan concept at two different branches | ✅ Members see/pay the specific branch's price |
| BR-13 | Owner changes a plan's price after members are already enrolled | ✅ Existing memberships keep their original price until renewal |
| BR-14 | Owner filters reports to one specific branch vs. "all branches" | ✅ Scoped figures vs. business-wide rollup, respectively |
| BR-15 | Coach, Staff, or Member attempts to create, edit, or delete a branch | 🚫 403 |
| BR-16 | A different Owner attempts to manage this Owner's branch | 🚫 403 |
| BR-17 | Any authenticated role lists branches (read-only) | ✅ Allowed for all roles |
| BR-18 | A single-branch gym (never creates a second branch) | ✅ Experience is unchanged — nothing about branch scoping is felt |

---

## 14. Expense Tracking

| ID | Scenario | Expected Result |
|---|---|---|
| EXP-01 | Default expense categories exist on first use | ✅ Seeded automatically, no manual setup needed |
| EXP-02 | Owner adds a custom expense category | ✅ Succeeds |
| EXP-03 | Owner deletes an unused expense category | ✅ Succeeds |
| EXP-04 | Owner attempts to delete a category that has expenses recorded against it | 🚫 Blocked |
| EXP-05 | Owner (or Staff) records an expense with positive amount, category, branch, and date | ✅ Appears immediately in that branch's expense list |
| EXP-06 | Owner (or Staff) submits a zero or negative amount | 🚫 Specific validation error |
| EXP-07 | Owner (or Staff) omits category or branch | 🚫 Specific validation error |
| EXP-08 | Owner attaches a receipt file to an expense | ✅ Stored as a simple upload (no OCR/auto-categorization) |
| EXP-09 | Owner edits or deletes **any** expense, including one Staff recorded | ✅ Allowed — Owner has unrestricted `MANAGE` |
| EXP-10 | Staff records an expense for a branch they're **not** assigned to | 🚫 403 |
| EXP-11 | Staff attempts to edit or delete an expense — **even one they created themselves** | 🚫 403 — only Owner can edit/delete |
| EXP-12 | Staff attempts to delete an expense category | 🚫 403 |
| EXP-13 | Coach or Member attempts to view, create, edit, or delete an expense via any route | 🚫 403 |

---

## 15. Product Catalog & Retail Sales

| ID | Scenario | Expected Result |
|---|---|---|
| PROD-01 | Default product categories exist on first use | ✅ Seeded automatically |
| PROD-02 | Owner creates a product (name, category, unit price) | ✅ Immediately available for sale |
| PROD-03 | Owner deactivates a product | ✅ Stops appearing in the active sale picker; past sales stay reportable |
| PROD-04 | Owner deletes an unused product category | ✅ Succeeds |
| PROD-05 | Owner attempts to delete a category with products in it | 🚫 Blocked |
| PROD-06 | Staff views the product catalog | ✅ Allowed, read-only (no create/edit/deactivate) |
| PROD-07 | Staff attempts to create/edit a product (including via a manipulated request) | 🚫 403 |
| PROD-08 | Staff attempts to delete a product category | 🚫 403 |
| PROD-09 | Coach or Member attempts to access the product catalog via any route | 🚫 403 |
| SALE-01 | Owner (or Staff) records a retail sale (product + quantity) | ✅ Total computed automatically at time-of-sale price |
| SALE-02 | A product's catalog price changes after a sale was recorded | ✅ The past sale's recorded total is unaffected — no retroactive rewrite |
| SALE-03 | Owner optionally attaches an existing member to a sale | ✅ Linked for reporting only — never affects billing/invoices/balance |
| SALE-04 | Owner records a walk-in sale with no member attached | ✅ Succeeds |
| SALE-05 | Owner searches for a member during a sale and gets no match | ✅ Rejected as a nonexistent member ID — never silently creates a new member |
| SALE-06 | Owner edits or deletes any sale, on any branch | ✅ Allowed, unrestricted |
| SALE-07 | Staff records a sale for their own assigned branch | ✅ Succeeds |
| SALE-08 | Staff attempts to record a sale for a branch they're **not** assigned to | 🚫 403 |
| SALE-09 | Staff attempts to view a different branch's sale | 🚫 403 |
| SALE-10 | Staff attempts to edit or delete a sale | 🚫 403 — Staff has create/view only |
| SALE-11 | Coach or Member attempts to record or view retail sales via any route | 🚫 403 |
| SALE-12 | Owner (multi-branch) queries sales for one branch | ✅ Never sees another branch's sales mixed in |

---

## 16. Financial Summary *(Owner-exclusive)*

| ID | Scenario | Expected Result |
|---|---|---|
| FIN-01 | Owner views the financial summary for a date range | ✅ Shows membership revenue + PT revenue + retail revenue, total expenses, and net total |
| FIN-02 | The net total | ✅ Correctly computes `membership + PT + retail − expenses` |
| FIN-03 | A PT session that's still pending/unconfirmed | ✅ Does **not** count toward PT revenue |
| FIN-04 | Owner (multi-branch) selects one specific branch | ✅ Figures scoped to that branch only |
| FIN-05 | Owner selects "all branches" / omits the filter | ✅ Business-wide rollup |
| FIN-06 | Owner supplies an unknown `branchId` | 🚫 400 |
| FIN-07 | Staff, Coach, or Member attempts to view the financial summary via any route | 🚫 403 — same Owner-only exclusion as the reports section |

---

## 17. Member Roster & Profile Management

| ID | Scenario | Expected Result |
|---|---|---|
| MBR-01 | Owner views the full onboarded member roster | ✅ Sees every member, gym-wide |
| MBR-02 | Roster includes Coaches | ✅ Tagged with a `role` field distinguishing them from Members |
| MBR-03 | A suspended Coach | ✅ Still appears in the roster (not silently hidden) |
| MBR-04 | A pending-approval user with no profile yet | ✅ Correctly excluded from the roster |
| MBR-05 | An enrolled member's roster entry | ✅ Includes their plan, status, and enrolling branch |
| MBR-06 | An expired membership | ✅ Shown as `expired`, not stale `active` data |
| MBR-07 | Owner reads a member's full profile (dob, gender, address, etc.) | ✅ Allowed |
| MBR-08 | Owner updates a member's profile fields (dob, gender, address, memberId in manual mode) | ✅ Allowed |
| MBR-09 | Owner (or Staff) submits `memberId` directly in a profile-update payload while in **auto** mode | 🚫 Rejected, value unchanged |
| MBR-10 | Owner submits an invalid `dob` (not a real date) | 🚫 Rejected |
| MBR-11 | Owner views a member's PT schedule | ✅ Allowed |
| MBR-12 | Owner views a member's payment history | ✅ Allowed (real data once billing is enabled; previously an explicit "not available" stub, not a misleading empty list) |
| MBR-13 | A member is deactivated | ✅ Their attendance history remains queryable |
| MBR-14 | A different Owner (different gym) attempts to read/write this member's profile | 🚫 403 |
| MBR-15 | Member attempts to list the gym roster | 🚫 403 |
| MBR-16 | Coach attempts to read a member's full profile or PT schedule directly | 🚫 403 (Coach's own scoped roster view is separate and limited to their own clients) |
| MBR-17 | Coach attempts to read a member's payment history | 🚫 403 |
| MBR-18 | Coach's member-list view | ✅ Never exposes the fuller PII fields Owner/Staff see |

---

## 18. Password Management (Owner sets passwords for staff accounts)

| ID | Scenario | Expected Result |
|---|---|---|
| PWD-01 | Owner sets a system-generated password for a Member | ✅ Succeeds; forces a password change on that member's next login |
| PWD-02 | Owner sets an explicit password for a Coach | ✅ Succeeds |
| PWD-03 | Coach attempts to set a password for a Member | 🚫 403 |
| PWD-04 | Staff attempts to set a password for a Member | 🚫 403 |
| PWD-05 | Member attempts to set their own password via this admin action | 🚫 403 (self-service password change is a separate, different flow) |
| PWD-06 | The password-set action | ✅ Requires authentication — anonymous requests rejected |
| PWD-07 | A member forced into "must change password" logs in and changes it | ✅ Requirement is cleared; future logins no longer force a change |

---

## 19. Cross-Gym / Multi-Tenant Isolation *(cross-cutting)*

| ID | Scenario | Expected Result |
|---|---|---|
| ISO-01 | Owner A attempts to view/manage Owner B's plans, members, branches, invoices, or reports | 🚫 403 on every one, even with a directly-manipulated request carrying a real ID from Owner B's gym |
| ISO-02 | Owner A's announcement | ✅ Never reaches Owner B's gym's people |
| ISO-03 | Owner A's report export | 🚫 Rejected if attempted (via a manipulated request) against Owner B's gym's data |
| ISO-04 | Owner A's dashboard/live counters | ✅ Never include Owner B's gym's activity |

---

## 20. Non-Functional

| ID | Scenario | Expected Result |
|---|---|---|
| NFR-01 | Every Owner-facing screen at 375px viewport width | ✅ Usable, no horizontal scroll, touch targets ≥ 44×44px |
| NFR-02 | Every Owner-only Voter attribute | ✅ Has both a passing-case test and a `403` test (verified in the codebase's own test suite) |
| NFR-03 | Any error an Owner encounters | ✅ Specific and actionable, never a raw error code or stack trace |
| NFR-04 | Sensitive Owner actions (mark-paid, suspend member, reset billing cycle, export report) | ✅ Each creates a correct, complete audit-log entry |

---

## Coverage note

Every scenario above maps to an existing, passing automated test in `backend/tests/` (Functional or Voter test suites) unless otherwise noted. This document is the QA-scenario index; the PHPUnit test suite is the executable source of truth — run `docker compose exec php bin/phpunit` to verify all of the above still holds after any change.
