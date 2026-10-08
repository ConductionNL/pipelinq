# Design: clients-history-summary-and-risk

## Context (read at pipelinq development cfe0a0a51)

- **360 summary.** `lib/Service/Customer360SummaryService.php::getSummary()`
  (:116) returns `openTicketCount`, `openTicketsByType`, `sla.breached`,
  `sla.atRisk`, `openLeadCount`, `openLeadValue` and `lastActivityAt`
  (`resolveLastActivity`, :209), served at `GET /api/customer-360/summary`
  (`appinfo/routes.php:108`). ClientDetail shows them as stat widgets
  `client-summary-open-tickets`, `-sla-breached`, `-sla-at-risk`,
  `-last-activity` in `src/manifest.json`.
- **Contract signals.** `lib/Service/RenewalEngineService.php` moves a contract
  to `expiring` inside its renewal window and to `churned` on silent expiry
  (`processContract`, `processExpiringContract`), run by
  `lib/BackgroundJob/RenewalWindowJob.php`.
- **Survey signals.** `lib/Service/DetractorFollowUpService.php` classifies a
  response as detractor (NPS at or below the Bain threshold, or a low rating,
  `THRESHOLD_KEY survey_detractor_rating_threshold`) and raises a task.
- **Model work.** `lib/Service/Competitor/RelevanceScorer.php` resolves hermiq's
  provider factory lazily and degrades to "unscored, never zero" when hermiq is
  absent or silent. `lib/Service/NaviService.php` is an analytics assistant and
  is not reused here.

## Decisions

### D1. Risk is a sum of named signals, stored on the client

`ClientRiskService::assess(clientId)` scores five signals, each with a weight
held in app config: a contract `expiring` (+30) or `churned` in the last 90 days
(+40); a detractor response in the last 180 days (+25 each, capped at 50); open
complaint tickets (+10 each, capped at 30); `sla.breached` above zero (+15);
no activity for more than 90 days (+20). Level: high at 60 or more, medium at 30
or more, else low. The result is written to `client.churnRisk` as `{level,
score, reasons[], computedAt}`, where each reason names the signal and the
record behind it. Stored, because the Clients list must sort and filter on it.

### D2. Recomputed daily and on change

`ClientRiskJob` (a `TimedJob`, daily, like `RenewalWindowJob`) recomputes every
client. A contract status change and a detractor classification also recompute
their client at once, so a client does not wait a day to turn red. The job
writes only when the level or the reasons change, to keep the audit trail quiet.

### D3. The summary is generated on request and never stored

`ClientHistorySummaryService::summarise(clientId, viewer)` reads the last 90 days
of the client's contact moments, tickets, notes and leads through OpenRegister's
ObjectService as the viewer, so RBAC decides what goes in. It sends a bounded
text (newest first, at most 40 records) to hermiq through the same lazy resolve
as `RelevanceScorer`, and returns the summary with `recordsRead` and
`generatedAt`. Without hermiq the card is hidden, not empty.

### D4. Where it shows

- ClientDetail: a Risk badge in the header with its reasons in a popover, and a
  History summary card with Summarise.
- Clients list: a Risk column (badge) and an At risk quick filter
  (`churnRisk.level` is `high` or `medium`).

## Risks

- Weights are guesses until used. They are app config and the reasons are
  always shown, so a wrong weight is visible, not hidden in a score.
- A summary can be wrong. The card says it is generated, names the records read,
  and links each client record list so the reader can check.
