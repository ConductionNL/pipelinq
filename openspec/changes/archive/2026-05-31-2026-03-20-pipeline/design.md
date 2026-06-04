# Design: pipeline search and stage validation

**status: pr-created**

## Architecture Overview

Both features are pure frontend changes to existing components. No new files, no backend endpoints, no schema changes.

```
src/views/pipeline/
  PipelineBoard.vue   ← add searchQuery + filtered computed + search input in header

src/views/settings/
  PipelineForm.vue    ← add probability range validation + inline error per stage row
```

---

## Feature 1: Pipeline Search Bar (REQ-PIPE-022)

### How it works

`PipelineBoard.vue` already computes `allItems` — a merged array of all leads and requests on the active pipeline, keyed by stage. The search adds a `searchQuery` reactive data property that filters this computed output before it reaches the column renderer.

**Computed flow:**

```
pipelineStore.allItems  →  filteredItems (computed)  →  column renderer
                               ↑
                           searchQuery.toLowerCase()
                           item.title.toLowerCase().includes(...)
```

The filter runs entirely in the browser — no additional API calls.

**Header layout change:**

The pipeline header currently shows:
```
[Pipeline selector dropdown]    [Show: All ▾]    [Kanban | List toggle]    [+ Add Lead]
```

After this change:
```
[Pipeline selector dropdown]    [🔍 Search...]    [Show: All ▾]    [Kanban | List toggle]    [+ Add Lead]
```

The search input uses `NcTextField` from `@conduction/nextcloud-vue` with `type="search"` and a placeholder string passed through `t(appName, 'Search pipeline...')`.

### Key design decisions

**In-memory filter only** — The full pipeline item set is already fetched on pipeline select. A server-side search would require a new API call on every keystroke and introduces debounce complexity. Given that typical pipelines have <500 items, in-memory filtering is instantaneous and avoids API round-trips.

**Empty columns remain visible** — This is explicitly required by REQ-PIPE-022 ("empty columns remain visible"). The filter reduces `filteredItems` per column to an empty array; the column still renders its header and drop zone. This is important so the board is still usable as a drag target even when search is active.

**Column header counts and totals update reactively** — The column header currently computes its count and value from the per-column item array. Since that array is derived from `filteredItems`, the header values automatically reflect the search.

**Search is cleared on pipeline switch** — When the user picks a different pipeline from the dropdown, `searchQuery` is reset to `''` to avoid showing an empty board because the new pipeline's item titles don't match the previous search.

---

## Feature 2: Stage Probability Validation (REQ-PIPE-005 Scenario 24)

### How it works

`PipelineForm.vue` renders an inline stage editor — a list of stage rows, each with fields for name, order, probability, isClosed, and isWon. The `probability` field is a number input.

**Validation logic (per stage row):**

```javascript
function isProbabilityValid(value) {
  if (value === null || value === '' || value === undefined) return true  // optional field
  const num = Number(value)
  return Number.isInteger(num) && num >= 0 && num <= 100
}
```

Each stage row tracks its own `probabilityError` string (empty = no error). On `@input` of the probability field, the validation runs and sets `probabilityError` to `t(appName, 'Probability must be between 0 and 100')` if invalid, or `''` if valid.

The save button is disabled while `stages.some(s => s.probabilityError)`.

**Inline error display:**

The error message appears immediately below the probability number input, styled with `color: var(--color-error)` and `font-size: var(--default-font-size-small)`. No dialog is shown — this is inline field-level feedback consistent with the form pattern used elsewhere in the app.

### Key design decisions

**Client-side only** — OpenRegister schemas can enforce `minimum: 0, maximum: 100` on the probability property at the API level (server-side), but client-side feedback is faster and avoids a round-trip error response from the API. Both can coexist; this change adds the client-side layer.

**Null/empty allowed** — The `probability` field is optional per the pipeline entity spec. A stage with no probability set is valid; only explicit out-of-range integers are rejected.

**Block save on invalid state** — The save button is disabled (`disabled` attribute + `aria-disabled="true"`) while any stage has a validation error. This prevents the user from submitting and getting a confusing API error.

---

## Entity Seed Data

### pipeline (3 examples — Dutch context)

