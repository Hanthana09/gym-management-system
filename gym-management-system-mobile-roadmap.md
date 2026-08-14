# Gym Management System — Mobile App Development Roadmap

**Companion to:** `gym-management-system-development-roadmap.md` (the web build this reuses), `gym-management-system-architecture.md` (data model, Voters, API — unchanged by this doc), `DESIGN-SYSTEM.md` (tokens ported to native, not reinvented), and `gym-management-system-functional-requirements.md` (every acceptance criterion already written for the web app applies here unchanged — a check-in is a check-in regardless of client).

**Scope note:** the architecture doc's §3.2/§4 originally scoped a mobile app narrowly ("optional, Member check-in"). This roadmap expands that to full feature parity across all four roles — Owner, Coach, Staff, Member — per direct request, i.e. "same facility, mobile app." It does not change that decision unilaterally elsewhere in the other docs; treat this file as the authority for mobile scope specifically.

**What this reuses vs. what's new:**
- **Backend: nothing new**, with one exception (push notification device-token registration, Phase 7). Every endpoint, Voter, and entity from the web build's Phases 0–15 is reused as-is — this is a new client against an already-finished API, not a second backend.
- **Design tokens: ported, not reinvented.** `DESIGN-SYSTEM.md`'s colors/type/component patterns move into a React Native theme object; the visual language stays identical across web and mobile.
- **Business logic: reused where the runtime allows.** API client functions, TanStack Query hooks, and validation logic are written once against fetch/TypeScript and shared between web and mobile via a workspace package (Phase 0) — only the *view* layer (JSX → React Native primitives, Tailwind classes → NativeWind) is rewritten per phase.

**Tech stack decision:** React Native + Expo (managed workflow), TypeScript, React Navigation, NativeWind (Tailwind-for-RN — lets `DESIGN-SYSTEM.md`'s exact class names carry over), TanStack Query (same as web), Expo SecureStore (token storage), Expo Notifications (push). Rationale: the team already knows React + TypeScript + Tailwind + TanStack Query from the web build (architecture doc §4 already flagged React Native for this reason) — Expo's managed workflow avoids native Xcode/Android Studio build maintenance until a specific native module genuinely requires ejecting.

**How to use this file:** same discipline as the web roadmap — work top to bottom, don't start a phase before the prior one's Definition of Done is checked off, and don't start this file at all before the corresponding web phase exists (Phase *N* here assumes web Phase *N*'s backend and functional requirements are already built and stable — this is a second client, not a race against the first one).

---

## Phase 0 — Mobile Environment & Tooling Setup

**Goal:** a running Expo app on a real device/simulator, authenticated against the same backend the web app already talks to.

