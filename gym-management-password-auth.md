# Setly — Password Auth: Owner-Assigned Passwords & Forgot Password Recovery

**Status:** Locked spec, ready for implementation
**Depends on:** Existing OTP auth (`WhatsAppOtpSender` → Brevo → Twilio channel chain), User/Gym/Branch entities, Symfony Voters, JWT + refresh token auth
**Does not depend on:** TOTP authenticator support (separate future phase), member profile extension phase

---

## 1. Problem Statement

Setly currently has no password recovery path. Users authenticate via OTP, but there is no way for an Owner to hand a Coach/Staff/Member a direct login credential, and no way for anyone to recover access if their registered phone/email channel is unreachable (lost phone, wrong number entered at signup, WhatsApp not installed, etc.). This phase closes both gaps:

1. **Owner-assigned passwords** — an Owner can create/reset a password for any user under their Gym, without going through OTP.
2. **Forgot password (self-service)** — any user, including those who registered via OTP-only and have never set a password, can recover account access via the existing OTP delivery channels.

---

## 2. Data Model

### 2.1 `User` entity — new fields (nullable, additive, no forced backfill)

| Field | Type | Notes |
|---|---|---|
| `password` | `string, nullable` | Already likely exists for OTP-only users as `null`. This phase is what first populates it for such users. |
| `requiresPasswordChange` | `bool, default true` | Set `true` whenever an Owner assigns/resets a password on someone else's behalf. Set `false` when the user sets their own password (self-service or post-forced-change). Irrelevant while `password` is null (OTP-only, no forced-change prompt possible). |
| `passwordSetBy` | `ManyToOne<User>, nullable` | Audit: which Owner last set this user's password. Null if user set it themself. |
| `passwordSetAt` | `datetime_immutable, nullable` | Audit timestamp. |

No existing serialization groups are touched. Add a new group `password-admin:write` scoped to the Owner-set-password endpoint only.

### 2.2 New entity: `PasswordResetToken`

| Field | Type | Notes |
|---|---|---|
| `id` | `uuid` | PK |
| `user` | `ManyToOne<User>` | Owning user |
| `tokenHash` | `string` | SHA-256 hash of the raw token. Raw token is never persisted. |
| `channel` | `enum: whatsapp\|email\|sms` | Which channel actually delivered the code, for audit/debugging channel fallback behavior. |
| `createdAt` | `datetime_immutable` | |
| `expiresAt` | `datetime_immutable` | `createdAt + 15 minutes` |
| `usedAt` | `datetime_immutable, nullable` | Set on successful redemption. Null = still valid/unused. |
| `requestIp` | `string, nullable` | Abuse tracing. |

Partial unique index: at most one **unused, unexpired** token per user at a time (mirrors your existing partial-unique-index pattern for coach schedules). A new request invalidates (sets `usedAt`) any prior outstanding token for that user rather than stacking multiple valid tokens.

---

## 3. Flows

### 3.1 Owner assigns/resets a password

1. Owner opens a Coach/Staff/Member's profile → "Set Password" action.
2. Owner either types a password or clicks "Generate" (server generates a random 10-char password, returned once in the response — never re-displayable).
3. Backend: hash + persist, set `requiresPasswordChange = true`, `passwordSetBy = owner`, `passwordSetAt = now`.
4. Frontend shows the credential once with a copy button and a clear "this won't be shown again" warning.
5. On that user's next login (password grant), the API response includes `password_change_required: true` alongside the JWT/refresh pair — user is still granted a session (so they're not locked out mid-flow) but the frontend routes them straight to a mandatory "Set a new password" screen before anything else is usable.

This also serves as a first-class **onboarding path**: Owners who prefer not to rely on OTP for less tech-savvy members can create the account with a password directly instead of triggering an OTP invite.

### 3.2 Forgot password — self-service, including OTP-only users

Key requirement: this must work identically whether the user **already has a password** (normal reset) or **has never set one** (`password IS NULL`, pure OTP-only account). The flow does not distinguish these cases from the user's perspective — from their side it's always "I can't log in, send me a code."

1. User submits phone or email on a "Forgot password?" screen.
2. Backend looks up the user. **Always returns a generic success response regardless of whether the user exists** (avoid account enumeration) — dispatch only happens if a match is found.
3. If found: invalidate any outstanding unused `PasswordResetToken` for this user, generate a new raw token (cryptographically random, ≥32 bytes, base62-encoded for easy manual entry), hash it, persist `PasswordResetToken`, dispatch via Messenger through the **existing OTP channel priority chain**: WhatsApp Cloud API → Brevo email → Twilio SMS. Record which channel actually succeeded in `channel`.
4. User receives the code, submits it + a new password to `reset-password`.
5. Backend validates: token exists, hash matches, not expired, not used. On success: set `password` (works identically whether this is the first password ever set or a genuine reset), clear `requiresPasswordChange`, mark token `usedAt = now`, invalidate all active refresh tokens for that user (force re-login everywhere — standard practice after a credential reset).
6. Old password (if any existed) is not required as input anywhere in this flow — that's the point of "forgot" password, and it's also what makes the OTP-only case work identically: there's simply nothing to validate against.

### 3.3 What does NOT change

- OTP login remains fully independent and unaffected — this phase adds a parallel password-based path, it does not replace or gate OTP login.
- Users who never touch this feature see no behavior change.

---

