# Design: prospect-discovery scoring improvements and client exclusion

## SBI Exact vs Prefix Scoring

In `scoreSbi()`:
- First check for exact match (full code equals target): 30 points
- Then check for prefix match (code starts with target but is not equal): 15 points
- Return highest score found across all target codes

## Keyword Scoring

Add `scoreKeywords()` method:
- Takes prospect's `tradeName` and `sbiDescription` as combined searchable text
- Takes ICP `keywords` array
- For each keyword, case-insensitive substring match awards +10
- Maximum total: +20 (capped)
- Score capped at 100 overall

## City Matching

Extend `scoreLocation()` to accept `$city` and `$targetCities` params:
- Province match OR city match awards 20 points
- City match takes precedence over province mismatch

## Client Exclusion via HTTP API

Replace empty stub in `getExistingClientNames()` with actual OpenRegister API call:
- Use `OC::$server->getHTTPClientService()` to call `/apps/openregister/api/objects/{register}/{schema}?_limit=500`
- Extract `name` field from each client object
- Return lowercased names for fuzzy matching

## Score Breakdown Tooltip

In ProspectCard.vue:
- Add hover tooltip on the fit score badge showing breakdown
- Show each category with points awarded vs max possible