- [ ] `npx create-expo-app gym-management-mobile --template` (TypeScript template), install React Navigation, NativeWind, TanStack Query, Expo SecureStore, Expo Notifications
- [ ] Extract the web frontend's `apiClient.ts` request/error-handling logic and `AuthContext`-adjacent types into a shared `packages/api-client` workspace package (pnpm/npm workspaces or a simple monorepo layout) — both apps import from it instead of maintaining two copies of the same `fetch` wrapper and `ApiError` class
- [ ] Point the shared client at the same backend used by web dev (`https://localhost:8543` from a simulator, the LAN IP from a physical device — Expo's dev client makes this reachable without the web app's Vite-proxy trick, since there's no browser same-origin/cookie constraint to work around on native)
- [ ] Confirm Expo Go (or a dev build) runs on both an iOS simulator and an Android emulator on the development machine
- [ ] Set up EAS (Expo Application Services) project for future build/submit steps (Phase 14) — do this now so `app.json`/`eas.json` exist from day one rather than being retrofitted later

**Definition of Done:** the Expo app makes one successful authenticated-looking `fetch()` to a backend health-check endpoint (reusing the shared API client package), visible in Expo's dev tools, on both a simulator and a physical device on the same network.

---

## Phase 1 — Mobile Design Foundation & Navigation Shell

**Goal:** the same discipline as web Phase 1 — visual and navigation rules decided before any real screen is built.

- [ ] Port `DESIGN-SYSTEM.md` §1–2's color/type tokens into `tailwind.config.js` for NativeWind — same token names (`paper`, `ink`, `hivis`, `owner`/`coach`/`member`, etc.) so a component built on web and one built on mobile read as the same design system, not two
- [ ] Load `Oswald`/`Space Grotesk`/`IBM Plex Mono` via `expo-font` (native font loading, not a `<link>` tag) — same three-typeface split as web (§2's rules on when each is used)
- [ ] Build the shared native component library first, mirroring web's Phase 1 primitives one-for-one: `Button`, `Input`, `Select` (native picker), `Card`, `BottomSheet` (a real bottom sheet library, e.g. `@gorhom/bottom-sheet`, not a CSS modal — mobile finally gets the *actual* pattern web only approximated), `NavShell`-equivalent
- [ ] Navigation shell per role, via React Navigation: **Member** gets a bottom tab navigator (Check-in, Sessions, Tracking, Notifications — identical tab order to web's `MEMBER_NAV_ITEMS`); **Owner/Coach/Staff** get a drawer navigator (the mobile equivalent of web's sidebar-with-hamburger-drawer pattern) — same role-based branching web's `NavShell` already does, just a different navigation primitive per platform
- [ ] Decide the offline/loading-state convention now (skeleton screens vs. spinners) — mobile networks are less reliable than a dev machine's localhost, and this decision compounds across every screen from here on
- [ ] A `/dev/components`-equivalent screen (a debug-only stack screen, gated behind `__DEV__`) rendering every primitive, mirroring web's own dev gallery

**Definition of Done:** the native component library renders correctly on both a small phone (iPhone SE simulator / a small Android emulator) and a large phone/tablet, and one real screen (login) is built entirely from those primitives with the ported design tokens visibly matching the web app's look.

---

## Phase 2 — Auth Foundation (Password + OTP)

**Backend:** none — reuses `POST /auth/login`, `POST /auth/otp/request`, `POST /auth/otp/verify`, `POST /auth/refresh` exactly as built for web (architecture doc §6.1, §8.5).

**Mobile app:**
- [ ] Login screen: password/OTP toggle, same UX decision as web Phase 2, native `TextInput`s with correct `keyboardType`/`textContentType` (`emailAddress`, `password`, `oneTimeCode`) so iOS/Android autofill and SMS OTP autofill work natively — this is strictly *better* than the web's `autocomplete="one-time-code"` approximation, not a downgrade
- [ ] OTP entry: 6 individual digit inputs, auto-advance, native SMS autofill (`textContentType="oneTimeCode"` on iOS, `autoComplete="sms-otp"` on Android)
- [ ] Token storage: **Expo SecureStore** (backed by iOS Keychain / Android Keystore) for the refresh token — the mobile equivalent of the web's httpOnly-cookie decision, same reasoning (never in plain AsyncStorage/localStorage-equivalent)
- [ ] Biometric unlock (Face ID / fingerprint) as an *optional* convenience layer on top of the stored refresh token via `expo-local-authentication` — this is new relative to web (no equivalent there) since it's a natively-available UX that meaningfully speeds up the single most repeated flow (opening the app to check in)

**Definition of Done:** can log in via both password and OTP on both a simulator and a physical device, a protected screen redirects to login when the token is missing/expired, and the refresh token survives an app restart (persisted in SecureStore) but not an app *uninstall* (Keychain/Keystore semantics, confirmed intentionally).

---

## Phase 3 — Invitations & Onboarding

**Backend:** none — reuses `POST /invitations`, `GET /invitations/me`, `PATCH /invitations/:id/approve|decline` (architecture doc §6.7, §9.1 `InvitationVoter`).

**Mobile app:**
- [ ] Owner: "Invite" flow — same single form (destination + role, including `staff` per web Phase 15.1), reachable via a floating action button (native equivalent already fits mobile conventions directly, no adaptation needed)
- [ ] Invitee: pending-invitation card shown immediately after login, Approve/Decline as large native buttons
- [ ] Push notification (once Phase 7 exists) on "you've been invited" — until then, the invitee still sees it via the in-app card on next login, same as web pre-Phase-7

**Definition of Done:** an Owner invites a test account from the mobile app, that account logs in on a second device/simulator and approves it, and the Owner's pending-invitations list updates (poll or Mercure-over-WebSocket — see Phase 7's note on why native push replaces in-app Mercure for background delivery, but foreground live-update can still subscribe the same way web does).

---

## Phase 4 — Membership Management

**Backend:** none — reuses plan CRUD, enrollment, pause/cancel endpoints (architecture doc §6.2).

**Mobile app:**
- [ ] Owner: plan management as a card list (mobile has no desktop-table equivalent to fall back to, so this is the *only* layout — simpler than web's dual card/table responsive split)
- [ ] Member: "My membership" screen applying the **Badge** pattern (`DESIGN-SYSTEM.md` §3/§4) — this is the one screen where a native card most needs to feel like a real object; consider a subtle native shadow/elevation treatment that CSS can only approximate, since this is the actual "ID card" the whole visual concept is named after

**Definition of Done:** Owner creates a plan on-device, Member sees accurate plan/status data on-device, Badge screen visually matches the web version's token usage (same role-color stripe, same badge-number formatting).

---

## Phase 5 — Attendance (Check-In) — the flagship mobile screen

**Backend:** none — reuses `POST /members/me/checkin`, the Owner/Staff front-desk variant `POST /members/:id/checkin` (web Phase 15.1), and the `attendance.checked_in` event (architecture doc §6.3, §8.1).

**Mobile app — this is the single most important screen in the entire mobile app, more so than on web:**
- [ ] One-tap check-in, no scrolling, from the first bottom tab — identical interaction to web's Phase 5 button, but now genuinely reachable in under 2 seconds from a locked phone (biometric unlock from Phase 2 → tab already selected on launch → tap)
- [ ] Home-screen quick action (iOS Home Screen Quick Actions / Android App Shortcuts) that deep-links straight to the check-in screen, skipping the app's normal launch screen entirely — a mobile-native capability with no web equivalent, and the highest-leverage addition this platform makes to the single most-repeated action in the whole product
- [ ] Offline-tolerant exactly like web: failed request shows a clear retry state, never a silent failure — same functional requirement, same UI contract
- [ ] Owner/Staff: front-desk check-in screen (member search/lookup + tap-to-check-in), the mobile equivalent of the `StaffDashboardPage` built in web Phase 15.1 — genuinely useful on a phone at a front desk without needing a tablet/kiosk
- [ ] Live attendance counter for Owner, subscribed the same way web's dashboard does (Mercure), foreground-only — see Phase 7 for what happens when the app is backgrounded

**Definition of Done:** check-in completes in under 2 taps from a locked phone on a real device (not a simulator — test this specific flow on physical hardware, same "test on a real phone" discipline the web roadmap calls out for its own Phase 5), and the Owner's live counter updates without a manual refresh while the app is foregrounded.

---

## Phase 6 — Personal Training Booking

**Backend:** none — reuses request/accept/decline endpoints and `session.requested`/`session.confirmed` events (architecture doc §6.4, §8.2).

**Mobile app:**
- [ ] Member: book a session — native date/time pickers (`@react-native-community/datetimepicker`), coach picker as a native list/modal
- [ ] Coach: schedule as an agenda/list view (a full calendar grid is even less useful on a phone than it was on web's 375px case — don't attempt a grid at any mobile size; reserve a real calendar grid for a future tablet-specific layout if one is ever built)
- [ ] Accept/decline as swipeable list-row actions (`react-native-gesture-handler` swipeable) — a genuinely mobile-native pattern that improves on web's button-only approach, appropriate to add here since it doesn't change the underlying action, just how it's triggered

**Definition of Done:** full booking loop (request → coach notified → accept → member notified) works end to end on-device for both roles.

---

## Phase 7 — Push Notifications

**Backend (the one new piece in this whole roadmap):**
- [ ] `PushToken` entity (`user_id`, `expo_push_token`, `platform`, `created_at`) + migration — a device registers its Expo push token after login
- [ ] `POST /users/me/push-tokens` (register), `DELETE /users/me/push-tokens/:token` (unregister on logout) — any authenticated user, scoped to self, no new Voter needed (same "own account" reasoning as `PATCH /users/me/notification-preferences` from web Phase 15.3)
- [ ] Extend `NotificationService::notify()` with **one more line**, same shape as the WhatsApp addition from web Phase 15.3: dispatch a `SendNotificationPushMessage` alongside the existing email/WhatsApp dispatches. **Same rule as 15.3's WhatsApp work applies here — zero changes to any event-emitting module.** A `SendNotificationPushMessageHandler` calls the Expo Push API (`https://exp.host/--/api/v2/push/send`) with the user's registered tokens.

**Mobile app:**
- [ ] Request notification permission at a sensible moment (after first login, with a clear explanation — not an immediate OS prompt on cold launch, which has poor opt-in rates)
- [ ] Register the device's Expo push token on login, unregister on logout (calls the new endpoint above)
- [ ] Foreground notification handling (in-app banner, matching web's bell/unread-badge concept) + background/killed-app handling (native OS notification, tapping it deep-links into the relevant screen — a session confirmation opens the sessions tab, a check-in-blocked issue opens check-in, etc.)
- [ ] This is what makes the app usable when backgrounded — Mercure (used for web's *foreground* live updates) has no equivalent when an app isn't running; push notifications are the mobile answer to "how does a Coach find out about a new booking request without the app open"

**Definition of Done:** a notification triggered by another role's action arrives as a real OS push notification within a few seconds when the app is backgrounded or closed, tapping it opens the app to the relevant screen, and — critically — confirm via `git diff` that no event-emitting module changed, exactly the same verification discipline web Phase 15.3 used for WhatsApp.

---

## Phase 8 — Personal Tracking

**Backend:** none — reuses `WorkoutLog`/`BodyMetric` CRUD (architecture doc §6.5).

**Mobile app:**
- [ ] Log-a-workout as a short native form — this is the one screen where mobile has a real advantage over web: a home-screen quick action or a post-check-in prompt ("Log today's workout?") can cut the time-to-log further than web's best case, since the phone is already in-hand at the gym
- [ ] Progress chart via `react-native-svg`-based charting (e.g. `victory-native` or `react-native-gifted-charts`) — simplified touch-friendly data points, matching web's own mobile-simplified chart approach from Phase 8

**Definition of Done:** a Member logs a workout in under 30 seconds on-device and sees it in the trend chart immediately.

---

## Phase 9 — Growth & Retention Features

**Goal:** same rationale as web Phase 9 — these map to the go-to-market doc's pillars, not generic features. Bulk import is genuinely a desktop-shaped task (uploading/mapping a CSV) and is explicitly **out of scope for mobile** — Owners already have it on web; don't rebuild a file-picker CSV flow for a phone screen where it adds no real value.

**Backend:** none — reuses `POST /referrals`, `ReferralCode`, and the `member.milestone_reached` event (architecture doc, web Phase 9.2/9.3).

**Mobile app:**
- [ ] Coach: "Recommend this to another gym" action, same lightweight lead-capture form as web
- [ ] Owner: referral code/link screen with a **native share sheet** (`expo-sharing`) — this is a direct upgrade over web's copy-link pattern, since a native share sheet reaches WhatsApp/SMS/email directly instead of requiring a manual paste
- [ ] Milestone celebration: native modal/haptic feedback (`expo-haptics`) on streak thresholds, **Share** action generating an image (via `react-native-view-shot` to snapshot the Badge/Ticket-styled celebration card) through the native share sheet — this is where mobile genuinely outperforms web's best approximation of "native share," since it *is* native

**Definition of Done:** a Coach submits a lead in under 30 seconds on-device, an Owner shares their referral link via the native share sheet, and a Member hitting a milestone can share a generated image through the native share sheet in one tap.

---

## Phase 10 — Billing & Payments (Manual)

**Backend:** none — reuses `Invoice` CRUD and `PATCH /invoices/:id/mark-paid` (architecture doc §6.9, §9.1 `InvoiceVoter`).

**Mobile app:**
- [ ] Owner: "Mark as paid" action with payment-method selector — genuinely useful at a front desk on a phone, same as the front-desk check-in case in Phase 5
- [ ] Member: invoice history, read-only list
- [ ] Owner: outstanding-invoices view, same priority-over-history reasoning as web Phase 10

**Definition of Done:** the same end-to-end loop as web Phase 10 (enroll → invoice → mark paid → membership activates → notified) verified on-device, including `InvoiceVoter`'s `MARK_PAID` rejection of a Member attempting it — confirm the mobile UI doesn't even render the action for a Member (defense in depth: hidden in UI *and* rejected server-side).

---

## Phase 11 — Analytics & Reporting

**Goal:** same sequencing rationale as web Phase 11 (needs real `Invoice` history to be meaningful) — this phase only starts once web Phase 11 already has real data flowing through it, since mobile reuses that same aggregated data, not a second aggregation pipeline.

**Backend:** none — reuses `GET /reports/dashboard`, `/reports/attendance`, `/reports/revenue-forecast`, `/reports/retention` (architecture doc §6.8). **`GET /reports/export`'s CSV/PDF download is explicitly out of scope for the mobile app** — a phone is a poor place to receive and view an exported file; Owners doing exports stay on web for that specific action, same "let the right device do the right job" reasoning as bulk import in Phase 9.

**Mobile app:**
- [ ] Dashboard: live counters (same Mercure-while-foregrounded pattern as Phase 5's attendance counter) + trend/forecast charts, simplified for a phone screen (fewer visible data points than web's `lg:` layout, matching web's own *mobile* dashboard treatment, not its desktop one)
- [ ] Forecast chart visually distinguishes historical from projected data — same non-negotiable rule as web (functional requirements §10.3), just as important on a smaller canvas
- [ ] Retention list: each at-risk member with their specific reason, same as web — this is a list screen, translates directly with no mobile-specific complication
- [ ] "Not enough data yet" empty state, same as web

**Definition of Done:** an Owner views live numbers, a trend chart, and a retention list with reasons, all on-device, scoped to their own gym — same `403`-verified scoping as web, tested against the mobile client specifically (a Voter bug wouldn't care which client hit it, but the mobile request path should still be exercised at least once, not assumed identical by inference).

---

## Phase 12 — Owner Experience Expansion Parity (Staff, Branding, WhatsApp)

**Goal:** mobile parity with web Phase 15's three features — sequenced last among the "same facility" feature phases since, like web's own Phase 15, none of them block anything earlier.

**Backend:** none — reuses everything from web Phase 15.1–15.3 and this doc's own admin-config follow-up (gym-wide WhatsApp switch + credentials, `GymVoter`).

**Mobile app:**
- [ ] Staff: the scoped-down read-only member list + check-in action from Phase 5's front-desk screen already covers this role's entire mobile surface — confirm no additional screen is needed rather than building one speculatively (Staff is deliberately the narrowest role; the mobile app should reflect that as directly as the web app does)
- [ ] Owner: branding settings screen (logo upload via `expo-image-picker`, native color picker — no exact RN equivalent of `<input type="color">`, so use a small color-swatch picker library, e.g. `react-native-wheel-color-picker`) — same boundary rule as web: brand color applies **only** to the Badge stripe and nav header area, never to `hivis` or role-tag colors, enforced identically on this client
- [ ] Owner: WhatsApp admin section (enable switch, credential fields) — same write-only access-token UX as web (never pre-filled, "configured" placeholder only)
- [ ] Any role: notification-preferences toggle (WhatsApp opt-in + the new push-notification permission from Phase 7, presented together as one "Notifications" settings screen — more natural grouping on mobile than web's split between account-settings toggle and OS-level permission)

**Definition of Done:** every Phase 15 web capability (and its 403 boundaries) is reachable and correctly scoped from the mobile app; a dedicated visual check confirms brand color still doesn't leak into `hivis`/role-tag colors on this client, mirroring web's own dedicated check for the same rule.

---

## Phase 13 — Cross-Device Testing & QA

- [ ] Manual pass on real devices: one physical iPhone, one physical Android phone, at minimum one small-screen and one large-screen device per platform if available — simulators/emulators for daily development, but this phase specifically requires hardware (push notifications, biometrics, and share sheets don't fully work in simulators)
- [ ] Automated testing via **Detox** or **Maestro** for the core flows (login, check-in, booking) — the mobile equivalent of web's Playwright responsive smoke tests, adapted to app-navigation testing rather than viewport-width testing
- [ ] Accessibility pass: VoiceOver (iOS) and TalkBack (Android) on icon-only buttons, minimum touch target 44×44pt (iOS) / 48×48dp (Android) — same 44px-class rule as web Phase 1, expressed in each platform's native unit
- [ ] Performance audit: cold-start time and check-in-screen time-to-interactive, budgeted now the same way web Phase 1 budgeted initial JS size — a slow cold start undermines the entire "check-in in under 2 taps" goal from Phase 5
- [ ] Role/permission regression pass: same Voter-level 403 sweep as web Phase 12, exercised through the mobile client specifically for at least the highest-traffic endpoints (check-in, member list, invoices)

**Definition of Done:** cold start to interactive check-in screen under 2 seconds on a mid-range physical device, zero critical accessibility violations on both platforms, all Voter 403 cases confirmed reachable and correctly rejected from the mobile client.

---

## Phase 14 — App Store & Play Store Release

- [ ] App Store Connect + Google Play Console developer accounts provisioned
- [ ] App icons, splash screen, and store listing assets — reusing `DESIGN-SYSTEM.md`'s tokens (the `hivis`/`ink`/`paper` palette) so the store listing itself looks like the same product as the web app's marketing surface
- [ ] Privacy policy and data-collection disclosures (App Store's "App Privacy" questionnaire, Play Console's "Data safety" form) — accurate to what's actually collected: health-adjacent data (`BodyMetric`/`health_notes`, architecture doc §9's encryption-at-rest note applies here too), location is *not* collected (no geofenced check-in in this roadmap), push token, standard account fields
- [ ] EAS Build for production iOS/Android binaries, EAS Submit for store submission
- [ ] Staged rollout (Play Store's staged rollout percentage, TestFlight beta on iOS first) rather than a 100% day-one release — catches device-specific crashes on a small cohort before the full user base sees them
- [ ] CI pipeline (EAS Build triggered from CI, or GitHub Actions calling `eas build`) mirroring web's Phase 13 lint → test → build → deploy discipline, adapted to mobile's build-then-submit shape (there's no "deploy to production" in the same instant sense — a store review step sits in between)

**Definition of Done:** the app is live (or in staged rollout) on both the App Store and Play Store, a TestFlight/internal-testing build was verified on real hardware before public release, and the core flows (login, check-in, booking) work against production from a real device on a real cellular/wifi network.

---

## Phase 15 — Post-Launch (mobile-specific enhancements)

- [ ] Offline check-in queue: if Phase 5's check-in request fails due to no connectivity (not a business-rule rejection like an expired membership — a genuine network failure), queue it locally and retry automatically when connectivity returns, surfacing a clear "will check in when back online" state rather than just a retry button — a meaningful step beyond web's retry-on-failure pattern, justified because a phone at a gym door is exactly where flaky wifi/cellular is most likely
- [ ] Apple Watch / Wear OS companion for one-tap check-in (genuinely optional, evaluate demand from real usage data before building — same "don't guess this upfront" discipline web Phase 9.3 already applied to milestone selection)
- [ ] Widget (iOS/Android home-screen widget) surfacing the live attendance counter for Owners, or a one-tap check-in button for Members, without opening the app at all
- [ ] Deep link handling for every push notification type from Phase 7, expanded as new notification types are added, so "tap notification → land on the exact relevant screen" stays true as the product grows

---

## Quick reference — what changes vs. the web roadmap, and what doesn't

| | Web | Mobile |
|---|---|---|
| Backend | Built across Phases 0–15 | **Reused as-is**, except one new push-token endpoint (Phase 7) |
| Design tokens | `DESIGN-SYSTEM.md` → Tailwind config | Same doc → NativeWind config, same token names |
| Responsive strategy | 375px-first, widen with breakpoints | N/A — phone-only layouts; no desktop fallback to design around |
| Live updates (foregrounded) | Mercure | Mercure (same mechanism, foreground only) |
| Live updates (backgrounded) | N/A (browser tab must be open) | Push notifications (Phase 7) — this is mobile's biggest structural addition |
| Bulk import, report export | In scope | Explicitly out of scope — stays a web-only, desktop-shaped task |
| Definition of Done discipline | Test on a real phone at the end of every phase | Test on real *hardware* (not just simulator) at the end of every phase — even stricter, since push/biometrics/share-sheets don't fully simulate |
