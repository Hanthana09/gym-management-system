# Setly — Phase: Dashboard Redesign (Multi-Branch + Notifications)

**Status:** Locked — supersedes the earlier single-branch-per-coach version of this spec
**Depends on:** existing User/Gym/Branch/Membership/AttendanceLog/PTBooking/Payment entities; existing Notification system (currently has a routing conflict — fixed as Phase 0 below)
**Touches:** web app only (React/TS/Tailwind). Coach mobile app (Expo) is a separate future phase — not in scope here.

---

## 0. What changed since the last version

- **Coaches and Staff can now work across multiple branches**, not just one. The dashboard, its queries, and its Voters must support a coach/staff member who is scoped to a *set* of branches, the same way Owner already handles multi-branch and Member already handles hub-wide multi-branch.
- **Members remain hub-scoped across branches** (unchanged) — but the Member home view must now show *which* branch each session/check-in happened at, since "multi-branch" was previously implemented in the data model but not surfaced in the UI.
- **Notifications are now part of every dashboard**: a notification bell in the top bar, an unread count, a panel listing recent notifications, and a mark-as-read action — reusing the existing WhatsApp/Email/SMS notification infrastructure's in-app record, not building a new channel.
- **All four dashboards must be mobile-responsive.** Owners check gym numbers from their phone between sets on the gym floor; Coaches and Staff live on mobile during working hours; Members almost exclusively use mobile. This is not a "nice to have" pass at the end — every component built in Phase 2 must be responsive from the start, and Phase 4 has an explicit mobile QA pass per view.

This is one phase, built in six sequential sub-phases. Each sub-phase has its own stop condition — do not skip ahead.

---

## Phase 0 — Fix the notification routing conflict (blocking prerequisite)

Nothing in Phase 3 onward (notification bell) can be built reliably until this is resolved.

**Problem:** two colliding implementations — an API Platform auto-generated resource at `/api/api/v1/notifications` and a hand-written controller at `/notifications`.

**Tasks:**
- [ ] Inspect both implementations. Decide the canonical shape: API Platform resource for CRUD (list/read/mark-read via PATCH), hand-written controller only for anything API Platform genuinely can't express (e.g. a bulk "mark all read" action, or a lightweight unread-count endpoint if that shouldn't be a full resource operation).
- [ ] Fix the double `/api/api/` prefix — check `config/packages/api_platform.yaml` (bundle config) vs `config/routes/api_platform.yaml` (routing import), per the project's known pitfall on this exact confusion.
- [ ] If the hand-written controller survives, confirm it has a class-level `#[Route]` attribute and does not collide with the API Platform path.
- [ ] Land on one final path scheme, e.g.: `GET /api/v1/notifications` (list, API Platform), `PATCH /api/v1/notifications/{id}` (mark read, API Platform), `POST /api/v1/notifications/mark-all-read` (hand-written), `GET /api/v1/notifications/unread-count` (hand-written, lightweight).
- [ ] Write a regression test hitting each final path to confirm no collision remains.

**Stop condition:** one unambiguous set of notification endpoints exists, documented, with no routing collisions. Do not proceed to Phase 1 until this is true.

---

## Phase 1 — Multi-branch data model & scoping

**Data model:**
- Confirm (inspect codebase first) how Coach/Staff-to-branch assignment is currently modeled. If it's a single nullable `branch_id` on `User`, this phase changes it to a many-to-many join (`user_branch_assignment` or similar: `user_id`, `branch_id`, `role_context`). If a join table already exists but is unused by the dashboard, reuse it — do not create a second one.
- `Membership`/`AttendanceLog`/`PTBooking` already carry `branch_id` — no change needed there, they're the source of truth for "which branch did this happen at."
- No change to Member's hub-scoping — it already spans branches; this phase only makes that visible in the UI (Phase 4).

**Backend tasks:**
- [ ] Migration for the coach/staff-branch join table (or repurpose the existing one).
- [ ] Update `CoachVoter`/`StaffVoter` (or equivalent) so branch-scoped checks test membership in the assigned-branches set, not equality against a single `branch_id`.
- [ ] Update any existing coach/staff-scoped queries elsewhere in the app (not just the dashboard) that assumed a single branch — grep for `->getBranch()` / `branch_id =` usage on User and flag any that need the same fix. Fixing those is in scope if it's a one-line change to use the new relation; if it's a larger refactor, note it and leave it for a follow-up phase instead of scope-creeping this one.

