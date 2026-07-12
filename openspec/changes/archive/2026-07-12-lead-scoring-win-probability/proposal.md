---
kind: config
depends_on: []
---

# Proposal: lead-scoring-win-probability

## Why

Every mature CRM surfaces a machine-derived win probability on a deal — Odoo 19's
AI win-probability, Dolibarr 21 lead scoring, Salesforce Einstein, Zoho and HubSpot
all ship it — and the competitor sweep flags it as table-stakes #4. Pipelinq already
materialises a `qualificationScore` and a `weightedValue` on the lead schema
declaratively, and it stores a `probability` field, but there is **no
recency-aware win probability**: a deal that has stalled for two months still shows
the same probability it had the day it was qualified. Sales reps need a single
number that *cools as a deal goes cold*, visible on the pipeline list and the deal
page, so stalling opportunities self-flag.

## What Changes

- Add a declarative `winProbability` calculation to the `lead` schema
  (`x-openregister-calculations` in `lib/Settings/pipelinq_register.json`): the
  lead's `probability` (already carrying the stage's base probability) **decayed by
  inactivity** — full within 14 days of the last touch, then stepped down at 30/60
  days. `materialise: false`, so it is recomputed fresh on every read and a stalling
  deal visibly cools without any write.
- Surface it declaratively (no Vue code): add `winProbability` to the `LeadDetail`
  Deal data widget in `src/manifest.json`, and add a colour-banded `winProbability`
  column to the `Leads` index reusing the existing `lead-probability` cell widget.
- Seed representative leads (municipality / consultancy / travel agency) that span
  hot / warm / cold win-probability bands for verification.

No new service class, no new schema, no PHP — purely declarative per ADR-031/ADR-032.

## Capabilities

### New Capabilities
- `lead-scoring-win-probability`: a recency-decayed win probability derived on the
  lead schema and surfaced on the pipeline list + deal detail.

### Modified Capabilities
<!-- none at requirement level: lead-management's existing qualificationScore/weightedValue/staleness requirements are unchanged; this adds a new derived field alongside them -->

## Impact

- **Config only:** `lib/Settings/pipelinq_register.json` (lead `x-openregister-calculations`
  + a lead seed object or two), `src/manifest.json` (LeadDetail Deal widget include +
  Leads index column).
- **Consumers:** the `crm-mcp-tool-surface` `pipelinq.getLead` tool will read
  `winProbability` when both changes land (no ordering dependency — a missing field
  is simply absent).
- **Procest:** none.
- **Feature tier:** V1.
