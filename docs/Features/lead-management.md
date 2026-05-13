# Lead Management

Tracks sales opportunities from first contact through won/lost. Leads are the core sales entity, flowing through configurable pipeline stages with value, probability, and priority tracking.

## Specs

- `openspec/specs/lead-management/spec.md`

## Features

### Lead CRUD (MVP)

Full create, read, update, and delete for lead records. Leads represent sales opportunities linked to clients and contacts.

- Lead list view with search, sort, and filter
- Lead detail view with info grid, pipeline progress visualization, and linked client/contact display
- Fields: title, description, value, probability, source, priority, expectedCloseDate, category, stage
- Client and contact linking with navigation
- Pipeline stage progress indicator showing completed/current/future stages

### Lead Value and Probability (MVP)

Financial tracking for sales forecasting. Lead values are displayed in EUR format, and probability percentages indicate likelihood of closing.

### Lead Source Tracking (MVP)

Records how the lead was acquired (e.g., website, referral, phone, campaign) for marketing attribution and analysis.

### Lead Priority (MVP)

Four-level priority system (low, normal, high, urgent) with visual indicators — urgent shows in error color, high in warning color.

### Lead Assignment (MVP)

Leads can be assigned to users for workload distribution and accountability. Assigned leads appear in the user's My Work view.

### Lead Lifecycle via Pipeline Stages (MVP)

Leads progress through pipeline stages (e.g., New → Contacted → Qualified → Proposal → Negotiation → Won/Lost). The detail view shows a visual progress tracker with completed, current, and future stage indicators.

### Error Handling (MVP)

- Structured error objects with HTTP status distinction (404, 403, 422, 500)
- Server-side validation error (422) parsing with field-level feedback
- Error toasts on save/delete failures with form data preservation
- Orphaned reference placeholders for deleted clients/contacts

### Planned (V1)

- Stale lead detection (no activity for X days)
- Aging indicator (days in current stage)
- Lead import/export CSV
- Stage probability mapping (auto-populates from pipeline stage)

### Planned (Enterprise)

- Lead scoring/rating
- Automated lead assignment rules
- Win/loss reason tracking
