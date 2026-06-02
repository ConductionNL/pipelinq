# Reverse-spec — Service request UI

Retroactively specifies the observed behavior of 52 method(s) implementing service request list and detail screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/requests/RequestCreateDialog.vue::objectStore`
- `src/views/requests/RequestCreateDialog.vue::onSave`
- `src/views/requests/RequestDetail.vue::assigneeOption`
- `src/views/requests/RequestDetail.vue::canConvert`
- `src/views/requests/RequestDetail.vue::canDelete`
- `src/views/requests/RequestDetail.vue::confirmDelete`
- `src/views/requests/RequestDetail.vue::convertToCase`
- `src/views/requests/RequestDetail.vue::currentStageOrder`
- `src/views/requests/RequestDetail.vue::fetchRelated`
- `src/views/requests/RequestDetail.vue::fetchUsers`
- `src/views/requests/RequestDetail.vue::formatDate`
- `src/views/requests/RequestDetail.vue::formatDatetime`
- `src/views/requests/RequestDetail.vue::loading`
- `src/views/requests/RequestDetail.vue::mounted`
- `src/views/requests/RequestDetail.vue::moveToNextStage`
- `src/views/requests/RequestDetail.vue::nextStage`
- `src/views/requests/RequestDetail.vue::objectStore`
- `src/views/requests/RequestDetail.vue::onAssigneeChange`
- `src/views/requests/RequestDetail.vue::onContactmomentSaved`
- `src/views/requests/RequestDetail.vue::onFormCancel`
- `src/views/requests/RequestDetail.vue::onFormSave`
- `src/views/requests/RequestDetail.vue::onQueueChange`
- `src/views/requests/RequestDetail.vue::onRoutingAssign`
- `src/views/requests/RequestDetail.vue::onStatusChange`
- `src/views/requests/RequestDetail.vue::preLinkedClient`
- `src/views/requests/RequestDetail.vue::queueOption`
- `src/views/requests/RequestDetail.vue::queueOptions`
- `src/views/requests/RequestDetail.vue::requestData`
- `src/views/requests/RequestDetail.vue::showRoutingSuggestions`
- `src/views/requests/RequestDetail.vue::sidebarProps`
- `src/views/requests/RequestDetail.vue::sortedStages`
- `src/views/requests/RequestDetail.vue::stageClass`
- `src/views/requests/RequestDetail.vue::statusTransitionOptions`
- `src/views/requests/RequestDetail.vue::statusTransitions`
- `src/views/requests/RequestDetail.vue::userOptions`
- `src/views/requests/RequestDetail.vue::viewCase`
- `src/views/requests/RequestForm.vue::autoAssignDefaultPipeline`
- `src/views/requests/RequestForm.vue::availableStatuses`
- `src/views/requests/RequestForm.vue::channelOptions`
- `src/views/requests/RequestForm.vue::clientOptions`
- `src/views/requests/RequestForm.vue::clients`
- `src/views/requests/RequestForm.vue::created`
- `src/views/requests/RequestForm.vue::errors`
- `src/views/requests/RequestForm.vue::objectStore`
- `src/views/requests/RequestForm.vue::onPipelineChange`
- `src/views/requests/RequestForm.vue::onSave`
- `src/views/requests/RequestForm.vue::pipelineOptions`
- `src/views/requests/RequestForm.vue::pipelines`
- `src/views/requests/RequestForm.vue::requestChannelsStore`
- `src/views/requests/RequestForm.vue::requestPipelines`
- `src/views/requests/RequestForm.vue::selectedPipeline`
- `src/views/requests/RequestForm.vue::stageOptions`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
