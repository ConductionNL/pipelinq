---
kind: code
depends_on: [marketing-segmentation-and-blast-07-segment-blast-views]
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

# Proposal: Marketing Segmentation and Blast — 08 Performance Dashboard

Member **8 of 11** in the `marketing-segmentation-and-blast` chain.
Predecessor: `marketing-segmentation-and-blast-07-segment-blast-views`.
This member builds the post-send analytics dashboard: overview metrics, A/B
variant comparison with chi-square significance, and revenue attribution.

## Why (carried from the giant)

Once a blast lands, the marketing manager needs to know what worked: open and
click rates per segment, which A/B variant won (with statistical significance),
and how much revenue the blast attributed to closed deals. This closes the
loop on the A/B Testing and Revenue Attribution demand.

## What this member does

- `src/views/blasts/PerformanceDashboard.vue` with three tabs: Overview
  (recent blasts table), A/B Testing (side-by-side variant comparison +
  chi-square significance once N≥500 and 24h elapsed), Attribution
  (attributed value per blast from the attribution endpoint).

## Out of scope

Tests (member 09), docs (10), verification (11).
