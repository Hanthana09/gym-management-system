# Gym Management System — Functional Requirements

**What this document is:** feature behavior from a user's point of view — user stories and acceptance criteria. It doesn't repeat the *how* (see `gym-management-system-architecture.md` for entities, Voters, and API shape) or the *when* (see `gym-management-system-development-roadmap.md` for phase order). Every acceptance criterion here should map to a passing test before its phase is considered done.

Format: **Given / When / Then** per criterion. Role in brackets after each story shows who it's for.

---

## 1. Authentication

### 1.1 Password login
**As a** user of any role, **I want to** log in with email/phone + password, **so that** I can access my dashboard.
- Given valid credentials, when I submit the login form, then I receive a JWT access token and refresh token and land on my role's dashboard.
- Given an incorrect password, when I submit, then I see a generic "invalid credentials" error (never confirm whether the email exists).
- Given 5 failed attempts within 15 minutes for the same account, when I try again, then I'm temporarily rate-limited with a clear message.

### 1.2 OTP login
**As a** user of any role, **I want to** log in with a one-time code sent to my email or phone, **so that** I don't need to remember a password.
- Given I enter a registered email or phone and request a code, when the request succeeds, then a 6-digit code is sent and I see a code-entry screen with a visible countdown to expiry (5 minutes).
- Given I enter the correct code before expiry, when I submit, then I receive the same JWT pair as password login.
- Given I enter an incorrect code, when I submit, then I see an error and my remaining attempts decrease; after 5 wrong attempts the code is invalidated and I must request a new one.
- Given a code has expired or already been used, when I submit it, then I get a clear "expired/used, request a new code" message — never a generic error.
- Given I request more than 3 codes in 10 minutes for the same destination, when I request again, then I'm rate-limited.

### 1.3 Session handling
**As a** logged-in user, **I want** my session to stay valid across a normal work session but expire if inactive, **so that** the app is both convenient and secure.
- Given my access token expires, when I make a request, then the app silently uses the refresh token to get a new one without logging me out, unless the refresh token has also expired — in which case I'm redirected to login with my place preserved where reasonable.

---

## 2. Invitations & Approval

### 2.1 Owner sends an invitation
**As an** Owner, **I want to** invite someone by email or phone as a Coach or Member, **so that** they can join my gym.
- Given I submit an email/phone and a role, when the invitation is created, then the invitee is notified (email/SMS + in-app if they already have an account) and the invitation shows as "pending" in my invitations list.
- Given I invite someone already invited and still pending, when I try again, then I see the existing pending invitation rather than creating a duplicate.
- Given an invitation is not responded to within 7 days, when the expiry passes, then it's marked expired and I can send a new one.

### 2.2 Invitee approves or declines
**As a** Coach or Member who has been invited, **I want to** review and explicitly approve or decline the invitation, **so that** I control whether I'm associated with a gym.
- Given I have a pending invitation, when I log in (via either method), then I see it clearly before or alongside my dashboard — it should not be possible to miss.
- Given I approve, when the action completes, then my account becomes active for that gym, my role-specific profile is created, and the Owner is notified.
- Given I decline, when the action completes, then no profile is created, the invitation is closed, and the Owner is notified of the decline (not the reason, unless I choose to provide one).
- Given I try to approve/decline an invitation that isn't mine, when I attempt it, then I get a permission error — this must hold even if I somehow have the invitation's ID.

---

## 3. Membership Management

### 3.1 Owner manages plans
**As an** Owner, **I want to** create and edit membership plans (name, price, duration, features), **so that** I can offer different options to Members.
- Given I create a plan, when I save it, then it's immediately available for enrollment.
- Given a plan has active members enrolled, when I try to delete it, then I'm blocked or warned rather than silently breaking existing memberships.

### 3.2 Member enrollment & status
**As a** Member, **I want to** see my current plan, status, and renewal date, **so that** I know where I stand.
- Given my membership is active, when I view "My membership," then I see plan name, price, start date, and next renewal/expiry date.
- Given my membership is within 7/3/1 days of expiring, when that threshold is crossed, then I receive a reminder notification.
- Given my membership has expired, when I try to check in, then I'm blocked with a clear message pointing to renewal — not a generic error.

### 3.3 Pause/cancel
**As a** Member, **I want to** pause or cancel my membership, **so that** I have control over recurring billing.
- Given I pause, when the action completes, then no further invoices are generated until I resume, and check-in is blocked while paused.

---

## 4. Attendance

