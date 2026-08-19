# Setly — Phase: Workout Scheduling & Assignment

**Status:** Locked, ready for Claude Code implementation
**Depends on:** Exercise media phase (`setly-phase-exercise-media.md`) — requires the `Exercise` entity to exist
**Companion prompt:** `claude-code-prompt-workout-scheduling.md`

---

## 1. Purpose

Let coaches build reusable workout templates and assign them to individual members. Members can only browse and log exercises that belong to their currently active assignment — no logging outside the scope of what their coach prescribed.

This phase covers: workout templates, coach-to-member assignment, live-editing propagation, and scoped exercise logging. It does **not** cover coach earnings/commission (separate future entity, flagged in the roadmap) or per-member schedule customization (see §6, deferred).

---

## 2. Locked design decisions

1. **Schedule is a template, Assignment is the instance.** A `WorkoutSchedule` is authored once by a coach and referenced by every `WorkoutAssignment` that uses it — never copied. Editing the template edits it for every member currently assigned to it.
2. **No concurrent schedules per coach-member pair.** A coach cannot have two active assignments with the same member simultaneously. Assigning a new schedule automatically replaces the existing one; enforced with a database-level partial unique index, not just application logic.
3. **Replacement preserves history.** The previous `WorkoutAssignment` transitions to `status = replaced` rather than being deleted. Its `ExerciseLog` rows remain queryable and linked to it.
4. **Exercise logging is scoped by assignment, not by the global catalog.** Every `ExerciseLog` references an `assignment_id`. A member can only log an exercise that is a `WorkoutScheduleExercise` row under that assignment's schedule, checked at write time — not cached, not snapshotted.
5. **No per-member schedule forking in this phase.** If a coach edits a schedule assigned to five members, all five see the change simultaneously. A "fork this schedule for one member" action is explicitly out of scope (see §6).

---

## 3. Data model

### `WorkoutSchedule`
| field | type | notes |
|---|---|---|
| id | uuid | PK |
| gym_id | uuid | FK, tenant scope |
| coach_id | uuid | FK → User (coach role) |
| name | string | e.g. "12-Week Strength Block" |
| type | string | strength / cardio / hypertrophy / mobility / custom |
| status | enum | draft / active / archived |
| created_at / updated_at | timestamp | |

### `WorkoutScheduleExercise` (pivot — the template's content)
| field | type | notes |
|---|---|---|
| id | uuid | PK |
| schedule_id | uuid | FK → WorkoutSchedule, cascade delete |
| exercise_id | uuid | FK → Exercise |
| day_number | int | 1–7, or sequence index for non-weekly plans |
| order | int | display order within the day |
| sets | int | |
| reps | int | |
| rest_seconds | int | nullable |
| notes | text | nullable, coach instructions |

### `WorkoutAssignment` (coach → member link)
| field | type | notes |
|---|---|---|
| id | uuid | PK |
| schedule_id | uuid | FK → WorkoutSchedule |
| member_id | uuid | FK → User (member role) |
| coach_id | uuid | FK → User (coach role), denormalized for fast Voter checks |
| status | enum | active / replaced / completed / cancelled |
| start_date | date | |
| assigned_at | timestamp | |

**Constraint:** partial unique index on `(coach_id, member_id) WHERE status = 'active'`.

### `ExerciseLog`
| field | type | notes |
|---|---|---|
| id | uuid | PK |
| assignment_id | uuid | FK → WorkoutAssignment |
| exercise_id | uuid | FK → Exercise |
| member_id | uuid | FK → User, denormalized for fast Voter checks |
| logged_at | timestamp | |
| sets_completed | int | |
| reps_completed | int | |
| weight | decimal | nullable |
| notes | text | nullable |

---

## 4. Assign / replace transaction

`WorkoutAssignmentService::assign(WorkoutSchedule $schedule, User $member, User $coach)`

1. Open a DB transaction.
2. Look up any existing `WorkoutAssignment` where `coach_id = $coach`, `member_id = $member`, `status = active`.
3. If found: set `status = replaced`, do **not** touch its `ExerciseLog` rows.
4. Create the new `WorkoutAssignment` with `status = active`, `assigned_at = now()`.
5. Commit.
6. Dispatch `WorkoutAssignmentCreated` domain event (post-commit, via Messenger) → triggers the Mercure publish in §5.

The partial unique index is the real safety net — even if two requests race, the DB rejects the second `active` row for the same coach-member pair, and the service catches that as a conflict rather than relying on the transaction alone.

---

## 5. Authorization — `ExerciseLogVoter`

Applies to `ExerciseLog::CREATE`.

```
supports(attribute, subject): attribute === 'CREATE' && subject instanceof ExerciseLog

voteOnAttribute:
  1. assignment = subject.assignment
  2. if assignment.member_id !== currentUser.id → DENY
  3. if assignment.status !== 'active' → DENY
  4. exists = WorkoutScheduleExercise::query()
       .where('schedule_id', assignment.schedule_id)
       .where('exercise_id', subject.exercise_id)
       .exists()
  5. if !exists → DENY
  6. GRANT
```

Single indexed lookup (`schedule_id` + `exercise_id` composite index on `WorkoutScheduleExercise`) — stays fast regardless of catalog size. This check reads live data, so a coach removing an exercise mid-session immediately blocks further logging of it — no cache invalidation to get wrong.

---

## 6. Real-time propagation (Mercure)

Since a `WorkoutSchedule` is referenced (not copied) by assignments, edits are already reflected on next read. Mercure closes the gap for members with the app open:

- Topic: `/members/{member_id}/assignment-updates`
- Published on: `WorkoutScheduleExercise` create/update/delete (for any schedule with ≥1 active assignment), `WorkoutAssignmentCreated`, `WorkoutAssignmentReplaced`
- Payload: `{ assignmentId, scheduleId, changeType }` — thin payload, client refetches the affected resource rather than receiving the full diff over the wire (keeps this consistent with the bandwidth-conscious approach from the exercise-media phase)

---

## 7. API surface

| Endpoint | Role | Behavior |
|---|---|---|
| `POST /workout-schedules` | Coach | Create template |
| `PATCH /workout-schedules/{id}` | Coach | Edit template metadata |
| `POST /workout-schedules/{id}/exercises` | Coach | Add line item |
| `PATCH /workout-schedule-exercises/{id}` | Coach | Edit line item |
| `DELETE /workout-schedule-exercises/{id}` | Coach | Remove line item |
| `POST /workout-assignments` | Coach | Assign (or replace) schedule for a member |
| `GET /workout-assignments?member=me&status=active` | Member | List own active assignment(s) |
| `GET /workout-assignments/{id}/exercises?muscle=&equipment=` | Member | Filtered exercises **scoped to this assignment's schedule only** |
| `POST /exercise-logs` | Member | Log a set; Voter-enforced |
| `GET /workout-assignments/{id}/logs` | Coach, Member | History for that assignment |

---

## 8. Out of scope for this phase

- Per-member schedule forking / customization of a shared template
- Coach commission or earnings tied to assignments
- Auto-progression (e.g. auto-increasing weight week over week)
- Push notifications beyond Mercure in-app updates (FCM/APNs wiring is a separate mobile phase)

---

## 9. Open question for a future phase

Should a "fork schedule for this member" action exist, so a coach can tweak one member's version without affecting the group template? Deferred until real usage shows this is needed.
