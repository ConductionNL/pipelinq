# Tasks: klantbeeld-360-activation

## 1. Summary service

- [ ] 1.1 Create `lib/Service/KlantbeeldSummaryService.php` with `getSummary(clientId)` aggregating (RBAC-scoped) open-ticket count + per-`ticketType` breakdown, SLA breached/at-risk counts (compare `slaDeadline` to now — reuse the SLA engine's threshold when present), distinct open-ticket queues, open-lead count + summed `value`, and last-activity time
  - files: `lib/Service/KlantbeeldSummaryService.php`
  - Acceptance criteria:
    - All reads go through `ObjectService`/`TicketService` with RBAC enabled; hidden objects never contribute
    - Open tickets counted across all three `ticketType`s and all open statuses (not a single equality filter)

## 2. Endpoint + access logging

- [ ] 2.1 Add `GET /api/klantbeeld/{clientId}/summary` (extend `ReportingController` or a small `KlantbeeldController`, `#[NoAdminRequired]` + per-object read guard on the client) returning the summary
- [ ] 2.2 Log each klantbeeld access (user, client id, time) on the endpoint (doelbinding MVP), reusing the app's audit facility
  - files: `lib/Controller/ReportingController.php` (or new `KlantbeeldController.php`), `appinfo/routes.php`
  - Acceptance criteria:
    - Route declares its auth posture (route-auth gate passes) and guards read against the client object (no IDOR)
    - An access-log entry is written per call

## 3. Surface on the declarative Client 360

- [ ] 3.1 Add a summary panel widget to the `ClientDetail` page in `src/manifest.json`, endpoint-bound to `/api/klantbeeld/{clientId}/summary`, reusing the declarative detail machinery (no bespoke `ClientDetail.vue`)
- [ ] 3.2 Add quick actions to `ClientDetail` — "Nieuw verzoek" (create `ticket` `ticketType=request` pre-linked to the client), "Contactpersoon toevoegen", "Notitie toevoegen" — as declarative page/header actions
  - files: `src/manifest.json`
  - Acceptance criteria:
    - The page still renders without a host `ClientDetail.vue`; `src/manifest.json` passes manifest validation
    - "Nieuw verzoek" prefills the client FK and sets `ticketType=request`

## 4. Seed data

- [ ] 4.1 Top up archetype seed coverage (municipality / consultancy / travel agency) so each client has a mix of open tickets across types + a lead, including one breached and one at-risk `slaDeadline`, so the summary is verifiable on a fresh install
  - files: `lib/Settings/pipelinq_register.json` (or the relevant `register.d/*` fragment)
  - Acceptance criteria:
    - Each archetype client resolves a non-trivial summary (open-ticket count, SLA breached/at-risk, pipeline value)
    - Example ids in docs use the nil UUID; seed objects follow the register's slug style

## 5. Tests + spec reconcile

- [ ] 5.1 Unit-test `KlantbeeldSummaryService` (cross-type open count, SLA breached/at-risk, RBAC scoping) and the endpoint (auth guard, access-log write) with the archetype fixtures
  - files: `tests/Unit/Service/KlantbeeldSummaryServiceTest.php`, `tests/Unit/Controller/*`
  - Acceptance criteria:
    - `composer check:strict` passes
    - RBAC-hidden tickets/leads are excluded from every count/total
- [ ] 5.2 Update `openspec/specs/klantbeeld-360/spec.md` status to in-progress and record the MVP trims (BRP/KVK, ZGW zaken, documents, pinned notes) as follow-ups

## Acceptance criteria (change-level)

- The Client 360 satisfies the draft's MVP core (identity, open/closed tickets, leads, contactmomenten timeline, SLA/queue status, quick actions) over the unified `ticket` schema.
- Only the non-declarable aggregation + access logging land as code; everything else reuses the declarative page (no bespoke `ClientDetail.vue`).
- Deferred enrichment (BRP/KVK, ZGW, documents, pinned notes) is tracked, not built.
