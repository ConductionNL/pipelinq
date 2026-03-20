# Delta Spec: prospect-discovery scoring improvements and client exclusion

## Newly Implemented

- **SBI exact vs prefix scoring**: `scoreSbi()` now distinguishes exact match (30 points) from prefix-only match (15 points). Iterates target codes, tracks highest score.
- **Keyword scoring**: New `scoreKeywords()` method awards +10 per keyword match (case-insensitive substring in tradeName or sbiDescription), capped at +20. Added to breakdown as `keywordMatch`.
- **City-level location scoring**: `scoreLocation()` accepts city and target cities. Province match OR city match awards 20 points.
- **Existing client exclusion**: `getExistingClientNames()` now calls OpenRegister API to fetch all clients and returns lowercased names for exclusion matching.
- **Score breakdown tooltip**: ProspectCard.vue shows hover tooltip on fit score badge with per-category score breakdown.
