# Reverse-spec — Forms and surveys UI

Retroactively specifies the observed behavior of 20 method(s) implementing intake form and survey builder/runner screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/components/surveys/QuestionEditor.vue::add`
- `src/components/surveys/QuestionEditor.vue::addOpt`
- `src/components/surveys/QuestionEditor.vue::emit`
- `src/components/surveys/QuestionEditor.vue::remove`
- `src/components/surveys/QuestionEditor.vue::rmOpt`
- `src/views/forms/FormBuilder.vue::addField`
- `src/views/forms/FormBuilder.vue::buildMappings`
- `src/views/forms/FormBuilder.vue::fieldTypeOptions`
- `src/views/forms/FormBuilder.vue::loadForm`
- `src/views/forms/FormBuilder.vue::mappingOptions`
- `src/views/forms/FormBuilder.vue::mounted`
- `src/views/forms/FormBuilder.vue::parseMappings`
- `src/views/forms/FormBuilder.vue::removeField`
- `src/views/forms/FormBuilder.vue::save`
- `src/views/surveys/SurveyAnalytics.vue::exportCsv`
- `src/views/surveys/SurveyDetail.vue::copyLink`
- `src/views/surveys/SurveyDetail.vue::del`
- `src/views/surveys/SurveyDetail.vue::publicUrl`
- `src/views/surveys/SurveyDetail.vue::store`
- `src/views/surveys/SurveyDetail.vue::survey`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
