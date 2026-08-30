# Design: 07 Segment and Blast Views

## Scope

`src/components/SegmentBuilder.vue`, `src/views/blasts/BlastForm.vue`,
`src/views/blasts/BlastMonitor.vue`. Consume the member 06 REST endpoints.

## Components

- **SegmentBuilder.vue** — props `modelValue` (rule tree), `entityType`.
  Recursive render: AND/OR node selector, add-condition / add-group / remove.
  Predicate form: field dropdown from entity schema, operator filtered by
  type, value input by type. Live validation (POST validate on blur), debounced
  size estimation, emits `update:modelValue`.
- **BlastForm.vue** — multi-step: name → segment picker (shows estimated size)
  → template picker (filtered by channel) → channel → schedule
  (datetime-local) → A/B toggle + split slider. Pre-send compliance check via
  segment compliance endpoint; missing-consent modal (skip / request / cancel);
  template validate for email. On submit POST `/api/blasts`, navigate to monitor.
- **BlastMonitor.vue** — progress bar, ETA, totals grid (queued/sent/delivered/
  bounced/opened/clicked/unsubscribed/complained), event timeline (last 50,
  reverse chronological). Polls `GET /api/blasts/:id` every 2s; stops on
  "sent"/"failed". Cancel button when "sending".

## Patterns

ADR-004: NC Vue components, axios; modals in their own files (modal-isolation
gate); NcSelect uses `inputLabel` (nc-input-labels gate). ADR-007: nl + en
i18n strings. ADR-010: CSS variables, no hardcoded colors.
