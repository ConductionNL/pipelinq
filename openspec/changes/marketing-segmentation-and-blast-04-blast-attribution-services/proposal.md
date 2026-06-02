---
kind: code
depends_on: [marketing-segmentation-and-blast-03-compliance-service]
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

# Proposal: Marketing Segmentation and Blast — 04 Blast and Attribution Services

Member **4 of 11** in the `marketing-segmentation-and-blast` chain.
Predecessor: `marketing-segmentation-and-blast-03-compliance-service`.
This member implements `BlastService` (send orchestration, A/B splitting,
throttled dispatch via openconnector) and `AttributionService`
(click→deal joining).

## Why (carried from the giant)

The Blast Engine is the core deliverable: pick a segment, pick a template,
send via openconnector sources (credentials never in Pipelinq code), throttle
to the provider rate limit, and split deterministically for A/B testing.
Revenue Attribution closes the loop Mailchimp can't — joining a blast click
to a later closed Deal with attributed revenue.

## What this member does

- `lib/Service/BlastService.php` — `sendBlast()` (compliance-gated queue
  creation), `dispatchBlastDeliveries()` (throttled openconnector send),
  `createAbVariant()`, `updateBlastTotals()`, `transitionQueuedDeliveries()`
  (called by ComplianceService).
- `lib/Service/AttributionService.php` — `recordClick()`, `linkBlastToDeal()`,
  `getBlastAttributedValue()`.

## Out of scope

The background job + webhooks that drive dispatch and event ingestion
(member 05); controllers (member 06); the live monitor + dashboard Vue
views (members 07, 08).
