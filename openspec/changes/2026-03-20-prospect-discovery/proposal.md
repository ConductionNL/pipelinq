# Proposal: prospect-discovery scoring improvements and client exclusion

## Problem

The prospect-discovery spec identifies gaps in scoring and client exclusion:
1. SBI scoring gives 30 points for prefix match but does not distinguish exact (30) vs prefix-only (15)
2. Keyword scoring not implemented (+10 per keyword, max +20)
3. City matching not implemented in scoring (only province)
4. Client exclusion stub returns empty array -- never actually excludes existing clients
5. Score breakdown not visible to users on prospect cards

## Proposed Change

1. Fix SBI scoring: exact match = 30, prefix-only = 15
2. Add keyword scoring to ProspectScoringService
3. Add city matching to location scoring (OR with province)
4. Wire up client exclusion via OpenRegister HTTP API
5. Add score breakdown tooltip to ProspectCard.vue

### Out of Scope
- Prospect enrichment (website, LinkedIn)
- Prospect list management
- Outreach tracking
- Bulk import/export
- Market segment analysis
- GDPR retention policies

## Impact
- **Files modified**: 3 (ProspectScoringService.php, ProspectDiscoveryService.php, ProspectCard.vue)
- **Risk**: Low — scoring improvements are isolated, client exclusion adds API call
