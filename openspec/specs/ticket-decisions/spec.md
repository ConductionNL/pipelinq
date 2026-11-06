# ticket-decisions Specification

## Purpose
A ticket shows the formal decisions on it, including those on the case it became, and a decision can be prepared from it. Follows board PqTicketBesluitvorming. Covers row req-decisions (decision 99). decidiq owns the decisions and the leaf; pipelinq places them.

## Requirements

### Requirement: The ticket page has a Decisions tab (REQ-TDT-001)

When decidiq is installed, the ticket page SHALL have a tab Decisions beside Overview that shows the heading "Decisions", the line "Formal decisions on this ticket, made and signed in decidiq", and the decisions whose subject is the ticket, through decidiq's `decidesk-decisions` leaf. The tab SHALL offer Create a proposal for this ticket, which creates a proposal in decidiq linked to the ticket, Convert to a case, which opens the existing convert flow, and Open in decidiq. Convert to a case SHALL show once on a ticket page: where the page already offers it in its own Case section (the simple structure), the tab SHALL not repeat it. The decisions widget SHALL no longer appear on the Overview. When decidiq is not installed the tab SHALL not show.

#### Scenario: A handler prepares a proposal
- GIVEN ticket PQ-2026-0417 with no decision
- WHEN the handler opens the Decisions tab and clicks Create a proposal for this ticket
- THEN decidiq holds a proposal whose subject is the ticket
- AND the tab lists it

#### Scenario: The tab explains the two routes
- GIVEN a ticket with no decision and no linked case
- WHEN the Decisions tab opens
- THEN it reads "A decision can be prepared straight from this ticket as a proposal in decidiq, or the request becomes a case in dossiq first. Either way the decision shows here."
- AND the list of decisions shows decidiq's own empty line

The two routes show above the list at all times, with or without a decision. The empty line and the labels of Create and Open in decidiq are the leaf's, so decidiq words them.

#### Scenario: Convert to case shows once in the simple structure
- GIVEN the simple structure, whose ticket page has a Case section with Convert to case
- WHEN the handler opens the Decisions tab
- THEN the tab names the two routes and has no second Convert to case

#### Scenario: decidiq is not installed
- GIVEN an instance without decidiq
- WHEN a ticket opens
- THEN there is no Decisions tab and no decisions widget

### Requirement: Decisions on the linked case show on the ticket (REQ-TDT-002)

When a ticket has a linked case (`caseReference`), the Decisions tab SHALL also list the decisions whose subject is that case, grouped under the case's title, each linking to the decision in decidiq. Only decisions the user may read SHALL show. The register and schema of the case SHALL be those of whichever installed app implements the case kind, looked up at run time; pipelinq SHALL NOT write another app's register or schema slug for it. When no installed app keeps cases, the tab SHALL show no list for a case.

#### Scenario: The request became a case
- GIVEN ticket PQ-2026-0417 converted to a dossiq case, and decidiq holds a decision on that case
- WHEN the handler opens the ticket's Decisions tab
- THEN the decision shows under the case title with a link to decidiq

#### Scenario: No installed app keeps cases
- GIVEN a ticket with a `caseReference` on an instance where no installed app implements the case kind
- WHEN the handler opens the Decisions tab
- THEN the tab shows the ticket's own decisions and no list for a case

#### Scenario: A decision the user may not read
- GIVEN the case's decision is restricted to the board's members and the handler is not one
- WHEN the handler opens the tab
- THEN that decision does not show
