# Klantbeeld 360 - Full Enrichment - Design

## Approach
1. Extend ClientDetail.vue with tabs: Profile, Timeline, Zaken, Documenten
2. Build BRP enrichment service via OpenConnector
3. Extend KvkApiClient for detailed company enrichment
4. Build ZGW Zaken integration for case visibility
5. Add document linking via Nextcloud Files

## Files Affected
- `src/views/clients/ClientDetail.vue` - Add klantbeeld tabs
- `src/components/klantbeeld/InteractionHistory.vue` - Aggregated timeline
- `src/components/klantbeeld/ZakenOverview.vue` - Linked cases from ZGW
- `src/components/klantbeeld/DocumentenTab.vue` - Document management
- `src/components/klantbeeld/BrpEnrichment.vue` - BRP data display
- `lib/Service/BrpService.php` - BRP lookup via OpenConnector
- `lib/Service/KvkApiClient.php` - Extend for detailed enrichment
- `lib/Service/ZakenService.php` - ZGW Zaken API integration
