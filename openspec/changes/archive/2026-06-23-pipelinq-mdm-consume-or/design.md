# Design: pipelinq-mdm-consume-or

**kind**: code

## What moves to OR vs what stays app-side

| Concern | Before (pipelinq imperative) | After | Why |
| --- | --- | --- | --- |
| Completeness (required golden-record fields) | `DataQualityScorer::completeness()` over `REQUIRED_ATTRIBUTES` | `x-openregister-quality` `required` rules on `goldenRecord.*` | Generic field-presence check — OR's `QualityScorer` does this and materialises on save |
| Format validity (email, kvk) | not modelled separately | `x-openregister-quality` `format` rules (`email`, `^[0-9]{8}$`) | New dimension OR gives us for free; tightens completeness into validity |
| Freshness (decay on last change) | `DataQualityScorer::freshness()` exp(-days/180) over max provenance lastUpdated | `x-openregister-quality` `freshness` rule (halfLifeDays 180) on a materialised `lastSourceUpdate` field | OR freshness decays a single date field; pipelinq materialises the max-provenance date so OR can read it |
| **Agreement / cross-source conflict** | `DataQualityScorer::agreement()` | **stays app-side** | Needs the *linked source records* (multiple objects + mappedAttributes), not a single object — OR's per-object scorer cannot see them |
| Overall `dataQualityScore` | weighted blend of the three pure terms | app-side blend of OR's materialised `qualityScore` + the agreement term | The views read `dataQualityScore`; we keep that field, now fed partly by OR |
| Gold/silver/bronze trust weighting | `TrustConfigurationService` | **stays app-side** | Survivorship domain logic, not an OR primitive |
| Deterministic key-collision detection | `detectDeterministicDuplicates()` O(n²) loop | `x-openregister-dedup` exact match rules + blocking key via OR `findDuplicates()` | Generic dedup — OR's service is RBAC/tenant-scoped and blocking-bucketed |
| Probabilistic name/address fuzzy | `detectProbabilisticDuplicates()` Jaro-Winkler + TF-IDF | OR `normalized` + `levenshtein` match rules (primary); `StringSimilarity` retained as ad-hoc fallback path with a note | OR has no Jaro-Winkler/TF-IDF yet; levenshtein-ratio + normalized covers the common case, ad-hoc rules bridge the rest |
| `autoMergeEligible` decision | `isAutoMergeEligible()` (confidence + non-overridable trust rule) | **stays app-side** | Depends on `TrustConfiguration.manualOverrideAllowed` — pipelinq survivorship policy |
| Candidate de-dup (keep higher confidence) | `dedupeCandidates()` | **stays app-side** | Adapts OR's pair list into pipelinq's DTO + applies the auto-merge gate |
| Merge / reverse-merge / sync queue | `MergeService` / `SyncQueueService` | **unchanged** | Pure pipelinq domain |

## Quality annotation mapping

OR's `QualityScorer` reads dotted field paths off the *object payload* and materialises on `ObjectCreatingEvent`/`ObjectUpdatingEvent`. Golden values live under `goldenRecord.*`, so rules target `goldenRecord.name` etc. Freshness needs a single date field; `MasterEntityService::recomputeGoldenRecord()` now also writes `lastSourceUpdate` = max(`attributeProvenance[*].lastUpdated`), which OR's freshness rule decays. The annotation writes `qualityScore` (∈[0,1]) + `qualityStatus` (good/fair/poor).

```json
"x-openregister-quality": {
  "field": "qualityScore",
  "statusField": "qualityStatus",
  "rules": [
    { "type": "required", "field": "goldenRecord.name", "weight": 1 },
    { "type": "required", "field": "goldenRecord.email", "weight": 1 },
    { "type": "format",   "field": "goldenRecord.email", "format": "email", "weight": 1 },
    { "type": "format",   "field": "goldenRecord.kvkNumber", "pattern": "^[0-9]{8}$", "weight": 1 },
    { "type": "freshness","field": "lastSourceUpdate", "halfLifeDays": 180, "weight": 2 }
  ],
  "thresholds": { "good": 0.8, "fair": 0.5 }
}
```

