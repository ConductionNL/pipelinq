# Feature Counsel Report: Pipelinq

**Date:** 2026-02-25
**Method:** 8-persona feature advisory analysis against OpenSpec specifications
**Personas:** Henk Bakker, Fatima El-Amrani, Sem de Jong, Noor Yilmaz, Annemarie de Vries, Mark Visser, Priya Ganpat, Jan-Willem van der Berg

---

## Architectural Decision: Business Logic in OpenRegister

> **Decision:** Business logic (status transitions, pipeline rules, validation beyond schema constraints) MUST be implemented in **OpenRegister**, not in a separate Pipelinq API layer and not only in frontend JavaScript. OpenRegister is the shared backend for all thin-client apps (Pipelinq, Procest, OpenCatalogi). Adding business logic capabilities to OpenRegister ensures all consumer apps benefit, and external API integrators get the same rules enforced as the frontend.
>
> This means the persona recommendations for a "server-side Pipelinq API layer" should be read as: **OpenRegister needs to support configurable business rules** (e.g., status transition tables, field-level validation rules, event hooks/webhooks) that Pipelinq and other apps can configure through their register definitions.

---

## Executive Summary

Pipelinq's specifications describe a technically solid CRM foundation with strong data model grounding in Schema.org, vCard, and VNG standards, built as a thin client on OpenRegister. However, the Feature Counsel reveals **critical gaps across all 8 personas** in five key areas: (1) **Dutch B1-level language** -- the entire app uses English CRM jargon (leads, pipelines, stages) that excludes non-technical Dutch users; (2) **API compliance** -- no OpenAPI spec, no NLGov API Design Rules v2 compliance, no RFC 7807 errors, no API versioning; (3) **Security & compliance** -- audit log export, data retention, GDPR/AVG requirements, and granular RBAC are insufficiently specified; (4) **Government interoperability** -- no publiccode.yml, no GEMMA mapping, no actual VNG API endpoints, no FSC readiness; (5) **Modern UX patterns** -- no keyboard shortcuts, no URL state management, no dark mode, no bulk operations. The consensus is clear: Pipelinq is a good developer's toolkit that needs significant investment in accessibility, compliance, and Dutch government standards to become a municipal product.

---

## Consensus Features (suggested by 3+ personas)

| # | Feature | Suggested by | Priority | Impact |
|---|---------|-------------|----------|--------|
| 1 | **Dutch B1-level language for all UI elements** | Henk, Fatima, Jan-Willem, Mark, Annemarie | MUST | 2.5 million low-literacy Dutch adults + all non-English-speaking municipal staff excluded without it |
| 2 | **Bulk operations on list views** | Sem, Mark, Jan-Willem, Priya | MUST | CRM without bulk select/edit/delete/assign is unusable for daily productivity |
| 3 | **OpenAPI 3.0 specification document** | Priya, Annemarie, Noor, Mark | MUST | External integrators cannot build against undocumented APIs; NLGov API Design Rules require it |
| 4 | **publiccode.yml** | Annemarie, Priya, Noor | MUST | Blocking for GEMMA Softwarecatalogus listing and municipal procurement |
| 5 | **Keyboard shortcuts (Ctrl+K, N, E, Esc, J/K)** | Sem, Mark, Henk | MUST | Power users lose productivity without keyboard-driven workflows |
| 6 | **Import/Export as MVP (not V1)** | Mark, Jan-Willem, Priya, Annemarie | MUST | No adoption without ability to import existing data and export for reporting |
| 7 | **Audit log export (CSV/JSON)** | Noor, Annemarie, Priya | MUST | ENSIA self-evaluation evidence; BIO2 control 12.4.1 requires log review |
| 8 | **RFC 7807 Problem Details error format** | Priya, Annemarie, Sem | SHOULD | NLGov API Design Rules mandate; enables standard cross-API error handling |
| 9 | **URL state for filters, search, pagination** | Sem, Mark, Priya | SHOULD | Browser back/forward broken without it; links not shareable |
| 10 | **Mobile-responsive design (especially kanban)** | Fatima, Jan-Willem, Henk | SHOULD | Kanban drag-and-drop unusable on phones; majority of low-tech users are mobile-first |
| 11 | **Simplified forms (progressive disclosure)** | Jan-Willem, Fatima, Henk | SHOULD | 8-12 fields per form overwhelms non-technical users; show 3 fields, hide rest behind "Meer opties" |
| 12 | **Contract/offerte management entity** | Mark, Priya, Annemarie | SHOULD | Won leads need follow-up; no contract entity means CRM flow stops at "Won" |
| 13 | **VNG Klantinteracties/Verzoeken API endpoints** | Annemarie, Priya, Noor | SHOULD | VNG compatibility claim is aspirational without actual API endpoints |
| 14 | **Business logic in OpenRegister (not frontend-only)** | Priya, Annemarie, Noor | SHOULD | Business logic only in frontend JS means external integrations bypass validation; OpenRegister must support configurable rules |
| 15 | **AVG/GDPR data retention and deletion** | Noor, Mark, Annemarie | MUST | PII stored indefinitely without retention policy violates AVG Article 5(1)(e) |
| 16 | **Onboarding wizard for first-time users** | Jan-Willem, Fatima, Henk | SHOULD | Empty dashboard with zeros and English labels is hostile to new users |
| 17 | **Help/contact accessible from every screen** | Jan-Willem, Fatima, Henk | SHOULD | No help button, phone number, or FAQ anywhere in specs |
| 18 | **Dark mode / prefers-color-scheme support** | Sem, Fatima, Henk | COULD | NL Design System tokens support dark variants; younger users expect it |

