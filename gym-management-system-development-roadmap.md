# Gym Management System — Development Roadmap

**Companion to:** `gym-management-system-architecture.md` (data model, Voters, API design, sequence diagrams — this doc doesn't repeat those, it sequences building them) and `gym-management-system-go-to-market.md` (Phase 9 specifically implements features named in that doc's strategic pillars — each Phase 9 item cross-references which pillar it supports).
**How to use this file:** work top to bottom. Each phase ends with a **Definition of Done** — don't start the next phase until it's checked off. Every phase ships a working vertical slice (backend endpoint + a real, responsive screen), not backend-only work followed by a frontend catch-up at the end.
**Status note:** Phases 0–8 are the core product. Phase 9 builds go-to-market-driven growth features once the core product works, before Billing — see Phase 9's intro for why it's sequenced there. Phase 11 (Analytics & Reporting) is sequenced after Billing (Phase 10) specifically because revenue forecasting needs real invoice history to be meaningful.

---

## Phase 0 — Environment & Tooling Setup

**Goal:** a running backend and frontend, connected, on your machine and in PhpStorm.

- [ ] Install PHP 8.3+, Composer, Docker, Node.js 20+, the Symfony CLI
- [ ] `composer create-project api-platform/api-platform gym-management-system` → `docker compose up -d` (ships Postgres + Mercure + PHP already wired)
- [ ] Scaffold frontend: `npm create vite@latest gym-management-frontend -- --template react-ts`, then add Tailwind CSS
- [ ] PhpStorm: install Symfony Support plugin, set CLI interpreter to the Docker Compose `php` service, add the Postgres data source under the Database tool window, configure Xdebug (port 9003)
- [ ] Confirm `https://localhost/api/docs` loads Swagger UI, and `npm run dev` serves the Vite app locally
- [ ] Set up a shared `.env.example` for both repos (or a monorepo `.env`) so API base URL is never hardcoded in frontend code

**Definition of Done:** frontend dev server makes one successful `fetch()` to a backend health-check endpoint, visible in the browser network tab, with no CORS errors.

---

## Phase 1 — Mobile-First Design Foundation

**Goal:** the visual and responsive rules are decided *before* any real screen is built, so every phase after this is mobile-first by construction, not retrofitted.

**Breakpoint strategy** (Tailwind defaults, used mobile-first — unprefixed classes are the mobile baseline, prefixes are overrides for larger screens):

| Breakpoint | Width | Primary use |
|---|---|---|
| *(base, no prefix)* | 0–639px | Phone — the default for every component |
| `sm:` | ≥640px | Large phone / small tablet portrait |
| `md:` | ≥768px | Tablet — front-desk kiosk, Coach on the gym floor |
| `lg:` | ≥1024px | Owner desktop dashboard, reports |
| `xl:` | ≥1280px | Wide desktop, data-dense tables |

