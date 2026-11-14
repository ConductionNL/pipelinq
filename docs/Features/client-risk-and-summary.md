# Client risk and history summary

**Status:** Implemented (change `clients-history-summary-and-risk`)

## Overview

An account manager opening a client sees two things before they call:

- **Risk**: a level (low, medium or high) saying how likely the client is to
  leave, always shown with the reasons behind it.
- **History summary**: a few written sentences about what happened with the
  client lately, generated on request.

## Risk

Every client carries `churnRisk` (`level`, `score`, `reasons[]`,
`computedAt`). It is read only in forms; `ClientRiskService` writes it.

### Signals and weights

The score is a plain sum of named signals. Each weight is app config
(`client_risk_weight_<signal>`), so an administrator can read and tune it.

| Signal | Counts when | Default points |
| --- | --- | --- |
| `contract-expiring` | a contract of the client is `expiring` | 30 |
| `contract-churned` | a contract turned `churned` in the last 90 days | 40 |
| `detractor` | a survey answer classified detractor in the last 180 days | 25 each, at most 50 |
| `open-complaints` | an open complaint ticket | 10 each, at most 30 |
| `sla-breached` | an open ticket is past its term | 15 |
| `no-recent-contact` | no contact for more than 90 days | 20 |

Level: **high** from 60 points, **medium** from 30, else **low**. Every reason
names the signal, its points and the records behind it, so a wrong weight is
visible rather than hidden in a number.

### When it is recomputed

- Daily, for every client (`ClientRiskJob`). A client is only written when its
  level or reasons changed, which keeps the audit trail quiet.
- At once, when a contract status changes or a survey answer is classified
  detractor.

### Where it shows

- **Clients list**: a Risk column and an **At risk** quick filter (high or
  medium, highest score first).
- **ClientDetail**: a Risk card with the level, the score and each reason, with
  links to the records where the app has a page for them.

## History summary

The **History summary** card on ClientDetail has a **Summarise** button.

- The text is written by **hermiq**, from the client's tickets (contact moments,
  requests, complaints), leads and notes of the **last 90 days**, newest first,
  at most **40 records**.
- Records are read **as the viewer**: what the viewer may not read never goes
  into the text.
- The card says how many records it read and when the summary was written.
- **The summary text is not stored anywhere.** It goes from hermiq to the
  viewer's screen and is gone when they leave the page, so it never outlives
  the records it was read from. Hermiq sends the prompt to the language model
  configured in hermiq.
- Without hermiq the card stays, without the button, and says summarising is
  not available.

API: `GET /apps/pipelinq/api/clients/{id}/history-summary` (needs read access to
the client; `?check=1` only answers whether summarising is available).

## Specification

- Change: `openspec/changes/clients-history-summary-and-risk/`
