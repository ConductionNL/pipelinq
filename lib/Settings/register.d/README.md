<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Register fragments (`register.d/`)

Modular OpenRegister configuration fragments for Pipelinq (ADR-037).

Every `*.json` file in this directory is **deep-merged** onto the monolith
register configuration (`../pipelinq_register.json`) by
`ConfigFileLoaderService::loadConfigurationFile()` before it is handed to
OpenRegister's `ConfigurationService::importFromApp()`.

## Why

Concurrent same-app feature builds used to conflict on the single shared
`pipelinq_register.json`. Instead of editing that monolith, a feature build
drops its **own** fragment file here. Files are merged in sorted filename
order, so prefix fragments with an ordering hint (e.g. `10-billing.json`,
`20-invoicing.json`) when ordering matters.

## Merge semantics

- Associative (object) keys merge **recursively**.
- List/scalar values from a fragment **replace** the base value.
- A short hash of all fragment content is folded into `info.version`
  (`<base>+frag.<hash>`) so OpenRegister re-runs the import whenever any
  fragment changes.

## Fragment shape

A fragment is a partial register configuration — the same shape as
`pipelinq_register.json`, containing only the keys it contributes, e.g.:

```json
{
  "components": {
    "schemas": {
      "myNewSchema": { "title": "My New Schema", "type": "object", "properties": {} }
    }
  }
}
```

Keep this directory tracked even when empty (this README anchors it).
