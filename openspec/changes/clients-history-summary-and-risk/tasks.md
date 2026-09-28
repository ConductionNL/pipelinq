# Tasks: clients-history-summary-and-risk

## 1. Risk

- [ ] 1.1 Fragment adding `churnRisk` (`level`, `score`, `reasons[]`, `computedAt`) to `client`, read only in forms
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 `lib/Service/ClientRiskService.php::assess()` with the five signals and weights from app config
  - Verify: PHPUnit `tests/Unit/Service/ClientRiskServiceTest.php` per signal, per cap and per level boundary
- [ ] 1.3 `lib/BackgroundJob/ClientRiskJob.php` daily, writing only on change; recompute on contract status change and on detractor classification
  - Verify: PHPUnit on the job and on the two triggers, asserting no write when nothing changed
- [ ] 1.4 Clients Risk column and At risk quick filter; ClientDetail Risk badge with reasons
  - Verify: Playwright `tests/e2e/client-risk.spec.ts` with a seeded churned contract sees the client under At risk with that reason

## 2. Summary

- [ ] 2.1 `lib/Service/ClientHistorySummaryService.php::summarise()` reading as the viewer, bounded input, hermiq through a lazy resolve, nothing stored
  - Verify: PHPUnit with a hermiq stub, without hermiq, and with a viewer who may read only some tickets (those stay out of the input)
- [ ] 2.2 Route `GET /api/clients/{id}/history-summary` with `#[NoAdminRequired]` and an object authorisation check
  - Verify: hydra gates route-auth, no-admin-idor and route-reachability pass on the diff
- [ ] 2.3 History summary card on ClientDetail, hidden without hermiq
  - Verify: Playwright with a hermiq stub shows the summary with records read and time

## 3. Docs

- [ ] 3.1 `docs/Features/client-risk-and-summary.md`: the signals, the weights and where the summary text goes
  - Verify: docs build exit 0
