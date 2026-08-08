# Gym Management System — Development Roadmap

**Companion to:** `gym-management-system-architecture.md` (data model, Voters, API design, sequence diagrams — this doc doesn't repeat those, it sequences building them).
**How to use this file:** work top to bottom. Each phase ends with a **Definition of Done** — don't start the next phase until it's checked off. Every phase ships a working vertical slice (backend endpoint + a real, responsive screen), not backend-only work followed by a frontend catch-up at the end.

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

## Phase 9 — Billing & Payments

**Backend:**
- [ ] `Invoice` entity, payment gateway integration (Stripe/PayHere), webhook handler for payment confirmation

**Frontend:**
- [ ] Payment flow using the gateway's mobile-optimized hosted checkout (don't hand-roll card input UI — use Stripe Elements or equivalent, which are already mobile-tested)
- [ ] Invoice history as a simple list, receipts downloadable/viewable inline

**Definition of Done:** a full enroll → pay → active-membership loop completes on a real mobile device including the payment step.

---

## Phase 10 — Cross-Device Testing & QA

- [ ] Manual pass on real devices: one iOS Safari phone, one Android Chrome phone, one tablet, one desktop browser — not just DevTools device emulation
- [ ] Automated responsive smoke tests (Playwright) at 375px, 768px, 1280px for the core flows: login, check-in, booking
- [ ] Accessibility pass: color contrast, focus states, screen-reader labels on icon-only buttons (relevant since Phase 1 leans on icon-heavy mobile nav)
- [ ] Performance audit (Lighthouse mobile score) against the Phase 1 budget, especially on the check-in and login routes
- [ ] Role/permission regression pass: for every Voter in the architecture doc, confirm both the "should succeed" and "should get 403" case still hold

**Definition of Done:** Lighthouse mobile performance ≥ 85 on the check-in route, zero critical accessibility violations, all Voter test cases green.

---

## Phase 11 — Staging & Production Deployment

- [ ] Provision production Postgres, Redis, and Mercure (managed or self-hosted per §4/§10)
- [ ] Set up a staging environment mirroring production, deployed automatically on merge to a `staging` branch
- [ ] CI pipeline (GitHub Actions): lint → test → build → deploy, blocking merge on failure
- [ ] Domain + HTTPS (Let's Encrypt or platform-managed TLS)
- [ ] Environment secrets (JWT keys, DB credentials, payment gateway keys) in a secrets manager, never committed
- [ ] Backups: database on a daily schedule, uploads directory backed up on the same schedule (per the local-storage note in the architecture doc §10)
- [ ] Monitoring: Sentry wired for both frontend and backend errors, an uptime check on the production URL
- [ ] Production smoke test: run the same core flows from Phase 10 against the live production URL before announcing launch

**Definition of Done:** a merge to `main` deploys to production automatically, a synthetic uptime check is green, and the core flows (login, check-in, booking) work on a real phone against the live URL.

---

## Phase 12 — Post-Launch (optional, per architecture doc §11 Phase 4)

- [ ] Mobile app shell (React Native) reusing the same API and component logic patterns established in Phase 1
- [ ] Push notifications (native, replacing/augmenting the in-app Mercure badge)
- [ ] Group class scheduling (`Class`/`ClassBooking` entities)
- [ ] Move file storage from local disk to S3 once traffic or multi-instance scaling makes it necessary (§4, §10)

---

## Quick reference — what "mobile-first" means in practice for this project

1. Write the 375px layout first, always. Widen with `sm:`/`md:`/`lg:`, never shrink down from desktop.
2. Every interactive element ≥ 44×44px.
3. Member-facing screens assume one thumb, one hand, possibly bad network (check-in above all).
4. Owner/Coach screens can lean more desktop/tablet since they're more often used at a desk or front counter — but must still not break at 375px.
5. Test on a real phone at the end of every phase that ships a new screen, not just at the end of the project.
