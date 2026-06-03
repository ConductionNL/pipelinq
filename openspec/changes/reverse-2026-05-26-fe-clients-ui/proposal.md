# Reverse-spec — Client UI

Retroactively specifies the observed behavior of 34 method(s) implementing client management screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/clients/ClientCreateDialog.vue::objectStore`
- `src/views/clients/ClientCreateDialog.vue::onSave`
- `src/views/clients/ClientDetail.vue::addContact`
- `src/views/clients/ClientDetail.vue::clientData`
- `src/views/clients/ClientDetail.vue::confirmDelete`
- `src/views/clients/ClientDetail.vue::createComplaint`
- `src/views/clients/ClientDetail.vue::createRequest`
- `src/views/clients/ClientDetail.vue::fetchRelated`
- `src/views/clients/ClientDetail.vue::formatCurrency`
- `src/views/clients/ClientDetail.vue::formatDate`
- `src/views/clients/ClientDetail.vue::loading`
- `src/views/clients/ClientDetail.vue::mounted`
- `src/views/clients/ClientDetail.vue::objectStore`
- `src/views/clients/ClientDetail.vue::onContactmomentSaved`
- `src/views/clients/ClientDetail.vue::onFormCancel`
- `src/views/clients/ClientDetail.vue::onFormSave`
- `src/views/clients/ClientDetail.vue::openLeadsCount`
- `src/views/clients/ClientDetail.vue::openLeadsValue`
- `src/views/clients/ClientDetail.vue::openRequestsCount`
- `src/views/clients/ClientDetail.vue::showDeleteWarning`
- `src/views/clients/ClientDetail.vue::sidebarProps`
- `src/views/clients/ClientDetail.vue::syncToContacts`
- `src/views/clients/ClientDetail.vue::totalValue`
- `src/views/clients/ClientDetail.vue::wonLeadsCount`
- `src/views/clients/ClientDetail.vue::wonLeadsValue`
- `src/views/clients/ClientForm.vue::handler`
- `src/views/clients/ClientForm.vue::isValid`
- `src/views/clients/ClientForm.vue::onSave`
- `src/views/clients/ClientForm.vue::populateForm`
- `src/views/clients/ClientForm.vue::validateAll`
- `src/views/clients/ClientForm.vue::validateField`
- `src/views/clients/ClientList.vue::createNew`
- `src/views/clients/ClientList.vue::openClient`
- `src/views/clients/ClientList.vue::setup`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
