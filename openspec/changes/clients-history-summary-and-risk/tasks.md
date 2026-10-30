# Tasks: clients-history-summary-and-risk

## 1. Risk

- [x] 1.1 Fragment adding `churnRisk` (`level`, `score`, `reasons[]`, `computedAt`) to `client`, read only in forms
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
  - Built: `lib/Settings/register.d/97-client-churn-risk.json` adds `churnRisk` (`level`, `score`, `reasons[]` with signal codes and the record behind each, `computedAt`). Not `readOnly` in the schema, because OpenRegister then refuses the service's own update; the Clients and ClientDetail `fieldOverrides` show it read only. No `format` added. Register import not run here (no live instance in this lane).
- [x] 1.2 `lib/Service/ClientRiskService.php::assess()` with the five signals and weights from app config
  - Verify: PHPUnit `tests/Unit/Service/ClientRiskServiceTest.php` per signal, per cap and per level boundary
  - Built: Split in two: `ClientRiskScore` (the arithmetic, pure) and `ClientRiskService` (reads, `weights()` from app config `client_risk_weights`, `recompute()`, `recomputeAll()`, writes in OpenRegister's system scope only when level or reasons change). Open complaints, breached deadlines and last activity come from `Customer360SummaryService::getSummary()`. Tests: `ClientRiskServiceTest` (15).
- [x] 1.3 `lib/BackgroundJob/ClientRiskJob.php` daily, writing only on change; recompute on contract status change and on detractor classification
  - Verify: PHPUnit on the job and on the two triggers, asserting no write when nothing changed
  - Built: `ClientRiskJob` (daily, `appinfo/info.xml`) and `ClientRiskTriggerListener` (contract status change, survey answer turning detractor; deferred through `DeferredObjectListenerJob`). Tests: `ClientRiskJobTest` (3), `ClientRiskTriggerListenerTest` (5), and `testRecomputeLeavesAnUnchangedClientAlone`.
- [x] 1.4 Clients Risk column and At risk quick filter; ClientDetail Risk badge with reasons
  - Verify: Playwright `tests/e2e/client-risk.spec.ts` with a seeded churned contract sees the client under At risk with that reason
  - Built: Clients: Risk badge column on `churnRisk.level` and an At risk quick filter (high or medium). ClientDetail: a Churn risk tile in the band, as PqKlant and PqOrganisatie draw it (level coloured, heaviest reason as caption), served by `Customer360Controller::summary()` through `ClientRiskPresenter`; the header badge with popover was replaced by the drawn tile (decision 130). The Playwright run is left for the live pass (decision 139).

## 2. Summary

- [x] 2.1 `lib/Service/ClientHistorySummaryService.php::summarise()` reading as the viewer, bounded input, hermiq through a lazy resolve, nothing stored
  - Verify: PHPUnit with a hermiq stub, without hermiq, and with a viewer who may read only some tickets (those stay out of the input)
  - Built: `ClientHistorySummaryService` (tickets and leads read with `_rbac: true`, notes through `ActivityTimelineReader`, last 90 days, newest first, at most 40, lazy hermiq resolve, nothing stored). Tests: `ClientHistorySummaryServiceTest` (6).
- [x] 2.2 Route `GET /api/clients/{id}/history-summary` with `#[NoAdminRequired]` and an object authorisation check
  - Verify: hydra gates route-auth, no-admin-idor and route-reachability pass on the diff
  - Built: `ClientHistorySummaryController::show()` with `#[NoAdminRequired]` and the client read guard `ClientReadAccess` (moved out of `Customer360Controller` so both endpoints guard the same way). Tests: `ClientHistorySummaryControllerTest` (6).
- [ ] 2.3 History summary card on ClientDetail, hidden without hermiq (UI waits for a board: PqKlant and PqOrganisatie do not draw a History summary card, decision 130)
  - Verify: Playwright with a hermiq stub shows the summary with records read and time

## 3. Docs

- [x] 3.1 `docs/Features/client-risk-and-summary.md`: the signals, the weights and where the summary text goes
  - Verify: docs build exit 0
  - Built: `docs/Features/client-risk-and-summary.md`, linked from `docs/Features/README.md`. Docs build not run in this lane.
