## Why

KCC agents need fast access to standardized answers during live citizen interactions to achieve high first-call resolution rates. The existing kennisbank spec identifies this as a core V1 capability required by 51/52 KCC tenders.

## What Changes

- Add searchable knowledge base module within Pipelinq
- Add three new OpenRegister schemas: kennisartikel, kenniscategorie, kennisfeedback
- Add kennisbank navigation, routes, and views
- Add public API for citizen-facing channels
- Add article lifecycle notifications and background review job
- Add dashboard widget and admin settings

## Capabilities

### New Capabilities
- `knowledge-base`: Core knowledge base module

### Modified Capabilities
- `dashboard`: Kennisbank widget
- `admin-settings`: Kennisbank configuration section

## Impact

- Data model: 3 new schemas in pipelinq_register.json
- Frontend: New views, components, store, routes
- Backend: PublicKennisbankController, KennisbankReviewJob
- Dependencies: markdown-it for Markdown rendering