## Dedup annotation mapping

OR's `findDuplicates()` returns `{objectA, objectB, score, matchedOn[]}` highest-first, RBAC/tenant-scoped. Blocking on a normalized token keeps buckets small.

```json
"x-openregister-dedup": {
  "blockingKeys": [],
  "matchRules": [
    { "field": "goldenRecord.kvkNumber", "method": "exact",      "weight": 0.4 },
    { "field": "goldenRecord.email",     "method": "exact",      "weight": 0.3 },
    { "field": "goldenRecord.name",      "method": "normalized", "weight": 0.2 },
    { "field": "matchName",      "method": "levenshtein","weight": 0.1 }
  ],
  "threshold": 0.7
}
```

> NOTE: OR's `SimilarityCalculator` does not traverse dotted paths the way the QualityScorer does — it reads `data[$field]` directly. Pipelinq therefore materialises **flattened top-level `match*` projections** (`matchName`, `matchEmail`, `matchKvkNumber`, `matchPhone`) of the golden-record values in `recomputeGoldenRecord()`, and both the annotation and the live `findDuplicates()` call target those flat fields. This is the durable bridge (parallels `lastSourceUpdate`) until OR's similarity path supports dotted paths.
>
> THRESHOLD: OR's `findDuplicates()` returns a **weight-normalised mean** of the per-field similarities. Two records agreeing on both natural keys (kvk weight 0.4 + email weight 0.3 = 0.7 contribution) but differing only on name formatting score ~0.7 — a hard duplicate the old deterministic loop flagged at confidence 1.0. The threshold is therefore 0.7 (not 0.85): a single weak field cannot reach it, but a natural-key collision always does. Pipelinq's adapter then re-promotes any pair whose `matchedOn` includes a natural key to `linkageMethod: deterministic-key`, `linkageConfidence: 1.0`, preserving the old DTO semantics.

## Adapter contract

`DuplicateDetectionService::detectDuplicates($entityType)` keeps its signature and DTO so the dashboard + `MdmDuplicateDetectionJob` are untouched. Internally it:
1. resolves register + masterEntity schema ids (from app config, as today),
2. calls OR `findDuplicates(register, schema, $adHocRules, threshold)`,
3. loads the two entities per returned pair, derives `linkageMethod` (`deterministic-key` when a natural-key field is in `matchedOn`, else `probabilistic-match`), maps `score`→`linkageConfidence`, picks a representative `matchedOn`,
4. applies the existing `isAutoMergeEligible()` gate and `dedupeCandidates()` higher-confidence rule.

If OR is unavailable, it falls back to the retained `StringSimilarity` ad-hoc path (logged), so the job degrades rather than fatals.

## Materialisation — verified live (no caveat needed)

OR's quality materialisation fires on object save events. This was a documented risk (a dev-env event-dispatch quirk can prevent OR listeners firing on peer-app saves). In this environment it was verified live and works: saving two `masterEntity` objects through OR's `ObjectService::saveObject()` returned `qualityScore: 1`, `qualityStatus: "good"` materialised onto the payload by `QualityScoreOnSaveListener`. So no caveat applies here. The full chain was verified live:
- the `masterEntity` schema (v1.1.0) in OR carries both `x-openregister-quality` (5 rules) and `x-openregister-dedup` (4 rules) after re-import;
- `qualityScore`/`qualityStatus` materialise on save;
- OR `findDuplicates()` returns the seeded near-dup pair (score 0.7875 on matchKvkNumber+matchEmail);
- pipelinq's adapter returns the candidate as `deterministic-key` confidence 1.0;
- the `/api/mdm/entities`, `/api/mdm/dashboard`, `/api/mdm/duplicates/{type}` endpoints the views consume return 200 with scores/candidates.
