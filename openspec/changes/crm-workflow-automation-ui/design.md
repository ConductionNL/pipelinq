# CRM Workflow Automation UI - Design

## Approach
1. Build automation builder UI with trigger selector, condition builder, action configurator
2. Extend ObjectEventHandlerService to fire n8n webhooks
3. Create AutomationService for managing n8n workflow lifecycle
4. Build automation list view with enable/disable toggles

## Files Affected
- `src/views/settings/AutomationBuilder.vue` - Visual automation builder
- `src/views/settings/AutomationList.vue` - Automation management
- `src/components/automation/TriggerSelector.vue` - CRM trigger picker
- `src/components/automation/ConditionBuilder.vue` - Condition configuration
- `src/components/automation/ActionConfigurator.vue` - Action setup
- `lib/Service/ObjectEventHandlerService.php` - Extend to fire n8n webhooks
- `lib/Service/AutomationService.php` - New n8n workflow management service
