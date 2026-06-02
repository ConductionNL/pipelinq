---
kind: code
depends_on: [marketing-segmentation-and-blast-05-jobs-and-webhooks]
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

# Proposal: Marketing Segmentation and Blast — 06 REST Controllers

Member **6 of 11** in the `marketing-segmentation-and-blast` chain.
Predecessor: `marketing-segmentation-and-blast-05-jobs-and-webhooks`.
This member adds the thin REST controllers (Blast, Segment, Template) that
expose the services to the frontend.

## Why (carried from the giant)

The Vue views (members 07, 08) need CRUD + query endpoints with proper
authorization. User identity must always derive from `IUserSession`, never
from the request body (ADR-005). Controllers stay thin and delegate to the
services built in members 02–04.

## What this member does

- `lib/Controller/BlastController.php` — list/create/get/patch/send/cancel
  blasts + list deliveries.
- `lib/Controller/SegmentController.php` — list/create/get segments, preview
  members, refresh size; validates rule trees via SegmentService.
- `lib/Controller/TemplateController.php` — list/create/get/patch templates;
  validates via ComplianceService.
- Routes in `appinfo/routes.php` (before any wildcard route).

## Out of scope

The Vue views that consume these endpoints (members 07, 08); tests (09).
