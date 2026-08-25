# gym-management-billing-v1.md

**Phase:** 10 — Recurring Billing & Check-in Gating
**Status:** Locked spec, ready for Claude Code prompt generation
**Depends on:** gym-management-system-architecture.md, gym-management-system-functional-requirements.md, existing `AttendanceSourceInterface` abstraction, existing `MembershipPlan` entity

---

## 1. Purpose

Introduce recurring monthly billing for member subscriptions, with strict enforcement: a member cannot check in unless their account is current. No partial payments are supported — an invoice is either fully paid or it isn't.

Read order before implementation:
1. This document (data model + business logic, authoritative)
2. `gym-management-system-architecture.md` (entity relationship conventions, Voter patterns)
3. `gym-management-system-functional-requirements.md` (role definitions: Owner, Coach, Staff, Member)
4. Existing `AttendanceSourceInterface` implementation (QR check-in, biometric bridge stub)

---

## 2. Scope

**In scope:**
- Recurring invoice generation from an active subscription
- Full-amount-only payment recording
- `Absent` invoice status for missed cycles
- Subscription suspension (stops future invoice generation)
- Check-in gating based on billing status
- Owner-only billing cycle anchor reset

**Explicitly out of scope for this phase (hard exclusions):**
- Partial payments of any kind
- Payment gateways / automated card charging (manual recording only)
- Physical/hardware check-in enforcement (turnstiles, door locks) — blocking is system-level only; a QR or staff check-in attempt is refused in software, nothing physically stops entry
- Late fees, proration, or interest on overdue amounts
- Reopening an `Absent` invoice back to `Pending`
- Automatic backfill of missed invoices when a suspended subscription is reactivated
- Owner-facing analytics/reporting on billing (belongs to Phase 11)
- Any `resetBillingCycle` permission for Coach, Staff, or Member roles

Do not implement anything from the "out of scope" list even if it seems like a natural extension. Stop at the boundary of this spec.

---

## 3. Data model

### 3.1 `MemberSubscription` (new)

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `member` | FK → Member | |
| `plan` | FK → MembershipPlan | existing entity |
| `branch` | FK → Branch | |
| `status` | enum | `ACTIVE`, `PAUSED`, `SUSPENDED`, `CANCELLED` |
| `billingAnchorDay` | int (1–31) | day-of-month the cycle renews on |
| `startDate` | date | |
| `nextBillingDate` | date | derived, advances each generation cycle |
| `createdAt` / `updatedAt` | datetime | |

- Only `ACTIVE` subscriptions are picked up by the invoice generation command.
- `PAUSED` is reserved for voluntary holds (not defined further in this phase — nullable/unused state, do not build pause UI yet).
- `SUSPENDED` is an Owner/Staff enforcement action (see §5.4).

### 3.2 `Invoice` (new)

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `subscription` | FK → MemberSubscription | |
| `member` | FK → Member | denormalized for query convenience |
| `branch` | FK → Branch | |
| `periodStart` | date | |
| `periodEnd` | date | |
| `dueDate` | date | equals `periodStart` unless a grace period is configured later |
| `amount` | decimal | copied from plan price at generation time |
| `status` | enum | `PENDING`, `PAID`, `ABSENT` |
| `paidAt` | datetime, nullable | |
| `markedAbsentAt` | datetime, nullable | |
| `markedAbsentBy` | FK → User, nullable | null if system-generated transition |
| `createdAt` | datetime | |

**Unique constraint:** `(subscription_id, periodStart)` — makes invoice generation idempotent; re-running the command never double-bills.

### 3.3 `Payment` (new)

| Field | Type | Notes |
|---|---|---|
| `id` | uuid | |
| `invoice` | FK → Invoice | |
| `amount` | decimal | must equal `invoice.amount` exactly — no partials |
| `method` | enum | `CASH`, `CARD`, `BANK_TRANSFER` |
| `recordedBy` | FK → User | staff or owner who recorded it |
| `paidAt` | datetime | |
| `resetBillingCycle` | bool | audit flag — was the anchor-day reset applied on this payment |
| `note` | text, nullable | |

---

## 4. State machines

### 4.1 Invoice lifecycle

```
PENDING → PAID     (payment recorded, full amount, any time before next cycle's generation)
PENDING → ABSENT   (exactly once: when the next cycle's invoice is generated and this one is still unpaid)
ABSENT  → PAID     (late payment recorded — no intermediate reopening to PENDING)
```

- `ABSENT` is a **one-time, precise transition** tied to the next cycle's generation event — it does not creep in gradually as time passes, and nothing else auto-marks it absent mid-cycle.
- There is no `ABSENT → PENDING` transition, ever.
- There is no partial-payment status. `amount != invoice.amount` is a hard rejection, not a partial credit.