### 4.1 Self check-in
**As a** Member, **I want to** check in with one tap, **so that** my visit is recorded with minimal friction.
- Given I have an active membership, when I tap check-in, then a timestamped record is created and I see immediate on-screen confirmation.
- Given my membership is not active (expired/paused/suspended), when I try to check in, then I'm blocked with a specific reason shown.
- Given the check-in request fails due to network issues, when that happens, then I see a clear retry option rather than a silent failure or an ambiguous spinner.

### 4.2 Owner visibility
**As an** Owner, **I want to** see check-ins as they happen, **so that** I have a live picture of who's in the gym.
- Given a Member checks in, when it happens, then my dashboard counter updates in real time without a manual refresh.
- Given I want historical data, when I view attendance reports, then I can filter by date range.

---

## 5. Personal Training

### 5.1 Member requests a session
**As a** Member, **I want to** request a session with a specific Coach at a specific time, **so that** I can book personal training.
- Given I submit a request, when it's created, then it shows as "pending" in my sessions list and the Coach is notified.
- Given the Coach hasn't responded, when I view my pending request, then I can cancel it before it's accepted.

### 5.2 Coach responds
**As a** Coach, **I want to** accept or decline session requests, **so that** I control my own schedule.
- Given a pending request, when I accept, then it becomes "confirmed," the Member is notified, and it appears on my schedule.
- Given a pending request, when I decline, then the Member is notified and the slot is freed.
- Given I try to respond to a request assigned to a different Coach, when I attempt it, then I get a permission error.

### 5.3 Session notes
**As a** Coach, **I want to** log notes after a session, **so that** I can track client progress over time.
- Given a completed session, when I add notes, then only I (and not the Member, by default) can see them, matching the open decision flagged in the architecture doc §9 unless the team decides otherwise.

---

## 6. Notifications

### 6.1 Receiving notifications
**As a** user of any role, **I want to** see notifications relevant to me — bookings, billing, announcements — **so that** I don't have to check manually.
- Given an event I care about occurs (my membership expiring, my session confirmed, a gym announcement), when it happens, then I see it in-app within a few seconds and can view a history of past notifications.
- Given I mark a notification read, when I do, then it's reflected immediately and persists across sessions/devices.

### 6.2 Owner announcements
**As an** Owner, **I want to** broadcast a message to all my gym's members and coaches, **so that** I can communicate gym-wide updates.
- Given I publish an announcement, when it's sent, then every active Member and Coach at my gym receives it; people at other gyms never see it.

### 6.3 Coach announcements
**As a** Coach, **I want to** message my own clients specifically, **so that** I don't have to go through the Owner for client-specific updates.
- Given I send a message, when it's sent, then only my assigned clients receive it, not the whole gym.

---

## 7. Personal Tracking

### 7.1 Workout logging
**As a** Member, **I want to** log a workout (type, duration), **so that** I can track my training history.
- Given I submit a log entry, when it's saved, then it appears immediately in my history, newest first.

### 7.2 Body metrics & progress
**As a** Member, **I want to** see a trend of my weight/body metrics over time, **so that** I can track progress toward my goals.
- Given I have logged entries, when I view "Personal tracking," then I see a chart plotting the trend, not just a raw list.
- Given I have no entries yet, when I view the screen, then I see an empty state that invites me to log my first entry, not a broken/blank chart.
- This data is private to me by default — no Owner or Coach can view it without an explicit opt-in grant (open decision, architecture doc §9).

---

## 8. Billing & Payments

**Current scope note:** payment recording is manual (Owner marks an invoice as paid — cash/bank transfer) for now; gateway integration (Stripe/PayHere) is deferred. The criteria below reflect the manual flow. When a gateway is added later, criterion 8.1's "Owner marks paid" step is replaced by an automated webhook confirmation — everything else (invoice creation, history, Member visibility) stays the same.

### 8.1 Enrollment invoice & manual payment recording
**As a** Member, **I want to** enroll in a plan and know what I owe, **so that** I can arrange payment with the gym.
**As an** Owner, **I want to** mark an invoice paid once I've received payment (cash/bank transfer), **so that** the member's status updates accurately.
- Given a Member enrolls in a plan, when enrollment completes, then an invoice is created with status `pending` and the Member can see the amount owed.
- Given an Owner marks an invoice as paid (specifying the payment method), when that action completes, then the invoice status becomes `paid`, the associated membership becomes/stays active, and the Member is notified.
- Given a Member attempts to mark their own invoice paid, when that's attempted, then it's rejected — only the Owner can confirm payment (functional requirement, not just a UI omission; enforce this at the API level).
- Given an invoice has been pending for an extended period, when that happens, then it remains visible to the Owner as outstanding — no automatic assumption of payment or automatic membership activation without an explicit Owner action.

