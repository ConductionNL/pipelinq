# KCC Werkplek Specification

## Purpose

Define functional requirements for the KCC Werkplek — a unified agent workspace in Pipelinq enabling KCC agents to handle omnichannel citizen interactions without navigating between views.

---

## Requirement: Unified Workspace View [V1] (REQ-KWP-010)

The KCC Werkplek MUST provide a single page that consolidates inbox, interaction form, and knowledge search in one view accessible from the main navigation.

### Scenario: Navigate to KCC Werkplek
- GIVEN a logged-in Nextcloud user
- WHEN they click "KCC Werkplek" in the main navigation
- THEN the browser MUST navigate to `/werkplek`
- AND the page MUST load showing three panels: inbox (left), contactmoment form (center), knowledge search (right)
- AND the page MUST show a loading indicator while workspace state is being fetched

### Scenario: Workspace state loaded on page entry
- GIVEN the user navigates to `/werkplek`
- WHEN the page finishes loading
- THEN the inbox panel MUST display the user's assigned requests and open tasks
- AND the agent status toggle MUST reflect the current `agentProfile.isAvailable` value
- AND the contactmoment form MUST be in its empty/ready state

### Scenario: Workspace renders on tablet (768px)
- GIVEN the viewport is 768px wide
- WHEN the user views the KCC Werkplek
- THEN the inbox and knowledge panels MUST collapse or scroll to allow the contactmoment form to remain usable
- AND all primary actions (register contactmoment, toggle availability) MUST remain accessible

---

## Requirement: Queue and Inbox Panel [V1] (REQ-KWP-020)

The inbox panel MUST display the current user's assigned requests and open tasks with priority ordering.

### Scenario: Inbox shows assigned requests
- GIVEN the current user has requests with `assignee` matching their user UID and status `new` or `in_progress`
- WHEN the inbox panel loads
- THEN those requests MUST be listed in the "Verzoeken" section
- AND each row MUST show: title, channel badge, priority badge, and creation date
- AND rows MUST be sorted by priority descending (urgent → hoog → normaal → laag)

### Scenario: Inbox shows open tasks
- GIVEN the current user has tasks with `assigneeUserId` matching their user UID and status `open` or `in_behandeling`
- WHEN the inbox panel loads
- THEN those tasks MUST be listed in the "Taken" section
- AND each row MUST show: subject, type badge (terugbelverzoek/opvolgtaak/informatievraag), deadline, and status
- AND overdue tasks (deadline in the past) MUST be visually highlighted in red

### Scenario: Inbox item loads context into center panel
- GIVEN a request or task is displayed in the inbox
- WHEN the user clicks the row
- THEN the contactmoment form in the center panel MUST pre-fill the client field with the linked client (if present)
- AND the request or task reference MUST be stored as context for the contactmoment being registered

### Scenario: Empty inbox state
- GIVEN the current user has no assigned requests or open tasks
- WHEN the inbox panel loads
- THEN a CnEmptyState MUST be shown with the message "Geen openstaande items"
- AND no error MUST be shown

### Scenario: Queue counts displayed
- GIVEN queue objects exist in the pipelinq register
- WHEN the workspace state loads
- THEN the queue names and item counts from the workspace state response MUST be displayed below the inbox sections
- AND queues with 0 items MUST still be listed (showing count "0")

---

## Requirement: Quick Contactmoment Registration [V1] (REQ-KWP-030)

The center panel MUST allow agents to register a contactmoment without leaving the workspace.

### Scenario: Channel selector adapts form fields
- GIVEN the contactmoment form is displayed
- WHEN the user selects channel "telefoon"
- THEN the CallTimer component MUST become visible
- AND a "Richting" field (inkomend/uitgaand) MUST appear
- WHEN the user selects channel "email"
- THEN the CallTimer MUST be hidden
- AND an "E-mailonderwerp" field MUST appear

### Scenario: Call timer tracks phone duration
- GIVEN the user has selected channel "telefoon"
- WHEN the user clicks "Start" on the CallTimer
- THEN the timer MUST begin counting up in MM:SS format
- WHEN the user clicks "Stop"
- THEN the elapsed time MUST be stored in the `duration` field in ISO 8601 format (e.g., PT4M32S)

### Scenario: Client autocomplete
- GIVEN the contactmoment form is in its ready state
- WHEN the user types 2 or more characters in the client field
- THEN a dropdown MUST appear with matching client names from the pipelinq register
- AND selecting a client MUST populate the client reference field

### Scenario: Contactmoment saved successfully
- GIVEN all required fields (subject, channel) are filled in
- WHEN the user clicks "Registreer"
- THEN a `contactmoment` object MUST be saved via the objectStore
- AND the `agent` field MUST be set to the current user's UID (from IUserSession, NOT from frontend-supplied data)
- AND the `contactedAt` field MUST be set to the current timestamp
- AND a success confirmation MUST be shown
- AND the form MUST reset to its empty state

