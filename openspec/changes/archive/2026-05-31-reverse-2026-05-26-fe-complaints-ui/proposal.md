# Reverse-spec — Complaint UI

Retroactively specifies the observed behavior of 36 method(s) implementing complaint registration screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/complaints/ComplaintCreateDialog.vue::objectStore`
- `src/views/complaints/ComplaintCreateDialog.vue::onSave`
- `src/views/complaints/ComplaintDetail.vue::applyStatusChange`
- `src/views/complaints/ComplaintDetail.vue::assigneeOption`
- `src/views/complaints/ComplaintDetail.vue::buildStatusHistory`
- `src/views/complaints/ComplaintDetail.vue::complaintData`
- `src/views/complaints/ComplaintDetail.vue::confirmDelete`
- `src/views/complaints/ComplaintDetail.vue::confirmResolution`
- `src/views/complaints/ComplaintDetail.vue::fetchRelated`
- `src/views/complaints/ComplaintDetail.vue::fetchUsers`
- `src/views/complaints/ComplaintDetail.vue::formatDateTime`
- `src/views/complaints/ComplaintDetail.vue::getTransitionButtonType`
- `src/views/complaints/ComplaintDetail.vue::getTransitionLabel`
- `src/views/complaints/ComplaintDetail.vue::loading`
- `src/views/complaints/ComplaintDetail.vue::mounted`
- `src/views/complaints/ComplaintDetail.vue::objectStore`
- `src/views/complaints/ComplaintDetail.vue::onAssigneeChange`
- `src/views/complaints/ComplaintDetail.vue::onFormCancel`
- `src/views/complaints/ComplaintDetail.vue::onFormSave`
- `src/views/complaints/ComplaintDetail.vue::onStatusTransition`
- `src/views/complaints/ComplaintDetail.vue::preLinkedClient`
- `src/views/complaints/ComplaintDetail.vue::resolutionDialogTitle`
- `src/views/complaints/ComplaintDetail.vue::sidebarProps`
- `src/views/complaints/ComplaintDetail.vue::slaIndicator`
- `src/views/complaints/ComplaintDetail.vue::statusTransitions`
- `src/views/complaints/ComplaintDetail.vue::userOptions`
- `src/views/complaints/ComplaintForm.vue::availableStatuses`
- `src/views/complaints/ComplaintForm.vue::clientOptions`
- `src/views/complaints/ComplaintForm.vue::clients`
- `src/views/complaints/ComplaintForm.vue::contactOptions`
- `src/views/complaints/ComplaintForm.vue::created`
- `src/views/complaints/ComplaintForm.vue::errors`
- `src/views/complaints/ComplaintForm.vue::isValid`
- `src/views/complaints/ComplaintForm.vue::objectStore`
- `src/views/complaints/ComplaintForm.vue::onClientChange`
- `src/views/complaints/ComplaintForm.vue::onSave`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