---

## Per-Persona Highlights

### Henk Bakker (Elderly Citizen, 78)
- **Top need**: Simple mode without kanban -- a plain list view as default
- **Key missing feature**: Dutch translations of all CRM jargon; minimum 16px font, 44px touch targets
- **Quote**: "Wat is een 'pipeline'? Geef mij gewoon een lijst met mijn klanten en wat ik vandaag moet doen."

### Fatima El-Amrani (Low-Literate Migrant, 52)
- **Top need**: Icon+color+text triple encoding for all status indicators
- **Key missing feature**: Mobile-responsive kanban alternative; RTL support; B1 language level
- **Quote**: "Ik begrijp de plaatjes, niet de woorden. Geef mij kleuren en icoontjes."

### Sem de Jong (Digital Native, 22)
- **Top need**: URL-based state management as a cross-cutting requirement
- **Key missing feature**: Keyboard shortcuts, dark mode, skeleton loading states, optimistic UI updates
- **Quote**: "Every tool I use in 2026 has a command palette. If I have to click through navigation to find a client by name, that is 5 clicks instead of 1 keyboard shortcut. Hard pass."

### Noor Yilmaz (Municipal CISO, 36)
- **Top need**: Comprehensive audit logging and export capability as first-class feature
- **Key missing feature**: Permission overview/access matrix, session timeout configuration, soft delete
- **Quote**: "The specifications treat security as a downstream concern of OpenRegister rather than a first-class design principle. I cannot recommend this for municipal deployment without addressing BIO2/ENSIA gaps."

### Annemarie de Vries (VNG Standards Architect, 38)
- **Top need**: publiccode.yml and GEMMA reference component mapping
- **Key missing feature**: VNG Klantinteracties/Verzoeken API endpoints, FSC readiness, Common Ground 5-layer documentation
- **Quote**: "De architectuur is goed, de richting is goed, maar er ontbreekt een hele laag aan interoperabiliteit en standaarden-compliance. Dit is een goed ontwikkelaarspakket dat nog geen overheidsproduct is."

### Mark Visser (MKB Software Vendor, 48)
- **Top need**: Contract/offerte management as a core entity
- **Key missing feature**: Bulk operations, Excel/XLSX export, inline editing, tags/labels
- **Quote**: "Zonder contracten is Pipelinq een halve CRM. Mijn hele business draait om contracten met gemeenten: looptijden, licentiekosten, verlengdata, SLA-afspraken."

### Priya Ganpat (ZZP Developer, 34)
- **Top need**: Server-side API layer with published OpenAPI specification
- **Key missing feature**: Webhook/event system, API versioning, bulk API endpoints, data model inconsistency resolution
- **Quote**: "Pipelinq's frontend calls OpenRegister directly -- that means all business logic lives only in JavaScript. This is architecturally fragile and makes external integration nearly impossible."

### Jan-Willem van der Berg (Small Business Owner, 55)
- **Top need**: Plain Dutch B1 language throughout -- every label, button, error message
- **Key missing feature**: Simplified 3-field forms, help button on every screen, onboarding wizard
- **Quote**: "Wat is een 'lead'? Ik heb klanten, geen 'leads'. En wat is een 'pipeline'? Ik ben slager, geen loodgieter."

