# Reverse-spec — Dashboard widget UI

Retroactively specifies the observed behavior of 56 method(s) implementing dashboard widgets. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/components/ProductRevenue.vue::fetchData`
- `src/components/ProductRevenue.vue::formatCurrency`
- `src/components/ProductRevenue.vue::objectStore`
- `src/components/ProspectWidget.vue::formatTime`
- `src/components/ProspectWidget.vue::onCreateLead`
- `src/components/ProspectWidget.vue::prospectStore`
- `src/components/ProspectWidget.vue::refresh`
- `src/components/widgets/ClientAutocomplete.vue::clearSelection`
- `src/components/widgets/ClientAutocomplete.vue::default`
- `src/components/widgets/ClientAutocomplete.vue::mounted`
- `src/components/widgets/ClientAutocomplete.vue::onInput`
- `src/components/widgets/ClientAutocomplete.vue::searchClients`
- `src/components/widgets/ClientAutocomplete.vue::selectClient`
- `src/components/widgets/ClientAutocomplete.vue::value`
- `src/views/widgets/ComplaintsOverviewWidget.vue::inProgressCount`
- `src/views/widgets/ComplaintsOverviewWidget.vue::newCount`
- `src/views/widgets/ComplaintsOverviewWidget.vue::openComplaints`
- `src/views/widgets/ComplaintsOverviewWidget.vue::overdueCount`
- `src/views/widgets/ComplaintsOverviewWidget.vue::totalOpen`
- `src/views/widgets/CreateLeadWidget.vue::fetchPipelines`
- `src/views/widgets/CreateLeadWidget.vue::getFirstStage`
- `src/views/widgets/CreateLeadWidget.vue::mounted`
- `src/views/widgets/CreateLeadWidget.vue::onClientSelected`
- `src/views/widgets/CreateLeadWidget.vue::onQuickAdd`
- `src/views/widgets/CreateLeadWidget.vue::onSubmit`
- `src/views/widgets/CreateLeadWidget.vue::pipelineOptions`
- `src/views/widgets/CreateLeadWidget.vue::resetForm`
- `src/views/widgets/DealsOverviewWidget.vue::clientMap`
- `src/views/widgets/DealsOverviewWidget.vue::fetchData`
- `src/views/widgets/DealsOverviewWidget.vue::fetchRaw`
- `src/views/widgets/DealsOverviewWidget.vue::items`
- `src/views/widgets/DealsOverviewWidget.vue::onShow`
- `src/views/widgets/FindClientWidget.vue::cancelAction`
- `src/views/widgets/FindClientWidget.vue::copyEmail`
- `src/views/widgets/FindClientWidget.vue::createClient`
- `src/views/widgets/FindClientWidget.vue::createLeadForClient`
- `src/views/widgets/FindClientWidget.vue::createRequestForClient`
- `src/views/widgets/FindClientWidget.vue::fetchData`
- `src/views/widgets/FindClientWidget.vue::fetchRaw`
- `src/views/widgets/FindClientWidget.vue::filteredClients`
- `src/views/widgets/FindClientWidget.vue::submitAction`
- `src/views/widgets/FindClientWidget.vue::viewClient`
- `src/views/widgets/MyLeadsWidget.vue::fetchData`
- `src/views/widgets/MyLeadsWidget.vue::fetchRaw`
- `src/views/widgets/MyLeadsWidget.vue::items`
- `src/views/widgets/MyLeadsWidget.vue::onShow`
- `src/views/widgets/RecentActivitiesWidget.vue::fetchData`
- `src/views/widgets/RecentActivitiesWidget.vue::fetchRaw`
- `src/views/widgets/RecentActivitiesWidget.vue::formatTimeAgo`
- `src/views/widgets/RecentActivitiesWidget.vue::items`
- `src/views/widgets/RecentActivitiesWidget.vue::onShow`
- `src/views/widgets/StartRequestWidget.vue::fetchRecentRequests`
- `src/views/widgets/StartRequestWidget.vue::mounted`
- `src/views/widgets/StartRequestWidget.vue::onClientSelected`
- `src/views/widgets/StartRequestWidget.vue::onSubmit`
- `src/views/widgets/StartRequestWidget.vue::resetForm`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
