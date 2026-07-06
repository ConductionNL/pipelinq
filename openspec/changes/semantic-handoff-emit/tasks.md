# Tasks: semantic-handoff-emit

Precondition for every task: **verify the hydra `semantic-object-handoff` contract (ADR-051) against HEAD in `../hydra` before wiring** — it is authored in parallel; the dialect/mapping shapes in design.md are indicative, not authoritative.

## 1. Handoff Service

- [ ] 1.1 `SemanticHandoffService` (kind resolution + emit wrapper)
  - **spec_ref**: `specs/request-management/spec.md#requirement-request-to-case-conversion-v1`
  - **files**: `lib/Service/SemanticHandoffService.php`
  - **acceptance_criteria**:
    - `hasImplementer(kindUri)` via OR `SemanticTypeResolver`, lazy container resolve, false when OR absent
    - `handoff(kindUri, payload)` invokes OR's handoff engine per the `x-openregister-handoff` dialect; no queueing/retry/log app-side (ADR-045)
    - No hard-coded app ids anywhere in the emit path (kind-addressed only); implementer app id appears only in returned provenance
    - Unit tests: implementer-present, implementer-absent, OR-absent, target-creation failure propagation

## 2. Request → ns#Case

- [ ] 2.1 Conversion endpoint + lifecycle wiring
  - **spec_ref**: `specs/request-management/spec.md#requirement-request-to-case-conversion-v1`
  - **files**: `lib/Controller/` (request conversion action), `appinfo/routes.php`
  - **acceptance_criteria**:
    - Allowed from `in_progress` only; refuses `new` and terminal statuses server-side
    - Success: status→`converted`, `caseReference = {targetUuid, implementerAppId}`; failure: request untouched
    - No-implementer: clean not-available error (no 500); auth attribute + per-object guard (no-admin-idor gate)
    - Emit mapping per hydra contract (title→name, description, client→subject, contact→applicant, priority, channel, provenance.source)

- [ ] 2.2 Request emit declaration on the schema
  - **spec_ref**: `specs/request-management/spec.md#requirement-request-to-case-conversion-v1`
  - **files**: `lib/Settings/pipelinq_register.json` (request schema)
  - **acceptance_criteria**:
    - `request` carries the ADR-051 emit/handoff declaration targeting `ns#Case` in the dialect form the hydra change defines (follow the ADR-048 `referenceSemanticType` precedent style in `register.d/92-product-supply-master.json`)
    - Existing objects remain valid (additive only)

- [ ] 2.3 "Convert to case" UI + converted state
  - **spec_ref**: `specs/request-management/spec.md#requirement-request-to-case-conversion-v1`
  - **files**: `src/views/requests/` (detail view), `src/modals/` if a confirm dialog is used
  - **acceptance_criteria**:
    - Action rendered only when initial-state reports an `ns#Case` implementer AND status is `in_progress`
    - Converted state: cross-app deep link from `caseReference`, core fields read-only, converted notice
    - Modal (if any) in `src/modals/` (modal-isolation gate); NcSelect fields carry `inputLabel`; English i18n source keys + nl catalog

## 3. Quote / Contract → Invoicing

- [ ] 3.1 `ns#Contract` declaration on the contract schema
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-schema-registration`
  - **files**: `lib/Settings/register.d/96-contract-renewal.json`
  - **acceptance_criteria**:
    - `contract` carries the `implements ns#Contract` declaration in the hydra dialect form; additive, existing objects valid

- [ ] 3.2 Contract "Send to invoicing" emit
  - **spec_ref**: `specs/contract-renewal-tracking/spec.md#requirement-contract-to-invoicing-handoff-emit`
  - **files**: `lib/Controller/` (contract handoff action), `appinfo/routes.php`, contract detail view component
  - **acceptance_criteria**:
    - Emit from `active` contracts to the `ns#Invoice` implementer with mapped fields (lines, amount+interval, currency, customer, provenance)
    - Hidden without implementer; endpoint refuses cleanly; failed handoff leaves the contract unchanged
    - Provenance link recorded on the contract; Playwright covers the UI action (with a stub implementer fixture), Newman covers the API refusal

- [ ] 3.3 Quote declaration + emit (spec-binding only until quoting is built)
  - **spec_ref**: `specs/product-catalog-quoting/spec.md#requirement-quote-semantic-kind-declaration-and-invoicing-emit-enterprise`
  - **files**: `openspec/specs/product-catalog-quoting/spec.md` (via opsx-sync on archive)
  - **acceptance_criteria**:
    - The quoting capability spec now requires the `ns#Quote` declaration + accepted-quote emit; no `register.d/` change ships (schema unbuilt at HEAD — verified)
    - `semantic-handoff-emit` introduces no phantom quote schema

## 4. Docs & Verification

- [ ] 4.1 Feature docs for the handoff chains
  - **spec_ref**: `specs/request-management/spec.md#requirement-request-to-case-conversion-v1`
  - **files**: `docs/Features/` (request-to-case + contracts pages)
  - **acceptance_criteria**:
    - Docs describe kind-addressed handoff (ns#Case / ns#Invoice), the hidden-without-implementer rule, and provenance links; no doc names procest/shillinq as the hard-wired target

- [ ] 4.2 Tests + gates
  - **spec_ref**: all
  - **files**: `tests/unit/`, `tests/e2e/`
  - **acceptance_criteria**:
    - PHPUnit: service + endpoint matrices (implementer present/absent, status guards, failure atomicity)
    - Playwright: convert-to-case happy path + hidden-action assertion through real UI clicks; API assertions in Newman
    - `composer check:strict` green; hydra gates pass (route-auth, no-admin-idor, spec-coverage, e2e-coverage)
