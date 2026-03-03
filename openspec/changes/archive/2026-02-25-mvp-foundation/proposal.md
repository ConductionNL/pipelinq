# Proposal: mvp-foundation

## Summary
Expand Pipelinq's data model from the current 3 schemas (client, request, contact) to include lead and pipeline entities, update the repair step to use the JSON config pattern (matching opencatalogi/softwarecatalog), register all 5 entity types in the Pinia store, and build an admin settings page for register/schema management.

## Motivation
Pipelinq currently has a basic CRM setup with clients, requests, and contacts. The ARCHITECTURE.md and FEATURES.md define leads and pipelines as core MVP entities — leads represent sales opportunities, and pipelines provide visual kanban tracking for both leads and requests. The current repair step uses inline PHP schema definitions instead of the standard `procest_register.json` pattern. The admin settings UI is missing entirely, requiring manual configuration.

This change lays the foundation for all subsequent Pipelinq features by:
1. Adding the missing lead and pipeline schemas to the register
2. Migrating to the JSON config pattern for maintainability
3. Registering all entity types in the frontend store layer
4. Providing admin UI for register configuration

## Affected Projects
- [x] Project: `pipelinq` — Register config, repair step, Pinia stores, admin settings

## Scope
### In Scope
- Create `lib/Settings/pipelinq_register.json` with all 5 schemas (client, contact, lead, request, pipeline)
- Rewrite `lib/Repair/InitializeSettings.php` to use `ConfigurationService::importFromApp('pipelinq')`
- Register lead and pipeline object types in `src/store/store.js`
- Create `lib/Settings/PipelinqAdmin.php` admin settings section
- Create `src/settings.js` admin settings entry point (if not exists)
- Create admin settings Vue component for register/schema management

### Out of Scope
- Lead/pipeline list and detail views (separate change)
- Pipeline kanban board (separate change)
- Dashboard KPIs (separate change)
- Request-to-case conversion bridge to Procest

## Approach
1. **Create `pipelinq_register.json`** — OpenAPI 3.0.0 format defining the `pipelinq` register with 5 schemas, matching the entity definitions from ARCHITECTURE.md (Schema.org types, vCard fields, required/optional properties)
2. **Rewrite repair step** — Replace inline PHP schema arrays with `ConfigurationService::importFromApp('pipelinq')`, matching the pattern used by opencatalogi and softwarecatalog
3. **Update store initialization** — Register `lead` and `pipeline` object types alongside existing client/request/contact types
4. **Build admin settings** — PHP admin section + Vue component showing register status, schema list, and re-import button

## Cross-Project Dependencies
- **OpenRegister** — `ConfigurationService::importFromApp()` must be available (already implemented)
- **Procest** — No impact; the request-to-case bridge is out of scope for this change

## Rollback Strategy
Revert the repair step to the inline PHP version. Remove the JSON config file. Remove lead/pipeline registrations from the store. The existing client/request/contact data in OpenRegister is unaffected.