**Stop condition:** a coach or staff user can be assigned to 2+ branches in the DB and existing branch-scoped authorization correctly allows/denies based on the full set, verified by tests.

---

## Phase 2 — Shared components: branch selector + notification bell (mobile-first)

Extend the shared component library (`src/components/dashboard/`) from the original phase with two new reusable pieces used across all four role views. Build every component in this phase — and re-check every component from the original phase (`KpiCard`, `AlertCard`, `ChartCard`, `AttendanceHeatmap`, `ActivityFeed`, `BranchBar`/`ProgressBar`) — at three breakpoints: desktop (≥1024px), tablet (768–1023px), phone (≤767px). Mobile is not an afterthought pass; design each component against a phone viewport first, then confirm it holds up wider.

- [ ] `BranchSelector` — generalize the existing Owner-only branch filter into a shared component. For Owner: shows all branches under the gym. For Coach/Staff: shows only their assigned branches (hidden entirely if they only have one). For Member: not shown as a filter, but branch appears as a small inline label per item instead (see Phase 4). On phone widths, render as a full-width dropdown/sheet, not a squeezed inline select.
- [ ] `NotificationBell` — icon + unread badge in the top bar, opens a `NotificationPanel`. On desktop/tablet this can be a dropdown; on phone widths it should be a full-height slide-over or bottom sheet (a small dropdown is unusable at phone width with a touch keyboard/thumb reach). Lists recent notifications with type icon, message, timestamp, and read/unread state, plus a "mark all read" action.
- [ ] Sidebar navigation: on phone/tablet widths, the sidebar collapses to a hamburger-triggered slide-in drawer (with a dimmed overlay behind it) rather than staying pinned — it should not permanently consume horizontal space on a phone screen. This applies to all four role views identically.
- [ ] KPI strips: reflow from the desktop's single row into 2 columns on tablet and phone rather than horizontally scrolling or shrinking numbers illegibly.
- [ ] Two-column layouts (`grid`, `grid-3`) collapse to a single column below 900px.
- [ ] All tap targets (nav items, branch selector, notification bell, alert cards) are at least 44×44px on touch viewports.
- [ ] Both components are role-agnostic — they take data/props, they don't know which role is rendering them.

**Stop condition:** every component from this phase and the original phase render correctly with mock data at all three breakpoints, in isolation, before being wired into any dashboard.

---

## Phase 3 — Backend: dashboard endpoints updated for multi-branch + notifications

- [ ] `GET /api/v1/dashboard/owner?branch={id}` — unchanged behavior, still gym-wide or single-branch filter.
- [ ] `GET /api/v1/dashboard/staff?branch={id}` — **updated**: `branch` is now required if the staff member has more than one assigned branch, optional (defaults to their only branch) if they have exactly one. Reject any branch id not in their assigned set with 403.
- [ ] `GET /api/v1/dashboard/coach?branch={id}` — **updated**, same rule as staff: multi-branch coaches must pass a `branch` param scoped to one of their own branches; single-branch coaches get it defaulted. All session/utilization/member data in the response is filtered to that one branch per request — do not silently merge multiple branches into one aggregate, the coach should explicitly switch branches via `BranchSelector` to see each one's data.
- [ ] `GET /api/v1/dashboard/member` — **updated**: response now includes a `branch` field (name, not just id) on every session and attendance record in the payload, since a member's history can span branches under their hub.
- [ ] Add to each of the four DTOs: `unreadNotificationCount: int`.
- [ ] Add `GET /api/v1/dashboard/notifications/recent?limit=10` (or reuse the canonical notifications list endpoint from Phase 0 with a `limit` param) — used to populate the `NotificationPanel`. Must return only the authenticated user's own notifications regardless of role.
- [ ] Re-run the index plan from the original spec (`AttendanceLog(branch_id, created_at)`, `Membership(branch_id, end_date)`, `PTBooking(coach_id, scheduled_at)`) and confirm it still covers these updated queries; add `PTBooking(branch_id, scheduled_at)` if coach queries now filter by branch as well as coach id.

**Stop condition:** all four endpoints pass the negative/permission tests in section 6, including the new multi-branch cases.

---

## Phase 4 — Frontend: role dashboards updated for multi-branch + notifications

