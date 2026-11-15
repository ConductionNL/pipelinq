# Tasks: ticket-decisions-tab

## 1. Tab
- [x] 1.1 `src/manifest.json` TicketDetail: a Decisions tab mounting the `decidesk-decisions` integration with the ticket as host, heading and description text, `requiredApp: decidiq`; remove the `ticket-decisions` widget and its layout cell from the overview
  - spec_ref: `openspec/changes/ticket-decisions-tab/specs/ticket-decisions/spec.md#requirement-the-ticket-page-has-a-decisions-tab-req-tdt-001`
  - Built: `ticket-panels` (type tabs) in `src/manifest.json`, Decisions tab with `visibleWhen: { appInstalled: decidiq }`; the simple structure patches the same widget to its four tabs (`src/menu-layout.simple.json`). Test: `tests/vitest/ticketDecisionsTab.spec.js`, `tests/vitest/simpleTicketPage.spec.js`.
- [x] 1.2 Empty state text and the buttons Convert to a case (existing convert dialog of req-to-case) and Open in decidiq beside the leaf's Create
  - Built: `src/components/tickets/TicketDecisionRoutes.vue` as widget `TicketDecisionRoutesWidget` (the line, the two routes, Convert to case). Create and Open in decidiq are the leaf's own buttons. The two routes show above the list at all times; "No decision linked" is the leaf's own empty line, in decidiq's words
  - Verify: manifest validation (`npm run check:manifest`), gate-53/55; live, the tab on a ticket without decisions shows the board's text

## 2. Case decisions
- [x] 2.1 When `caseReference` is set, mount the leaf a second time with the case as host, or, if the integration widget only takes the page object, `src/components/tickets/CaseDecisionsList.vue` reading decisions with `subjectId` = case id through the OpenRegister object API
  - spec_ref: `#requirement-decisions-on-the-linked-case-show-on-the-ticket-req-tdt-002`
  - Built: widget `ticket-case-decisions` in `src/manifest.json`, the leaf a second time with `props: { register: @config.caseRegister, schema: @config.caseSchema, objectId: @object.caseReference }` (design D2, first option). Register and schema are looked up, not written: `SemanticHandoffService::implementerLocation()` asks OpenRegister which installed app implements the case kind, `DashboardController` hands the slugs to the page as `case_target`, and `pageAppConfig()` adds them to the page's app config. The host resolves the tokens and mounts nothing on a ticket without a case or on an instance without a case app: nextcloud-vue#1520, tested there (`CnDetailWidgetHost.widgetPropTokens.spec.js`). Needs a nextcloud-vue release that carries it.
  - Verify: vitest with a ticket with and without `caseReference`; RBAC respected because the read goes through OpenRegister as the user

## 3. Text and tests
- [x] 3.1 l10n nl and en for the heading, description and empty state (and de, fr, es, it)
- [x] 3.2 (live pass, decision 139) One Playwright test: open a ticket, Decisions tab, Create a proposal, the proposal is listed (skips with a reason when decidiq is not installed)
  - Built: `tests/e2e/ticket-decisions-tab.spec.ts`. Run against :8080 it found a decidiq defect: the leaf wrote `outcome: 'pending'`, outside the decision schema's enum, so every proposal got a 400 and the dialog kept spinning. Fixed in decidiq (ConductionNL/decidiq#1730); this test passes once that lands.
