# Tasks: pipeline-lead-rotation

- [ ] 1.1 Schema: `agentProfile.leadCapPer30Days` (integer, minimum 1, optional), `agentProfile.pauseLeadAssignment` (boolean), `lead.assignmentReason` (string, read only in forms)
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 `lib/Service/LeadRotationService.php::assign()` per D1, reusing `RoutingService::findMatchingAgents()` and the availability filter
  - Verify: PHPUnit `tests/Unit/Service/LeadRotationServiceTest.php`: fewest wins, cap excludes, pause excludes, tie goes to the oldest last assignment, nobody eligible writes only the reason
- [ ] 1.3 Call it from `DealCreatedListener` behind `lead_rotation_enabled`, only for a lead without assignee
  - Verify: PHPUnit on the listener with the switch off, on, and with an assignee already set
- [ ] 1.4 Admin switch in pipelinq admin settings; cap and pause on the agent profile form
  - Verify: Playwright `tests/e2e/lead-rotation.spec.ts`: switch on, two agents with skill Solar, three Solar leads created by the website form end up 2 and 1
- [ ] 1.5 LeadDetail shows `assignmentReason`
  - Verify: Vitest on the lead header with a reason
- [ ] 1.6 User doc `docs/Features/lead-rotation.md` and Dutch strings
  - Verify: `npm run test:l10n` exit 0; docs build exit 0
