# Tasks: pipelinq-mdm-consume-or

## 1. Schema annotations

- [x] 1.1 Declare `x-openregister-quality` + `x-openregister-dedup` on `masterEntity`
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-or-materialised-data-quality`
  - **files**: `lib/Settings/register.d/90-master-data-management.json`
  - **acceptance_criteria**:
    - `x-openregister-quality` declares required/format/freshness rules, writes `qualityScore`/`qualityStatus`, thresholds good 0.8 / fair 0.5
    - `x-openregister-dedup` declares matchRules + threshold 0.7 (tuned for OR's weight-normalised mean so natural-key collisions surface)
    - New `qualityScore`, `qualityStatus`, `lastSourceUpdate` properties added to `masterEntity`

## 2. Data quality — consume OR, keep agreement

- [x] 2.1 Reduce `DataQualityScorer` to the agreement term + OR-score blend
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-or-materialised-data-quality`
  - **files**: `lib/Service/Mdm/DataQualityScorer.php`
  - **acceptance_criteria**:
    - `completeness()` and `freshness()` imperative formulas deleted
    - `agreement()` retained unchanged
    - `score()` / `scoreEntity()` blend OR's materialised `qualityScore` with the agreement term into `dataQualityScore`

- [x] 2.2 Materialise `lastSourceUpdate` in `recomputeGoldenRecord`
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-or-materialised-data-quality`
  - **files**: `lib/Service/Mdm/MasterEntityService.php`
  - **acceptance_criteria**:
    - `recomputeGoldenRecord()` writes `lastSourceUpdate` = max provenance `lastUpdated`
    - OR's freshness rule has a date field to decay

## 3. Duplicates — consume OR service

- [x] 3.1 Inject OR `DuplicateDetectionService`, adapt result into the candidate DTO
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-or-backed-duplicate-detection`
  - **files**: `lib/Service/Mdm/DuplicateDetectionService.php`
  - **acceptance_criteria**:
    - `detectDuplicates()` calls OR `findDuplicates()` and maps `{objectA,objectB,score,matchedOn}` → existing DTO
    - hand-rolled deterministic + probabilistic loops deleted
    - `isAutoMergeEligible()` + `dedupeCandidates()` retained; `StringSimilarity` retained only as the noted ad-hoc fallback path
    - signature + DTO unchanged so the dashboard and `MdmDuplicateDetectionJob` keep working

## 4. Verify

- [x] 4.1 Update unit tests for the new contracts
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-or-materialised-data-quality`
  - **files**: `tests/Unit/Service/Mdm/DataQualityScorerTest.php`, `tests/Unit/Service/Mdm/DuplicateDetectionServiceTest.php`
  - **acceptance_criteria**:
    - DataQuality tests cover the agreement term + OR-blend; deleted-dimension tests removed
    - Duplicate tests cover the adapter mapping + auto-merge gate against an OR-service stub
    - vitest/phpunit ≥ baseline

- [x] 4.2 Live + structural verify
  - **spec_ref**: `specs/master-data-management/spec.md#requirement-or-backed-duplicate-detection`
  - **files**: `openspec/changes/pipelinq-mdm-consume-or/design.md`
  - **acceptance_criteria**:
    - Schema shows the annotations in OR after `maintenance:repair`
    - OR `findDuplicates()` returns a seeded near-dup pair live
    - Views render scores/candidates; materialisation caveat noted if applicable
