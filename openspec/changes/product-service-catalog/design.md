# Product & Service Catalog (PDC) - Design

## Approach
1. Create `pdc_register.json` with IPDC-compliant product schema
2. Build PDC management views for product editors
3. Implement UPL reference validation
4. Build public API for citizen portal integration
5. Add multilingual content support per SDG requirements

## Files Affected
- `lib/Settings/pdc_register.json` - New register definition for PDC
- `lib/Service/PdcService.php` - PDC management service
- `src/views/pdc/ProductList.vue` - PDC product management
- `src/views/pdc/ProductDetail.vue` - Product editor with content blocks
- `lib/Controller/PdcPublicController.php` - Public read-only API
- `src/router/index.js` - Add PDC management routes