### 8.2 Invoice history
**As a** Member, **I want to** see my past invoices, **so that** I have a record for my own budgeting.
- Given I have past invoices, when I view billing history, then I see date, amount, status (paid/pending), and — for paid invoices — the payment method and when it was recorded.
- I can view only my own invoices, never another Member's (same scoping principle as every other role-specific feature in this document).

---

## 9. Reporting (Owner)

### 9.1 Revenue & attendance reports
**As an** Owner, **I want to** see revenue and attendance summaries, **so that** I can understand how my gym is performing.
- Given I view the reports screen, when it loads, then I see current-period revenue and a check-in trend, both filterable by date range.
- This data is visible only to the Owner of that gym — never to Coaches or Members, and never to another gym's Owner.

---

## 10. Analytics & Advanced Reporting (Owner)

### 10.1 Live business dashboard
**As an** Owner, **I want to** see today's key numbers update in real time, **so that** I have an accurate picture of my gym right now, not as of last night.
- Given I have the dashboard open, when a Member checks in, then the live counter updates without a manual refresh (same behavior as the Phase 5 attendance counter — this is that same mechanism, reused, not rebuilt).
- Given I view the dashboard, when it loads, then I see today's check-ins, today's revenue, and current active-member count — all reflecting activity up to the current moment, not just yesterday's aggregated numbers.

### 10.2 Attendance trend analysis
**As an** Owner, **I want to** see how attendance has changed over time, **so that** I can spot patterns (busy days, slow periods, growth or decline).
- Given I select a date range, when the trend loads, then I see check-in counts per day across that range as a chart, not just a single total.
- Given the range spans a period before this feature existed, when it loads, then I see accurate historical data, not a gap — the underlying attendance data (Phase 5) predates this reporting feature and must be reflected correctly.

### 10.3 Revenue forecasting
**As an** Owner, **I want to** see a projected revenue estimate for the coming weeks/months, **so that** I can plan ahead.
- Given sufficient historical revenue data exists, when I view the forecast, then I see a 30/60/90-day projection along with a clear indication of how it was calculated (a trend line, not just a bare number) — this must never be presented as a guaranteed figure.
- Given I have too little historical data for a meaningful projection (e.g. a brand-new gym), when I view the forecast, then I see an explicit "not enough data yet" state rather than a misleadingly confident number.

### 10.4 Retention & churn prediction
**As an** Owner, **I want to** see which members are at risk of leaving, **so that** I can reach out before they lapse.
- Given a member's check-in frequency has dropped or their membership is nearing expiry without a renewal, when I view the retention list, then they appear on it with a clear, specific reason (not just a bare "at risk" label — functional requirement, not a nice-to-have, since an unexplained risk score isn't actionable).
- Given a member's activity is normal, when I view the retention list, then they don't appear on it — the list should be short and specific enough to act on, not a restatement of the full member list.

### 10.5 Exportable reports
**As an** Owner, **I want to** export any of the above as a file, **so that** I can share it or keep records outside the app.
- Given I choose a report and a date range, when I export it, then I receive a CSV or PDF (my choice) containing exactly that data, scoped to that range.
- Given the export is for another Owner's gym data (attempted via a manipulated request), when that's attempted, then it's rejected — export must respect the same gym-scoping as viewing the data (functional requirements §9.1's scoping rule applies equally to exports).

---

## 11. Staff Role (front-desk / assistant-manager)

### 11.1 Staff onboarding
**As an** Owner, **I want to** invite trusted staff with limited access, **so that** I can delegate front-desk work without giving away financial or management control.
- Given I invite someone with role `staff`, when they approve the invitation, then they get exactly the access defined in architecture doc §2's table — no more, no less.
- Given a Staff invitation is pending, when the invitee views it, then it's presented the same way a Coach or Member invitation is — same approve/decline UI, just a different role label.

### 11.2 Staff day-to-day access
**As a** Staff member, **I want to** check members in and look up their status, **so that** I can do front-desk work.
- Given I check a member in, when the action completes, then it behaves identically to an Owner-performed front-desk check-in (same `AttendanceLog` creation, same membership-status validation from §4.1).
- Given I try to access revenue reports, plan pricing, or staff management, when I attempt it, then I get a permission error — these are Owner-only regardless of how directly I try to reach them (e.g. a manipulated request to a reports endpoint, not just a hidden UI element).
- Given I view the member list, when it loads, then I can see it (name, status, attendance) but have no edit/suspend/remove actions available.

---

## 12. White-Label Branding (Owner)

