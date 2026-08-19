# Setly — Phase: Exercise Catalog & Media

**Status:** Locked, ready for Claude Code implementation
**Depends on:** none (foundational — `WorkoutScheduleExercise` in the scheduling phase depends on this)
**Companion prompt:** `claude-code-prompt-exercise-media.md`
**Source dataset:** [`yuhonas/free-exercise-db`](https://github.com/yuhonas/free-exercise-db) — public domain, JSON + JPG images, ~800 exercises

---

## 1. Purpose

Seed a platform-wide exercise catalog with static images that coaches browse when building `WorkoutSchedule` templates and members browse when viewing their assigned exercises. Optimized for low-bandwidth conditions (Sri Lanka launch market) — this is a picker/browse UI, so most requests are for small thumbnails, not full-size images.

---

## 2. Locked design decisions

1. **Switched from ExerciseDB (GIF) to free-exercise-db (JPG), public domain.** The ExerciseDB open-source repo's server code is AGPL, but its actual media/dataset is served from a commercial CDN with its own paid terms — unclear rights for redistribution inside a paid SaaS product. `free-exercise-db` is explicitly public domain with no such ambiguity. This phase is built around static JPGs, not animated GIFs.
2. **Exercise catalog is platform-wide, not gym-scoped.** All gyms and coaches share one catalog. No `gym_id` on `Exercise`.
3. **Two-tier image sizing, no animation tier.** Since the source has 1-2 static images per exercise (typically a start/end position) rather than a GIF, there's no transcode-to-video step. Instead: a small poster thumbnail for grid/picker views, and larger detail images for the exercise detail screen. A simple position toggle (image 0 ↔ image 1) approximates before/after motion cheaply, no video decode needed.
4. **One-time ETL, no live third-party dependency.** Dataset is cloned/downloaded once into an import command; the app never calls out to GitHub at runtime.
5. **Colocated Redis cache for catalog list/filter queries** (per existing infra decision — Redis runs in the same Docker Compose stack as the app, accessed only through Symfony's cache interface, extractable to a dedicated instance later without code changes).
6. **No CDN in this phase.** Images are served from the droplet's Nginx with long, immutable cache headers. A CDN edge layer is deferred to the same future "expand" trigger point already agreed for Redis — added when real concurrent load justifies it, not preemptively.

---

## 3. Data model

### `Exercise`
| field | type | notes |
|---|---|---|
| id | uuid | PK |
| source_id | string | original dataset id/slug, e.g. `Alternate_Incline_Dumbbell_Curl` — unique, used for idempotent re-import |
| name | string | |
| slug | string | URL-safe, derived from name |
| force | string, nullable | push / pull / static |
| level | string | beginner / intermediate / expert |
| mechanic | string, nullable | compound / isolation |
| equipment | string, nullable | |
| primary_muscles | json | array of strings |
| secondary_muscles | json | array of strings |
| instructions | json | array of instruction step strings |
| category | string | strength / stretching / cardio / plyometrics / etc. |
| poster_image_path | string | Flysystem path, WebP, ~300px, ~10-15KB |
| detail_image_paths | json | ordered array of Flysystem paths, WebP, ~600px, ~40-60KB each — usually 1-2 entries |
| created_at / updated_at | timestamp | |

No `gym_id` — shared platform reference data.

---

## 4. Import / transcode pipeline

Symfony console command `app:exercise:import`, run once at deploy time and re-runnable safely (idempotent on `source_id`).

1. Download the combined `exercises.json` and the `exercises/` image directory from the dataset (pinned to a specific commit/release, not `main`, so re-runs are reproducible).
2. For each exercise record:
   - Validate required fields per the dataset's own JSON Schema; skip and log records with nulls in fields Setly requires (some entries have incomplete data upstream).
   - For each image path listed: resize and re-encode to WebP.
     - First image → poster (300px, quality ~80).
     - All images → detail set (600px, quality ~85), stored in order.
   - Store via Flysystem under `exercises/{source_id}/poster.webp` and `exercises/{source_id}/detail-{n}.webp`.
   - Upsert the `Exercise` row keyed on `source_id` — re-running the import updates existing rows rather than duplicating.
3. Log a summary: imported / updated / skipped counts, and any records skipped for missing required fields.

---

## 5. API surface

| Endpoint | Role | Behavior |
|---|---|---|
| `GET /exercises?muscle=&equipment=&category=` | Coach | Full catalog, filtered/paginated, list response includes `posterUrl` only |
| `GET /exercises/{id}` | Coach, Member (via scoped endpoint in scheduling phase) | Full detail including `detailImageUrls` |

The unscoped `GET /exercises` list endpoint is coach-facing only (building schedules). Members never call it directly — they use the assignment-scoped endpoint defined in the scheduling phase (`GET /workout-assignments/{id}/exercises`), which filters against this same table but restricts results to what's in their active schedule.

**Caching:** `GET /exercises` list/filter responses cached in Redis (colocated, per infra decision), keyed by the filter parameters, invalidated only when the catalog is re-imported (rare — this is near-static reference data).

---

## 6. Frontend behavior

- **Picker/grid (coach building a schedule):** virtualized grid, lazy-loads `posterUrl` only via IntersectionObserver.
- **Detail view:** loads `detailImageUrls`, shown as a simple tap-to-toggle between position images (no video player needed) alongside instructions.
- **Cache headers:** `Cache-Control: immutable, max-age=31536000` on all `/media/exercises/*` responses, filenames content-hashed so re-imports that change an image get a new URL rather than requiring cache invalidation.

---

## 7. Out of scope for this phase

- Animated media / video loops (not applicable — source dataset has no animation)
- CDN edge caching (deferred to the general "expand" infra trigger point)
- Per-gym custom exercise additions (coaches adding their own exercises outside the catalog) — potential future phase
- Multi-language exercise names/instructions

---

## 8. Verification checklist

- [ ] Re-running `app:exercise:import` does not create duplicate `Exercise` rows
- [ ] Poster images are consistently ≤15KB, detail images ≤60KB each
- [ ] `GET /exercises` list response never includes `detailImageUrls`
- [ ] Redis cache invalidates correctly after a re-import
- [ ] Records with missing required upstream fields are skipped and logged, not imported with nulls
