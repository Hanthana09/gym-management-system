# Claude Code Prompt — Exercise Catalog & Media Phase

## Docs to read first

1. `CLAUDE.md` — project entry point and conventions
2. `gym-management-system-architecture.md`
3. `gym-management-functional-requirements.md`
4. `setly-phase-exercise-media.md` — full spec for this phase (authoritative source for all decisions below)

This phase has no dependencies on other phases — it's foundational. The workout-scheduling phase (`setly-phase-workout-scheduling.md`) depends on the `Exercise` entity built here.

---

## Data model (inline reference — full detail in the spec doc)

```
Exercise
  id, source_id (unique), name, slug,
  force(nullable), level, mechanic(nullable), equipment(nullable),
  primary_muscles (json array), secondary_muscles (json array),
  instructions (json array), category,
  poster_image_path, detail_image_paths (json array, ordered),
  timestamps

No gym_id — platform-wide shared catalog.
```

---

## Backend tasks

1. **`Exercise` entity + migration** per the schema above. Unique index on `source_id`.

2. **Image processing service** (`ExerciseImageProcessor` or similar) using Imagick/GD (check what's already available in the project's PHP extensions before adding a new dependency):
   - Input: a source JPG.
   - Output: WebP re-encode at two target sizes — 300px longest edge, quality ~80 (poster); 600px longest edge, quality ~85 (detail).
   - Return the processed binary + dimensions for storage.

3. **Import command `app:exercise:import`**
   - Accepts a `--source` option pointing to a local path (the dataset should be downloaded/vendored into a known location as a build step, not fetched from GitHub at runtime — pin to a specific commit SHA for reproducibility, document the SHA in the command's help text).
   - Parse `exercises.json` (or the per-exercise JSON files, per the dataset's actual layout — verify at implementation time which format is present).
   - For each record:
     - Validate required fields (`name`, `primaryMuscles`, `category` at minimum). Skip and log records missing these — do not import with nulls in required fields.
     - Run each listed image through `ExerciseImageProcessor`.
     - Store outputs via the existing Flysystem abstraction under `exercises/{source_id}/poster.webp` and `exercises/{source_id}/detail-{n}.webp`.
     - Upsert `Exercise` by `source_id` (find-or-create, then overwrite fields) — running the command twice must not create duplicates.
   - At the end, output a summary table: imported count, updated count, skipped count with reasons.
   - Command must be safely re-runnable (idempotent).

4. **API Platform resource** for `Exercise`:
   - `GET /exercises` with filters on `primary_muscles`, `equipment`, `category` (array-contains filter on the JSON fields — check API Platform's filter support for JSON columns on the project's Postgres version, may need a custom filter class).
   - List response: `id, name, slug, category, equipment, posterUrl` — explicitly exclude `detailImageUrls` and `instructions` from the list serialization group.
   - `GET /exercises/{id}`: full detail including `detailImageUrls` and `instructions`.
   - Use a separate normalization group (e.g. `exercise:list` vs `exercise:detail`) so the exclusion is enforced by API Platform's serializer, not by manual field-stripping in a controller.

5. **Redis caching** on the `GET /exercises` list/filter endpoint — colocated Redis per existing infra setup, accessed through Symfony's `CacheInterface`, not a direct Redis client call. Cache key includes the normalized filter parameters. Invalidate on `app:exercise:import` completion (clear the whole exercise list cache tag/prefix, not individual keys).

6. **Static file serving with cache headers** — confirm Nginx config sets `Cache-Control: immutable, max-age=31536000` on the `/media/exercises/` path, and that filenames are content-hashed (or versioned via the `source_id` + a content hash suffix) so a re-import that changes an image doesn't require manual cache busting.

---

## Frontend tasks

1. **Exercise picker/grid component** (reusable — this is also used inside the workout-scheduling phase's coach builder and member scoped views):
   - Virtualized grid (react-window or equivalent already in use in the project).
   - IntersectionObserver-based lazy loading — only fetch `posterUrl` for visible cells.
   - Filter controls for muscle group, equipment, category, wired to the `GET /exercises` query params.

2. **Exercise detail view:**
   - Loads `GET /exercises/{id}`, displays `detailImageUrls` as a simple tap-to-toggle (not a carousel library — just swap the visible image on tap, since there are usually only 1-2 images).
   - Renders `instructions` as a numbered list.

---

## Testing

**Positive:**
- Running `app:exercise:import` against a small fixture dataset creates the expected number of `Exercise` rows with correctly sized poster/detail images.
- Running the import command twice does not duplicate rows; second run updates existing rows if source data changed.
- `GET /exercises?muscle=biceps` returns only exercises with `biceps` in `primary_muscles` or `secondary_muscles` (confirm which field(s) the filter should check per the spec's intent, and be explicit in the filter implementation).

**Negative / edge cases:**
- A fixture record missing `name` or `category` is skipped, not imported with a null, and appears in the command's "skipped" summary.
- `GET /exercises` list response, when inspected, does not contain `detailImageUrls` or `instructions` keys at all (not just empty — absent from the serialized output).
- Requesting a poster/detail image path that doesn't exist returns a proper 404, not a broken image silently.

---

## Hard exclusion list — do not build

- Any video/GIF transcoding — the source dataset has no animation, don't build infrastructure for a format that isn't there
- CDN integration (Cloudflare, Spaces, etc.) — explicitly deferred, Nginx + cache headers only for now
- Per-gym custom exercise creation
- Multi-language fields
- Live fetching from GitHub at request time — import is offline/build-time only

---

## Verification checklist

- [ ] `source_id` uniqueness enforced at the database level, not just application logic
- [ ] Poster images consistently ≤15KB, detail images ≤60KB (spot-check a sample after import)
- [ ] `exercise:list` serialization group genuinely excludes detail fields (verify via raw API response, not just the frontend's rendering)
- [ ] Redis cache clears correctly after a re-import (stale filtered results don't persist)
- [ ] Re-running the import command against unchanged source data produces zero duplicate rows and zero unnecessary re-writes of unchanged images
- [ ] Nginx serves exercise media with immutable long-lived cache headers (check via response headers, not assumption)
