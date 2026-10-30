---
kind: code
---

# Proposal: pipelines-as-cards

## Summary

Board PqPipelines: the Pipelines page opens as cards. Each card shows the pipeline's name, its description, "6 fases · aangemaakt 12 mrt 2026", and its stages as chips in order. The table stays one switch away, and the header has Download and New pipeline. Stages are edited in the pipeline's own form.

Needs nextcloud-vue `feat/card-chips-from-a-list`, which adds chips per list entry and a template meta part.