---

## Feature Suggestions by Category

### Accessibility & Inclusivity

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Dutch B1-level language for all UI text | Henk, Fatima, Jan-Willem, Mark, Annemarie | MUST | All labels, buttons, error messages, statuses in plain Dutch |
| 2 | Progressive disclosure on forms (3 fields default) | Jan-Willem, Fatima, Henk | SHOULD | Hide advanced fields behind "Meer opties" link |
| 3 | Icon+color+text triple encoding for statuses | Fatima, Henk, Jan-Willem | SHOULD | Never rely on text or color alone |
| 4 | Minimum 16px font, 44px touch targets | Henk, Fatima | SHOULD | WCAG AA minimum for elderly and motor-impaired users |
| 5 | Onboarding wizard (step-by-step, plain Dutch) | Jan-Willem, Fatima, Henk | SHOULD | Guided first-time experience |
| 6 | Help button / phone number on every screen | Jan-Willem, Fatima, Henk | SHOULD | Non-technical users need immediate assistance path |
| 7 | Simple list view as default (not kanban) | Jan-Willem, Henk, Fatima | SHOULD | Kanban assumes Trello familiarity |
| 8 | RTL language support | Fatima | COULD | Arabic-speaking users in Dutch municipalities |
| 9 | Reduced motion support (`prefers-reduced-motion`) | Sem | SHOULD | WCAG 2.1 SC 2.3.3 requirement |
| 10 | Dark mode / `prefers-color-scheme` support | Sem, Fatima | COULD | NL Design System tokens support dark variants |
| 11 | Print functionality for customer/work overviews | Jan-Willem | COULD | Non-digital-native users still print lists |
| 12 | `tel:` links for phone numbers | Jan-Willem, Fatima | SHOULD | Tap-to-call on mobile devices |

### Security & Compliance

| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | Audit log export (CSV/JSON, filterable) | Noor, Annemarie, Priya | MUST | BIO2 12.4.1, ENSIA evidence |
| 2 | Audit log retention policy | Noor | MUST | BIO2, ISO 27002:2022 clause 8.15 |
| 3 | AVG/GDPR data retention and deletion spec | Noor, Mark, Annemarie | MUST | AVG Article 5(1)(e), Article 17 |
| 4 | Granular RBAC (per-entity-type CRUD+export) | Noor, Priya | MUST | BIO2 9.1.2, principle of least privilege |
| 5 | IP address / session context in audit entries | Noor | HIGH | BIO2 12.4.1, incident investigation |
| 6 | Permission overview / access matrix admin UI | Noor | MUST | ISO 27002:2022 clause 8.3 |
| 7 | Session timeout configuration | Noor | HIGH | BIO2 9.4.2 |
| 8 | Soft delete with data snapshot for compliance | Noor | MUST | AVG Article 17 |
| 9 | Data classification labeling per field/record | Noor | HIGH | BIO2 8.2.1 |
| 10 | DLP controls on bulk export | Noor | HIGH | Prevent data exfiltration |
| 11 | Security admin settings section | Noor | SHOULD | No security configuration surface exists |
| 12 | API rate limiting with documented headers | Noor, Priya | SHOULD | BIO2 12.2.1, OWASP API Security |
| 13 | Input sanitization specification | Noor | SHOULD | BIO2 14.1.2, OWASP Top 10 |
| 14 | CORS origin configuration (not wildcard) | Noor, Priya | SHOULD | BIO2 14.1.2 |

