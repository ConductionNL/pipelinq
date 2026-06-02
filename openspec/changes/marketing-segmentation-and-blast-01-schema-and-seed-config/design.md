# Design: 01 Schema and Seed Config

## Scope

The declarative foundation for the whole chain. Six OpenRegister schemas
declared in `lib/Settings/pipelinq_register.json` plus seed objects. No
PHP, no Vue.

## Declarative-vs-imperative decision (ADR-031)

The entity data model is pure declarative configuration: schemas and seed
objects live in `pipelinq_register.json` and are materialised by
OpenRegister. No imperative migration code or custom DAO is written — all
CRUD downstream goes through `ObjectService` (ADR-001, ADR-022). This is
why the chain root is `kind: config`: it touches only declarative JSON and
carries the integration assurance that the materialised values are correct.

## Schemas (per ADR-000 + giant design.md)

- **Segment** — `name`, `description`, `rules` (object: `{type:"AND"|"OR",
  children:[...]}` with leaf `{field, operator, value}`), `entityType`
  ("contact"|"customer"), `estimatedSize`, `createdBy`, `createdAt`,
  `updatedAt`. Stored query, never materialised as a list.
- **CampaignTemplate** — `name`, `channel` ("email"|"sms"), `subject`,
  `bodyHtml`, `bodyText`, `senderName`, `senderEmail`, `replyTo`,
  `footerOverride`, `variables`, `language`, `createdBy`, `createdAt`.
- **Blast** — `name`, `segmentId`, `templateId`, `channel`, `scheduledFor`,
  `sentAt`, `status`, `abVariantOf`, `abSplitPercent`, `totals` (object:
  queued/sent/delivered/bounced/opened/clicked/unsubscribed/complained),
  `connectorSourceId`, `createdBy`, `createdAt`.
- **BlastDelivery** — `blastId`, `contactId`, `email`, `status`, `sentAt`,
  `providerId`, `openedAt`, `firstClickAt`, `clickedUrls`, `bouncedAt`,
  `bounceType`, `unsubscribedAt`.
- **ConsentRecord** — `contactId`, `channel`, `lawfulBasis`, `consentSource`,
  `consentedAt`, `withdrawnAt`, `withdrawnReason`, soft-bounce counter.
- **AttributionLink** — `blastId`, `contactId`, `dealId`, `firstClickAt`,
  `closedWonAt`, `attributedValue`.

## Seed data

5 Segment, 3 CampaignTemplate (2 email + 1 SMS), 2 Blast (A/B pair),
20 BlastDelivery (4 delivered, 3 bounced, 2 unsubscribed, 11 queued),
10 ConsentRecord (varied lawful basis + withdrawal states), 2 AttributionLink.
All objects carry the `@self` envelope (`register: pipelinq`, `schema: <slug>`,
unique slug) with realistic Dutch names and timestamps. Email templates
include `{{unsubscribe_link}}` and a physical-address block so the
compliance member (03) has compliant seed templates to read. BlastDelivery
clicked URLs carry `utm_campaign=blast-...&utm_source=pipelinq-blast`.

## Integration assurance

Seed objects must load cleanly into the register (valid OpenRegister format,
rule trees valid JSON conforming to the AND/OR node structure), so member 02
onward can query live Segment/Contact data via `ObjectService`.
