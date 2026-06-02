---
kind: code
depends_on: [marketing-segmentation-and-blast-01-schema-and-seed-config]
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

# Proposal: Marketing Segmentation and Blast — 02 Segment Service

Member **2 of 11** in the `marketing-segmentation-and-blast` chain.
Predecessor: `marketing-segmentation-and-blast-01-schema-and-seed-config`.
This member implements `SegmentService` — the rule-tree validator and
evaluator that makes a Segment a live query, not a frozen list.

## Why (carried from the giant)

A Segment must stay live: a new Contact matching the rules is auto-included
in the next blast without re-exporting a list. This is the Customer
Segmentation demand cluster (demand 2). SegmentService is the engine that
validates rule trees against the entity schema, evaluates them per object,
estimates segment size, and yields the member list at send time.

## What this member does

Implements `lib/Service/SegmentService.php` consuming the schemas declared
in member 01:
- `validateRules()` — recursive rule-tree validation against entity schema
- `evaluateRules()` — per-entity AND/OR evaluation with type coercion
- `estimateSize()` — count matching objects, cached with TTL
- `getMembersForBlast()` — minimal member projection for delivery

## Out of scope

Compliance gating (member 03), blast orchestration (member 04), controllers
(member 06), the SegmentBuilder Vue view (member 07), tests (member 09).
