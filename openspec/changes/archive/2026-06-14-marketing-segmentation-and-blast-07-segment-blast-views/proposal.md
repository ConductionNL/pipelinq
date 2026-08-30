---
kind: code
depends_on: [marketing-segmentation-and-blast-06-rest-controllers]
chain:
  - marketing-segmentation-and-blast-01-schema-and-seed-config
  - marketing-segmentation-and-blast-02-segment-service
  - marketing-segmentation-and-blast-03-compliance-service
  - marketing-segmentation-and-blast-04-blast-attribution-services
  - marketing-segmentation-and-blast-05-jobs-and-webhooks
  - marketing-segmentation-and-blast-06-rest-controllers
  - marketing-segmentation-and-blast-07-segment-blast-views
  - marketing-segmentation-and-blast-08-performance-dashboard
  - marketing-segmentation-and-blast-09-unit-integration-tests
  - marketing-segmentation-and-blast-10-docs
  - marketing-segmentation-and-blast-11-quality-verification
---

# Proposal: Marketing Segmentation and Blast — 07 Segment and Blast Views

Member **7 of 11** in the `marketing-segmentation-and-blast` chain.
Predecessor: `marketing-segmentation-and-blast-06-rest-controllers`.
This member builds the front-of-house Vue views: the visual segment builder,
the blast creation wizard, and the live send monitor.

## Why (carried from the giant)

The marketing manager composes "industry = 'gemeente' AND employees > 50" in
a visual builder, picks a segment + template, schedules or sends, and watches
delivery/open/click/bounce/unsubscribe in real time. These three views are the
primary operator surface.

## What this member does

- `src/components/SegmentBuilder.vue` — recursive AND/OR rule composer with
  live validation + size estimation against member 06 endpoints.
- `src/views/blasts/BlastForm.vue` — multi-step create wizard (name → segment
  → template → channel → schedule → A/B), with compliance + template checks.
- `src/views/blasts/BlastMonitor.vue` — live progress bar, totals grid, event
  timeline, cancel; polls the blast endpoint.

## Out of scope

The PerformanceDashboard (member 08); tests (09).