## 4. API Endpoints

| Method | Path | Auth | Notes |
|---|---|---|---|
| `POST` | `/api/v1/users/{id}/set-password` | Owner (Voter-scoped to own Gym) | Body: `{ password?: string }` — omit to auto-generate. Returns plaintext password once. |
| `POST` | `/api/v1/auth/login` | Public | Existing endpoint; response gains `password_change_required` boolean. |
| `POST` | `/api/v1/auth/change-password` | Authenticated (self) | Body: `{ currentPassword?: string, newPassword: string }`. `currentPassword` required unless `requiresPasswordChange` is true (post-Owner-assignment first change) or `password` was null. |
| `POST` | `/api/v1/auth/forgot-password` | Public | Body: `{ identifier: string }` (phone or email). Always 200, generic message. |
| `POST` | `/api/v1/auth/reset-password` | Public | Body: `{ identifier: string, token: string, newPassword: string }`. |

---

## 5. Permission Rules (Voters)

- **Owner** may set/reset passwords for any Coach, Staff, or Member within their own Gym (all branches). Cannot act on users in a Gym they don't own.
- **Coach/Staff** cannot set or reset another user's password under any circumstance — self-service only, via `forgot-password`/`reset-password`/`change-password`.
- **Member** may only ever act on their own account via the self-service endpoints.
- Branch-scoping: a Coach scoped to Branch A must not be able to enumerate or target Branch B users via any of these endpoints (defense in depth even though Coaches have no password-admin rights at all in this phase).

---

## 6. Rate Limiting & Abuse Prevention

- `forgot-password`: rate-limited per identifier (e.g. 3 requests / 15 min) and per IP (e.g. 10 requests / 15 min) via Symfony's RateLimiter component. This is especially important given the WhatsApp 250-conversation/day cap — unrestricted retries could burn quota fast.
- `reset-password`: rate-limited per identifier to prevent token brute-forcing (token is high-entropy, but still — e.g. 10 attempts / token lifetime, then force a fresh `forgot-password` request).
- `set-password` (Owner-facing): standard authenticated rate limits only; no special throttling needed since it requires an authenticated Owner session.
- All password reset tokens are single-use and short-lived (15 min); expired/used tokens return a generic "invalid or expired code" error without distinguishing which.

---

## 7. Hard Exclusions (Do Not Build)

- No "security questions" flow.
- No password strength meter/UI polish beyond basic server-side validation (min length 8, existing Symfony password validator constraints) — cosmetic strength UX is a later polish pass, not this phase.
- No account lockout after N failed login attempts — that's a separate brute-force-protection phase, not bundled here.
- No email-only or SMS-only forced channel selection by the user — channel selection stays automatic via the existing priority chain, consistent with OTP login.
- No changes to the OTP login flow itself.
- No TOTP/authenticator app integration (tracked separately).
- No "remember this device" changes (tracked separately, part of the OTP-reduction phase).
- No admin/support-staff "impersonate user" tooling.

---

## 8. Negative / 403 Test Cases

- Coach attempts `POST /api/v1/users/{memberId}/set-password` → 403.
- Owner attempts `set-password` on a user belonging to a different Gym → 403 (or 404 to avoid leaking existence, consistent with your existing patterns).
- `forgot-password` with an identifier that doesn't exist → 200, generic message, **no token created, no dispatch triggered** (verify no Messenger message enqueued).
- `reset-password` with an expired token → 400, generic "invalid or expired" message.
- `reset-password` with an already-used token → 400, same generic message.
- `reset-password` replayed twice with the same valid token → second attempt fails (token consumed on first success).
- `change-password` called by an authenticated user without `currentPassword` when `requiresPasswordChange` is false and `password` is not null → 400 (current password required in the normal case).
- Exceeding `forgot-password` rate limit → 429.
- Login as a user with `requiresPasswordChange = true` → JWT still issued, but `password_change_required: true` present; verify frontend gate (manual/E2E, not a pure backend test).
- OTP-only user (`password IS NULL`) runs `forgot-password` → `reset-password` successfully sets their first password, `requiresPasswordChange` ends up `false`, subsequent OTP login still works unaffected.

---

## 9. Verification Checklist

- [ ] Migration adds `password` (if not already present), `requiresPasswordChange`, `passwordSetBy`, `passwordSetAt` to `User` — all nullable/defaulted, no backfill required.
- [ ] `PasswordResetToken` entity + migration created with partial unique index on `(user_id) WHERE used_at IS NULL AND expires_at > now()` semantics (enforced at application level if partial index with time-based predicate isn't practical in Postgres — confirm approach during implementation).
- [ ] `set-password` endpoint enforces Owner + same-Gym Voter.
- [ ] `forgot-password` / `reset-password` fully functional for both password-holding and OTP-only (`password IS NULL`) users, verified with a dedicated test for the OTP-only case.
- [ ] Reset dispatch reuses `WhatsAppOtpSender` → Brevo → Twilio chain via Messenger, no new delivery code paths created.
- [ ] All refresh tokens invalidated on successful password reset.
- [ ] Rate limits active on `forgot-password` and `reset-password`.
- [ ] Frontend: forced-change gate screen, forgot-password screen, reset-password screen, Owner-facing "Set Password" action with one-time credential display.
- [ ] All negative/403 cases in Section 8 covered by tests.
- [ ] No existing OTP login test regresses.
