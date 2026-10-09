# resident-view-preview Specification

## Purpose
A handler sees on a request ticket exactly what the resident will read in
their portal, and knows that everything else stays internal. From pipelinq
matrix row `portal-visible-fields`.

## Requirements

### Requirement: A request ticket previews the resident's view (REQ-RVP-001)

TicketDetail SHALL show, on a request ticket, a section with the fields the
resident portal shows for that ticket, built by the same code that serves the
portal. The section SHALL state that every other field stays internal, and
SHALL NOT appear on complaint or interaction tickets.

#### Scenario: A KCC employee checks the answer before setting awaiting customer

- GIVEN a KCC employee on the TicketDetail page of a request ticket with an internal note and a message to the customer
- WHEN they scroll to What the resident sees
- THEN they read the subject, status, description and the message to the customer as the resident will
- AND the internal note is not in the section
- AND a line says everything else on this ticket stays internal

#### Scenario: The handler's name follows the portal setting

- GIVEN a portal whose settings do not show the handler's name
- WHEN a KCC employee opens What the resident sees on a request ticket assigned to them
- THEN the section does not show their name

#### Scenario: A complaint ticket has no preview

- GIVEN a KCC employee on the TicketDetail page of a complaint ticket
- WHEN the page loads
- THEN no What the resident sees section is shown

### Requirement: The preview shows the organisation portal too (REQ-RVP-002)

When portaliq is installed and the ticket belongs to an organisation client,
the section SHALL show a second panel with the fields an organisation contact
reads in portaliq, built from pipelinq's portal contribution. portaliq SHALL
show the message to the customer on a request.

#### Scenario: An account manager sees both portals

@e2e exclude needs portaliq installed next to pipelinq, which the e2e instance does not have; covered by ResidentViewControllerTest::testPortaliqShowsTheOrganisationsView

- GIVEN portaliq is installed and a request ticket belongs to the client Acme
- WHEN an account manager opens What the resident sees on that ticket
- THEN one panel shows the resident portal and one shows the Acme contact's portaliq view
- AND both panels show the message to the customer

### Requirement: The preview respects the handler's own rights (REQ-RVP-003)

The system SHALL answer the preview only for a ticket the calling user may
read, and SHALL answer not found otherwise.

#### Scenario: A colleague without access asks for the preview

@e2e exclude pure-backend API contract (404 for an unreadable ticket); covered by ResidentViewControllerTest::testAnUnreadableTicketIsNotFound

- GIVEN a ticket the calling user may not read
- WHEN they request `GET /apps/pipelinq/api/tickets/{id}/resident-view`
- THEN the response is 404 and carries no ticket data
