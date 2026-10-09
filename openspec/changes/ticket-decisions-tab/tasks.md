# Tasks: ticket-decisions-tab

## 1. Tab
- [ ] 1.1 `src/manifest.json` TicketDetail: a Decisions tab mounting the `decidesk-decisions` integration with the ticket as host, heading and description text, `requiredApp: decidiq`; remove the `ticket-decisions` widget and its layout cell from the overview
  - spec_ref: `openspec/changes/ticket-decisions-tab/specs/ticket-decisions/spec.md#requirement-the-ticket-page-has-a-decisions-tab-req-tdt-001`
- [ ] 1.2 Empty state text and the buttons Convert to a case (existing convert dialog of req-to-case) and Open in decidiq beside the leaf's Create
  - Verify: manifest validation (`npm run check:manifest`), gate-53/55; live, the tab on a ticket without decisions shows the board's text

## 2. Case decisions
- [ ] 2.1 When `caseReference` is set, mount the leaf a second time with the case as host, or, if the integration widget only takes the page object, `src/components/tickets/CaseDecisionsList.vue` reading decisions with `subjectId` = case id through the OpenRegister object API
  - spec_ref: `#requirement-decisions-on-the-linked-case-show-on-the-ticket-req-tdt-002`
  - Verify: vitest with a ticket with and without `caseReference`; RBAC respected because the read goes through OpenRegister as the user

## 3. Text and tests
- [ ] 3.1 l10n nl and en for the heading, description and empty state
- [ ] 3.2 One Playwright test: open a ticket, Decisions tab, Create a proposal, the proposal is listed (skips with a reason when decidiq is not installed)
