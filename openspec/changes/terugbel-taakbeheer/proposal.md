# Terugbel- en Taakbeheer

## Problem
No task/terugbelverzoek entity, callback workflow, or backoffice routing exists. KCC agents cannot create callback requests or route follow-up tasks to colleagues. 31% of klantinteractie-tenders explicitly require this.

## Proposed Solution
Build callback request and task management enabling KCC agents to create terugbelverzoeken and follow-up tasks assigned to backoffice colleagues. Tasks appear in the assignee's my-work inbox. Supports priority, deadline, preferred callback time, and SLA tracking.

## Impact
- Depends on: kcc-werkplek (contactmoment schema)
- New `taak` schema in pipelinq_register.json
- Integration with existing my-work view
- Notification integration for new task assignment
- VNG InterneTaak mapping
