# Contact Relationship Mapping - Tasks

- [ ] Add `relationship` schema to `pipelinq_register.json` with properties: fromContact, toContact, fromType, toType, type, inverseType, notes, startDate, endDate, strength
- [ ] Create `RelationshipService.php` with auto-inverse relationship creation
- [ ] Define predefined relationship types (partner, ouder/kind, werkgever/werknemer, collega)
- [ ] Build `RelationshipManager.vue` component for CRUD operations
- [ ] Integrate relationship display into client detail view
- [ ] Add relationship type selector with inverse type auto-fill
- [ ] Add relationship list with filter by type and status (active/ended)
- [ ] Write unit tests for RelationshipService auto-inverse logic
