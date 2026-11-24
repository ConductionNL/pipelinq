---
kind: code
---

# Proposal: lead-win-probability-recency

## Summary

The lead schema derives `winProbability`, the stage probability decayed by how long the lead has not moved (`lib/Settings/pipelinq_register.json`, spec `lead-scoring-win-probability`). Nobody sees it. The spec's own defect record (run 31473685688, pipelinq#774) names the cause: the calculation is `materialise: false`, OpenRegister computes such calculations only when the request carries `_extend[]=calculations`, and neither the Leads index nor the lead page asks for it, because nextcloud-vue's `useListView` and `useDetailView` have no `extend` option. Meanwhile the Leads column labelled "Win chance" shows `qualificationScore`, the lead score, which is a different number.

This is the build plan for that row. The spec stands; this change amends the surfacing requirement to name the boards and the extend, and lists the tasks.

## The row this covers

Source: pipelinq `openspec/parity/capabilities.json`.

- **lead-win-probability** See how likely you are to win a lead, judged by how recently it moved (specified). Boards: PqLeads, PqLead, PqPipeline.

## What changes

1. nextcloud-vue: an `extend` option on `type: "index"` and `type: "detail"` pages, passed as `_extend[]` to OpenRegister (generic; any app's virtual calculations become visible).
2. pipelinq manifest: Leads, LeadDetail and the pipeline board ask for `calculations`; the Win chance column and the deal data show `winProbability`; the lead score keeps its own label.
3. Seed data whose `@self.updated` spans the bands, so a fresh install shows hot and cold leads.

## Screens

PqLeads, PqLead, PqPipeline (see `screens.md`).
