# Reverse-spec — Settings UI

Retroactively specifies the observed behavior of 95 method(s) implementing settings and admin configuration screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/components/admin/AgentProfileSettings.vue::addProfile`
- `src/components/admin/AgentProfileSettings.vue::allSkills`
- `src/components/admin/AgentProfileSettings.vue::cancelEdit`
- `src/components/admin/AgentProfileSettings.vue::deleteProfile`
- `src/components/admin/AgentProfileSettings.vue::getSkillNames`
- `src/components/admin/AgentProfileSettings.vue::loading`
- `src/components/admin/AgentProfileSettings.vue::mounted`
- `src/components/admin/AgentProfileSettings.vue::profiles`
- `src/components/admin/AgentProfileSettings.vue::profilesStore`
- `src/components/admin/AgentProfileSettings.vue::saveEdit`
- `src/components/admin/AgentProfileSettings.vue::skillsStore`
- `src/components/admin/AgentProfileSettings.vue::startEdit`
- `src/components/admin/AgentProfileSettings.vue::toggleSkill`
- `src/components/admin/QueueSettings.vue::addQueue`
- `src/components/admin/QueueSettings.vue::cancelEdit`
- `src/components/admin/QueueSettings.vue::deleteQueue`
- `src/components/admin/QueueSettings.vue::loading`
- `src/components/admin/QueueSettings.vue::queues`
- `src/components/admin/QueueSettings.vue::queuesStore`
- `src/components/admin/QueueSettings.vue::saveEdit`
- `src/components/admin/QueueSettings.vue::startEdit`
- `src/components/admin/SkillSettings.vue::addSkill`
- `src/components/admin/SkillSettings.vue::cancelEdit`
- `src/components/admin/SkillSettings.vue::deleteSkill`
- `src/components/admin/SkillSettings.vue::loading`
- `src/components/admin/SkillSettings.vue::saveEdit`
- `src/components/admin/SkillSettings.vue::skills`
- `src/components/admin/SkillSettings.vue::skillsStore`
- `src/components/admin/SkillSettings.vue::startEdit`
- `src/views/settings/PipelineForm.vue::addMapping`
- `src/views/settings/PipelineForm.vue::addStage`
- `src/views/settings/PipelineForm.vue::created`
- `src/views/settings/PipelineForm.vue::errors`
- `src/views/settings/PipelineForm.vue::isValid`
- `src/views/settings/PipelineForm.vue::loadViews`
- `src/views/settings/PipelineForm.vue::moveStage`
- `src/views/settings/PipelineForm.vue::onSave`
- `src/views/settings/PipelineForm.vue::recomputeOrders`
- `src/views/settings/PipelineForm.vue::removeMapping`
- `src/views/settings/PipelineForm.vue::removeStage`
- `src/views/settings/PipelineForm.vue::sortedStages`
- `src/views/settings/PipelineForm.vue::stageErrors`
- `src/views/settings/PipelineForm.vue::viewOptions`
- `src/views/settings/PipelineManager.vue::countAffectedItems`
- `src/views/settings/PipelineManager.vue::loading`
- `src/views/settings/PipelineManager.vue::objectStore`
- `src/views/settings/PipelineManager.vue::onDeleteClick`
- `src/views/settings/PipelineManager.vue::onDeleteConfirm`
- `src/views/settings/PipelineManager.vue::onEdit`
- `src/views/settings/PipelineManager.vue::onSave`
- `src/views/settings/PipelineManager.vue::pipelines`
- `src/views/settings/PipelineManager.vue::schemaLabel`
- `src/views/settings/PipelineManager.vue::stageCount`
- `src/views/settings/PipelineManager.vue::stagePreview`
- `src/views/settings/ProductCategoryManager.vue::cancelAdding`
- `src/views/settings/ProductCategoryManager.vue::cancelEdit`
- `src/views/settings/ProductCategoryManager.vue::confirmRemove`
- `src/views/settings/ProductCategoryManager.vue::fetchCategories`
- `src/views/settings/ProductCategoryManager.vue::objectStore`
- `src/views/settings/ProductCategoryManager.vue::saveEdit`
- `src/views/settings/ProductCategoryManager.vue::saveNew`
- `src/views/settings/ProductCategoryManager.vue::sortedCategories`
- `src/views/settings/ProductCategoryManager.vue::startAdding`
- `src/views/settings/ProductCategoryManager.vue::startEditing`
- `src/views/settings/ProspectSettings.vue::fetchSettings`
- `src/views/settings/ProspectSettings.vue::save`
- `src/views/settings/Settings.vue::addLeadSource`
- `src/views/settings/Settings.vue::addRequestChannel`
- `src/views/settings/Settings.vue::checkLeadSourceUsage`
- `src/views/settings/Settings.vue::checkRequestChannelUsage`
- `src/views/settings/Settings.vue::countObjectsWithField`
- `src/views/settings/Settings.vue::leadSourceTags`
- `src/views/settings/Settings.vue::leadSourcesLoading`
- `src/views/settings/Settings.vue::leadSourcesStore`
- `src/views/settings/Settings.vue::mounted`
- `src/views/settings/Settings.vue::objectStore`
- `src/views/settings/Settings.vue::registerGroups`
- `src/views/settings/Settings.vue::reimport`
- `src/views/settings/Settings.vue::removeLeadSource`
- `src/views/settings/Settings.vue::removeRequestChannel`
- `src/views/settings/Settings.vue::renameLeadSource`
- `src/views/settings/Settings.vue::renameRequestChannel`
- `src/views/settings/Settings.vue::requestChannelTags`
- `src/views/settings/Settings.vue::requestChannelsLoading`
- `src/views/settings/Settings.vue::requestChannelsStore`
- `src/views/settings/Settings.vue::save`
- `src/views/settings/Settings.vue::settingsStore`
- `src/views/settings/TagManager.vue::cancelAdding`
- `src/views/settings/TagManager.vue::cancelEdit`
- `src/views/settings/TagManager.vue::confirmRemove`
- `src/views/settings/TagManager.vue::default`
- `src/views/settings/TagManager.vue::saveNew`
- `src/views/settings/TagManager.vue::saveRename`
- `src/views/settings/TagManager.vue::startAdding`
- `src/views/settings/TagManager.vue::startEditing`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
