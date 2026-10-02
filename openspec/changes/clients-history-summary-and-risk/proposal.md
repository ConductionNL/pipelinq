---
kind: code
depends_on: []
---

# Proposal: clients-history-summary-and-risk

## Summary

Open a client and read a short written summary of what happened with them
lately, before you call. Open the Clients list and see which clients are at
risk of leaving, with the reasons named. The signals already exist, per
contract and per survey answer; nothing brings them together per client.

## Motivation

Two rows of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), both decided `build` by the OpenSpec pass of 2026-09-27.
Both are in the core area of the matrix (clients).

**`clients-ai-summary`**, "Get a short written summary of a client's history
before you contact them". Rated no, built.state none. Matrix evidence:
"lib/Service/Customer360SummaryService.php (route /api/customer-360/summary,
appinfo/routes.php:108) and the ClientDetail stat widgets (src/manifest.json:1801
client-summary-last-activity) give counts and dates, not a written summary;
lib/Service/NaviService.php uses a language model only for analytics questions".
Note: "a numeric 360 summary exists; no prose summary of the history". Demand:
changelog, https://docs.espocrm.com/extensions/intelligence/. One competitor
rates it yes:

- hubspot-crm: https://knowledge.hubspot.com/records/summarize-records, "Use the
  Record summary card on record previews and record views. This summary includes
  information about the currently viewed record's activities, notes, and
  ownership". All products and plans.

**`clients-churn-risk`**, "See which clients are at risk of leaving before they
actually go". Rated partial, built.state built. Matrix evidence:
"lib/Service/RenewalEngineService.php:187 marks contracts 'expiring' ahead of
their end and :231 'churned' on silent expiry (ContractDetail, Contracts page),
and lib/Service/DetractorFollowUpService.php:6 'an unhappy answer becomes
somebody's task'; there is no churn score or at-risk list across signals".
Note: "warning signals exist per contract and per survey answer, not as a client
risk view". Demand: changelog, https://www.hubspot.com/spotlight. One
competitor rates it yes:

- hubspot-crm: https://knowledge.hubspot.com/ai-tools/use-the-customer-health-agent,
  "assess the health of an account based on logged CRM activities, call
  transcripts, and public information ... identify at-risk accounts and
  prioritize retention efforts".

Both rows are the same screen, ClientDetail, telling an account manager where a
client stands. The missing half of `clients-churn-risk` is the per-client view
across signals; the contract and survey signals stay as they are.

## What changes

- ClientDetail gets a History summary card with a Summarise button. hermiq writes
  a few sentences from the client's recent contact moments, tickets, notes and
  leads that the viewer may read. The card names how many records it read and
  when it was written.
- Every client gets a risk level (low, medium, high) with the reasons behind it,
  recomputed daily from explainable signals: an expiring or churned contract, a
  detractor answer, open complaints, a breached SLA, and silence since the last
  contact.
- The Clients list gets a Risk column and an At risk quick filter; ClientDetail
  shows the level and its reasons.

## Out of scope

- A trained prediction model. The level is a weighted sum an administrator can
  read and tune; the reasons are always shown.
- Storing the written summary. It is generated on request and not saved, so it
  never outlives the records it was read from.

## Impact

- `lib/Settings/register.d/` fragment adding `churnRisk` to `client`.
- New `lib/Service/ClientRiskService.php` and `lib/BackgroundJob/ClientRiskJob.php`.
- New `lib/Service/ClientHistorySummaryService.php`, one route.
- `src/manifest.json`: ClientDetail widgets, Clients column and quick filter.
