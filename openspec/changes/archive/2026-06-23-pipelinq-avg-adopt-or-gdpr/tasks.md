## 1. Bridge onto OR's canonical GDPR capability

- [x] 1.1 Add `lib/Service/Avg/OrGdprBridge.php` — lazy DI resolve of `DataSubjectRequestService` + `DataSubjectDeadline`; verbs `computeDueAt`/`extend`/`findSubjectData`/`assembleAccessExport`/`erase` (pseudonymise mode); degrades to safe empties when OR is absent (`isAvailable()`); never logs the subject value
- [x] 1.2 Confirm OR's two GDPR services resolve from the server container in the :8080 deployment

## 2. Leg 1 — Deadline (EU art-12(3))

- [x] 2.1 Re-point `DeadlineService::computeDeadline` onto `OrGdprBridge` — base = received + 1 month; extension applies the single +2-month extension; remove the 30/60-day constants
- [x] 2.2 Keep the 7-day reminder / <72h escalation / breach chain (`DeadlineTrackerService`) computed FROM the new EU deadline; preserve the TermijnEvent idempotency guards
- [x] 2.3 `ExtensionService` yields received + 1mo + 2mo for the single permitted extension

## 3. Leg 2 — Discovery (NER index)

- [x] 3.1 Re-point `EvidenceCollectionService::collectFromOpenRegister` onto `OrGdprBridge::findSubjectData`; remove the `bsn`-equality `findAll`
- [x] 3.2 Keep the OpenConnector federated-source collection + BewijsItem packaging + scope overlay + dedup

## 4. Leg 3 — Access / portability export

- [x] 4.1 Anchor `BundleService::assemble` on `OrGdprBridge::assembleAccessExport` for art-15 / art-20 requests; keep signing / one-time-token / AP-dossier wrapper

## 5. Leg 4 — Erasure (legal-hold-aware pseudonymise)

- [x] 5.1 Re-point `DataDeletionService::pseudonymizeCustomerBookings` onto `OrGdprBridge::erase` (pseudonymise mode); remove the named-field SHA-256
- [x] 5.2 Align `RetentionService::pseudonymizeEvidence` on OR's `[erased]` token; keep the 30-day evidence-window schedule and the 5-year `deleteExpiredDossiers` cascade
- [x] 5.3 VERIFY the retention invariant live: a Boekhoudplicht-held booking row is reported `held` and survives erasure (OR pseudonymise is value-replace + `saveObject`, never a row delete)

## 6. Leg 5 — Request model mapping

- [x] 6.1 Map `avgVerzoek.artikel` → `dataSubjectRequest.type` (`AvgRequestService::orRequestTypeFor`)

## 7. Verify

- [x] 7.1 Update unit tests to the OR behaviour; full PHPUnit green (1581 tests)
- [x] 7.2 `composer lint` + `phpcs --warning-severity=0` clean on all changed lib files
- [x] 7.3 Live on :8080: deadline = received + 1 month; extension + 2 months; NER discovery shape; dry-run erase graceful; artikel→type mapping; held booking row survives erase; BSN never logged
- [x] 7.4 No frontend change → vitest at baseline
