# CLAUDE.md — Gym Management System

This file is the entry point for Claude Code working in this repository. Read it before writing any code, and re-check the relevant section before starting each phase.

## What this project is

A single-gym management platform with three roles — Owner, Coach, Member — covering membership management, attendance, personal training, personal tracking, invitations/approval-based onboarding, OTP + password login, and notifications. Mobile-first responsive design throughout; Member-facing screens especially (check-in above all) must work well on a phone.

## The source-of-truth documents

Don't improvise architecture, feature behavior, sequencing, or visual design — they're already decided. Use these documents in this order for every phase:

1. **`gym-management-system-development-roadmap.md`** — tells you **what phase you're on and in what order**. Work through its phases sequentially (Phase 0 → Phase 16). Do not start a phase's frontend work before its backend is done, and do not start the next phase until the current phase's "Definition of Done" checklist is fully met.
2. **`gym-management-system-functional-requirements.md`** — tells you **exactly how each feature must behave**. Every acceptance criterion (Given/When/Then) in the relevant section should have a corresponding test before you consider that piece of the phase done. If a feature's behavior is ambiguous, this doc is the tiebreaker before you guess.
3. **`gym-management-system-architecture.md`** — tells you **how to build it**: entity definitions and the ER diagram (§5), tech stack (§4), full Voter class bodies (§9.1 — copy these, don't reinvent the permission logic), REST endpoint list (§7), and sequence diagrams for the non-obvious flows (§8). The Voters in §9.1 are written out in full — use them as-is unless a functional requirement forces a change.
4. **`DESIGN-SYSTEM.md`** — tells you **what it should look like**: color tokens, typography, and component patterns (Card, Ticket, Badge, tag/pill, button variants). Use these tokens and patterns for every screen; don't invent new visual styles ad hoc. If a UI need doesn't fit an existing pattern, flag it rather than improvising one.
5. **`gym-management-system-go-to-market.md`** — relevant specifically for Phase 9 (Growth & Retention Features), which implements features named directly in this doc's strategic pillars. Not needed for other phases.

**Working pattern per phase:** read the phase's section in the roadmap → read the matching feature's acceptance criteria in the functional requirements doc → find the matching entities/Voters/endpoints in the architecture doc → build UI from `DESIGN-SYSTEM.md`'s existing patterns → implement → write tests against the acceptance criteria → check off the roadmap's Definition of Done → move on.

## Tech stack (see architecture doc §4 for the full table and rationale)

- Backend: PHP 8.3+, Symfony 7.4 LTS, API Platform, Doctrine ORM
- Auth: Symfony Security + LexikJWTAuthenticationBundle, Symfony Voters for RBAC
- Async/scheduling: Symfony Messenger, Symfony Scheduler
- Realtime: Mercure
- Database: PostgreSQL, cache/queue: Redis
- File storage: local disk via Flysystem (local adapter) for now — see architecture §4/§10 before touching storage code; don't add DigitalOcean Spaces (or any cloud storage) code speculatively
- Frontend: React + TypeScript, Tailwind CSS, TanStack Query
- Mobile-first responsive rules: see roadmap Phase 1 — build every component at 375px first, widen with `sm:`/`md:`/`lg:`

## Conventions

- **Entities:** one Doctrine entity per row in architecture doc §5.1's ER diagram. Match field names and types exactly as specified there unless a migration reason forces a change — and if it does, update the architecture doc too.
- **Permissions:** every non-trivial endpoint gets a Voter, not an inline role check in the controller. Copy the Voter bodies directly from architecture doc §9.1 where one already exists for that entity.
- **API resources:** prefer API Platform's `#[ApiResource]` with a `security:` expression referencing the Voter attribute (see the "Usage" example at the end of architecture doc §9.1) over hand-written CRUD controllers, unless the functional requirements need response shaping API Platform can't express cleanly.
- **Events:** business-logic modules emit domain events (`membership.expiring`, `session.confirmed`, etc. — named in architecture doc §6 and §8's sequence diagrams) via Symfony's EventDispatcher. The Notification module only ever subscribes to events — it should never be called directly by other modules.
- **Frontend components:** build from the shared primitives established in roadmap Phase 1 (`Button`, `Input`, `Select`, `Card`, `BottomSheet`/`Modal`, `NavShell`). Don't create one-off styled components for a single screen if an existing primitive covers it.
- **Commits:** one phase (or a clearly-scoped sub-piece of a large phase) per logical commit/PR. Reference the phase number and the functional requirement section in the commit message, e.g. `feat(attendance): self check-in endpoint + mobile UI (Phase 5, FR §4.1)`.

## Testing requirements

- **Every Voter** needs at least two test cases: one role/subject combination that should pass, one that should return `403`. This is non-negotiable — architecture doc §9's own testing note calls this out, and functional requirements doc's non-functional section repeats it.
- **Every acceptance criterion** in the functional requirements doc should be traceable to a test, ideally named after the criterion (e.g. a test literally named `given_expired_membership_when_checkin_then_blocked`).
- **Responsive smoke tests** (Playwright, per roadmap Phase 10) at 375px / 768px / 1280px for any new user-facing flow, not just at the end of the project.

## What NOT to do

- Don't build features the roadmap hasn't reached yet, even if they seem quick — sequencing matters because later phases assume earlier ones (e.g. Attendance assumes Membership status checks exist).
- Don't add DigitalOcean Spaces/cloud storage code — local disk via Flysystem is the current decision; the abstraction is deliberately there so this is a config change later, not something to build now.
- Don't let Owners directly create active Coach accounts — onboarding is always invite → explicit approval by the invitee (architecture doc §6.7, functional requirements §2). This is a common shortcut to avoid: it's tempting to add a simple "add coach" button, but it violates the approval requirement. **Member accounts are the one deliberate exception, added by `gym-management-member-profile-extension.md`:** `POST /members` creates an immediately-`active` walk-in Member account with no OTP/pending step, for front-desk registration. It's audit-logged (`member.created_manual`) same as a status change. This overrides functional requirements §15.2's older "member creation only ever happens through the invite/approve flow" line — see that section's own updated note. Originally Owner-only; a follow-up feature ("editable/manual Member ID mode") widened creation and profile-field editing (`dob`/`gender`/`address*`/`memberId`) to **Owner + Staff** via `MemberVoter::EDIT_PROFILE` (gym-wide/unscoped for Staff), since front-desk registration is typically a Staff task. `MemberVoter::MANAGE` (suspend/reactivate an existing account) deliberately stayed Owner-only and untouched — don't widen that one without a fresh, explicit product decision. **Coach accounts gained their own parallel exception in `gym-management-coach-management.md`:** `POST /coaches` creates an immediately-`active` Coach account (audit-logged `coach.created`), and `PATCH /coaches/:id` / `PATCH /coaches/:id/status` cover identity + profile editing and suspend/reactivate — all **Owner-only**, gated by the now-implemented `CoachManagementVoter::MANAGE` (architecture doc §9.1). This overrides architecture doc §6.7 / functional requirements §2's invite-only rule for coaches — a deliberate product decision, not a shortcut; the invite → approve flow stays fully available alongside it. Staff still get nothing here (coach management is not a front-desk task), and neither exception extends to **Owner** accounts.
- Don't skip the OTP flow's rate limiting or expiry logic "for now" — it's a named acceptance criterion (functional requirements §1.2), not an enhancement.
- Don't build desktop-first and adapt down. If a component doesn't work at 375px, it's not done, regardless of how it looks at 1280px.
- Don't let a gym's brand color (Phase 15) override the `hivis` CTA color or role-tag colors — `DESIGN-SYSTEM.md` §4.1 is explicit that white-labeling is bounded to the badge/nav header, not a full theming system. It's easy but wrong to make this "configurable everywhere" once the color field exists.
- Don't let the Staff role (Phase 15) accumulate permissions beyond what §2's table explicitly grants — it's deliberately the narrowest role in the system. If a task seems to need Staff to have Coach-level or Owner-level access, that's a sign to flag it, not to quietly widen a Voter.
- Don't confuse Member hub access with Coach/Staff branch scoping (Phase 16) — Members can check in at any branch by design; Coach/Staff visibility is intentionally narrowed to their assigned branch(es). If a change makes Members branch-restricted, or makes Staff gym-wide again, that's reversing a deliberate decision, not a bug fix — check architecture doc §5.2's note before "fixing" either direction.

## Quick file map for a new session

| I need to... | Read |
|---|---|
| Know what to build next | `gym-management-system-development-roadmap.md` — find current phase |
| Know exactly how a feature should behave | `gym-management-system-functional-requirements.md` — matching section |
| Know the entity fields / Voter code / endpoint shape | `gym-management-system-architecture.md` §5 / §9.1 / §7 |
| Understand a multi-step flow (booking, OTP login, invitation approval) | `gym-management-system-architecture.md` §8 sequence diagrams |
| Know the mobile-first rules | `gym-management-system-development-roadmap.md` Phase 1 |
| Know what colors/fonts/component pattern to use | `DESIGN-SYSTEM.md` |
| Understand why a Phase 9 feature exists | `gym-management-system-go-to-market.md` — matching pillar |