```json
[
  {
    "title": "Verkooppijplijn",
    "description": "Standaard verkoopproces voor leads en kansen",
    "totalsLabel": "EUR",
    "isDefault": true,
    "stages": [
      { "name": "Nieuw",          "order": 0, "probability": 10,  "isClosed": false, "isWon": false },
      { "name": "Gecontacteerd",  "order": 1, "probability": 20,  "isClosed": false, "isWon": false },
      { "name": "Gekwalificeerd", "order": 2, "probability": 40,  "isClosed": false, "isWon": false },
      { "name": "Offerte",        "order": 3, "probability": 60,  "isClosed": false, "isWon": false },
      { "name": "Onderhandeling", "order": 4, "probability": 80,  "isClosed": false, "isWon": false },
      { "name": "Gewonnen",       "order": 5, "probability": 100, "isClosed": true,  "isWon": true  },
      { "name": "Verloren",       "order": 6, "probability": 0,   "isClosed": true,  "isWon": false }
    ]
  },
  {
    "title": "Serviceverzoeken",
    "description": "Pijplijn voor burgerverzoeken en ondersteuningsvragen",
    "totalsLabel": "Uren",
    "isDefault": true,
    "stages": [
      { "name": "Nieuw",              "order": 0, "isClosed": false, "isWon": false },
      { "name": "In behandeling",     "order": 1, "isClosed": false, "isWon": false },
      { "name": "Afgerond",           "order": 2, "isClosed": true,  "isWon": true  },
      { "name": "Afgewezen",          "order": 3, "isClosed": true,  "isWon": false },
      { "name": "Omgezet naar zaak",  "order": 4, "isClosed": true,  "isWon": false }
    ]
  },
  {
    "title": "Overheidsaanbestedingen",
    "description": "Aanbestedingsprocedure voor gemeentelijke opdrachten",
    "totalsLabel": "EUR",
    "isDefault": false,
    "stages": [
      { "name": "Verkenning",     "order": 0, "probability": 10,  "isClosed": false, "isWon": false },
      { "name": "Aanmelding",     "order": 1, "probability": 30,  "isClosed": false, "isWon": false },
      { "name": "Beoordeling",    "order": 2, "probability": 50,  "isClosed": false, "isWon": false },
      { "name": "Gunning",        "order": 3, "probability": 85,  "isClosed": false, "isWon": false },
      { "name": "Toegewezen",     "order": 4, "probability": 100, "isClosed": true,  "isWon": true  },
      { "name": "Afgewezen",      "order": 5, "probability": 0,   "isClosed": true,  "isWon": false }
    ]
  }
]
```

### lead (4 examples — Dutch context, showing title field used in search)

```json
[
  {
    "title": "Gemeente Amsterdam — digitaal loket 2026",
    "value": 85000,
    "probability": 60,
    "stage": "Offerte",
    "stageOrder": 3,
    "source": "website",
    "priority": "high"
  },
  {
    "title": "Provincie Noord-Holland — CRM migratie",
    "value": 140000,
    "probability": 40,
    "stage": "Gekwalificeerd",
    "stageOrder": 2,
    "source": "referral",
    "priority": "normal"
  },
  {
    "title": "Waterschap Rijn en IJssel — portaalvernieuwing",
    "value": 55000,
    "probability": 80,
    "stage": "Onderhandeling",
    "stageOrder": 4,
    "source": "event",
    "priority": "urgent"
  },
  {
    "title": "GGD Hollands Midden — registratiesysteem",
    "value": 32000,
    "probability": 20,
    "stage": "Gecontacteerd",
    "stageOrder": 1,
    "source": "cold-call",
    "priority": "low"
  }
]
```

---

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/views/pipeline/PipelineBoard.vue` | MODIFY | Add `searchQuery` data, `filteredItems` computed, `NcTextField` search input in header; reset `searchQuery` on pipeline change |
| `src/views/settings/PipelineForm.vue` | MODIFY | Add `probabilityError` per stage row, validate on `@input`, disable save while any stage has error, render inline error text |
| `l10n/en.json` | MODIFY | Add translation keys: `'Search pipeline...'`, `'Probability must be between 0 and 100'` |
| `l10n/nl.json` | MODIFY | Add Dutch translations for the two new keys |

> `l10n` files are included as supporting changes; the core change targets 2 Vue files as stated in the proposal.
