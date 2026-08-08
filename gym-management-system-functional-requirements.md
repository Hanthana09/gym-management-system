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

### 8.1 Enrollment payment
**As a** Member, **I want to** pay for a plan when I enroll, **so that** my membership becomes active.
- Given I complete payment successfully, when the gateway confirms it, then my membership status becomes active and I receive a receipt/notification.
- Given payment fails, when that happens, then my membership stays inactive/pending and I see a clear reason and retry option.

### 8.2 Invoice history
**As a** Member, **I want to** see my past invoices, **so that** I have a record for my own budgeting.
- Given I have past payments, when I view billing history, then I see date, amount, and status for each, with a downloadable/viewable receipt.

---

## 9. Reporting (Owner)

### 9.1 Revenue & attendance reports
**As an** Owner, **I want to** see revenue and attendance summaries, **so that** I can understand how my gym is performing.
- Given I view the reports screen, when it loads, then I see current-period revenue and a check-in trend, both filterable by date range.
- This data is visible only to the Owner of that gym — never to Coaches or Members, and never to another gym's Owner.

---

## Non-functional acceptance criteria (apply across all features above)

- Every screen above must be usable at a 375px viewport with no horizontal scroll and no touch target under 44×44px (per the development roadmap's Phase 1 rules).
- Every role-scoped action (marked "own," "self," or role-specific above) must be backed by a Voter test with both a passing case and a `403` case — a feature isn't done if only the happy path is tested.
- Every user-facing error state (expired OTP, blocked check-in, permission denial) must show a specific, actionable message — never a raw error code or a generic "something went wrong."
