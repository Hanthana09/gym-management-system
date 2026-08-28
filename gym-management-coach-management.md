# Setly — Coach Management (CRUD + Password)

**Status:** Implemented
**Depends on:** User/CoachProfile/BranchAssignment entities (Phase 2, Phase 16), Symfony Voters, `gym-management-password-auth.md` (Owner-assigned passwords), the existing invitation flow (architecture doc §6.7)
**Does not change:** the invite → approve onboarding flow, PT session / schedule endpoints, branch-assignment endpoints, the FinancialSummary PT-revenue estimate (architecture doc §6.13)

---

## 1. Problem statement

Before this feature an Owner could **not** manage coach accounts directly:

- Coach creation was invite-only (`POST /invitations` with `role=coach` → invitee approves).
- `CoachProfile.specialty` / `.bio` / `.hourlyRate` existed in the schema since Phase 2 but had **no accessors and no endpoint** — nothing could read or write them (architecture doc's `CoachProfile` docblock explicitly deferred this to "a future coach-profile-management phase").
- There was no coach equivalent of `PATCH /members/:id/status` — no way to suspend or reactivate a coach.
- `CoachManagementVoter` was specified in architecture doc §9.1 but never implemented (only its sibling `StaffManagementVoter` existed).
- Password set/reset already worked for any user via `POST /users/{id}/set-password` (`gym-management-password-auth.md`), but the **UI only surfaced it on the Member Detail screen** — an Owner had no screen from which to set a coach's password.

This feature adds full Owner-facing coach CRUD and wires the existing password action into a coach screen.

---

## 2. Product decision — direct creation overrides invite-only

`POST /coaches` creates an **immediately-active** coach account (no invitation, no OTP, no pending-approval step) — the same shape as `MemberCreationService::createWalkIn()`. This deliberately overrides architecture doc §6.7 and functional requirements §2's "an Owner never creates an active Coach directly" rule, and CLAUDE.md's named anti-pattern. Confirmed with the product owner; CLAUDE.md's "What NOT to do" bullet carries the updated note.

The invite → approve flow is **unchanged and still available** — direct creation is a parallel path, chosen when the Owner would rather not rely on OTP onboarding for a coach.

Branch assignment stays a separate Owner action (`POST /branches/:id/assign`) — a newly-created coach has no branch assignments until the Owner adds them on the Branches page.

---

## 3. Data model

No schema change. `CoachProfile.specialty` (`varchar(255)`), `.bio` (`text`), `.hourlyRate` (`decimal(8,2)`) all already exist — this feature only adds PHP accessors. `User` gains `setName()` / `setEmail()` / `setPhone()` (identity was previously only ever set at construction).

---

## 4. API endpoints

All under `/api` (same prefix as `MemberController`).

| Method | Path | Auth | Notes |
|---|---|---|---|
| `POST` | `/coaches` | Owner (role gate) | Body: `{ name, email?, phone?, specialty?, bio?, hourlyRate? }`. `name` + at least one of email/phone required. Email/phone uniqueness → `409`. `hourlyRate` non-negative numeric, normalized to 2 dp. Returns `201` + coach detail. Audit: `coach.created`. |
| `GET` | `/coaches/{id}` | Owner, Staff, or the coach themselves | Full coach detail (identity + specialty/bio/hourlyRate + status + assigned branches). |
| `PATCH` | `/coaches/{id}` | Owner — `CoachManagementVoter::MANAGE` | Partial. Any of `name`/`email`/`phone`/`specialty`/`bio`/`hourlyRate`. `name` never empty; can't remove the **last** contact method (`400`); email/phone uniqueness excluding self (`409`). Audit: `coach.profile_updated` with the list of changed field names. |
| `PATCH` | `/coaches/{id}/status` | Owner — `CoachManagementVoter::MANAGE` | Body: `{ status: "active" \| "suspended" }` only (mirrors `PATCH /members/:id/status`; no hard delete — PT history references the coach). Idempotent — re-setting the same status is a no-op with no audit entry. Audit: `coach.status_changed` with `previousStatus`/`newStatus`. |
| `POST` | `/users/{id}/set-password` | Owner — `PasswordManagementVoter::SET_PASSWORD` | **Unchanged** — already role-agnostic (`gym-management-password-auth.md` §3.1). Now reachable from a coach screen. |

The pre-existing `GET /coaches` (list, `{id, name}` only) and `GET /coaches/{id}/schedule` live in `PtSessionController` and are **untouched**. No route collision: list vs. create differ by method; `/coaches/{id}` vs `/coaches/{id}/schedule` differ by path.

---

## 5. Permissions

`CoachManagementVoter` (architecture doc §9.1, implemented here for the first time):

```
const MANAGE = 'COACH_MANAGE';   // subject: CoachProfile — Owner only
```

Single-gym collapse (same as `StaffManagementVoter` / `MemberVoter`'s Owner branch): the doc's `$subject->getUser()->getGym() === $user->getGym()` becomes `isOwner($user)` — no entity in this codebase has `getGym()`.

- **Create** — plain Owner role gate in the controller (no `CoachProfile` subject exists yet to vote on), same pattern as `MemberController::create()`.
- **Update / status** — `CoachManagementVoter::MANAGE`. Coach, Staff, Member → `403`.
- **Read** — Owner/Staff (directory) or the coach reading their own record. Member → `403`.

### Negative / 403 cases (covered by `tests/Functional/CoachControllerTest.php`)

- Coach or Staff `POST /coaches` → `403`.
- Coach `PATCH`-ing another coach, or their own status → `403`.
- Staff `PATCH /coaches/{id}` → `403`.
- Member `GET /coaches/{id}` → `403`.
- Duplicate email on create / update → `409`.
- Removing the last contact method on update → `400`.
- Negative `hourlyRate` → `400`.
- `status` other than `active`/`suspended` → `400`.
- Re-suspending an already-suspended coach → no duplicate audit entry.

---

## 6. Frontend

- **`/owner/coaches/:id` → `CoachDetailPage`** — reached from a coach row on the Owner's Members roster (coach names are now clickable, alongside member names). Profile summary + inline edit form (`CoachProfileForm`, shared with the Add Coach modal), "Set password" (reuses `SetPasswordModal` from the member side verbatim), and suspend/reactivate with an inline confirm step (same asymmetry as the member status action).
- **"Add coach" button** on `OwnerMembersPage`, Owner-only, next to "Add Member" — opens `AddCoachModal`. The modal notes that it creates an active account and points to the invitation flow as the alternative.
- Hooks: `useCoachDetail`, `useCoachManagement` (`create` / `updateProfile` / `updateStatus`). Types in `src/coaches/types.ts` — `CoachProfileDetailDto` is distinct from personal-training's `CoachDto` (`{id, name}` picker shape) and the roster's `MemberListItemDto` (`role: 'coach'` rows carry no profile fields).
- DESIGN-SYSTEM.md: existing `Input`/`Button`/`Card`/`Modal` primitives only; `bio` is a single styled `<textarea>`, not a new shared component.

---

## 7. Hard exclusions (do not build)

- No direct creation of **Owner** accounts, and no widening of any coach action to **Staff** — coach management is not a front-desk task.
- No hard delete of a coach `User` row (PT session history references it — suspend instead).
- No coach-schedule view on the Owner Coach Detail screen (the existing `useCoachSchedule` hook is hardcoded to the logged-in coach; an Owner-facing coach schedule is out of scope here).
- No changes to PT billing / invoicing — `hourlyRate` remains a read-time estimate input only (architecture doc §6.13).
- No bulk coach import (the CSV path stays invite-only, `role` column already accepts `coach`).
