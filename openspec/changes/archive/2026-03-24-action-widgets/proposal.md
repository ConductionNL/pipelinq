# Action Widgets

## Summary

Add three action-oriented Nextcloud Dashboard widgets to Pipelinq: "Start Request", "Create Lead", and "Find Client". These widgets let users perform common CRM actions directly from the Nextcloud dashboard or MyDash without navigating into the Pipelinq app.

## Motivation

CRM users perform three actions dozens of times per day: logging new service requests from incoming calls/emails, creating sales leads from conversations, and looking up client information. Currently all three require navigating into Pipelinq first. Dashboard action widgets reduce this to a single interaction from the Nextcloud home screen.

This is a common pattern in CRM systems (Salesforce Quick Actions, HubSpot Quick Create, EspoCRM Dashboard widgets). The key insight is that these aren't display widgets showing lists — they're **action widgets** that let users do something immediately.

### Existing Widget Landscape

Pipelinq already has 4 display widgets (My Leads, Deals Overview, Recent Activities, Client Search). The existing Client Search widget is read-only (browse/search). The new "Find Client" widget replaces it with an enhanced version that includes quick actions (call, email, view, create request for client).

## Affected Projects
- [x] Project: `pipelinq` — Three new widgets (PHP + Vue) + deprecate existing ClientSearchWidget

## Scope

### In Scope

#### 1. Start Request Widget (`pipelinq_start_request_widget`)
- Quick intake form embedded in the dashboard widget
- Fields: title (required), client (optional autocomplete), category (dropdown from configured categories), priority (dropdown), channel (dropdown: phone, email, walk-in, web)
- On submit: creates request via OpenRegister API, shows success with link to the new request
- Recent requests list (last 3) below the form for quick reference

#### 2. Create Lead Widget (`pipelinq_create_lead_widget`)
- Quick lead creation form embedded in the dashboard widget
- Fields: title (required), client (optional autocomplete), pipeline (dropdown, defaults to default pipeline), value (optional number), source (dropdown from configured sources)
- On submit: creates lead via OpenRegister API in the first stage of the selected pipeline, shows success with link
- "Quick add" mode: just title + client for fastest possible entry

#### 3. Find Client Widget (`pipelinq_find_client_widget`)
- Replaces existing `ClientSearchWidget` with an enhanced action-oriented version
- Search input with live filtering (existing behavior)
- Per-client action buttons: view detail, create request for this client, create lead for this client, copy email/phone
- "New client" button at the top for quick client creation (name + type + email)
- Shows client type (person/organization) with appropriate icon
- Click-through navigates to client detail in Pipelinq

#### Shared
- Each widget: PHP class implementing `IWidget`, Vue component, webpack entry point
- All forms use NL Design System compatible styling (CSS variables, no hardcoded colors)
- Dutch + English translations
- Client autocomplete component shared between Start Request and Create Lead widgets
- Success feedback: brief inline confirmation with link to created entity

### Out of Scope

- Full entity creation forms with all fields (stay in the Pipelinq app)
- Request-to-case conversion (that's a Procest workflow)
- Pipeline kanban view (existing in-app feature)
- Email/phone integration (clicking contact info uses browser defaults)

## Approach

### PHP (3 new classes)

```
lib/Dashboard/
  StartRequestWidget.php      — IWidget, order: 14
  CreateLeadWidget.php         — IWidget, order: 15
  FindClientWidget.php         — IWidget, order: 13 (replaces ClientSearchWidget at 13)
```

All registered in Application.php. The existing `ClientSearchWidget` registration is removed and replaced by `FindClientWidget`.

### Vue (3 new components)

```
src/views/widgets/
  StartRequestWidget.vue       — Inline form + recent requests
  CreateLeadWidget.vue         — Inline form + quick-add mode
  FindClientWidget.vue         — Enhanced search + action buttons
```

Each uses `initializeStores()` to get schema registry and calls OpenRegister API directly (established pattern).

### Webpack (3 new entry points)

```
src/startRequestWidget.js
src/createLeadWidget.js
src/findClientWidget.js
```

Compiled to `js/pipelinq-startRequestWidget.js`, etc.

### Shared Components

A small `ClientAutocomplete.vue` component (used by Start Request and Create Lead widgets) that searches clients as the user types and returns the selected client ID.

## Cross-Project Dependencies

- **OpenRegister**: All CRUD via OpenRegister API (existing)
- **MyDash** (optional): Widgets appear automatically in MyDash widget catalog
- **Procest** (indirect): Requests created here may later become Procest cases via the existing request-to-case flow

## Rollback Strategy

Remove widget registrations from Application.php, delete new files (3 PHP classes, 3 Vue components, 3 JS entry points, 1 shared component). Restore `ClientSearchWidget` registration if needed.

## Acceptance Criteria

### Start Request Widget
1. GIVEN a user with the Start Request widget on their dashboard, WHEN they fill in a title and submit, THEN a new request is created in OpenRegister and the widget shows a success message with a link to the request
2. GIVEN the Start Request widget, WHEN the user types in the client field, THEN matching clients appear as autocomplete suggestions
3. GIVEN a fresh installation with no configured request channels, WHEN the widget loads, THEN the channel dropdown shows sensible defaults (phone, email, walk-in, web)

### Create Lead Widget
4. GIVEN a user with the Create Lead widget on their dashboard, WHEN they fill in a title and submit, THEN a new lead is created in the first stage of the default pipeline
5. GIVEN the Create Lead widget, WHEN the user selects a specific pipeline, THEN the lead is created in the first stage of that pipeline
6. GIVEN the Create Lead widget in "quick add" mode, WHEN the user enters only a title and presses Enter, THEN the lead is created with minimal fields and the form resets for the next entry

### Find Client Widget
7. GIVEN a user with the Find Client widget, WHEN they type a search query, THEN matching clients are shown with name, contact info, and type icon
8. GIVEN search results in the Find Client widget, WHEN the user clicks the "Create request" action on a client, THEN the Start Request widget (or a dialog) opens pre-filled with that client
9. GIVEN the Find Client widget, WHEN the user clicks "New client", THEN a minimal inline form appears for quick client creation (name, type, email)
10. GIVEN the Find Client widget, WHEN the user clicks a client name, THEN they navigate to the client detail page in Pipelinq
