# Tasks: pipelinq-gdpr-to-or-subsystem (Seam 3)

## 1. Investigation (DONE — recorded in design.md)

- [x] 1.1 Read OR `DsarService`, `AvgRetentionService`, `RetentionService`, `ArchivalService`, `AuditHashService` and determine per-service consumability + contract
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014-openregister-compliance-subsystem-consumption-boundary`
  - **acceptance_criteria**:
    - Per service: consumable-from-leaf-app? and exact method contract documented
    - Decisive mismatches (admin-gating, PII-index vs register-scoped find, activity-keyed vs dossier-keyed retention, soft-delete vs field-pseudonymise) documented

## 2. Behaviour-preservation pinning tests (no production logic change)

- [ ] 2.1 Pin `EvidenceCollectionService::collectFromOpenRegister` find-set
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014-openregister-compliance-subsystem-consumption-boundary`
  - **files**: `tests/Unit/Service/Avg/EvidenceCollectionServiceTest.php`
  - **acceptance_criteria**:
    - Asserts a plain `['bsn' => …]` equality filter is issued to `ObjectService::findAll` (not the OR PII index)
    - Asserts the collected BewijsItem set for a fixed subject+scope is unchanged

- [ ] 2.2 Pin `DataDeletionService` anonymised-field-set
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014-openregister-compliance-subsystem-consumption-boundary`
  - **files**: `tests/Unit/Service/DataDeletionServiceTest.php`
  - **acceptance_criteria**:
    - Asserts exactly `customerName/customerEmail/customerPhone` are SHA-256 hashed
    - Asserts every other field + the record are retained (no soft-delete)
    - Asserts the find uses `['customerId' => …]` equality on the booking register/schema

- [ ] 2.3 Pin `Avg/RetentionService` cut-offs
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014-openregister-compliance-subsystem-consumption-boundary`
  - **files**: `tests/Unit/Service/Avg/RetentionServiceTest.php`
  - **acceptance_criteria**:
    - Asserts evidence pseudonymisation cut-off = `verzameldOp + configured days`
    - Asserts dossier hard-delete only when `retentieTot <= now`
    - No dependency on OR `processing_activity_id` / `AvgRetentionService`

## 3. Capability boundary spec

- [ ] 3.1 Add REQ-AVG-014 (OR compliance-subsystem consumption boundary) to the capability
  - **spec_ref**: `specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-014-openregister-compliance-subsystem-consumption-boundary`
  - **files**: `openspec/specs/avg-verzoeken-workflow/spec.md` (via sync from this change's delta)
  - **acceptance_criteria**:
    - Boundary requirement + 4 scenarios present; `openspec validate --strict` passes

## 4. Verification

- [ ] 4.1 `composer lint` + `phpcs --warning-severity=0` clean on any changed `lib/`/`tests/`
- [ ] 4.2 Full Unit suite ≥ baseline genuinely-passing count; do not add to the pre-existing OR-stub harness errors
- [ ] 4.3 Live-verify on :8080: seed a throwaway subject + objects, run DSAR find + retention pass via the existing path, prove identical objects/fields/cut-offs; clean up seeded data