### API & Developer Experience

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | OpenAPI 3.0 specification document | Priya, Annemarie, Noor | MUST | Ship at `/api/v1/openapi.yaml` |
| 2 | Business logic in OpenRegister (configurable rules, transitions, webhooks) | Priya, Annemarie | MUST | Business logic must be server-side in OpenRegister, not just in frontend JS |
| 3 | Webhook/event notification system | Priya, Mark, Annemarie | SHOULD | Real-time integration for zaaksystemen |
| 4 | API versioning (`/api/v1/`) | Priya, Annemarie | MUST | NLGov API Design Rules ADR-20 |
| 5 | RFC 7807 Problem Details errors | Priya, Annemarie, Sem | SHOULD | NLGov API Design Rules ADR-06 |
| 6 | RFC 8288 Link header pagination | Priya, Annemarie | SHOULD | NLGov API Design Rules ADR-12 |
| 7 | HATEOAS / `_links` in responses | Priya, Annemarie | SHOULD | NLGov API Design Rules ADR-09 |
| 8 | Bulk operations API endpoint | Priya, Mark | SHOULD | Municipal data migrations require batch import |
| 9 | Cross-entity search API | Priya | COULD | Single query across clients, leads, requests |
| 10 | Health check endpoint (`/api/health`) | Priya | SHOULD | Monitoring dashboards need this |
| 11 | ETag / conditional request support | Priya | SHOULD | Caching and conflict detection |
| 12 | Resolve 3 data model contradictions | Priya | MUST | stages embedded vs. separate; lead stage string vs. UUID; entityType singular vs. array |
| 13 | Developer onboarding guide | Priya | SHOULD | "Getting Started" for external integrators |
| 14 | Rate limiting headers (X-RateLimit-*) | Priya, Noor | SHOULD | Documented limits prevent integration failures |

### UX & Performance

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Keyboard shortcuts (Ctrl+K, N, E, Esc, J/K) | Sem, Mark | MUST | Power user productivity |
| 2 | URL-based state management | Sem, Priya, Mark | MUST | Filters, search, pagination, view toggle in URL |
| 3 | Skeleton/shimmer loading states | Sem | SHOULD | Replace blank screens with layout-matching placeholders |
| 4 | Optimistic UI updates (kanban, quick actions) | Sem | SHOULD | Instant feedback, rollback on API failure |
| 5 | Undo toast for destructive actions (10s window) | Sem | SHOULD | Replace confirmation dialogs for power users |
| 6 | Global search / command palette (Ctrl+K) | Sem | SHOULD | Cross-entity search from anywhere |
| 7 | Inline editing on list views | Mark, Sem | SHOULD | Double-click cell to edit without navigating to detail |
| 8 | Configurable page size (10/25/50/100) | Sem, Mark | SHOULD | Default 20-25 too small for large monitors |
| 9 | Form autosave / draft state | Sem | COULD | Preserve form data on accidental navigation |
| 10 | Auto-refresh dashboard on interval | Sem | COULD | Stale data after 30 minutes of sitting on dashboard |
| 11 | Independent section loading on dashboard | Sem | SHOULD | Slow chart should not block KPI cards |
| 12 | WIP limits per kanban column | Mark, Sem | COULD | Visual warning when stage is overloaded |
| 13 | Favorites / recently viewed | Mark | COULD | Quick access to frequently used records |

### Standards & Interoperability

| # | Feature | Personas | Priority | Standard |
|---|---------|----------|----------|----------|
| 1 | publiccode.yml | Annemarie, Priya | MUST | Standaard voor Publieke Code |
| 2 | GEMMA reference component mapping | Annemarie | MUST | GEMMA referentiearchitectuur |
| 3 | Common Ground 5-layer documentation | Annemarie | HIGH | Common Ground architectuurprincipes |
| 4 | VNG Klantinteracties API endpoint | Annemarie, Priya | HIGH | VNG Klantinteracties API 2.0 |
| 5 | VNG Verzoeken API endpoint | Annemarie, Priya | HIGH | VNG Verzoeken API |
| 6 | FSC (Federated Service Connectivity) readiness | Annemarie | MEDIUM | Inter-organizational data exchange |
| 7 | NLGov API Design Rules v2 compliance | Annemarie, Priya | MUST | ADR-01 through ADR-57 |
| 8 | EUPL-1.2 license consideration | Annemarie | LOW | Dutch gov preferred OSS license |
| 9 | KVK number as separate field (not in taxID) | Mark, Annemarie | SHOULD | KVK != BTW-nummer; different identifiers |
| 10 | Automated WCAG AA testing in CI | Annemarie, Sem | SHOULD | Dutch accessibility law compliance |
| 11 | Multi-tenancy specification | Annemarie | HIGH | Shared Nextcloud for multiple municipalities |
| 12 | Data portability / full export in open format | Annemarie | HIGH | Prevent vendor lock-in |

### Business & Workflow