- [ ] Add `NotificationBell` to the top bar on **all four** role home views (Owner, Staff, Coach, Member) — identical placement and behavior everywhere.
- [ ] Owner home view: unchanged from the original spec, `BranchSelector` already present.
- [ ] Staff home view: add `BranchSelector` to the top bar — hidden if the staff member has only one branch (don't show a selector with one option). All widgets (check-ins, expiring memberships, activity feed) re-fetch scoped to the selected branch.
- [ ] Coach home view: add `BranchSelector` to the top bar — same hide-if-single-branch rule. Today's session list, assigned-members count, and weekly utilization bar are scoped to whichever branch is selected; switching branches re-fetches, it does not blend data from both.
- [ ] Member home view: no selector, but every session row (next PT session) and every attendance entry now shows a small branch label (e.g. "Colombo 07" as a muted inline tag) so a member training at two branches can tell them apart at a glance. The attendance streak grid stays branch-agnostic (it's about the member's overall consistency, not a per-branch metric).
- [ ] Confirm the old shortcut-tile grids are still removed on all four views (regression check from the original phase — don't let them creep back in during this update).
- [ ] Mobile QA pass on each of the four views individually, at phone width (≤767px): sidebar collapses to the hamburger drawer, KPI strip reflows to 2 columns, charts/heatmap remain readable (horizontal scroll is acceptable for the heatmap only, not for KPIs or session lists), the Coach session list and Member session/attendance rows remain legible without horizontal overflow, and the notification panel opens as a full-height sheet rather than a cramped dropdown.

**Stop condition:** a coach or staff test account seeded with 2 branch assignments can switch between them via `BranchSelector` and see correctly-scoped data on every widget, at both desktop and phone width; a member test account seeded with attendance at 2 branches sees both, correctly labeled, at both widths.

---

## Phase 5 — Verification (whole phase, all sub-phases)

- [ ] All Phase 0 regression tests pass — no notification routing collision.
- [ ] All four dashboard endpoints respond in <500ms against a seeded dataset (~300 members, 2 branches, at least one coach and one staff account assigned to both branches).
- [ ] No N+1 queries on any endpoint, including the new notification-count and notification-list calls.
- [ ] All negative/permission tests in section 6 pass, including new multi-branch cases.
- [ ] All four home views render correctly with zero data, with single-branch coach/staff accounts, and with multi-branch coach/staff accounts.
- [ ] Notification bell shows correct unread count and updates after "mark all read" on all four views.
- [ ] Member view correctly labels branch per session/attendance entry.
- [ ] Design tokens still match exactly across all four views (fonts, lime/ember/coach-green accent rules unchanged by this update).
- [ ] Sidebar nav unchanged (as content) — only its responsive presentation (drawer vs. pinned) changes by breakpoint.
- [ ] All four views manually tested at 375px (small phone), 768px (tablet), and 1440px (desktop) widths with no horizontal overflow, no clipped text, and all tap targets ≥44×44px.

---

## 6. Negative / permission test cases (full list, including new ones)

- Staff calling `/dashboard/owner` → 403.
- Coach calling `/dashboard/staff` → 403.
- Member calling `/dashboard/coach` or `/dashboard/staff` → 403.
- Coach A cannot see Coach B's sessions via any parameter on `/dashboard/coach`.
- Owner passing a branch id from a different gym → 403.
- Unauthenticated request to any of the four dashboard endpoints, or to the notifications endpoints → 401.
- **New:** Multi-branch coach passing a `branch` id they are *not* assigned to → 403.
- **New:** Single-branch coach omitting `branch` param → succeeds, defaults to their one branch.
- **New:** Multi-branch coach omitting `branch` param → 400 (must choose explicitly, no silent default when ambiguous).
- **New:** Staff requesting notifications → only ever receives their own, never another user's, regardless of shared branch.
- **New:** Member requesting `/dashboard/member` sees sessions/attendance across both their branches in one response (this endpoint stays hub-wide, unlike coach/staff) — confirm this is NOT accidentally branch-filtered by a leftover `branch` param.

## 7. Hard exclusions — still do not build in this phase

- No new `Payment`/`Billing` entity (Phase 10 elsewhere in the roadmap).
- No coach mobile app (Expo) work of any kind.
- No Redis caching layer for dashboard queries.
- No customizable/drag-and-drop dashboard layouts.
- No CSV/PDF export of dashboard data.
- No new notification *channels* (WhatsApp/Email/SMS already exist) — this phase only surfaces existing in-app notification records in the dashboard UI.
- No changes to sidebar navigation structure.
