# Setly — Member Profile Extension & Owner Member Detail View

**Status:** Draft spec — confirm assumptions against current `Member` entity before generating code.
**Phase type:** Additive extension to existing Member management (not a new module).

---

## 1. Purpose

Two things, kept deliberately separate so they can be built/reviewed independently:

1. **Extend the Member entity** with `memberId`, `dob`, `gender`, `address`, and derived `age` — plus proper CRUD for Owner/Staff to manage a member's profile after they've joined (members currently arrive via owner-initiated invite chain; this phase adds the *manage* half, not the *invite* half).
2. **Owner-facing Member Detail View** — a single screen per member aggregating profile, PT session schedule, attendance history, and payment history (partial — see §6 Dependencies).

---

## 2. Non-Goals / Hard Exclusions

Explicitly **not** in this phase:

- Changing the invite/onboarding flow (OTP signup, invite chain) — untouched.
- Building the Invoice/billing entity (Phase 10) — payment history is stubbed only.
- Coach commission/earnings (separate future entity per existing notes).
- Multi-country address support — Sri Lanka only, flat fields.
- Bulk import of members (Phase 9 — Growth & Retention).
- Editing PT session schedules or workout assignments from this screen (link out to existing workout-scheduling views instead of duplicating that UI).
- Hard delete of members. Deactivation only (soft delete), to preserve referential integrity with attendance/workout/exercise-log history.
- Exposing `dob`, `address`, or `gender` to the Coach mobile app payloads.

---

## 3. Data Model Changes

**Additive only — new nullable columns on the existing `Member` entity, no existing columns touched, no existing serialization groups modified (new fields go in new/explicit groups).**

```
Member (existing entity, extended)
├── memberId: string, unique per gym, NOT NULL after generation, system-generated, immutable
├── dob: date, nullable
├── gender: enum('male','female','other','prefer_not_to_say'), nullable
├── addressLine: string(255), nullable
├── addressCity: string(100), nullable
├── addressPostalCode: string(20), nullable
```

`age` is **not** a column. It's a computed getter (`Member::getAge(): ?int`) derived from `dob`, returning `null` when `dob` is null. Exposed via API Platform as a computed/normalized field, not persisted — avoids a second source of truth going stale.

### Member ID generation

- Format: `{GymCode}-{0001}` (zero-padded, sequential per gym, not global).
- Generated once, at first successful profile completion (either at invite-acceptance or at manual creation by Owner/Staff — see §4).
- Generation must be race-safe under concurrent creation for the same gym (DB-level unique constraint + retry-on-conflict, or a per-gym sequence table — do not rely on in-app locking alone).
- Immutable once assigned. Not editable via any CRUD endpoint, even by Owner.
- `GymCode` source: confirm whether Gym entity already has a short code field; if not, this phase adds one (nullable, backfillable, Owner-editable) since it's needed here regardless.

### Backfill

Existing members get `memberId` generated via a one-time idempotent console command (same pattern as the exercise-media import — pinned, safe to re-run, skips rows that already have a `memberId`). `dob`/`gender`/`address*` are left `null` for existing members; Owner/Staff fill them in over time via the new edit UI. No forced backfill of PII.

---

## 4. CRUD Scope

| Operation | Who | Notes |
|---|---|---|
| Create (manual, walk-in, no invite) | Owner, Staff | New pathway alongside existing invite flow. Generates `memberId` immediately. |
| Read (own profile) | Member | Existing fields only; new PII fields visible to the member themselves. |
| Read (any member in gym) | Owner, Staff | Full fields including new PII fields. |
| Read (assigned members) | Coach | **Excludes** `dob`, `address*`, `gender` from response — training-relevant fields only. |
| Update profile fields (incl. new fields) | Owner, Staff | `memberId` excluded from writable fields — 403/validation error if present in payload. |
| Update profile fields (limited) | Member (self) | Contact info + address only, not `memberId`, `gender` policy TBD — confirm whether member can self-edit gender/DOB or whether that's Owner/Staff-only for data-integrity reasons. |
| Deactivate (soft delete) | Owner | Sets `isActive = false`, does not cascade-delete attendance/PT/exercise history. |
| Reactivate | Owner | — |
| Hard delete | **None** | Not exposed via API. |

All of the above go through the existing Voter pattern for this entity — extend the existing `MemberVoter`, do not create a parallel authorization path.

---

## 5. Owner Member Detail View

Single aggregation endpoint (or 3–4 parallel calls from frontend — implementation detail, not a hard requirement) backing one screen with tabs/sections:

1. **Profile** — all fields from §3, edit-in-place for Owner/Staff.
2. **PT Session Schedule** — read-only pull from the existing workout-scheduling module (assignments referencing this member). No new write path; link to existing schedule-editing UI for changes.
3. **Attendance History** — read-only pull from existing attendance tracking. Paginated, most-recent-first.
4. **Payment History** — **stub tab only in this phase.** Render an empty-state ("Billing not yet enabled") rather than fabricating data or blocking the rest of the screen on Phase 10. Backend: expose the tab's contract (endpoint shape/DTO) now so Phase 10 slots in without a frontend rework, but the endpoint itself returns `[]` / 501-style "not yet available" rather than querying a non-existent Invoice table.

This view is Owner/Staff only. Not exposed on the Coach app.

---

## 6. Dependencies & Open Questions

Confirm before generating the Claude Code prompt:

1. Does the `Gym` entity already have a short code suitable for `memberId` prefixing? If not, this phase adds one.
2. Can a Member self-edit `dob`/`gender`, or is that Owner/Staff-only (common pattern: lock sensitive demographic fields to staff to prevent casual member edits)?
3. Confirm current `MemberVoter` scopes (gym-scoping, branch-scoping) so the new fields inherit the same isolation without a parallel check being written.
4. Confirm whether Staff role currently has member-write permission at all, or read-only today — this phase assumes Staff can write, per functional requirements' four-role model, but verify against current implementation.
5. Payment History stub: confirm the empty-state contract is acceptable, versus waiting to ship this tab until Phase 10 lands.

---

## 7. Test Cases (must include, mirrors existing pattern)

- 403: Coach attempting to read `dob`/`address`/`gender` on an assigned member → fields must be absent from response, not merely empty.
- 403: Member attempting to write `memberId` via update payload → rejected, existing value unchanged.
- 403: Member from Gym A reading/editing Member from Gym B → 403 (existing gym-scoping Voter, verify it still holds with new fields).
- Concurrency: two simultaneous manual member creations in the same gym → both succeed with distinct sequential `memberId`s, no collision.
- Deactivated member: attendance/PT/exercise-log history remains queryable and intact (not cascade-deleted).
- `age` computed field: returns `null` when `dob` is null; correct integer otherwise; not present as a writable API field.
- Backfill command: idempotent, safe to re-run, does not overwrite existing `memberId`s.

---

## 8. Frontend (web, Owner/Staff)

- Extend existing Member edit form with DOB (date picker), Gender (select), Address (3 fields).
- Member list: show `memberId` as a column; do not show computed `age` in list view (detail view only) to avoid extra per-row computation cost at scale — confirm if list-view age is actually wanted.
- New Member Detail screen per §5, tabbed.
- Manual "Add Member" flow (walk-in path) — separate entry point from invite flow, reuses the same form component as edit.
