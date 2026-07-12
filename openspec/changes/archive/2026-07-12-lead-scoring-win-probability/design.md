# Design: lead-scoring-win-probability

## Context

The `lead` schema in `lib/Settings/pipelinq_register.json` already carries four
declarative calculations under `configuration.x-openregister-calculations`:
`daysSinceActivity` and `daysInStage` (`materialise: false`, `dateDiff`-based),
`weightedValue` (`materialise: false`, `value * probability / 100`), and
`qualificationScore` (`materialise: true`, a weighted `+`/`if` sum). It also stores
a `probability` integer (0–100, `visible: false`) that the stage-change writer
denormalises from the pipeline stage. This change adds one more calculation,
`winProbability`, and surfaces it — no new schema, no service, no PHP.

## Goals / Non-Goals

**Goals:**
- A single recency-aware win-probability number per lead, fresh on every read.
- Fully declarative: one calculation block + two `src/manifest.json` edits.
- Reuse the existing calculation dialect and the existing `lead-probability` cell widget.

**Non-Goals:**
- No ML/AI scoring (Einstein-style). The decay is a transparent, deterministic rule.
- No change to the manual `probability` field or the stage-probability denormalisation.
- No second "band/colour" calculation — colour banding is done in the manifest cell widget.

## Decisions

### The `winProbability` calculation (declarative)

Added to `lead.configuration.x-openregister-calculations`:

```jsonc
"winProbability": {
  "type": "integer",
  "materialise": false,
  "description": "Recency-decayed win probability (0-100): the lead's stage-denormalised `probability`, decayed by inactivity (full <=14d, 80% <=30d, 50% <=60d, else 25%). materialise:false so a stalling deal cools on read with no write. Single-object (ADR-031): reads @self.updated + the lead's own probability only.",
  "expression": {
    "if": [
      { "gt": [ { "dateDiff": { "from": { "prop": "@self.updated" }, "to": "now", "unit": "days" } }, 60 ] },
      { "/": [ { "*": [ { "prop": "probability" }, 25 ] }, 100 ] },
      { "if": [
        { "gt": [ { "dateDiff": { "from": { "prop": "@self.updated" }, "to": "now", "unit": "days" } }, 30 ] },
        { "/": [ { "*": [ { "prop": "probability" }, 5 ] }, 10 ] },
        { "if": [
          { "gt": [ { "dateDiff": { "from": { "prop": "@self.updated" }, "to": "now", "unit": "days" } }, 14 ] },
          { "/": [ { "*": [ { "prop": "probability" }, 8 ] }, 10 ] },
          { "prop": "probability" }
        ] }
      ] }
    ]
  }
}
```

Only the operators already proven in this register are used — `if`, `gt`, `*`, `/`,
and `dateDiff` (all present in the existing `daysSinceActivity` / `weightedValue` /
`qualificationScore` blocks). The `dateDiff(@self.updated → now)` sub-expression is
repeated inline rather than referencing the sibling `daysSinceActivity` calculation,
to avoid any dependency on calc-referencing-calc ordering.

**Why `materialise: false`:** recency decay must reflect the *current* clock. A
materialised (on-save) value would freeze at the last write and never cool — the
exact behaviour this change exists to fix. `daysSinceActivity` uses the same
`materialise: false` for the same reason.

### Surfacing (declarative, no Vue)

- **LeadDetail Deal widget** (`src/manifest.json`, widget id `lead-deal`): add
  `"winProbability"` to its `content.include` array, so the deal page renders it
  next to `probability`/`value`/`status`.
- **Leads index** (`src/manifest.json`, `Leads` page columns): add a
  `{ "key": "winProbability", "label": "Win %", "widget": "lead-probability" }`
  column, reusing the already-registered `lead-probability` cell widget that the
  `probability` column uses — a colour-banded 0–100 renderer, no new component.

### Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Win-probability derivation | **Declarative** (`x-openregister-calculations`) | It is a derived/virtual field computed from single-object inputs (`@self.updated`, `probability`). This is precisely the calculation extension's purpose; a service method would be the anti-pattern ADR-031 names. |
| Recency (time-based) freshness | **Declarative, `materialise:false`** | Read-time computation keeps the value current with no scheduled job — ADR-031's preferred alternative to a "walk the queue and re-score" background job. |
| Colour banding of the number | **Declarative (manifest cell widget)** | Reuses the existing `lead-probability` registered renderer; no per-page code. |

No exception to ADR-031 is taken; nothing lands in PHP.

## Seed Data

The main register already seeds five leads (Gemeente Amsterdam, Provincie
Zuid-Holland, Rijkswaterstaat, GGD Rotterdam, Waterschap Hollandse Delta). Because
`winProbability` decays from `@self.updated` (which the importer sets to import
time), fresh seed leads all read their full probability initially. To make the
decay observable and cover the standard organisation archetypes, ensure the seed
spans the bands with explicit, obviously-placeholder identifiers:

- **Municipality (hot):** `{ "title": "Gemeente Voorbeeld – KCC portaal", "value": 45000,
  "probability": 70, "stage": "Gekwalificeerd", "status": "open", "source": "referral" }`
  — recently updated → `winProbability` = 70.
- **Consultancy (warm):** `{ "title": "Meridiaan Advies – data-governance retainer",
  "value": 18000, "probability": 50, "stage": "Voorstel", "status": "open", "source": "partner" }`
  — a stalled deal → decays toward 40/25.
- **Travel agency (cold):** `{ "title": "Zonnereizen – boekingsplatform",
  "value": 9000, "probability": 40, "stage": "Nieuw", "status": "open", "source": "cold-call" }`
  — long untouched → decays to ~10.

Seed object UUIDs follow the register's existing slug-only or explicit-id style;
any explicit example id in docs uses the nil UUID
`00000000-0000-0000-0000-000000000000`. Because decay depends on wall-clock age
relative to import time, the definitive band verification is the calculation unit
behaviour (the spec scenarios), with the seed providing a live, non-zero surface.

## Risks / Trade-offs

- **`dateDiff` operator/units availability** → Uses the exact `dateDiff` shape and
  `unit: "days"` already shipping in `daysSinceActivity`; if the engine rejected the
  repeated inline sub-expression, fall back to referencing `daysSinceActivity`. Covered
  by the calculation scenarios.
- **Seed decay is time-relative** → On a fresh import all leads look "hot"; the
  observable cold band only appears as the instance ages. Mitigation: the spec
  scenarios pin the exact band arithmetic; the seed is illustrative, not the gate.
- **Two probability numbers on one page** (`probability` + `winProbability`) could
  confuse → labels disambiguate ("Probability" vs "Win %"); the design keeps the raw
  `probability` for transparency into the decay input.

## Open Questions

- Should the decay thresholds (14/30/60 days, 80/50/25%) be admin-configurable, or
  is a fixed rule acceptable for V1? (Provisional: fixed rule for V1.)
- Should `winProbability` feed a *second* declarative field that replaces
  `weightedValue` (value × winProbability instead of value × probability) on
  forecasts? (Provisional: no — keep `weightedValue` on the manual probability;
  revisit if the forecast should also decay.)