| # | Feature | Personas | Priority | Notes |
|---|---------|----------|----------|-------|
| 1 | Bulk operations (select, assign, delete, export) | Mark, Sem, Priya | MUST | Standard CRM feature; hours wasted without it |
| 2 | Import/Export as MVP tier | Mark, Jan-Willem | MUST | Adoption blocker: can't import 80+ existing clients |
| 3 | Contract/offerte management entity | Mark | HIGH | Won leads need contract follow-up |
| 4 | Notes as first-class entity | Mark, Jan-Willem | HIGH | Core CRM functionality; activity timeline is not enough |
| 5 | Tags/labels for clients and leads | Mark | HIGH | Multi-tag filtering for customer segmentation |
| 6 | Email integration / send history | Mark | MEDIUM | Link to Nextcloud Mail for correspondence tracking |
| 7 | Reminders / tasks per lead | Mark, Jan-Willem | MEDIUM | "Bel morgen terug" functionality |
| 8 | Customer lifetime value calculation | Mark | MEDIUM | Sum of won deals per client |
| 9 | Configurable lead sources in MVP | Mark | SHOULD | Default sources don't fit all industries |
| 10 | Duplicate merge functionality | Mark | LOW | Detected duplicates need merge capability |
| 11 | Team view / delegation for My Work | Annemarie | MEDIUM | Municipal teams need cross-visibility |
| 12 | Reporting: monthly/quarterly revenue | Mark | MEDIUM | Management meeting requires beyond KPI dashboard |

---

## Recommended Actions

### MUST (blocking for key user groups)

1. **Dutch B1-level language throughout** -- Create a terminology mapping table (Lead->Kans, Pipeline->Overzicht, Request->Verzoek, Stage->Fase, etc.) and require all user-facing text in plain Dutch. Without this, 5/8 personas cannot use the app. (Henk, Fatima, Jan-Willem, Mark, Annemarie)

2. **Add publiccode.yml** -- Non-negotiable for GEMMA Softwarecatalogus listing and Dutch government procurement. Takes half a day to create but unlocks the entire public sector market. (Annemarie, Priya)

3. **Ship OpenAPI 3.0 specification** -- External integrators cannot build against undocumented APIs. Required by NLGov API Design Rules ADR-01. Publish at `/api/v1/openapi.yaml`. (Priya, Annemarie, Noor)

4. **Resolve 3 data model contradictions** -- (a) stages embedded vs. separate objects, (b) lead stage as name vs. UUID reference, (c) pipeline entityType singular vs. array. These will cause expensive bugs post-implementation. (Priya)

5. **Add AVG/GDPR compliance requirements** -- Data retention policies, right to erasure documentation, soft delete with audit snapshot, export per data subject. PII stored indefinitely violates AVG. (Noor, Mark, Annemarie)

6. **Specify comprehensive audit logging** -- Exportable logs (CSV/JSON), configurable retention, IP/session tracking, filterable by date/user/action/entity, dedicated admin viewer. Required for BIO2/ENSIA compliance. (Noor, Annemarie, Priya)

### SHOULD (significant improvement for multiple personas)

1. **Add keyboard shortcuts specification** -- Ctrl+K for search, N for new, E for edit, Esc for close, J/K for list navigation. Include "?" key for shortcuts help dialog. (Sem, Mark)

2. **URL-based state management as cross-cutting requirement** -- All filters, search, pagination, sort, view toggle encoded in URL. Browser back/forward must work. Links must be shareable. (Sem, Priya, Mark)

3. **Move import/export to MVP tier** -- No adoption without ability to import existing client data and export for reporting. Include XLSX format alongside CSV. (Mark, Jan-Willem)

4. **Add bulk operations to all list views** -- Checkboxes, select-all, bulk assign/delete/export/status-change. Standard CRM feature missing entirely from specs. (Mark, Sem, Priya)

5. **Add business logic capabilities to OpenRegister** -- Status transitions, pipeline rules, and validation must be enforced server-side in OpenRegister (not in a separate Pipelinq API layer, and not only in frontend JS). OpenRegister should support configurable transition tables, field validation rules, and event hooks that consumer apps define through their register configurations. (Priya, Annemarie)

6. **Build VNG Klantinteracties/Verzoeken API endpoints** -- Without actual VNG-compatible endpoints, the VNG compatibility claim is aspirational. (Annemarie, Priya)

7. **Add GEMMA reference component mapping** -- Document that Pipelinq = Klantbeheercomponent + Verzoekafhandelcomponent. Essential for municipal enterprise architects. (Annemarie)

