# Reverse-spec — Contact 360 UI

Retroactively specifies the observed behavior of 52 method(s) implementing contact detail, relationships and quick-log screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/components/ContactImportDialog.vue::doSearch`
- `src/components/ContactImportDialog.vue::importContact`
- `src/components/ContactImportDialog.vue::onSearch`
- `src/components/ContactRelationships.vue::closeDialog`
- `src/components/ContactRelationships.vue::confirmRemove`
- `src/components/ContactRelationships.vue::editRelationship`
- `src/components/ContactRelationships.vue::fetchRelationships`
- `src/components/ContactRelationships.vue::groupedRelationships`
- `src/components/ContactRelationships.vue::isEnded`
- `src/components/ContactRelationships.vue::loadEntityName`
- `src/components/ContactRelationships.vue::navigateToEntity`
- `src/components/ContactRelationships.vue::objectStore`
- `src/components/ContactRelationships.vue::onTypeSelect`
- `src/components/ContactRelationships.vue::removeRelationship`
- `src/components/ContactRelationships.vue::saveRelationship`
- `src/components/ContactRelationships.vue::searchEntities`
- `src/components/ContactRelationships.vue::strengthOptions`
- `src/components/ContactRelationships.vue::typeOptions`
- `src/components/ContactmomentQuickLog.vue::clientSelectOptions`
- `src/components/ContactmomentQuickLog.vue::clients`
- `src/components/ContactmomentQuickLog.vue::created`
- `src/components/ContactmomentQuickLog.vue::errors`
- `src/components/ContactmomentQuickLog.vue::objectStore`
- `src/components/ContactmomentQuickLog.vue::onSave`
- `src/components/ContactmomentQuickLog.vue::requestSelectOptions`
- `src/components/ContactmomentQuickLog.vue::requests`
- `src/components/EmailTimeline.vue::fetchEmails`
- `src/components/EmailTimeline.vue::formatDate`
- `src/components/EmailTimeline.vue::groupedEmails`
- `src/views/contacts/ContactDetail.vue::confirmDelete`
- `src/views/contacts/ContactDetail.vue::contactData`
- `src/views/contacts/ContactDetail.vue::loadClientName`
- `src/views/contacts/ContactDetail.vue::loading`
- `src/views/contacts/ContactDetail.vue::mounted`
- `src/views/contacts/ContactDetail.vue::objectStore`
- `src/views/contacts/ContactDetail.vue::onFormCancel`
- `src/views/contacts/ContactDetail.vue::onFormSave`
- `src/views/contacts/ContactDetail.vue::preSelectedClient`
- `src/views/contacts/ContactDetail.vue::sidebarProps`
- `src/views/contacts/ContactDetail.vue::syncToContacts`
- `src/views/contacts/ContactForm.vue::ensureClientInOptions`
- `src/views/contacts/ContactForm.vue::handler`
- `src/views/contacts/ContactForm.vue::isValid`
- `src/views/contacts/ContactForm.vue::loadInitialClients`
- `src/views/contacts/ContactForm.vue::mounted`
- `src/views/contacts/ContactForm.vue::objectStore`
- `src/views/contacts/ContactForm.vue::onSave`
- `src/views/contacts/ContactForm.vue::populateForm`
- `src/views/contacts/ContactForm.vue::searchClients`
- `src/views/contacts/ContactForm.vue::selectedClient`
- `src/views/contacts/ContactForm.vue::validateAll`
- `src/views/contacts/ContactForm.vue::validateField`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
