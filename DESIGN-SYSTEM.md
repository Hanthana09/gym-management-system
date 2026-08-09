# Design System — extracted from the GymHub prototype

**Source:** the original GymHub multi-gym artifact (network-map hero, ticket/badge cards). This doc formalizes those tokens for the real project so every phase — including the ones already built — uses the same visual language. Add this file to the repo root alongside the other four docs, and treat it as authoritative for anything visual, the same way the architecture doc is authoritative for data/permissions.

## Why this exists

Phase 1 shipped a generic component library (brand blue, default Tailwind palette) because the design direction wasn't locked in yet at that point. This doc is the retrofit: the same tokens and component patterns from the original concept, translated into real Tailwind config instead of the CSS-variable workaround the artifact sandbox required.

---

## 1. Color tokens

Add to `tailwind.config.ts` under `theme.extend.colors`:

```ts
colors: {
  paper: {
    DEFAULT: "#ECEDE6",   // page background
    dim: "#E1E3D6",       // secondary surface (nav hover, table header)
  },
  card: "#F6F6F0",         // card/panel surface, sits slightly above paper
  ink: {
    DEFAULT: "#14181A",    // primary text, dark surfaces (topbar)
    soft: "#565F5A",       // secondary text, captions, timestamps
  },
  line: "#C7CBBB",          // hairlines, dashed ticket borders
  hivis: "#D6FA3C",         // the one bright accent — CTAs, active states, unread badges. Use sparingly.

  // Role accent colors — used consistently for badges, tags, nav accents,
  // and anything that needs to visually say "this is an Owner/Coach/Member thing"
  owner: { DEFAULT: "#4A3AFF", soft: "#ECEAFF" },
  coach: { DEFAULT: "#256B57", soft: "#E2EFE9" },
  member: { DEFAULT: "#E8611F", soft: "#FDE9DC" },
},
```

**Rules:**
- `hivis` is the *only* saturated accent used for actions (primary CTA buttons, unread indicators, the check-in button). Don't introduce a second bright color for the same purpose.
- Role colors (`owner`/`coach`/`member`) are for identity, not action — badge stripes, role tags, nav active-state accents keyed to the logged-in user's role. Never use a role color as a button's action color.
- `ink` (near-black, not pure black) is the dominant text/surface color, paired with the `paper` off-white background — not a dark-mode-first palette.

## 2. Typography

```ts
fontFamily: {
  display: ['"Oswald"', "sans-serif"],       // headings, big numbers, station/screen titles — always uppercase
  sans: ['"Space Grotesk"', "sans-serif"],    // body text, UI labels — the default
  mono: ['"IBM Plex Mono"', "monospace"],     // badge numbers, timestamps, data values, tags
},
```

Load via `index.html`:
```html
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
```

**Rules:**
- `font-display` (Oswald) is always `uppercase tracking-wide` — screen titles, section headers, the big number on a stat card.
- `font-mono` is reserved for anything that reads as *data*: badge IDs, check-in timestamps, member counts — never for prose or labels a human wrote.
- Body copy is `font-sans` (Space Grotesk) throughout; this is the default, so most components don't need to declare it explicitly.

## 3. Component patterns (map onto Phase 1's existing primitives — extend, don't replace)

| Pattern | Where it applies | Key classes |
|---|---|---|
| **Card** | Default panel/container | `bg-card border border-line rounded` |
| **Ticket** | Anything representing a discrete, scannable "event" — attendance rows, PT session rows, invitation rows | `bg-card border-2 border-dashed border-line rounded-xl` + two small circular "punch holes" at the vertical mid-point of the left/right edges (see prototype's `::before`/`::after` — recreate as two absolutely-positioned `rounded-full` divs colored `bg-paper` with a dashed border) |
| **Badge (ID card)** | Member's membership card, any "this is who I am" surface | White card, `rounded-xl`, a `h-2.5` top stripe colored by role (`bg-owner`/`bg-coach`/`bg-member`), `font-mono` badge number top-right, initials avatar in a role-colored soft circle |
| **Tag/pill** | Status labels (active/paused/pending), role labels | `font-mono text-xs uppercase tracking-wide rounded-full px-2 py-0.5`, background = role/status `-soft` color, text = role/status `DEFAULT` color |
| **Primary button** | Default action | `bg-ink text-paper rounded hover:bg-ink/90` |
| **Hivis button** | The one emphasized action per screen (e.g. check-in) | `bg-hivis text-ink font-semibold rounded` |
| **Nav item (active state)** | Sidebar/tab active indicator | left border accent (`border-l-[3px] border-ink`) rather than a filled background — matches the prototype's understated active state |

## 4. Signature motif — carry it forward deliberately

The original concept's throughline was **"one badge, every station"** — a transit-network visual language (gyms/features as connected stations, membership as a badge that works everywhere). Even though the product scope narrowed to a single gym, keep the *visual* vocabulary:

- The **ticket pattern** (dashed border, punch holes) is well suited to Attendance rows in Phase 5 — each check-in *is* a ticket stub. Use it there specifically, not just decoratively elsewhere.
- The **badge pattern** belongs on the Member's "My membership" screen (Phase 4) and nowhere else — it's meant to feel like a single, special object, not a generic card style reused everywhere.
- Resist the urge to reintroduce the network-map SVG hero — that was specific to the multi-gym hub concept and doesn't fit the single-gym product; the color/type/card system is what's worth keeping.

## 5. Applying this to already-built phases

Phases 1–4 were built before this doc existed, so they're on generic styling. Retrofitting is mechanical, not a redesign — same components, same structure, new tokens:

- [ ] Phase 1: update `tailwind.config.ts` with §1–2 above; restyle `Button`, `Card`, `NavShell` to use the new tokens; update the `/dev/components` gallery.
- [ ] Phase 2: login/OTP screens — restyle using `Card`/`Button` now that they carry the right tokens (should mostly cascade automatically if Phase 1's primitives were updated first).
- [ ] Phase 3: invitation cards → apply the **Ticket** pattern (an invitation is exactly the kind of discrete "event" that pattern fits).
- [ ] Phase 4: Member's "My membership" screen → apply the **Badge** pattern specifically here.
- [ ] Phase 5 (in progress): Attendance history rows → **Ticket** pattern; the check-in button itself → **Hivis button** pattern (it's the single most-repeated primary action in the whole app, exactly what `hivis` is for).
