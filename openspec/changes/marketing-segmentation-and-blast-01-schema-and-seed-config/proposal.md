---
kind: config
depends_on: []
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

# Proposal: Marketing Segmentation and Blast — 01 Schema and Seed Config

Member **1 of 11** in the `marketing-segmentation-and-blast` chain.
Predecessor: none (chain root). This member declares the six new
OpenRegister schemas the whole feature reads (Segment, CampaignTemplate,
Blast, BlastDelivery, ConsentRecord, AttributionLink) in
`lib/Settings/pipelinq_register.json`, and seeds them with realistic
Dutch demo objects so every downstream code member has live data to read.

This is the ADR-032 `kind: config` chain root: declare the declarative
surface first (expand), so each code member that follows merges safely
against a register that already carries the new schemas and seed objects.

## Why (carried from the giant)

Marketing Segmentation and Blast brings Pipelinq from single-customer
outreach to campaign-grade marketing — a rule-based segment builder, a
multi-channel blast engine with delivery tracking, and a performance
dashboard with A/B testing and revenue attribution. Market evidence:
3 feature clusters (Marketing Automation demand 3, Email Campaign
Management demand 3, Customer Segmentation demand 2) with a combined
demand score of 8. MKB marketing managers today juggle Mailchimp plus
spreadsheet exports; the data model that makes Pipelinq-native blasts
possible starts here.

## What this member does

- Registers six schema definitions under `components.schemas[]`:
  segment, campaign-template, blast, blast-delivery, consent-record,
  attribution-link (fields per ADR-000 data model + design.md).
- Seeds `components.objects[]` with 5 Segment, 3 CampaignTemplate,
  2 Blast (A/B pair), 20 BlastDelivery, 10 ConsentRecord, and
  2 AttributionLink objects with realistic Dutch values.
- Confirms ADR-012 deduplication (no prior Segment/Blast/Compliance/
  Attribution services or schemas exist).

## Out of scope (handled by later chain members)

All backend services, jobs, webhooks, controllers, Vue views, tests,
docs, and verification — members 02–11.
