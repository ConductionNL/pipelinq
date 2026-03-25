## Why

KCC agents need to log every client interaction (phone, email, counter, chat) as a structured contactmoment record linked to a client and optionally to a request or case. Without this, Pipelinq cannot serve as the klantinteractie hub that 54% of Dutch government tenders require. The existing `omnichannel-registratie` spec defines the channel-aware registration form, and `contactmomenten-rapportage` defines reporting — but neither covers the core CRUD, timeline display, search, and lifecycle management of contactmomenten themselves. This change fills that gap.

## What Changes

- Add a **Contactmoment entity** in the OpenRegister schema with fields aligned to VNG Klantinteracties (`Contactmoment`) and Schema.org (`CommunicateAction`): timestamp, agent, client reference, channel, subject, summary, outcome, duration, linked request/case, and channel-specific metadata.
- Add a **contactmomenten list view** (`/contactmomenten`) with search, sort, filter by channel/agent/date range, and pagination.
- Add a **contactmoment detail view** showing the full record, linked client, linked request/case, and channel metadata.
- Add a **quick-log form** accessible from client detail, request detail, and the contactmomenten list — pre-fills context (client, request) when launched from those views.
- Add a **client timeline integration** — contactmomenten appear in the client detail activity timeline, sorted chronologically with other activities.
- Add **Pinia store** (`contactmomentenStore`) querying OpenRegister API for CRUD operations.
- Extend the **register schema** (`pipelinq_register.json`) with the Contactmoment object definition.

## Capabilities

### New Capabilities
- `contactmomenten`: Core CRUD, list/detail views, quick-log form, client timeline integration, and search for contact moment records.

### Modified Capabilities
- `client-management`: Client detail view gains a contactmomenten tab/section in the activity timeline.
- `request-management`: Request detail view gains linked contactmomenten display.

## Impact

- **Frontend**: New views (`src/views/contactmomenten/`), new store (`src/store/contactmomenten.js`), new route entries, navigation item.
- **Register schema**: `lib/Settings/pipelinq_register.json` gains Contactmoment object definition.
- **Existing views**: Client detail and request detail views get additional sections/tabs for linked contactmomenten.
- **Procest bridge**: Contactmomenten linked to requests carry over when a request is converted to a case in Procest.
- **Dependencies**: OpenRegister (data storage), Nextcloud Activity API (timeline events).
