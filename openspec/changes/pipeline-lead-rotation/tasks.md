# Tasks: pipeline-lead-rotation

- [x] 1.1 Schema: `agentProfile.leadCapPer30Days` (integer, minimum 1, optional), `agentProfile.pauseLeadAssignment` (boolean), `lead.assignmentReason` (string, hidden in forms) and `lead.assignedAt` (date-time, hidden in forms), both `x-openregister.serverSet`, not `readOnly` (OpenRegister refuses a changed `readOnly` value on update)
  - Verify: `npm run check:schema-l10n` exit 0; register import without `PARTIAL IMPORT` (not run here: CI's Newman seed imports it, live pass decision 139)
- [x] 1.2 `lib/Service/LeadRotationService.php::assign()` per D1, reusing `RoutingService::findMatchingAgents()` and the availability filter
  - Verify: PHPUnit `tests/Unit/Service/LeadRotationServiceTest.php`: fewest wins, cap excludes, pause excludes, tie goes to the oldest last assignment, nobody eligible writes only the reason
- [x] 1.3 Call it from `DealCreatedListener` behind `lead_rotation_enabled`, only for a lead without assignee
  - Verify: PHPUnit on the listener with the switch off, on, and with an assignee already set
- [ ] 1.4 (UI waits for board PqBeheer, decision 162; the switch is app config `lead_rotation_enabled`, writable today through `PUT /api/settings`) Admin switch in pipelinq admin settings; cap and pause on the agent profile form
  - Verify: Playwright `tests/e2e/lead-rotation.spec.ts`: switch on, two agents with skill Solar, three Solar leads created by the website form end up 2 and 1
- [ ] 1.5 (UI waits for board PqLead, decision 162) LeadDetail shows `assignmentReason`
  - Verify: Vitest on the lead header with a reason
- [x] 1.6 User doc `docs/Features/lead-rotation.md` and Dutch strings
  - Verify: `npm run test:l10n` exit 0; docs build exit 0

## Evidence

- 1.1 `lib/Settings/register.d/98-lead-rotation.json`; schema strings in all six locales.
- 1.2 `lib/Service/LeadRotationService.php`; `tests/Unit/Service/LeadRotationServiceTest.php` (8): in turn two and one, cap excludes, only 30 days count, pause excludes, unavailable excluded, assignee left alone, unreadable leads count as none, the switch.
- 1.3 `lib/Listener/DealCreatedListener.php::runDeferredWork()` (one write with the forecast default, as the signed-in user or the system account when nobody is); `tests/Unit/Listener/DealCreatedListenerTest.php` (5): off, on, signed-in colleague, redelivery, missing lead.
- 1.6 `docs/Features/lead-rotation.md`; the reason sentences and schema strings in en, nl, de, es, fr, it.
