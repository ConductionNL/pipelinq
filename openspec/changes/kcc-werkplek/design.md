# KCC Werkplek - Design

## Approach
1. Add `contactmoment` schema to pipelinq_register.json
2. Build KCC werkplek as a dedicated route/view
3. Create identification panel integrating BRP (via OpenConnector) and KVK (existing KvkApiClient)
4. Build contact moment registration form
5. Add queue overview and agent statistics

## Files Affected
- `lib/Settings/pipelinq_register.json` - Add contactmoment schema
- `src/views/KccWerkplek.vue` - New main KCC workspace view
- `src/components/kcc/IdentificationPanel.vue` - BSN/KVK lookup
- `src/components/kcc/ContactMomentForm.vue` - Registration form
- `src/components/kcc/QueueOverview.vue` - Waiting contacts display
- `src/router/index.js` - Add KCC werkplek route
- `lib/Controller/KccController.php` - New controller for KCC-specific endpoints
