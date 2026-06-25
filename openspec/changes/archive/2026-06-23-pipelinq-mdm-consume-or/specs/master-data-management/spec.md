# Master Data Management — Consume OpenRegister MDM Abstraction (Delta)

**Spec refs**: archived change `2026-06-14-master-data-management` (REQ-MDM-002/003/007), OpenRegister `mdm-foundation` (`x-openregister-quality`, `x-openregister-dedup`, `DuplicateDetectionService`), ADR-022 (apps consume OR abstractions)

## MODIFIED Requirements

### Requirement: REQ-MDM-002 — Deterministic Duplicate Detection on Natural Keys

The system MUST detect deterministic duplicates by declaring exact-match rules on the natural keys (KvK number, email, …) in the `masterEntity` schema's `x-openregister-dedup` annotation and delegating detection to OpenRegister's `DuplicateDetectionService::findDuplicates()`. The app MUST NOT run a hand-rolled natural-key comparison loop. The app MUST adapt OR's `{objectA, objectB, score, matchedOn[]}` result into the existing duplicate-candidate DTO, classifying a pair whose `matchedOn` includes a natural key as `linkageMethod = deterministic-key`, `linkageConfidence = 1.0`. The app MUST retain (app-side, not OR) the auto-merge eligibility decision, which depends on the trust-tier rule's `manualOverrideAllowed`.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-foundation`. Auto-merge gate + DTO adaptation stay in pipelinq.

#### Scenario: Two entities with same KvK

- GIVEN two Master Entities with masterId A and B, both carrying KvK number "12345678"
- WHEN the duplicate detector runs
- THEN OpenRegister's `findDuplicates()` returns the pair (matched on the flattened `matchKvkNumber` field)
- AND the app emits a duplicate-candidate DTO with `linkageMethod = deterministic-key`, `linkageConfidence = 1.0`
- AND the candidate appears in the stewardship queue for data-steward approval
- OR is auto-merged if `manualOverrideAllowed = false` for KvK conflicts in trust-configuration

#### Scenario: Hand-rolled deterministic loop is removed

- WHEN the duplicate-detection service source is inspected
- THEN it MUST NOT contain the imperative deterministic natural-key comparison loop (detection is delegated to OpenRegister)

---

### Requirement: REQ-MDM-003 — Probabilistic Duplicate Detection on Fuzzy Match

The system MUST support probabilistic duplicate detection via the `normalized` and `levenshtein` match methods declared in the `x-openregister-dedup` annotation and resolved by OpenRegister's `DuplicateDetectionService`. Because OpenRegister returns a weight-normalised mean of per-field similarities, the dedup threshold MUST be tuned (0.7) so that a pair agreeing on natural keys surfaces while a single weak field cannot. Jaro-Winkler / TF-IDF scoring that OpenRegister does not yet model MAY be retained as a noted in-process fallback path used only when OpenRegister is unavailable.

**Feature tier**: MVP
**Handoff**: Primary path is OpenRegister `findDuplicates()`; Jaro-Winkler/TF-IDF retained only as the OR-unavailable fallback.

#### Scenario: Name similarity fuzzy match

- GIVEN two Master Entities "Jansens Bouw BV" and "Jansen's Bouw B.V." sharing a natural key
- WHEN the detector runs
- THEN OpenRegister's `findDuplicates()` returns the pair with `linkageMethod = probabilistic-match` (or `deterministic-key` when a natural key matched)
- AND it appears in the stewardship queue for human decision

#### Scenario: Below threshold produces no candidate

- GIVEN two entities whose only weak signal is a partial name match below the dedup threshold
- WHEN the detector runs
- THEN NO candidate is generated (insufficient confidence)

#### Scenario: Fallback path on OpenRegister unavailability

- GIVEN OpenRegister's duplicate-detection service cannot be resolved
- WHEN `detectDuplicates()` runs
- THEN the app MUST degrade to the in-process Jaro-Winkler/TF-IDF fallback rather than failing

---

### Requirement: REQ-MDM-007 — Data-Quality-Score per Master Entity

Each Master Entity MUST have a `dataQualityScore` (0-1). The generic field-quality dimensions (completeness, format, freshness) MUST be declared on the `masterEntity` schema via the OpenRegister `x-openregister-quality` annotation so OpenRegister materialises `qualityScore` and `qualityStatus` on save; the app MUST NOT re-implement those dimensions imperatively. The app MUST retain the cross-source agreement (conflict) term — which depends on the linked source records and is not expressible as a single-object OR rule — and MUST blend OpenRegister's materialised `qualityScore` with the agreement term into `dataQualityScore`. `MasterEntityService` MUST materialise `lastSourceUpdate` (the most recent provenance `lastUpdated`) so OpenRegister's freshness rule has a single date field to decay.

**Feature tier**: MVP
**Handoff**: Consumes OpenRegister `mdm-foundation` for completeness/format/freshness; agreement + trust weighting stay in pipelinq.

#### Scenario: Quality annotation is declared and materialises on save

- WHEN the pipelinq register is (re-)imported and a Master Entity is saved
- THEN the `masterEntity` schema configuration MUST contain `x-openregister-quality`
- AND OpenRegister MUST materialise `qualityScore` and `qualityStatus` onto the saved object

#### Scenario: Freshness has a materialised date field

- WHEN `MasterEntityService` recomputes a golden record
- THEN it MUST materialise `lastSourceUpdate` as the most recent `attributeProvenance[*].lastUpdated`

#### Scenario: Agreement term stays app-side and is blended

- GIVEN two non-withdrawn source records that disagree on an attribute
- WHEN the data-quality score is recomputed
- THEN the app MUST compute the agreement (1 − conflicting/total) term itself
- AND `dataQualityScore` MUST combine OpenRegister's materialised `qualityScore` with the agreement term

#### Scenario: Imperative completeness/freshness scoring is removed

- WHEN the data-quality scorer source is inspected
- THEN it MUST NOT contain imperative completeness or freshness formulas (delegated to OpenRegister)
