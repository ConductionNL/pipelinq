# CRM Workflow Automation UI - Tasks

- [ ] Build TriggerSelector.vue with CRM-specific triggers (lead stage changed, request created, etc.)
- [ ] Build ConditionBuilder.vue for trigger conditions (stage filter, value filter, assignee filter)
- [ ] Build ActionConfigurator.vue for automation actions (send notification, update field, create task)
- [ ] Build AutomationBuilder.vue composing trigger + conditions + actions
- [ ] Build AutomationList.vue with enable/disable toggles and execution history
- [ ] Create AutomationService.php for n8n workflow CRUD via MCP
- [ ] Extend ObjectEventHandlerService to fire n8n webhooks after event detection
- [ ] Add scheduled trigger support (daily, weekly, custom cron)
- [ ] Build automation template library for common CRM patterns
- [ ] Add execution log display per automation
