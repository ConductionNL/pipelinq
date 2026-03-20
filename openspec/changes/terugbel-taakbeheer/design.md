# Terugbel- en Taakbeheer - Design

## Approach
1. Add `taak` schema to pipelinq_register.json mapping to VNG InterneTaak
2. Build task creation form integrated into KCC werkplek
3. Integrate tasks into existing my-work view
4. Add notification on task assignment
5. Build task lifecycle management (open -> in_behandeling -> afgerond/verlopen)

## Files Affected
- `lib/Settings/pipelinq_register.json` - Add taak schema
- `src/components/kcc/TerugbelForm.vue` - Callback request creation form
- `src/components/kcc/TaakForm.vue` - General task creation form
- `src/views/MyWork.vue` - Extend to show taak entities alongside leads/requests
- `lib/Service/NotificationService.php` - Add task assignment notifications
- `src/views/tasks/TaakDetail.vue` - Task detail/completion view
