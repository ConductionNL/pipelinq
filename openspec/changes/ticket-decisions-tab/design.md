# Design: ticket-decisions-tab

Read at pipelinq development `c9c6a728d` and decidiq development `d49a5ef2` (8 Oct 2026).

## Screen

Board **PqTicketBesluitvorming** on canvas part 1 (https://claude.ai/artifact/5NkFW28vZUUij43xzxHg5a; local copy `zuiddrecht/v2/project/PqTicketBesluitvorming.dc.html`): "Decisions on a ticket; create a proposal."

| Where | What it shows |
|---|---|
| Header | "Event permit for the Parkstraat street party", Log contact, Edit, More; pills Request, In progress; breadcrumb Questions and reports / PQ-2026-0417; "by phone · received 4 October" |
| What now? | Step 2: in progress, the checklist and "Then: answer the customer, or finish" (built, req-sla-deadline and the next-step panel) |
| Tabs | Overview, Contact moments 2, Related 2, Decisions (selected) |
| Decisions panel | heading Decisions, "Formal decisions on this ticket, made and signed in decidiq"; empty state "No decision linked" with "A decision can be prepared straight from this ticket as a proposal in decidiq, or the request becomes a case in dossiq first. Either way the decision shows here."; buttons Create a proposal for this ticket, Convert to a case, Open in decidiq |
| Sidebar | Suggested colleagues (built, req-routing), Customer, Channel, Contact, Deadline, Priority, Handler, Linked case ("No case linked yet.", Link an existing case), Related |

## Decisions

- **D1. Mount, do not rebuild.** The tab mounts decidiq's `decidesk-decisions` leaf with the ticket as host. pipelinq adds the heading, the empty-state text and the two buttons the leaf does not own (Convert to a case, and the Open in decidiq link when the list is empty).
- **D2. The case's decisions.** When `caseReference` is set, the tab mounts the leaf a second time with the case as host (register and schema of the dossiq case, its id), under the heading of the case title. If the integration widget cannot take another host than the page object, the tab lists the case's decisions itself through OpenRegister's object API with `subjectId` = the case id, read-only, linking to decidiq. No decidiq change either way.
- **D3. One place.** The `ticket-decisions` widget and its layout cell leave the overview. Leads keep their `lead-decisions` widget; that is another board.
- **D4. Hidden without decidiq.** The tab is declared with the leaf's `requiredApp`, so it does not show when decidiq is not installed.