### 4.2 Subscription lifecycle

```
ACTIVE → PAUSED       (not built out this phase — status exists, no triggering UI yet)
ACTIVE → SUSPENDED    (Owner/Staff action)
SUSPENDED → ACTIVE    (reactivation — see §5.4)
ACTIVE → CANCELLED    (terminal)
```

- Only `ACTIVE` subscriptions get invoices generated.
- Suspending a subscription does **not** touch existing invoices — any `PENDING` or `ABSENT` invoices remain on record as unpaid debt. There is no auto-waive step in this phase.

---

## 5. Business logic

### 5.1 Invoice generation command

`app:billing:generate-invoices`, run daily via host cron (reuse the same cron slot planned for the Phase 11 `DAILY_METRIC_SNAPSHOT` job — do not add a second cron entry).

```
For each subscription where status = ACTIVE and nextBillingDate <= today:
    1. If a PENDING invoice exists for the just-ended period → mark it ABSENT
       (set markedAbsentAt = now, markedAbsentBy = null)
    2. Create a new PENDING invoice for the new period:
       periodStart = nextBillingDate
       periodEnd   = nextBillingDate + cycleLength - 1 day
       dueDate     = periodStart
       amount      = plan.price
    3. Advance nextBillingDate:
       new anchor month, same billingAnchorDay
       clamp to the last day of the month if billingAnchorDay exceeds it
       (e.g. anchor day 31 in a 30-day month → last day of that month)
```

Exactly one invoice — the immediately preceding one — is touched per cycle boundary per subscription. The command must be safe to re-run (idempotent via the unique constraint in §3.2).

### 5.2 Payment recording

`POST /api/v1/invoices/{id}/payments`

```json
{
  "amount": 5000,
  "method": "cash",
  "resetBillingCycle": false,
  "note": null
}
```

Logic:
1. Reject if `invoice.status == PAID` (409 — already settled).
2. Reject if `amount != invoice.amount` (422 — "Partial payments are not supported. Enter the full amount due.").
3. Reject if `resetBillingCycle == true` and the requester is not `ROLE_OWNER` (403 — see §5.3). Do not silently downgrade to `false`; fail the whole request.
4. Mark invoice `PAID`, set `paidAt`.
5. If `resetBillingCycle == true`:
   - `subscription.billingAnchorDay = day(paidAt)`
   - `subscription.nextBillingDate = paidAt + cycleLength`, clamped per §5.1
   - This is a **permanent anchor-day change**, not a one-time nudge — every subsequent cycle renews on the new day until reset again.
6. If `resetBillingCycle == false` (default): the subscription's schedule is untouched. The invoice just paid is settled; `nextBillingDate` and `billingAnchorDay` remain exactly as before.

This logic is identical whether the invoice being paid is `PENDING` or `ABSENT`.

### 5.3 Permission: billing cycle reset is Owner-only

```php
class InvoicePaymentVoter extends Voter
{
    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        if ($attribute === 'RESET_BILLING_CYCLE') {
            return $this->security->isGranted('ROLE_OWNER');
        }
        // existing RECORD_PAYMENT branch scoping unaffected
    }
}
```

- Backend Voter is the real gate. The frontend must not render the `resetBillingCycle` checkbox at all for Staff-role sessions — but that's a UX nicety, not the enforcement mechanism.
- Coach and Member roles have no access to this endpoint at all (existing branch/role scoping applies).

### 5.4 Suspension

`PATCH /api/v1/subscriptions/{id}/suspend` — Owner or Staff, branch-scoped like other staff actions.

- Sets `subscription.status = SUSPENDED`.
- The generation command skips it from that point forward — no new invoices.
- Existing `PENDING`/`ABSENT` invoices are left as-is (not waived, not auto-paid).

`PATCH /api/v1/subscriptions/{id}/reactivate` — Owner or Staff.

- Sets `subscription.status = ACTIVE`.
- `nextBillingDate` resets to the reactivation date (not backfilled) — the generation command will not attempt to create invoices for the months the member was suspended.
- `billingAnchorDay` is unchanged unless a fresh payment with `resetBillingCycle: true` occurs afterward.

### 5.5 Check-in eligibility

New service, consumed by every implementation of `AttendanceSourceInterface` (QR, staff manual check-in, future biometric bridge) — implemented once, not duplicated per source.

```php
interface CheckInEligibilityChecker
{
    public function checkEligibility(Member $member, Branch $branch): EligibilityResult;
}
```

```
1. subscription.status != ACTIVE  → blocked, reason: 'subscription_inactive'
2. any invoice with status = ABSENT → blocked, reason: 'absent_invoice'
3. any invoice with status = PENDING and dueDate < today → blocked, reason: 'overdue'
4. otherwise → allowed
```