**Rules to lock in now:**
- [ ] Every component is written for the 375px viewport first (iPhone SE — the tightest common target), then widened with `sm:`/`md:`/`lg:` overrides — never the reverse
- [ ] Minimum touch target 44×44px on any interactive element (buttons, nav items, table row actions) — this affects table design specifically: Owner's member table (dense on desktop) needs a card-based mobile equivalent, not a horizontally-scrolled shrunk table
- [ ] Navigation pattern differs by role and by breakpoint: **Member** gets a bottom tab bar on mobile (Check-in, Sessions, Tracking, Notifications) collapsing to a sidebar at `lg:`; **Owner/Coach** get a sidebar from `md:` up (they're more likely on a tablet/desktop at a desk) with a hamburger drawer below that
- [ ] Forms use full-width single-column stacked fields below `md:`, native `<select>`/date pickers (better mobile keyboard/UX than custom widgets) — multi-column forms only appear at `md:` and up
- [ ] Modals become bottom sheets on mobile (slide up, easier one-thumb dismissal) and stay centered dialogs at `md:` and up
- [ ] Build a small shared component library first — `Button`, `Input`, `Select`, `Card`, `BottomSheet`/`Modal`, `NavShell` — before building any feature screen, so every phase reuses the same primitives instead of re-solving responsiveness per screen
- [ ] Performance budget, decided now: target < 200KB JS (gzipped) for the initial Member route — check-in is the single most mobile-critical, most-repeated action in the whole system and likely happens on gym wifi or mobile data at the door

**Definition of Done:** the shared component library renders correctly at 375px, 768px, and 1280px in Storybook (or a simple `/dev/components` route if skipping Storybook), and one real screen (e.g. login) is built entirely from those primitives.

---

## Phase 2 — Auth Foundation (Password + OTP)

**Backend** (architecture doc §6.1, §8.5, §9):
- [ ] `User`, `OtpCode` entities + migration
- [ ] `POST /auth/login`, `POST /auth/otp/request`, `POST /auth/otp/verify`, `POST /auth/refresh`
- [ ] Rate limiting on both OTP endpoints (§9)

**Frontend:**
- [ ] Login screen: single-column, full-width inputs, big tap targets, toggle between "Password" and "Get a code" (OTP) — this toggle is the first real test of the mobile-first rules from Phase 1
- [ ] OTP entry screen: 6 individual digit inputs sized for thumb input, auto-advance focus, paste-to-fill support (SMS autofill on mobile browsers via `autocomplete="one-time-code"`)
- [ ] Token storage: httpOnly cookie preferred over localStorage for the refresh token

**Definition of Done:** can log in via both password and OTP from a 375px viewport with no horizontal scroll, and a protected route redirects to login when the token is missing/expired.

---

## Phase 3 — Invitations & Onboarding

**Backend** (architecture doc §6.7, §8.4, §9.1 `InvitationVoter`):
- [ ] `Invitation` entity + migration, `InvitationVoter`
- [ ] `POST /invitations`, `GET /invitations/me`, `PATCH /invitations/:id/approve|decline`

**Frontend:**
- [ ] Owner: "Invite" flow — a single form (email/phone + role), reachable from a floating action button on mobile, a toolbar button at `md:`
- [ ] Invitee: pending-invitation banner/card shown immediately after login if one exists, with clear Approve/Decline actions sized for thumb tap
- [ ] Empty/success states for both sides (no pending invitations, invitation sent confirmation)

**Definition of Done:** an Owner can invite a test email, that user can log in (via OTP, since they likely have no password yet) and see + approve the invitation, and the Owner sees a live update via Mercure without refreshing.

---

## Phase 4 — Membership Management

**Backend** (§6.2, §5.1 `MEMBERSHIP_PLAN`/`MEMBERSHIP`, `MembershipVoter`):
- [ ] Plan CRUD (Owner), enrollment, pause/cancel
- [ ] `membership.expiring` scheduled job (Symfony Scheduler, §8.3)

**Frontend:**
- [ ] Owner: plan management — card grid on mobile, table at `lg:`
- [ ] Member: "My membership" screen — status, plan, renewal date as a single scannable card, matching the badge concept from the architecture doc's prototype

**Definition of Done:** Owner creates a plan on a phone-width viewport without needing to rotate to landscape; a Member sees accurate plan/status data.

---

## Phase 5 — Attendance (Check-In)

**Backend** (§6.3, §8.1, `AttendanceVoter`):
- [ ] `AttendanceLog` entity, `POST /members/me/checkin`
- [ ] Mercure publish on `attendance.checked_in` for the live Owner counter

**Frontend — the highest-priority mobile screen in the whole system:**
- [ ] Member check-in is one tap, no scrolling, large single button, from the bottom nav's first tab
- [ ] Works offline-tolerant: if the network request fails, show a clear retry state rather than a silent failure (someone standing at the gym door needs immediate, unambiguous feedback)
- [ ] Owner: live attendance counter on the dashboard via Mercure subscription, readable at a glance on a tablet at the front desk

**Definition of Done:** check-in completes in under 2 taps from app open on a real mobile device (not just a resized browser — test on an actual phone at this phase), and the Owner's counter updates without a manual refresh.

---

## Phase 6 — Personal Training Booking

**Backend** (§6.4, §8.2, `PtSessionVoter`):
- [ ] `PtSession` entity, request/accept/decline endpoints, `session.requested`/`session.confirmed` events

**Frontend:**
- [ ] Member: book a session — coach picker + date/time as native inputs on mobile, calendar widget acceptable at `md:`+
- [ ] Coach: schedule view — list/agenda layout on mobile (a full calendar grid doesn't fit 375px usefully), switches to a real calendar grid at `lg:`
- [ ] Accept/decline as swipe actions or clear buttons — avoid tiny icon-only controls

**Definition of Done:** full booking loop (request → coach notified → accept → member notified) works end to end on mobile viewports for both roles.

---

## Phase 7 — Notifications

**Backend** (§6.6, `NotificationVoter`):
- [ ] `Notification` entity, event subscriber turning domain events into rows, Messenger async delivery, Mercure for live push

**Frontend:**
- [ ] Notification bell with unread badge, opens a bottom sheet on mobile / dropdown at `md:`+ (same pattern established in Phase 1)
- [ ] Respect the permission scoping already defined — no UI work needed to filter, just render what the API returns

**Definition of Done:** a notification triggered by another role's action (e.g. Owner announcement) appears in-app within a few seconds via Mercure, with correct read/unread state persisted.

---

## Phase 8 — Personal Tracking

**Backend** (§6.5, `PersonalTrackingVoter`):
- [ ] `WorkoutLog`, `BodyMetric` entities, Member-only CRUD

**Frontend:**
- [ ] Log-a-workout as a short mobile-first form (type, duration — minimal required fields, this needs to be fast to fill in right after a workout)
- [ ] Progress chart: simplified/scrollable on mobile (fewer axis labels, larger touch-friendly data points), full detail at `md:`+

**Definition of Done:** a Member can log a workout in under 30 seconds on mobile and see it reflected in the trend chart immediately.

---

## Phase 9 — Growth & Retention Features

**Goal:** build the product-side support for the go-to-market plan (`gym-management-system-go-to-market.md`) — these aren't generic features, each one maps directly to a named GTM pillar. Inserted here, after the core product is functional (Phases 0–8) and before Billing, because these features are what make the first real customer cohort (GTM §6, the 90-day plan) actually work in practice.

### 9.1 Bulk member import (GTM Pillar A — remove switching cost)

**Backend:**
- [ ] `POST /invitations/bulk` — accepts a CSV (name, email/phone, role) and creates one `Invitation` per row, reusing the existing `Invitation` entity and `InvitationVoter` from Phase 3 as-is. **Do not bypass the invite/approve flow for bulk imports** — every imported person still explicitly approves before their account is active; bulk import only saves the Owner from filling out the single-invite form repeatedly.
- [ ] Row-level validation and a per-row result report (created / duplicate / invalid), since real-world spreadsheets are messy — this is the actual value of the feature, not the CSV parsing itself.

**Frontend:**
- [ ] Owner: CSV upload screen with a preview/mapping step (map spreadsheet columns to name/email/phone/role) before committing, and a clear per-row success/error summary after import.

**Definition of Done:** an Owner can upload a realistic messy spreadsheet (inconsistent columns, some missing phone numbers, a duplicate row) and get a clear report of what was imported vs. what needs fixing — no silent partial failures.

### 9.2 Referral & lead capture (GTM Pillar B — Coach-led growth, Pillar F — Owner referral)

**Backend:**
- [ ] `ReferralLead` entity: submitted by a Coach or Owner, captures a prospective gym's name + contact info + who referred them.
- [ ] `POST /referrals` — any authenticated Coach or Owner can submit a lead. This is deliberately a lightweight capture endpoint (notifies the team, doesn't attempt to auto-provision a new gym) — provisioning a new customer stays a manual/sales step at this stage.
- [ ] `ReferralCode` entity for Owner-to-owner referral: a stable code per Owner, tracked usage count, for the "refer a gym, both get a free month" program (GTM §5 Pillar F). Credit application logic can be a manual admin action initially — don't over-build automated billing credit before Phase 10 (Billing) exists.

**Frontend:**
- [ ] Coach dashboard: a clearly visible "Recommend this to another gym" action — this is the direct product support for GTM Pillar B, and it's a Coach-facing flow specifically, separate from anything Owner-facing.
- [ ] Owner dashboard: a referral screen showing their code/shareable link and the status of gyms they've referred.

**Definition of Done:** a Coach can submit a lead in under 30 seconds from their dashboard, and an Owner can see their referral code and share it — both flows tested on mobile per the standard responsive rules.

### 9.3 Member shareable milestones (GTM Pillar D)

**Backend:**
- [ ] Milestone detection: on relevant events (check-in streaks, workout count thresholds), emit a `member.milestone_reached` event via the existing EventDispatcher pattern — the Notification module (Phase 7) picks this up the same way it picks up every other event, no new coupling needed.
- [ ] Keep the initial milestone set small and data-informed rather than guessed (per the go-to-market doc's §7 note: "don't guess this upfront, let early-cohort usage data inform it") — e.g. start with just check-in streaks, expand once Phase 9's first real users show what they actually respond to.

**Frontend:**
- [ ] Milestone celebration moment (toast/modal) when reached, with a "Share" action.
- [ ] Share generates an image using the `Badge`/`Ticket` visual patterns from `DESIGN-SYSTEM.md` — this should look like it belongs to the same product as the membership badge, not a generic achievement graphic — and uses the native mobile share sheet where available.

**Definition of Done:** a Member hitting a streak threshold sees a celebratory moment and can generate/share an on-brand image in one tap on mobile.

---

## Phase 10 — Billing & Payments (Manual)

**Scope note:** gateway integration (Stripe/PayHere) is deferred — see architecture doc §6.9 for why this is a clean later addition, not a shortcut that creates rework. This phase builds manual payment recording only.

**Backend** (architecture doc §6.9, §9.1's `InvoiceVoter`, §7's `/invoices` endpoints):
- [ ] `Invoice` entity + migration, including `payment_method`, `recorded_by`, `paid_at` per §5.1.
- [ ] Copy `InvoiceVoter` from §9.1 — `VIEW` (Owner: any in-gym; Member: own only) and `MARK_PAID` (Owner only, no exceptions).
- [ ] `PATCH /invoices/:id/mark-paid` — sets `status = paid`, `paid_at = now()`, `recorded_by = <Owner>`, records `payment_method`. This write must hit the audit log (§9) — it's a financial record with no gateway receipt behind it.
- [ ] Apply `ReferralCode` credit (Phase 9.2) here, now that billing exists to apply it to.

**Frontend:**
- [ ] Owner: "Mark as paid" action on a pending invoice — payment method selector (cash/bank transfer), simple confirmation.
- [ ] Member: invoice history — status, amount, and for paid invoices, method + recorded date.
- [ ] Owner: outstanding-invoices view (pending, not yet marked paid) — this is the practical day-to-day screen for this phase, more so than history.

**Definition of Done:** a Member enrolls, gets an invoice, the Owner marks it paid with a method recorded, the Member's membership activates and they're notified, and the action shows up in the audit log — verified end to end on mobile. `InvoiceVoter`'s `MARK_PAID` rejection of a Member attempting it themselves is tested explicitly.

**Deferred to a later phase (not part of this one):** Stripe/PayHere gateway integration, automated webhook-driven payment confirmation, hosted checkout UI. When that's built, per §6.9 it slots in as a second path to `status = paid` without touching `InvoiceVoter`, the entity, or downstream analytics.

---

## Phase 11 — Analytics & Reporting

**Goal:** turn the raw data every prior phase has been generating (attendance, memberships, invoices) into decision-making tools for the Owner. Sequenced after Billing specifically because revenue forecasting needs real `Invoice` history to be meaningful — building this earlier would mean forecasting off zero or fake data.

**Backend** (architecture doc §6.8, §5.1's `DAILY_METRIC_SNAPSHOT`, extended `ReportVoter` in §9.1):
- [ ] `DAILY_METRIC_SNAPSHOT` entity + migration.
- [ ] Nightly Symfony Scheduler job aggregating `ATTENDANCE_LOG`/`MEMBERSHIP`/`INVOICE` into one snapshot row per gym per day. **Backfill historical snapshots from existing data** when this ships — Owners who've been live since Phase 5 need trend history to go back further than "since this feature launched" (functional requirements §10.2's explicit criterion).
- [ ] Extend `ReportVoter` with the `EXPORT` attribute (already written in architecture doc §9.1 — copy it in) so exports are audit-logged separately from ordinary dashboard views.
- [ ] `GET /reports/dashboard`, `/reports/attendance`, `/reports/revenue-forecast`, `/reports/retention`, `/reports/export` per §7.
- [ ] Revenue forecast and churn/retention logic: **rules-based/statistical, not ML** (architecture doc §6.8 explains why) — a weighted moving average for revenue, explainable signals (declining visit frequency, expiry without renewal) for retention risk. Every "at risk" flag must come with a stated reason, not a bare score.
- [ ] Live dashboard numbers (today's check-ins/revenue) reuse the Phase 5 Mercure/Redis pattern directly — don't build a second live-data mechanism.

**Frontend:**
- [ ] Dashboard: live counters (reusing the Phase 5 pattern) alongside the aggregated trend/forecast views.
- [ ] Attendance trend and revenue forecast as charts, date-range filterable — forecast chart must visually distinguish historical data from projection (functional requirements §10.3 — never present a projection as equivalent to actuals).
- [ ] "Not enough data yet" empty state for the forecast when a gym is too new for a meaningful projection.
- [ ] Retention list: each at-risk member shown with their specific reason, not just flagged.
- [ ] Export action: pick a report + date range + format (CSV/PDF), download.
- [ ] All of the above uses `DESIGN-SYSTEM.md`'s existing card/table/chart conventions from earlier Owner-facing screens (Phase 4's plan management, Phase 9's referral screen) — this phase shouldn't need new visual patterns.

**Testing:**
- `ReportVoter`'s `EXPORT` attribute: pass case (Owner, own gym) + `403` (different Owner, or Coach/Member attempting export).
- Every Given/When/Then in functional requirements §10.1–10.5, including the "not enough data" forecast state and the retention list's reason requirement.
- Confirm the nightly aggregation job produces correct backfilled data against a known test dataset before trusting it for real gyms.

**Definition of Done:** an Owner can view live today's-numbers, a multi-week attendance trend, a clearly-projected (not actual) revenue forecast, a retention list with specific reasons per member, and export any of it — all scoped strictly to their own gym, verified by the `403` test case.

---

## Phase 12 — Cross-Device Testing & QA


- [ ] Manual pass on real devices: one iOS Safari phone, one Android Chrome phone, one tablet, one desktop browser — not just DevTools device emulation
- [ ] Automated responsive smoke tests (Playwright) at 375px, 768px, 1280px for the core flows: login, check-in, booking
- [ ] Accessibility pass: color contrast, focus states, screen-reader labels on icon-only buttons (relevant since Phase 1 leans on icon-heavy mobile nav)
- [ ] Performance audit (Lighthouse mobile score) against the Phase 1 budget, especially on the check-in and login routes
- [ ] Role/permission regression pass: for every Voter in the architecture doc, confirm both the "should succeed" and "should get 403" case still hold

**Definition of Done:** Lighthouse mobile performance ≥ 85 on the check-in route, zero critical accessibility violations, all Voter test cases green.

---

## Phase 13 — Staging & Production Deployment

- [ ] Provision a production DigitalOcean Droplet (Bangalore region) running Postgres, Redis, and Mercure alongside the app — self-hosted on the Droplet is the default per §10; only move Postgres to DigitalOcean's Managed Database product if the operational overhead of self-managing it becomes a real problem, not preemptively.
- [ ] Set up a staging environment mirroring production, deployed automatically on merge to a `staging` branch
- [ ] CI pipeline (GitHub Actions): lint → test → build → deploy, blocking merge on failure
- [ ] Domain + HTTPS (Let's Encrypt) — point `setly.fit`'s DNS A record at the Droplet's IP
- [ ] Environment secrets (JWT keys, DB credentials — payment gateway keys once/if that's added, per Phase 10's deferral note) in a secrets manager, never committed
- [ ] Backups: enable DigitalOcean's Droplet backup toggle (daily, ~20% of Droplet cost) for full-server recovery, plus a separate database dump schedule matching §10's guidance — a Droplet backup alone is coarser-grained than a proper DB backup/restore workflow
- [ ] Monitoring: Sentry wired for both frontend and backend errors, an uptime check on the production URL
- [ ] Production smoke test: run the same core flows from Phase 12 against the live production URL before announcing launch

**Definition of Done:** a merge to `main` deploys to production automatically, a synthetic uptime check is green, and the core flows (login, check-in, booking) work on a real phone against the live URL.

---

## Phase 14 — Post-Launch (optional, per architecture doc §11 Phase 4)

- [ ] Mobile app shell (React Native) reusing the same API and component logic patterns established in Phase 1
- [ ] Push notifications (native, replacing/augmenting the in-app Mercure badge)
- [ ] Group class scheduling (`Class`/`ClassBooking` entities)
- [ ] Move file storage from local disk to DigitalOcean Spaces once traffic or multi-instance scaling makes it necessary (§4, §10)

---

## Quick reference — what "mobile-first" means in practice for this project

1. Write the 375px layout first, always. Widen with `sm:`/`md:`/`lg:`, never shrink down from desktop.
2. Every interactive element ≥ 44×44px.
3. Member-facing screens assume one thumb, one hand, possibly bad network (check-in above all).
4. Owner/Coach screens can lean more desktop/tablet since they're more often used at a desk or front counter — but must still not break at 375px.
5. Test on a real phone at the end of every phase that ships a new screen, not just at the end of the project.
