# Proposal: contract-renewal-tracking

## Problem

Pipelinq models revenue exclusively as one-off deals: a lead moves through the pipeline, closes won, and disappears from the revenue picture. For the Dutch MKB services audience Pipelinq targets (agencies, IT dienstverleners, onderhoudscontracten, SaaS-achtige abonnementen) the majority of revenue is *recurring*, and the CRM is blind to it:

1. **No contract concept** — There is nowhere to record "Jansen BV pays €750/month for support until 2027-03-31 with a 2-month notice period." The `client-management` spec only shows "Contract Number" as a *custom-field example*; no schema, no lifecycle, no value.

2. **The customer portal already promises contracts that don't exist** — the in-flight `customer-portal` change builds `PortalContractService`, contract list/detail endpoints, and a Contracts tab that "query the client-management register for contracts" — but no capability anywhere defines or stores those contracts. This change provides the missing supply side.

3. **No renewal motion** — Renewals are the highest-probability revenue a services business has, and the cheapest to win — yet Pipelinq gives no warning when a contract approaches its end or notice deadline. Churn happens by calendar accident. Every peer product (HubSpot renewal pipelines, Pipedrive recurring revenue, Salesforce contracts/renewal opportunities) treats renewals as first-class pipeline objects.

4. **No MRR/ARR or churn visibility** — Forecast roll-up (in-flight change) covers deal-based forecasting, but there is no recurring-revenue baseline: current MRR/ARR, growth, renewal rate, churned value per period. For a business that bills monthly, the pipeline value chart answers the wrong question.

5. **Renewal work is invisible in daily tooling** — nothing in My Work, the dashboard, or notifications tells an account manager "3 contracts hit their notice deadline this month."

## Solution

Add a `contract-renewal-tracking` capability on top of existing building blocks (clients, leads/pipeline, My Work, OR notifications):

1. **`contract` schema** in the pipelinq register — client reference, line items referencing the existing product/service catalog, start/end dates, billing interval (monthly/quarterly/annual/one-off), value per interval, auto-renew flag, notice period, owner, lifecycle status (`draft`, `active`, `expiring`, `renewed`, `churned`, `cancelled`), and a reference to the renewal lead once created.

2. **Contract lifecycle management** — CRUD views (contracts list + detail, contracts tab on the client view), guarded status transitions, and an automatic nightly evaluation that flips `active` contracts to `expiring` when they enter their renewal window (endDate − max(noticePeriodDays, configured renewal lead time)).

3. **Renewal pipeline automation** — entering the renewal window auto-creates a renewal lead in the existing pipeline (reusing `lead-management`; no parallel pipeline concept), pre-filled with the contract's value, client, and owner, linked bidirectionally to the contract. Renewal lead won → contract `renewed` (and a successor contract is drafted); lost or contract ends without renewal → `churned`.

4. **Renewal reminders** — notification rules via the x-openregister-notifications dialect (ADR-031) on the contract's transition to `expiring` (and on approaching notice deadline), targeting the contract owner; a My Work entry mirrors the renewal task so it lands in the daily queue.

5. **Recurring-revenue analytics** — MRR/ARR roll-up (normalizing intervals to monthly), per-client recurring value, renewal rate and churned value per period, surfaced as dashboard widgets (MRR KPI card, "Renewals due" list) and a recurring-revenue block in pipeline insights.

6. **Portal alignment** — the contract objects carry exactly the fields `customer-portal`'s `PortalContractService` reads (`contractNumber`, `startDate`, `endDate`, `value`, `status`), making the portal's Contracts tab real.

## Scope

New schema in `pipelinq_register.json`:
- `contract` — contractNumber, clientRef, title, lineItems[] (productRef, description, quantity, unitValue), billingInterval, valuePerInterval, currency, startDate, endDate, autoRenew, noticePeriodDays, status, ownerId, renewalLeadRef, predecessorContractRef, notes

Backend services:
- `ContractService` — CRUD wrappers where app logic is needed (status-transition guards, contract numbering, successor drafting); plain reads go through OR (`useObjectStore`) per ADR-022
- `RenewalEngineService` — nightly window evaluation, renewal-lead creation, won/lost reconciliation
- `RecurringRevenueService` — MRR/ARR normalization, renewal-rate and churn aggregates

Background job: nightly renewal-window evaluation (valid bootstrap registration pattern).

Frontend:
- Contracts list view + contract detail view; Contracts tab on the client detail (klantbeeld) view
- Create/edit contract modal (`src/modals/`)
- Dashboard: MRR KPI card + "Renewals due" widget
- Recurring-revenue block in pipeline insights

Notifications: schema-rule based via the x-openregister-notifications dialect (ADR-031).

Seed data: 2 example contracts (one monthly auto-renew, one annual with notice period entering its renewal window).

**Depends on:** `client-management` (clients), `lead-management` + `pipeline` (renewal leads), `product-service-catalog` (line items), `my-work`, `dashboard`, OpenRegister (storage/RBAC/notifications, ADR-031). **Feeds:** `customer-portal` (PortalContractService), `forecast-roll-up-and-categories` (renewal leads enter the forecast like any lead).

## Out of Scope

- Invoicing, billing runs, or payment collection — Shillinq territory (a `pipelinq-contract-to-shillinq-billing` bridge would be a separate change in the established `pipelinq-*-to-shillinq-*` series)
- Quote-to-contract conversion — `product-catalog-quoting` extension, separate change
- Price indexation / CPI escalation clauses
- E-signature or contract document generation — documents attach via OR files / DocuDesk
- Usage-based or seat-based metered billing models — fixed interval values only in MVP
- A separate renewal pipeline — renewal leads live in the existing pipeline (optionally tagged), per lead-management
- Quota/forecast rendering — owned by `forecast-roll-up-and-categories`

## Success Criteria

- An account manager creates a €750/month support contract for a client with endDate 2027-03-31 and a 60-day notice period from the client's Contracts tab
- The dashboard MRR card immediately reflects the added €750; ARR shows ×12 normalization; a quarterly €3,000 contract contributes €1,000 to MRR
- 60+ configured-lead-time days before endDate, the nightly job flips the contract to `expiring`, a renewal lead appears in the pipeline pre-filled with value/client/owner, the owner gets an OR-engine notification and a My Work entry
- Winning the renewal lead marks the contract `renewed` and drafts a successor contract starting 2027-04-01; losing it (or the end date passing without renewal) marks it `churned` and its value appears in the churn metric for that period
- Pipeline insights shows renewal rate (renewed ÷ due) and churned MRR per period
- The customer portal's Contracts tab lists the client's contracts with number, dates, value, and status, served from these objects
- All UI strings use English i18n source keys with Dutch translations
