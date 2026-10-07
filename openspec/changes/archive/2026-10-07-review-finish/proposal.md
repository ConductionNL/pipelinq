---
kind: code
depends_on: []
---

# Proposal: review-finish

## Summary

The last items from Ruben's pipelinq review. Money shows in the currency you
chose in setup, not in a fixed EUR. The booking page names the customer and
the service. A fresh install gets its default pipelines again. The lead form
no longer asks for a probability that nothing uses.

## Motivation

Ruben's review of the pipelinq beta on 2026-10-06, the leftovers after
pipeline-numbers-tell-the-truth and the booking pages work.

- **B4.** My Work printed a lead's value as "EUR 1.000". The services index
  formatted every price in EUR. The forecast pages, the open pipeline KPI and
  the commercial formatters did the same. An install set up in USD read EUR
  everywhere.
- **Booking data block.** The booking page showed `customerId` and
  `serviceId` as raw ids.
- **R5.** Creating the default pipeline failed with
  "propertyMappings.1.totalsProperty null". The request mapping sent null
  for `totalsProperty`, which the pipeline schema types as a string, so
  OpenRegister refused the whole pipeline. The pipeline form sent the same
  null for an empty totals property.
- **Probability.** Since pipeline-numbers-tell-the-truth the win chance is
  the qualification score, but the lead form still had a probability input.

## What changes

- A shared `reportingCurrency()` reads the `config` initial state. The
  commercial and locale formatters default to it. An amount tied to an
  object (a lead, a service, a booking) uses the object's own currency and
  falls back to the reporting currency.
- My Work, the forecast dashboard and trend, the open pipeline KPI and the
  services index follow that rule. The services index uses a new
  `objectCurrency` cell formatter.
- Blast attribution records carry the deal's currency, or the reporting
  currency.
- The booking data block shows names through two formatters that look the
  contact (or client) and the service up once.
- The default pipelines and the pipeline form leave empty optional fields
  out. The default sales pipeline's totals label is the reporting currency.
- The lead form drops its probability input. An edited lead keeps its
  stored probability.

## Out of scope

- POS, the cash register coupling (kassakoppeling), cash shifts, gift cards
  and the CCV payment adapter stay in EUR. They follow the Dutch fiscal till
  and the payment terminal, and their stored records say EUR.
- Campaign cost (`budgetEur`, `amountEur`) is EUR by its schema field names.
- Sales contract stat blocks keep their "EUR" label; a contract carries its
  own currency and the stats block cannot read it yet.