8. **Add progressive disclosure to forms** -- Show 3 essential fields by default, hide advanced fields behind "Meer opties". (Jan-Willem, Fatima, Henk)

9. **Add granular RBAC specification** -- Per-entity-type permissions (create/read/update/delete/export) with admin UI for permission overview. (Noor, Priya)

10. **Add webhook/event system** -- POST callbacks with signed payloads for status changes, stage moves, entity creation. Required for municipal system integration. (Priya, Mark, Annemarie)

### COULD (nice-to-have, improves specific persona experience)

1. **Dark mode / system theme support** -- Respect `prefers-color-scheme` media query; NL Design System tokens already support dark variants. (Sem, Fatima)

2. **Contract/offerte management entity** -- Add `contract` schema: title, client, start/end date, value, status, linked lead. Completes the sales lifecycle. (Mark)

3. **Onboarding wizard** -- Step-by-step guide in plain Dutch for first-time users. Replace empty dashboard with welcoming flow. (Jan-Willem, Fatima, Henk)

4. **Global search / command palette** -- Ctrl+K/Cmd+K to search across all entity types from anywhere. (Sem)

5. **FSC readiness specification** -- Federated Service Connectivity for inter-municipal data exchange. (Annemarie)

6. **Notes as first-class entity** -- Dedicated notes model per client/lead/request with search capability. (Mark, Jan-Willem)

7. **Mobile-responsive kanban alternative** -- List/card view for mobile devices where drag-and-drop is impractical. (Fatima, Jan-Willem)

8. **Skeleton loading states** -- Replace blank screens with layout-matching shimmer placeholders. (Sem)

---

## Potential OpenSpec Changes

These features could be turned into OpenSpec changes using `/opsx:new`:

| Change Name | Description | Related Personas | Estimated Complexity |
|-------------|-------------|-----------------|---------------------|
| `dutch-b1-terminology` | Create terminology mapping table and require all UI text in Dutch B1 level | Henk, Fatima, Jan-Willem, Mark, Annemarie | M |
| `publiccode-yml` | Add publiccode.yml and GEMMA reference component mapping | Annemarie, Priya | S |
| `openapi-specification` | Create complete OpenAPI 3.0 spec for all Pipelinq endpoints | Priya, Annemarie, Noor | L |
| `data-model-resolution` | Resolve 3 contradictions: stages, lead stage field, entityType | Priya | M |
| `avg-gdpr-compliance` | Add data retention, soft delete, right to erasure, export per subject | Noor, Annemarie | L |
| `audit-logging` | Comprehensive audit logging spec: export, retention, filtering, IP tracking | Noor, Annemarie, Priya | L |
| `keyboard-shortcuts` | Global shortcut map with help dialog | Sem, Mark | M |
| `url-state-management` | Cross-cutting URL state for filters, search, pagination, view toggles | Sem, Priya, Mark | M |
| `bulk-operations` | Bulk select/assign/delete/export on all list views | Mark, Sem, Priya | L |
| `import-export-mvp` | Move CSV/XLSX import and export from V1 to MVP tier | Mark, Jan-Willem | M |
| `openregister-business-logic` | Add configurable business rules to OpenRegister: transition tables, validation rules, event hooks/webhooks | Priya, Annemarie | XL |
| `vng-api-endpoints` | VNG Klantinteracties and Verzoeken compatible API endpoints | Annemarie, Priya | XL |
| `rbac-granular` | Per-entity-type CRUD+export permissions with admin overview UI | Noor, Priya | L |
| `webhook-events` | Webhook/event notification system for status changes and entity events | Priya, Mark, Annemarie | L |
| `progressive-disclosure` | Simplified forms with 3 essential fields and "Meer opties" expansion | Jan-Willem, Fatima, Henk | M |
| `onboarding-wizard` | Step-by-step first-time user experience in plain Dutch | Jan-Willem, Fatima, Henk | M |
| `dark-mode` | prefers-color-scheme support with NL Design dark tokens | Sem, Fatima | M |
| `contract-management` | New contract entity: title, client, dates, value, status, linked lead | Mark | L |
| `mobile-responsive` | Mobile-friendly kanban alternative and responsive layouts | Fatima, Jan-Willem, Henk | L |
| `nlgov-api-compliance` | RFC 7807 errors, Link header pagination, API versioning, HATEOAS | Priya, Annemarie | L |
