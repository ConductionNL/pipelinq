# Reverse-spec — Frontend service layer

Retroactively specifies the observed behavior of 60 method(s) implementing frontend API service helpers. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/services/complaintStatus.js::getAllowedTransitions`
- `src/services/complaintStatus.js::getCategoryLabel`
- `src/services/complaintStatus.js::getChannelLabel`
- `src/services/complaintStatus.js::getPriorityColor`
- `src/services/complaintStatus.js::getPriorityLabel`
- `src/services/complaintStatus.js::getSlaColor`
- `src/services/complaintStatus.js::getSlaIndicator`
- `src/services/complaintStatus.js::getStatusColor`
- `src/services/complaintStatus.js::getStatusLabel`
- `src/services/complaintStatus.js::isTerminalStatus`
- `src/services/complaintStatus.js::isValidPriority`
- `src/services/complaintStatus.js::isValidTransition`
- `src/services/complaintStatus.js::requiresResolution`
- `src/services/dashboardData.js::getClients`
- `src/services/dashboardData.js::getClosedStageNames`
- `src/services/dashboardData.js::getComplaints`
- `src/services/dashboardData.js::getLeads`
- `src/services/dashboardData.js::getMyLeads`
- `src/services/dashboardData.js::getMyRequests`
- `src/services/dashboardData.js::getPipelines`
- `src/services/dashboardData.js::getRequests`
- `src/services/dashboardData.js::invalidateDashboardData`
- `src/services/localeUtils.js::formatCurrency`
- `src/services/localeUtils.js::formatDate`
- `src/services/localeUtils.js::formatDateFull`
- `src/services/localeUtils.js::formatNumber`
- `src/services/localeUtils.js::getUserLocale`
- `src/services/pipelineUtils.js::formatAge`
- `src/services/pipelineUtils.js::getAgingClass`
- `src/services/pipelineUtils.js::getDaysAge`
- `src/services/pipelineUtils.js::isStale`
- `src/services/queueUtils.js::filterByCapacity`
- `src/services/queueUtils.js::findMatchingAgents`
- `src/services/queueUtils.js::getOldestWaitingTime`
- `src/services/queueUtils.js::getWaitingTime`
- `src/services/queueUtils.js::isAtCapacity`
- `src/services/queueUtils.js::prioritySortComparator`
- `src/services/queueUtils.js::sortByWorkload`
- `src/services/requestStatus.js::getAllowedTransitions`
- `src/services/requestStatus.js::getPriorityColor`
- `src/services/requestStatus.js::getPriorityLabel`
- `src/services/requestStatus.js::getStatusColor`
- `src/services/requestStatus.js::getStatusLabel`
- `src/services/requestStatus.js::isTerminalStatus`
- `src/services/requestStatus.js::isValidPriority`
- `src/services/requestStatus.js::isValidTransition`
- `src/services/taskUtils.js::fetchUserGroups`
- `src/services/taskUtils.js::getDefaultDeadline`
- `src/services/taskUtils.js::getTaskPriorityColor`
- `src/services/taskUtils.js::getTaskPriorityLabel`
- `src/services/taskUtils.js::getTaskPriorityLabels`
- `src/services/taskUtils.js::getTaskStatusLabel`
- `src/services/taskUtils.js::getTaskStatusLabels`
- `src/services/taskUtils.js::getTaskTypeLabel`
- `src/services/taskUtils.js::getTaskTypeLabels`
- `src/services/taskUtils.js::isTaskOverdue`
- `src/services/taskUtils.js::searchAssignees`
- `src/services/viewService.js::getSchemaProperties`
- `src/services/viewService.js::getView`
- `src/services/viewService.js::getViews`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
