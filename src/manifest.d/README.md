<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Manifest fragments (`manifest.d/`)

Modular frontend-manifest fragments for Pipelinq (ADR-037).

Every `*.json` file in this directory is **deep-merged** onto the bundled
`../manifest.json` by `mergeManifestFragments()` in `src/main.js` at build
time (via webpack `require.context`). The merged manifest feeds both the
vue-router route table and the `CnAppRoot` `manifest` prop.

## Why

Concurrent same-app feature builds used to conflict on the single shared
`manifest.json` (pages array, menu entries). Instead of editing that monolith,
a feature build drops its **own** fragment file here.

## Merge semantics

- Array values (`pages`, `menu`) from a fragment are **concatenated** onto the
  base arrays.
- Plain-object values merge **recursively**.
- Scalar values from a fragment **replace** the base value.
- Fragments are applied in sorted filename order — prefix with an ordering
  hint (`10-…`, `20-…`) when order matters.

## Fragment shape

```json
{
  "pages": [
    { "id": "my-page", "route": "/my-page", "type": "list", "schema": "myNewSchema" }
  ],
  "menu": [
    { "id": "my-page", "label": "My Page", "icon": "Plus" }
  ]
}
```

`_placeholder.json` ships an empty `{ "pages": [], "menu": [] }` so
`require.context` always has at least one match (an empty glob throws).
Keep it tracked.