### Scenario: Contactmoment requires subject and channel
- GIVEN the contactmoment form has no subject or no channel selected
- WHEN the user clicks "Registreer"
- THEN the form MUST show inline validation errors on the empty required fields
- AND no contactmoment object MUST be created

### Scenario: New task creation from workspace
- GIVEN a contactmoment has just been registered
- WHEN the user clicks "Nieuwe taak"
- THEN a CnFormDialog MUST open pre-filled with the linked client UUID and a reference to the contactmoment
- AND submitting the dialog MUST create a `task` object

---

## Requirement: Inline Knowledge Base Search [V1] (REQ-KWP-040)

The knowledge search panel MUST allow agents to find articles without navigating away from the workspace.

### Scenario: Search returns matching articles
- GIVEN the agent types 2 or more characters in the knowledge search field
- WHEN the debounced search fires (300ms delay)
- THEN the system MUST query `kennisartikel` objects with matching title or body text
- AND results MUST be filtered to `visibility=intern` OR `visibility=openbaar` (both are shown to agents)
- AND only articles with `status=gepubliceerd` MUST be returned
- AND results MUST be listed showing title, summary snippet (max 150 chars), and category badges

### Scenario: Article expands inline on click
- GIVEN search results are displayed
- WHEN the user clicks an article result
- THEN the full article body MUST render inline in the panel as formatted HTML (Markdown → HTML)
- AND the category breadcrumb, tags, and author MUST be visible below the title
- AND a "Terug naar resultaten" link MUST be shown to collapse back to the list

### Scenario: Feedback buttons on expanded article
- GIVEN an article is expanded in the knowledge panel
- WHEN the user clicks "Nuttig"
- THEN a `kennisfeedback` object MUST be created with `rating=nuttig` and `agent` set to the current user UID
- AND the button MUST show a visual confirmation (e.g., checkmark, disabled state)
- WHEN the user clicks "Niet nuttig"
- THEN the feedback comment field MUST expand for the agent to optionally type a suggestion

### Scenario: No results found
- GIVEN the agent searches for a term with no matching articles
- WHEN the search completes
- THEN a message "Geen artikelen gevonden voor '[term]'" MUST be shown
- AND no error state MUST be shown

---

## Requirement: Agent Availability Toggle [V1] (REQ-KWP-050)

Agents MUST be able to set their availability status from within the workspace.

### Scenario: Toggle shows current availability
- GIVEN the workspace state has loaded
- WHEN the agent views the availability toggle
- THEN the toggle MUST show "Beschikbaar" with a green indicator if `agentProfile.isAvailable = true`
- AND "Niet beschikbaar" with a grey indicator if `agentProfile.isAvailable = false`

### Scenario: Agent marks themselves unavailable
- GIVEN the agent is currently marked as available
- WHEN the agent clicks the availability toggle
- THEN the system MUST send `PUT /api/kcc-werkplek/availability` with `{ "isAvailable": false }`
- AND the toggle MUST update to "Niet beschikbaar" upon success
- AND the change MUST be persisted in the agent's `agentProfile` object

### Scenario: Availability toggle handles API error
- GIVEN the availability API returns a non-2xx response
- WHEN the agent clicks the toggle
- THEN an NcDialog error MUST inform the agent that the status could not be updated
- AND the toggle MUST revert to its previous state

### Scenario: No agentProfile exists for user
- GIVEN the current user has no agentProfile object in the register
- WHEN the workspace state loads
- THEN the availability toggle MUST still be shown in a default "Beschikbaar" state
- AND clicking toggle MUST create a new agentProfile object via ObjectService before updating

---

## Requirement: Workspace State API [V1] (REQ-KWP-060)

The backend MUST provide a single aggregated endpoint for workspace initialization.

### Scenario: State endpoint returns expected structure
- GIVEN a valid authenticated request to `GET /api/kcc-werkplek/state`
- WHEN the endpoint processes the request
- THEN the response MUST include `agentProfile`, `assignedRequests`, `openTasks`, and `queueCounts` keys
- AND HTTP 200 MUST be returned
- AND no stack traces, SQL, or internal paths MUST appear in the response

### Scenario: State endpoint filters by current user
- GIVEN user A and user B both have assigned requests
- WHEN user A calls `GET /api/kcc-werkplek/state`
- THEN only requests with `assignee = user A UID` MUST appear in `assignedRequests`
- AND user B's requests MUST NOT be included

### Scenario: State endpoint handles missing agentProfile gracefully
- GIVEN no agentProfile exists for the requesting user
- WHEN `GET /api/kcc-werkplek/state` is called
- THEN the response MUST include `"agentProfile": null` (not an error)
- AND the other fields MUST still be populated normally
- AND HTTP 200 MUST be returned

### Scenario: Availability endpoint requires authentication
- GIVEN an unauthenticated request to `PUT /api/kcc-werkplek/availability`
- WHEN the request is received
- THEN the response MUST be HTTP 401
- AND no data MUST be modified
