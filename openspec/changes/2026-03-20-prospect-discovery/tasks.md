# Tasks: prospect-discovery scoring improvements and client exclusion

## 1. SBI exact vs prefix scoring
- [ ] 1.1 Differentiate exact match (30) from prefix-only match (15)
  - **spec_ref**: `specs/prospect-discovery/spec.md#Exact vs prefix SBI scoring differentiation`
  - **files**: `pipelinq/lib/Service/ProspectScoringService.php`

## 2. Keyword scoring
- [ ] 2.1 Add scoreKeywords() with +10 per keyword, max +20
  - **spec_ref**: `specs/prospect-discovery/spec.md#Keyword scoring in trade name`
  - **files**: `pipelinq/lib/Service/ProspectScoringService.php`

## 3. City matching
- [ ] 3.1 Add city matching to scoreLocation() (OR with province)
  - **spec_ref**: `specs/prospect-discovery/spec.md#City-level location scoring`
  - **files**: `pipelinq/lib/Service/ProspectScoringService.php`

## 4. Client exclusion wiring
- [ ] 4.1 Implement getExistingClientNames() via OpenRegister HTTP API
  - **spec_ref**: `specs/prospect-discovery/spec.md#Exclude by company name`
  - **files**: `pipelinq/lib/Service/ProspectDiscoveryService.php`

## 5. Score breakdown tooltip
- [ ] 5.1 Add hover tooltip showing score breakdown on ProspectCard
  - **spec_ref**: `specs/prospect-discovery/spec.md#Score breakdown visibility`
  - **files**: `pipelinq/src/components/ProspectCard.vue`
