# Reverse-spec — Pipeline board UI

Retroactively specifies the observed behavior of 56 method(s) implementing pipeline board interaction and rendering. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/pipeline/PipelineBoard.vue::allItems`
- `src/views/pipeline/PipelineBoard.vue::beforeDestroy`
- `src/views/pipeline/PipelineBoard.vue::closedStages`
- `src/views/pipeline/PipelineBoard.vue::ensureObjectTypes`
- `src/views/pipeline/PipelineBoard.vue::fetchItemsLegacy`
- `src/views/pipeline/PipelineBoard.vue::fetchItemsViaMappings`
- `src/views/pipeline/PipelineBoard.vue::fetchPipelineItems`
- `src/views/pipeline/PipelineBoard.vue::fetchSchemaItems`
- `src/views/pipeline/PipelineBoard.vue::formatDate`
- `src/views/pipeline/PipelineBoard.vue::getColumnProperty`
- `src/views/pipeline/PipelineBoard.vue::getItemTotalsValue`
- `src/views/pipeline/PipelineBoard.vue::getStageItems`
- `src/views/pipeline/PipelineBoard.vue::getStageTotalValue`
- `src/views/pipeline/PipelineBoard.vue::isItemOverdue`
- `src/views/pipeline/PipelineBoard.vue::mounted`
- `src/views/pipeline/PipelineBoard.vue::objectStore`
- `src/views/pipeline/PipelineBoard.vue::onDrop`
- `src/views/pipeline/PipelineBoard.vue::onPipelineChange`
- `src/views/pipeline/PipelineBoard.vue::onSidebarSave`
- `src/views/pipeline/PipelineBoard.vue::openItem`
- `src/views/pipeline/PipelineBoard.vue::openStages`
- `src/views/pipeline/PipelineBoard.vue::pipelineSelectOptions`
- `src/views/pipeline/PipelineBoard.vue::pipelines`
- `src/views/pipeline/PipelineBoard.vue::propertyMappings`
- `src/views/pipeline/PipelineBoard.vue::selectedPipeline`
- `src/views/pipeline/PipelineBoard.vue::settingsStore`
- `src/views/pipeline/PipelineBoard.vue::showFilterOptions`
- `src/views/pipeline/PipelineBoard.vue::sortedListItems`
- `src/views/pipeline/PipelineBoard.vue::sortedStages`
- `src/views/pipeline/PipelineBoard.vue::syncSidebarState`
- `src/views/pipeline/PipelineBoard.vue::toggleClosedStage`
- `src/views/pipeline/PipelineBoard.vue::toggleSidebar`
- `src/views/pipeline/PipelineBoard.vue::toggleSort`
- `src/views/pipeline/PipelineCard.vue::agingClass`
- `src/views/pipeline/PipelineCard.vue::agingLabel`
- `src/views/pipeline/PipelineCard.vue::currentColumnValue`
- `src/views/pipeline/PipelineCard.vue::daysAge`
- `src/views/pipeline/PipelineCard.vue::formatDate`
- `src/views/pipeline/PipelineCard.vue::handler`
- `src/views/pipeline/PipelineCard.vue::isOverdue`
- `src/views/pipeline/PipelineCard.vue::loadUsers`
- `src/views/pipeline/PipelineCard.vue::objectStore`
- `src/views/pipeline/PipelineCard.vue::onAssignChange`
- `src/views/pipeline/PipelineCard.vue::onDragStart`
- `src/views/pipeline/PipelineCard.vue::onStageChange`
- `src/views/pipeline/PipelineCard.vue::stageOptions`
- `src/views/pipeline/PipelineCard.vue::userOptions`
- `src/views/pipeline/PipelineSidebar.vue::internalOpen`
- `src/views/pipeline/PipelineSidebar.vue::onCreate`
- `src/views/pipeline/PipelineSidebar.vue::onEdit`
- `src/views/pipeline/PipelineSidebar.vue::onSave`
- `src/views/pipeline/PipelineSidebar.vue::open`
- `src/views/pipeline/PipelineSidebar.vue::schemaLabels`
- `src/views/pipeline/PipelineSidebar.vue::sortedStages`
- `src/views/pipeline/PipelineSidebar.vue::stageCount`
- `src/views/pipeline/PipelineSidebar.vue::stageFlow`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