Notes:
- Rule 3 is evaluated live on every check-in attempt — it is **not** dependent on the nightly generation command having run yet. A member whose due date passed yesterday is blocked today even though the invoice is still formally `PENDING` in the database (it only becomes `ABSENT` once the next cycle's generation runs).
- A `PENDING` invoice **before** its due date does not block check-in — the member is current until the due date actually passes.
- Blocking is system-level only. The QR check-in page shows a "payment due" state instead of a success state; a staff-facing manual check-in screen shows the same reason as a blocking banner. Nothing prevents physical entry in this phase.

---

## 6. API endpoints (new)

| Method | Path | Role | Notes |
|---|---|---|---|
| POST | `/api/v1/invoices/{id}/payments` | Owner, Staff (branch-scoped) | `resetBillingCycle` Owner-only |
| GET | `/api/v1/members/{id}/billing-status` | Owner, Staff, Coach (own branch), Member (self) | returns current subscription status + outstanding invoices |
| PATCH | `/api/v1/subscriptions/{id}/suspend` | Owner, Staff (branch-scoped) | |
| PATCH | `/api/v1/subscriptions/{id}/reactivate` | Owner, Staff (branch-scoped) | |
| GET | `/api/v1/branches/{id}/invoices?status=absent,overdue` | Owner, Staff (own branch) | powers the dashboard "needs attention" widget |

`billing-status` response shape:
```json
{
  "subscriptionStatus": "active",
  "eligibleForCheckIn": false,
  "blockReason": "overdue",
  "outstandingInvoices": [
    { "id": "...", "periodStart": "2026-08-03", "dueDate": "2026-08-03", "amount": 5000, "status": "pending" }
  ]
}
```

---

## 7. Frontend tasks

- **Payment recording modal** (Owner/Staff): amount pre-filled and locked to `invoice.amount` (no free-entry field — reinforces no-partial-payment rule at the UI level too). `resetBillingCycle` checkbox rendered only for Owner-role sessions, unchecked by default.
- **Member profile**: badge showing outstanding amount and block reason (`absent`, `overdue`, `suspended`) in ember (`#E8611F`).
- **Owner/Staff dashboard**: "needs attention" widget listing members with `absent` or `overdue` invoices, sorted oldest-first, with a quick "record payment" action — consistent with the existing dashboard philosophy (no redundant sidebar re-links).
- **QR check-in page**: on blocked eligibility, render the reason in plain language ("Payment due since 3 Aug") instead of a green checked-in state.
- **Member's own dashboard**: current billing status and next due date.

---

## 8. Negative / 403 test cases (required before phase sign-off)

- [ ] Staff submits `resetBillingCycle: true` → 403, invoice remains unpaid, no anchor change
- [ ] Payment `amount` less than or greater than `invoice.amount` → 422, invoice stays `PENDING`/`ABSENT`
- [ ] Payment attempted on an already-`PAID` invoice → 409
- [ ] Check-in attempt with `SUSPENDED` subscription → blocked, reason `subscription_inactive`
- [ ] Check-in attempt with an `ABSENT` invoice on record → blocked, reason `absent_invoice`
- [ ] Check-in attempt with a `PENDING` invoice past `dueDate` → blocked, reason `overdue`
- [ ] Check-in attempt with a `PENDING` invoice before `dueDate` → allowed
- [ ] Staff attempts `record payment` / `suspend` / `reactivate` for a branch they're not assigned to → 403
- [ ] Re-running `app:billing:generate-invoices` twice in one day → no duplicate invoices (unique constraint holds)
- [ ] Subscription with `billingAnchorDay = 31` rolling into a 30-day or 28/29-day month → clamps to last day, no crash
- [ ] Reactivating a subscription suspended for 3 months → exactly one invoice generated going forward, no backfill of the missed 3 cycles
- [ ] Payment on an `ABSENT` invoice with `resetBillingCycle: true` → invoice marked `PAID`, anchor day and `nextBillingDate` updated correctly, no attempt to reopen the invoice as `PENDING` first

---

## 9. Verification checklist / stop conditions

- [ ] All entities migrate cleanly as additive, nullable-where-new columns — no changes to existing serialization groups or endpoints
- [ ] Backend test suite (including §8) passes in full
- [ ] `CheckInEligibilityChecker` is called from all existing `AttendanceSourceInterface` implementations — verify no source bypasses it
- [ ] Manual smoke test: create a subscription, let a cycle lapse unpaid, confirm check-in blocks exactly at the due date (not before, not only after the next cycle's generation)
- [ ] Manual smoke test: Owner-only reset — pay late with the box checked, confirm all future invoices land on the new anchor day, not just the next one

**Stop here.** Do not proceed to Phase 11 analytics, late fees, proration, or pause/resume UI — those are separate, unlocked specs.
