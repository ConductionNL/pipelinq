---
kind: code
depends_on: [marketing-segmentation-and-blast-02-segment-service]
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

# Proposal: Marketing Segmentation and Blast — 03 Compliance Service

Member **3 of 11** in the `marketing-segmentation-and-blast` chain.
Predecessor: `marketing-segmentation-and-blast-02-segment-service`.
This member implements `ComplianceService` — the GDPR/CAN-SPAM gate that
blocks sends without lawful basis, enforces unsubscribe footers, and
withdraws consent on unsubscribe/bounce.

## Why (carried from the giant)

Marketing compliance (AVG consent tracking, GDPR right-to-be-forgotten,
CAN-SPAM physical address) must be enforced by the system, not checked in
spreadsheets. Every requirement ties to GDPR Art. 6 (lawful basis) or
Art. 17 (right to be forgotten) — fail safe on consent.

## What this member does

Implements `lib/Service/ComplianceService.php` reading ConsentRecord and
CampaignTemplate (member 01) plus Segment members (member 02):
- `checkSegmentCompliance()` — find members lacking lawful basis per channel
- `hasConsentForChannel()` — per-contact consent check
- `recordConsentWithdrawal()` — withdraw on unsubscribe/bounce, transition
  queued deliveries
- `validateTemplate()` — enforce `{{unsubscribe_link}}` + physical address
  on email templates

## Out of scope

Blast orchestration that calls this gate (member 04); webhook ingestion
that drives withdrawal (member 05); template/segment controllers (member 06).
