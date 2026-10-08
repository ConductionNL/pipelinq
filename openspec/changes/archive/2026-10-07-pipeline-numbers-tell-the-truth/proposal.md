---
kind: code
depends_on: []
---

# Proposal: pipeline-numbers-tell-the-truth

## Summary

The dashboard and the pipeline show numbers a sales manager can trust. The
weighted forecast uses each lead's win chance, which is its qualification
score. Revenue charts count won deals only. The pipeline gauge measures
against a target you set, in your own currency. Every lead sits in a
pipeline stage, so none goes missing from the board. The Pipeline menu item
gets a board icon.

## Motivation

Ruben's review of the pipelinq beta on 2026-10-06 (items F1, F2, F3, F5).

- **F3.** A lead worth 250000 with score 65 added EUR 0 to the weighted
  forecast. The forecast multiplied by `probability`, a field the lead form
  hides, so it is empty on every real install. Ruben decided: a lead's win
  chance is its `qualificationScore`, read as a percentage.
- **F2.** "Revenue over time", "Revenue by source" and "Top customers by
  revenue" summed every lead, open ones included, so an open tender showed as
  revenue. "Pipeline by stage" summed won and lost deals too. The "Open
  pipeline vs target" gauge had a fixed target of 500000 and a fixed currency
  of EUR.
- **F1.** Leads existed without a stage. The stage is optional in the schema,
  the lead form only set one when a default pipeline existed, and the enquiry
  flow and the relation backfill set a pipeline without a stage. Such a lead
  is on no board column and in no per-stage figure.
- **F5.** The Pipeline menu item used the `Domain` (building) icon.

## What changes

- The commercial overview and the stage report weight each lead by its win
  chance: `qualificationScore` as a percentage, clamped to 0-100. The lead list,
  the client and contact deal lists show one "Win chance" column instead of
  "Probability", "Win %" and "Score".
- The revenue charts filter on `status: won`, "Pipeline by stage" on
  `status: open`. The gauge formats in `@config.currency` and reads its target
  from `@config.pipelineTarget`, the new app setting `pipeline_target` on the
  forecast settings screen. With no target it says so instead of showing a
  made-up one.
- `LeadStagePlacer` puts a lead in its pipeline's first open stage, with
  `stageOrder` and `stageEnteredAt`. A creating listener applies it to every
  new lead, whatever created it. `BackfillLeadRelations` applies it to stored
  leads, using the lead's creation time as entry time. The lead form falls
  back to the first lead pipeline and keeps `stageOrder` in step.
- The Pipeline and Pipelines menu items use `ViewColumnOutline`.

## Out of scope

- The lead form's own `probability` input stays; it no longer feeds any
  figure. Removing it belongs to the form work.
