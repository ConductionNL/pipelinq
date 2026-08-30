# Reverse-spec — Lead UI

Retroactively specifies the observed behavior of 55 method(s) implementing lead editing and lead-product linking screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/components/LeadContactRoles.vue::addRole`
- `src/components/LeadContactRoles.vue::fetchRoles`
- `src/components/LeadContactRoles.vue::getRoleLabel`
- `src/components/LeadContactRoles.vue::loadEntityName`
- `src/components/LeadContactRoles.vue::objectStore`
- `src/components/LeadContactRoles.vue::removeRole`
- `src/components/LeadContactRoles.vue::roleOptions`
- `src/components/LeadContactRoles.vue::searchContacts`
- `src/components/LeadContactRoles.vue::sortedContactRoles`
- `src/components/LeadProducts.vue::addLineItem`
- `src/components/LeadProducts.vue::calculateTotal`
- `src/components/LeadProducts.vue::fetchData`
- `src/components/LeadProducts.vue::formatCurrency`
- `src/components/LeadProducts.vue::getProductName`
- `src/components/LeadProducts.vue::grandTotal`
- `src/components/LeadProducts.vue::hasManualOverride`
- `src/components/LeadProducts.vue::objectStore`
- `src/components/LeadProducts.vue::onProductSelect`
- `src/components/LeadProducts.vue::productOptions`
- `src/components/LeadProducts.vue::removeLineItem`
- `src/components/LeadProducts.vue::resetAddForm`
- `src/components/LeadProducts.vue::updateLineItem`
- `src/views/leads/LeadCreateDialog.vue::objectStore`
- `src/views/leads/LeadCreateDialog.vue::onSave`
- `src/views/leads/LeadDetail.vue::confirmDelete`
- `src/views/leads/LeadDetail.vue::currentStageOrder`
- `src/views/leads/LeadDetail.vue::fetchRelated`
- `src/views/leads/LeadDetail.vue::formatValue`
- `src/views/leads/LeadDetail.vue::leadData`
- `src/views/leads/LeadDetail.vue::loading`
- `src/views/leads/LeadDetail.vue::mounted`
- `src/views/leads/LeadDetail.vue::objectStore`
- `src/views/leads/LeadDetail.vue::onFormCancel`
- `src/views/leads/LeadDetail.vue::onFormSave`
- `src/views/leads/LeadDetail.vue::onProductValueChanged`
- `src/views/leads/LeadDetail.vue::priorityClass`
- `src/views/leads/LeadDetail.vue::sidebarProps`
- `src/views/leads/LeadDetail.vue::sortedStages`
- `src/views/leads/LeadDetail.vue::stageClass`
- `src/views/leads/LeadDetail.vue::syncLeadValue`
- `src/views/leads/LeadForm.vue::autoAssignDefaultPipeline`
- `src/views/leads/LeadForm.vue::clientOptions`
- `src/views/leads/LeadForm.vue::clients`
- `src/views/leads/LeadForm.vue::created`
- `src/views/leads/LeadForm.vue::errors`
- `src/views/leads/LeadForm.vue::leadPipelines`
- `src/views/leads/LeadForm.vue::leadSourcesStore`
- `src/views/leads/LeadForm.vue::objectStore`
- `src/views/leads/LeadForm.vue::onPipelineChange`
- `src/views/leads/LeadForm.vue::onSave`
- `src/views/leads/LeadForm.vue::pipelineOptions`
- `src/views/leads/LeadForm.vue::pipelines`
- `src/views/leads/LeadForm.vue::selectedPipeline`
- `src/views/leads/LeadForm.vue::sourceOptions`
- `src/views/leads/LeadForm.vue::stageOptions`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
