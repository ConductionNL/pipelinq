# Action Widgets

## Overview

Action Widgets are Nextcloud Dashboard widgets that provide quick-action forms directly on the Nextcloud dashboard. They allow KCC agents and sales teams to perform common CRM operations without navigating into the full Pipelinq app.

Three widgets are provided:

| Widget | Dashboard ID | Purpose | Order |
|--------|-------------|---------|-------|
| Find Client | `pipelinq_find_client_widget` | Search clients and take quick actions | 13 |
| Start Request | `pipelinq_start_request_widget` | Create new service requests inline | 14 |
| Create Lead | `pipelinq_create_lead_widget` | Create new sales leads inline | 15 |

## Standards Compliance

| Standard | Reference |
|----------|-----------|
| GEMMA Callcentercomponent | Quick intake via dashboard |
| TEC CRM 1.1 (Lead Management) | Lead creation from dashboard |
| TEC CRM 3.1 (Case Management) | Request creation from dashboard |
| Nextcloud IWidget API | Dashboard widget interface |

## Start Request Widget

Provides an inline form for creating service requests (verzoeken) directly from the Nextcloud dashboard.

### Fields

| Field | Type | Required | Default |
|-------|------|----------|---------|
| Title | Text input | Yes | n/a |
| Client | Autocomplete | No | n/a |
| Category | Text input | No | n/a |
| Priority | Dropdown | No | normal |
| Channel | Dropdown | No | n/a |

### Behavior

- Title is required; form submission is blocked if empty.
- Priority options: low, normal, high, urgent.
- Channel options: phone, email, walk-in, web.
- On submit, creates a request via OpenRegister API with status "new".
- On success, displays confirmation message with a link to the created request.
- Shows the 3 most recent requests below the form.

## Create Lead Widget

Provides an inline form for creating sales leads with pipeline assignment.

### Fields

| Field | Type | Required | Default |
|-------|------|----------|---------|
| Title | Text input | Yes | n/a |
| Client | Autocomplete | No | n/a |
| Pipeline | Dropdown | No | n/a |
| Value | Number input | No | n/a |
| Source | Text input | No | n/a |

### Behavior

- Title is required; form submission is blocked if empty.
- Pipeline dropdown loads available pipelines from OpenRegister.
- On submit, creates a lead assigned to the first stage of the selected pipeline.
- Supports quick-add mode: pressing Enter submits the form.

## Find Client Widget

Provides a client search interface with quick action buttons.

### Search

- Live filtering as the user types.
- Displays client type icons to differentiate persons and organizations.

### Actions per Client

| Action | Description |
|--------|-------------|
| View | Navigate to client detail page |
| Create Request | Start a new request pre-linked to this client |
| Create Lead | Start a new lead pre-linked to this client |
| Copy Email | Copy client email to clipboard |

### New Client

- A mini-form allows creating a new client directly from the widget.

## Shared Infrastructure

### ClientAutocomplete Component

All widgets share a `ClientAutocomplete.vue` component (`src/components/widgets/ClientAutocomplete.vue`) that:

- Searches clients by name as the user types (2+ characters).
- Queries the OpenRegister API for matching client objects.
- Emits the selected client object to the parent widget.

### Architecture

Each widget consists of three files:

| Layer | File Pattern | Purpose |
|-------|-------------|---------|
| PHP | `lib/Dashboard/{Name}Widget.php` | Nextcloud IWidget registration |
| Vue | `src/views/widgets/{Name}Widget.vue` | Widget UI component |
| JS | `src/{name}Widget.js` | Webpack entry point, registers with OCA.Dashboard |

### i18n

All visible text uses `t('pipelinq', '...')` for internationalization (Dutch + English).

### Styling

All styling uses CSS variables only (NL Design System compatible, no hardcoded colors). Form inputs use `@nextcloud/vue` components (NcTextField, NcButton, NcSelect, etc.).

## Change History

| Date | Change | Issues |
|------|--------|--------|
| 2026-03-24 | Initial implementation (action-widgets) | #83, #85–#97 |
