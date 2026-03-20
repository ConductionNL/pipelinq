# Contact Relationship Mapping - Design

## Approach
1. Add `relationship` schema to `lib/Settings/pipelinq_register.json`
2. Create `RelationshipService` for auto-inverse creation
3. Build Vue components for relationship management on detail views
4. Add relationship type registry with predefined types (family, professional, organizational)

## Files Affected
- `lib/Settings/pipelinq_register.json` - Add relationship schema
- `lib/Service/RelationshipService.php` - New service for relationship CRUD with auto-inverse
- `src/components/RelationshipManager.vue` - New component for managing relationships
- `src/views/clients/ClientDetail.vue` - Add relationships tab
- `src/views/contacts/ContactDetail.vue` - Add relationships tab (if separate view exists)