### 12.1 Logo and brand color
**As an** Owner, **I want to** add my gym's logo and pick a brand color, **so that** the app feels like my gym's, not a generic template.
- Given I upload a logo and set a brand color, when I save, then they appear on the navigation header and the Member's membership badge (per `DESIGN-SYSTEM.md`'s bounded rule) within a normal page load — no separate publish step.
- Given I haven't set a logo/color, when a Member views the app, then a sensible default (the product's own branding) is shown — never a broken image or empty color swatch.
- Given my brand color is set, when I view any primary action button (check-in, "send request," etc.), then it still shows the product's standard `hivis` color, not my brand color — this is enforced by design, not something I can override even if I try (e.g. via a crafted API request setting an unexpected field).

---

## 13. WhatsApp Notifications

### 13.1 Opt-in and delivery
**As a** user of any role, **I want to** optionally receive notifications via WhatsApp, **so that** I don't have to rely on checking the app.
- Given I opt in to WhatsApp notifications, when a notification event fires for me (booking confirmed, membership expiring, announcement, etc.), then I receive it via WhatsApp in addition to the in-app notification — not instead of it.
- Given I haven't opted in, when a notification event fires, then I receive it through the channels already configured (in-app, email, SMS) with no WhatsApp message sent.
- Given I reply to a WhatsApp notification message, when that happens, then nothing is expected to process that reply — this is an outbound-only channel; a two-way WhatsApp inbox is explicitly not part of this feature (architecture doc §6.6).

---

## 14. Branch Management (Phase 16)

### 14.1 Owner manages branches
**As an** Owner, **I want to** add and manage multiple physical locations under my business, **so that** I can run a multi-branch operation from one account.
- Given I create a new branch, when it's saved, then it's immediately available for plan creation, Coach/Staff assignment, and appears in branch-selection pickers throughout the app.
- Given my business has exactly one branch, when I use the app, then nothing about the experience feels different from a single-location setup — the primary branch is used transparently as the default everywhere a branch context is needed (functional requirement: single-branch businesses should never feel like they're using a "lesser" or more complicated version of a multi-branch product).
- Given I deactivate a branch, when that happens, then it stops accepting new check-ins/bookings but its historical data (past attendance, past sessions) remains intact and reportable.

### 14.2 Coach/Staff branch assignment
**As an** Owner, **I want to** assign Coaches and Staff to one or more branches, **so that** their access and schedules make sense for where they actually work.
- Given I assign a Coach to two branches, when they view their schedule, then they see sessions from both, clearly labeled by branch.
- Given a Coach/Staff member is unassigned from a branch, when that happens, then they immediately lose view/action access to that branch's members and attendance — this should be enforced at the API level (a lingering session token shouldn't still grant access), not just hidden from the UI.

### 14.3 Member hub access (the core behavior of this phase)
**As a** Member, **I want to** check in and use my membership at any of my gym's branches, **so that** I'm not restricted to a single location.
- Given I have an active membership (regardless of which branch's plan I originally enrolled in), when I check in at any branch belonging to the same business, then it succeeds and is recorded against that specific branch.
- Given I book a PT session, when I select a branch, then I can choose any branch where at least one Coach is assigned — not just my enrolling branch.
- Given I view my attendance history, when it loads, then check-ins from every branch I've visited appear together, each clearly labeled with which branch it was.

### 14.4 Per-branch pricing
**As an** Owner, **I want to** set different membership prices per branch, **so that** I can reflect real cost differences between locations.
- Given two branches have different plans/prices, when a Member enrolls, then they see and pay the price of the specific branch they're enrolling at — not a business-wide average or their first branch's price.
- Given a Member's enrolling branch's plan changes price after they've already enrolled, when that happens, then their existing membership's price is unaffected until their next renewal — a price change is never retroactively applied to an active membership.

### 14.5 Branch-scoped and business-wide reporting
**As an** Owner, **I want to** see reports either per branch or for my whole business, **so that** I can compare branch performance or understand the business as a whole.
- Given I select a specific branch on the reports screen, when it loads, then every number (attendance, revenue, retention) reflects only that branch.
- Given I select "all branches" / leave no branch filter, when it loads, then the numbers are the business-wide rollup, not just the primary branch's numbers.

---

## Non-functional acceptance criteria (apply across all features above)

- Every screen above must be usable at a 375px viewport with no horizontal scroll and no touch target under 44×44px (per the development roadmap's Phase 1 rules).
- Every role-scoped action (marked "own," "self," or role-specific above) must be backed by a Voter test with both a passing case and a `403` case — a feature isn't done if only the happy path is tested.
- Every user-facing error state (expired OTP, blocked check-in, permission denial) must show a specific, actionable message — never a raw error code or a generic "something went wrong."
