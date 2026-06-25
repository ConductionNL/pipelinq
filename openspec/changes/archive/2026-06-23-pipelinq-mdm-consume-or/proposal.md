# Proposal: pipelinq-mdm-consume-or

## Why

Pipelinq hand-rolls generic data-quality scoring and duplicate detection that OpenRegister now ships as a foundational, RBAC/tenant-scoped abstraction (`mdm-foundation`). Per ADR-022 pipelinq should declare the rules and let OR compute, deleting the duplicated imperative engine and aligning with the shared path.

## What Changes

- Declare `x-openregister-quality` + `x-openregister-dedup` on the `masterEntity` schema.
- Reduce pipelinq's `DataQualityScorer` to the cross-source agreement term + an OR-`qualityScore` blend; delete the imperative completeness/format/freshness formulas.
- Re-point `DuplicateDetectionService` onto OR's `findDuplicates()`; delete the hand-rolled deterministic + probabilistic loops; keep the auto-merge gate, candidate de-dup, and a noted in-process fallback.
- Materialise `lastSourceUpdate` + flattened `match*` projections so OR's single-object scorer/similarity can read them.
- Views, merge, survivorship, trust, and sync queue are unchanged.

## Problem

Pipelinq's Master Data Management (MDM) capability (archived change `2026-06-14-master-data-management`) hand-rolls two pieces of generic data-quality machinery that OpenRegister now ships as a foundational, DI-resolvable abstraction (OR `mdm-foundation`, openregister/development e39023689):

1. **Imperative data-quality scoring.** `DataQualityScorer` computes a per-Master-Entity `dataQualityScore` from completeness (required golden-record attributes filled) + freshness (exponential decay on the most recent source change) + agreement (cross-source conflict). `MdmDataQualityScorerJob` walks every active entity nightly and persists the score. The completeness, format and freshness dimensions are generic field-quality checks that OR now materialises on save via the `x-openregister-quality` schema annotation.

2. **Imperative duplicate detection.** `DuplicateDetectionService::detectDuplicates()` runs an O(n²) comparison loop over every active entity for deterministic natural-key collisions and probabilistic name/address fuzzy matching. The deterministic key collision + per-field similarity blocking is exactly what OR's `OCA\OpenRegister\Service\Quality\DuplicateDetectionService::findDuplicates()` now provides declaratively via the `x-openregister-dedup` annotation, RBAC- and tenant-scoped through `ObjectService::findAll`.

Hand-rolling these in pipelinq duplicates OR's engine, drifts from the shared abstraction, and is not RBAC/tenant-scoped the way OR's path is. Per ADR-022 (apps consume OR abstractions) pipelinq should declare the rules and let OR compute.

## Solution

Re-point pipelinq's MDM quality + dedup onto the OR abstraction, keeping only the parts that genuinely depend on pipelinq's survivorship/trust model:

1. **Data quality → `x-openregister-quality` annotation.** Declare the annotation on the `masterEntity` schema mapping pipelinq's completeness (required golden-record fields) and format (email / kvk) dimensions to OR quality rules + a freshness rule on a materialised `lastSourceUpdate` field. OR writes `qualityScore` + `qualityStatus` on save. Pipelinq's `DataQualityScorer` is reduced to the **cross-source agreement / conflict** term that OR does not model (it needs the linked source records, not a single object), and `scoreEntity()` becomes a post-step that blends OR's materialised `qualityScore` with the app-side agreement term into the existing `dataQualityScore` field the views read. The completeness/freshness/format formulas and their imperative code are deleted.

2. **Duplicates → `x-openregister-dedup` annotation + OR service.** Declare the annotation (blocking key + per-field exact/normalized/levenshtein match rules + threshold) on `masterEntity`. Inject OR's `DuplicateDetectionService` into pipelinq's service; `detectDuplicates()` calls `findDuplicates(register, schema, matchRules)` and adapts OR's `{objectA, objectB, score, matchedOn[]}` result into pipelinq's existing candidate DTO (`fromEntity/intoEntity/linkageMethod/linkageConfidence/matchedOn`) so the Duplicate Candidates dashboard and the auto-merge job keep working unchanged. The hand-rolled deterministic + probabilistic comparison loops are deleted. Kept app-side: the `autoMergeEligible` decision (depends on the trust-tier rule's `manualOverrideAllowed`) and the higher-confidence pair de-dup.

3. **Survivorship / trust / merge / sync-queue stay entirely in pipelinq** — they depend on the gold/silver/bronze trust tiers, attribute provenance and merge lineage that are pipelinq domain logic, not OR primitives.

## Scope

Schema annotations in `lib/Settings/register.d/90-master-data-management.json` (`masterEntity`):
- `x-openregister-quality` — required rules on golden-record name/email/kvk, format rules on email/kvk, freshness rule on `lastSourceUpdate`; writes `qualityScore`/`qualityStatus`; thresholds good 0.8 / fair 0.5
- `x-openregister-dedup` — blocking key, exact/normalized/levenshtein match rules on email/name/kvk/phone, threshold 0.85
- New `qualityScore`, `qualityStatus`, `lastSourceUpdate` properties on `masterEntity`

Backend:
- `DataQualityScorer` reduced to the agreement term + an OR-score blend; completeness/freshness/format formulas deleted
- `MasterEntityService.recomputeGoldenRecord()` materialises `lastSourceUpdate` (max provenance lastUpdated) so OR's freshness rule has a field to decay
- `DuplicateDetectionService` calls OR's `findDuplicates()` + adapts the result; deterministic/probabilistic loops deleted (Jaro-Winkler/TF-IDF `StringSimilarity` retained only as the ad-hoc fallback path with a note, since OR has no Jaro-Winkler yet)
- `MdmDuplicateDetectionJob` unchanged in shape (consumes the same candidate DTO)
- `MdmDataQualityScorerJob` unchanged in shape (still calls `scoreEntity`, now an OR-blend post-step)

Frontend: no change — `MdmMasterEntityListView` / `MdmDataQualitySection` keep reading `dataQualityScore`; `MdmDuplicateCandidatesDashboard` keeps reading the candidate DTO.

**Depends on:** OpenRegister `mdm-foundation` (`x-openregister-quality`, `x-openregister-dedup`, `DuplicateDetectionService`), archived `master-data-management`.

## Out of Scope

- Survivorship / trust-tier resolution, merge, reverse-merge, sync queue — unchanged
- A new customer/contact schema — Master Entity model unchanged
- Adding Jaro-Winkler / TF-IDF to OR — pipelinq passes ad-hoc match rules meanwhile
- Changing the views or the candidate DTO contract

## Success Criteria

- The `masterEntity` schema in OR shows `x-openregister-quality` and `x-openregister-dedup` in its configuration after re-import
- OR `findDuplicates()` called on the masterEntity schema with two near-duplicate seeded entities returns the candidate pair
- The Master entities / Data quality / Duplicates views still render their scores and candidates
- Pipelinq's completeness/freshness/format scoring code is deleted; the agreement term + OR-blend remain
- `composer lint` + phpcs clean on changed PHP, build green, vitest ≥ baseline
