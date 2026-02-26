# Pipelinq — Feature Analysis & Product Strategy

## Executive Summary

There is **no production-ready native Nextcloud CRM**. The market has SaaS CRMs with data sovereignty issues (Salesforce, HubSpot), standalone self-hosted CRMs with integration burden (SuiteCRM, EspoCRM, Twenty), and Dutch government API specifications without usable frontends (OpenKlant). Pipelinq fills all three gaps by being sovereign, integrated, and government-ready.

**Key insight**: CRM is fundamentally about communication, documents, scheduling, and contact management — all things Nextcloud already does. A Nextcloud-native CRM orchestrates these capabilities rather than rebuilding them.

## 1. Competitive Landscape

### Nextcloud Ecosystem

| Name | Status | Approach |
|------|--------|----------|
| **Nextcloud Contacts** | Bundled, active | Address book (vCard/CardDAV), not a CRM |
| **SuiteCRM Integration** | Available | Dashboard widget bridging to external SuiteCRM |
| **CiviCRM Integration** | Available | Thin connector to external CiviCRM |
| **nextcloud-CRM (lasagne20)** | Early stage | Experimental, not production-ready |

**Finding**: No mature native CRM exists. All solutions are integrations with external systems.

### Self-Hosted Open Source

| Name | Positioning | Strengths | Weaknesses |
|------|------------|-----------|------------|
| **SuiteCRM** | Enterprise CRM (Salesforce alt) | Full feature set, workflows, campaigns | Dated UX, heavy, separate deployment |
| **EspoCRM** | Lightweight modern CRM | Clean UI, REST API, BPM workflows | No Nextcloud integration, separate infra |
| **Twenty** | Modern CRM (dev-first) | Beautiful UI, custom objects, AI features | TypeScript/React stack, no gov features |
| **Monica** | Personal relationship manager | Simple, focused | Not a business CRM, no workflows |
| **Vtiger** | All-in-one CRM+helpdesk | 200+ extensions, inventory mgmt | PHP monolith, complex |
| **CiviCRM** | Nonprofit CRM | Donor/grant/volunteer management | CMS-dependent, niche audience |

### Enterprise SaaS

| Name | Price/user/mo | Strengths | Why Not |
|------|--------------|-----------|---------|
| **Salesforce** | $25-300 | Market leader, AI, ecosystem | Data sovereignty, vendor lock-in, cost |
| **HubSpot** | Free-$3,600/mo | Great UX, inbound marketing | US jurisdiction, escalating costs |
| **Dynamics 365** | $65+ | Microsoft integration, Gov cloud | Complex, expensive, M365 dependency |
| **Zoho** | $14-52 | Affordable, full suite | SaaS-only, limited gov compliance |

### Dutch Government

| Name | Type | Status |
|------|------|--------|
| **OpenKlant** | VNG Klantinteracties implementation | Active (Maykin B.V., 4 municipalities) |
| **VNG Klantinteracties** | API specification | Pre-1.0, deprioritized since mid-2024 |
| **VNG Verzoeken API** | API specification | Part of ZGW family |

## 2. Feature Matrix

### Contact Management

| Feature | Tier | Justification |
|---------|------|---------------|
| Client CRUD (person + organization) | **MVP** | Core entity |
| Client list with search, sort, filters | **MVP** | Navigation |
| Client detail view with activity timeline | **MVP** | Critical UX pattern |
| Contact person CRUD linked to clients | **MVP** | Relationship management |
| Nextcloud Contacts sync (IManager) | **MVP** | Avoid duplicate contact entry |
| Duplicate detection (name/email match) | **V1** | Data quality |
| Import (CSV/vCard) | **V1** | Onboarding and migration |
| Export (CSV/vCard/PDF) | **V1** | Reporting and portability |
| Contact segmentation/tags | **V1** | Grouping and targeting |
| Contact merge | **V1** | Data cleanup |
| Hierarchical organizations (parent/child) | **Enterprise** | Government org structures |
| BSN/KVK number lookup | **Enterprise** | Dutch gov identity verification |

### Request/Lead Management

| Feature | Tier | Justification |
|---------|------|---------------|
| Request CRUD with status lifecycle | **MVP** | Core workflow |
| Request list with status filters | **MVP** | Workflow overview |
| Request detail view with client link | **MVP** | Record inspection |
| Priority levels (low/normal/high/urgent) | **MVP** | Triage |
| Request-to-case conversion (bridge to Procest) | **V1** | Core government workflow |
| Kanban/pipeline board view | **MVP** | Visual workflow management (moved from V1) |
| Assignment to user/team | **V1** | Workload distribution |
| Category/product classification | **V1** | Request routing |
| Channel tracking (phone/email/web/counter) | **V1** | Omnichannel analysis |
| Configurable pipeline stages | **Enterprise** | Workflow flexibility |
| SLA tracking (response/resolution time) | **Enterprise** | Service quality |
| Automated assignment rules | **Enterprise** | Scale operations |

### Communication & Collaboration

| Feature | Tier | Justification |
|---------|------|---------------|
| Internal notes on entities (ICommentsManager) | **MVP** | Collaboration basics |
| Shared contact views (multi-user access) | **MVP** | Team CRM |
| Talk integration (per-client/request chat, IBroker) | **V1** | Unique differentiator |
| Calendar integration (follow-ups, ICalendarEventBuilder) | **V1** | No forgotten follow-ups |
| Activity stream (publish CRM events, IManager) | **V1** | Unified timeline |
| Notifications (assignment, status change) | **V1** | Immediate feedback |
| User mentions in notes | **V1** | Team collaboration |
| Shared folders per client (Files) | **V1** | Document management |
| Email logging (link Mail messages) | **V1** | Communication history |
| Email templates | **Enterprise** | Standardized comms |
| Mass email/campaigns | **Enterprise** | Marketing |

### Lead Management

| Feature | Tier | Justification |
|---------|------|---------------|
| Lead CRUD with sales fields (value, probability, close date) | **MVP** | Core sales entity |
| Lead list with search, sort, filters | **MVP** | Navigation |
| Lead detail view with activity timeline | **MVP** | Critical UX pattern |
| Lead source tracking (website, referral, campaign, etc.) | **MVP** | Marketing attribution |
| Lead assignment to users | **MVP** | Workload distribution |
| Lead-to-won/lost lifecycle (via pipeline stages) | **MVP** | Sales tracking |
| Lead import (CSV) | **V1** | Onboarding and migration |
| Lead export (CSV) | **V1** | Reporting and portability |
| Lead scoring/rating | **Enterprise** | Qualification automation |
| Automated lead assignment rules | **Enterprise** | Scale operations |

### Pipeline & Kanban

| Feature | Tier | Justification |
|---------|------|---------------|
| Configurable pipelines (admin creates boards) | **MVP** | Core kanban workflow |
| Pipeline stages (ordered columns with drag-and-drop) | **MVP** | Visual workflow management |
| Default Sales Pipeline (New→Contacted→Qualified→Proposal→Negotiation→Won/Lost) | **MVP** | Out-of-box usability |
| Default Service Pipeline (New→In Progress→Completed/Rejected/Converted) | **MVP** | Out-of-box usability |
| Mixed entity pipelines (leads + requests on same board) | **MVP** | Unified workflow view |
| Pipeline view toggle (kanban / list) | **MVP** | Users need both visual kanban and data-dense list views |
| Lead/request quick actions on cards (move stage, assign) | **MVP** | Common CRM pattern: change stage without opening detail |
| Stage probability mapping (auto-populates lead probability) | **V1** | Sales forecasting |
| Pipeline analytics (conversion rates, stage duration) | **V1** | Management visibility |
| Pipeline funnel visualization (dashboard chart) | **V1** | Visual conversion between stages |
| Stage revenue summary (total value per column header) | **V1** | Quick pipeline value at-a-glance |
| Stale lead detection (no activity for X days) | **V1** | Highlights forgotten leads |
| Aging indicator (days in current stage) | **V1** | Shows how long a lead has been stuck |
| Multiple pipelines per team | **V1** | Team-specific workflows |
| Pipeline templates | **Enterprise** | Standardized board setup |
| Automation on stage change (notifications, field updates) | **Enterprise** | Workflow automation |
| Sales forecast summary (weighted pipeline value) | **Enterprise** | Sum of value * probability across pipeline |
| Win/loss reason tracking | **Enterprise** | Record why leads were won or lost for analysis |

### My Work (Werkvoorraad)

| Feature | Tier | Justification |
|---------|------|---------------|
| Personal workload view (my leads, my requests) | **MVP** | Productivity essential |
| Sort by priority and due date | **MVP** | Task prioritization |
| Filter by entity type (leads, requests) | **MVP** | Focused views |
| Cross-app workload (include Procest tasks) | **V1** | Unified work queue |
| Overdue item highlighting | **V1** | Proactive management |
| Workload analytics (items per user) | **Enterprise** | Management visibility |

### Admin Settings

| Feature | Tier | Justification |
|---------|------|---------------|
| Nextcloud admin settings page | **MVP** | App configuration |
| Pipeline management (CRUD) | **MVP** | Core configuration |
| Stage management per pipeline | **MVP** | Workflow customization |
| Default pipeline selection | **MVP** | Out-of-box experience |
| Lead source configuration | **V1** | Customizable values |
| Request channel configuration | **V1** | Customizable values |
| Priority label/color customization | **Enterprise** | Visual customization |

### Reporting & Analytics

| Feature | Tier | Justification |
|---------|------|---------------|
| Dashboard with counts (clients, open requests) | **MVP** | At-a-glance overview |
| Request status distribution chart | **MVP** | Visual status overview |
| List/table export (CSV) | **V1** | Data portability |
| Dashboard with KPI cards | **V1** | Management visibility |
| Custom report builder | **Enterprise** | Flexible analytics |
| Charts/graphs (funnel, trends) | **Enterprise** | Visual analytics |

### Security & Compliance

| Feature | Tier | Justification |
|---------|------|---------------|
| RBAC via OpenRegister | **MVP** | Access control |
| Audit trail (who changed what) | **MVP** | Accountability |
| WCAG AA compliance | **MVP** | Government requirement |
| GDPR data export (right of access) | **V1** | EU compliance |
| GDPR data deletion (right to erasure) | **V1** | EU compliance |
| NL Design System theming | **V1** | Government visual compliance |
| Data retention policies | **Enterprise** | Compliance automation |
| Field-level access control | **Enterprise** | Sensitive data protection |

### Integration

| Feature | Tier | Justification |
|---------|------|---------------|
| File attachments (IRootFolder) | **V1** | Document management |
| VNG Klantinteracties API mapping | **V1** | Dutch gov interop |
| VNG Verzoeken API mapping | **V1** | Dutch gov interop |
| External REST API | **V1** | OpenRegister provides this |
| Nextcloud Flows automation | **Enterprise** | Low-code triggers |
| Webhook support | **Enterprise** | External integration |
| Federated client sharing | **Enterprise** | Cross-org CRM |

### Customization

| Feature | Tier | Justification |
|---------|------|---------------|
| Configurable list columns | **V1** | UI flexibility |
| Custom fields (OpenRegister schema) | **V1** | Organization-specific needs |
| Saved views/filters | **V1** | User productivity |
| Custom dashboards | **Enterprise** | Personalized views |
| Public intake form | **Enterprise** | Citizen-facing intake |

## 3. Gap Analysis

### What Competitors Do Well

- **Enterprise SaaS**: Mature UX, AI features, ecosystem, mobile apps
- **Self-hosted OSS**: Full data ownership, no licensing costs, customizable
- **Dutch gov tools**: Direct VNG API alignment, Common Ground compatibility

### What They Lack

| Gap | Opportunity for Pipelinq |
|-----|--------------------------|
| No native collaboration platform | Chat, files, calendar, contacts are separate systems in all competitors |
| No federation/cross-org sharing | Only Pipelinq can share client data across municipalities via Nextcloud federation |
| Integration tax | Competitors need separate connectors for every tool; Pipelinq gets them free |
| No request-to-case flow | VNG Verzoek-to-Zaak is government-specific; no competitor implements natively |
| No NL Design System theming | No competitor supports Dutch government design tokens |
| Data locked in CRM silo | Pipelinq data on OpenRegister is reusable by other apps |

### Nextcloud-Native Advantages

| Capability | Why Competitors Cannot Match It |
|------------|-------------------------------|
| Zero-cost collaboration stack | Would need 5+ separate tool integrations |
| Federated cross-org CRM | Requires federation protocol; no CRM has this |
| Design token theming | NL Design System via nldesign app is Nextcloud-specific |
| Data platform reuse | OpenRegister objects shared across Procest, OpenCatalogi, etc. |
| Air-gapped deployment | SaaS CRMs cannot function without internet |
| Virtual calendar provider | CRM deadlines in user's calendar without sync |
| Talk rooms per CRM entity | Built-in real-time chat; no competitor has this |

## 4. Strategic Positioning

### Positioning Statement

**Pipelinq is the CRM that lives where your team already works.** Built natively into Nextcloud, it turns your existing collaboration platform into a client relationship system — with contacts, calendar, files, and chat already connected.

### Differentiation Strategy

Three pillars:

1. **Platform leverage** — Every Nextcloud feature (AI, workflows, federation) automatically benefits Pipelinq
2. **Government-first** — VNG standard alignment, NL Design System, GDPR-by-architecture, Common Ground
3. **Data platform** — OpenRegister makes CRM data reusable across apps, not locked in a silo

### Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Feature gap vs mature CRMs | High | Focus MVP on core workflows; don't try to match Salesforce |
| User familiarity with existing tools | High | Polish UX from day one; provide migration tooling |
| VNG standard instability | Medium | International standards primary; VNG as thin mapping layer |
| Small team | High | Thin client architecture minimizes backend code; leverage OpenRegister |
| OpenRegister dependency | Medium | Actively developed, used by multiple apps |

## 5. Recommended Feature Set Summary

### MVP (29 features)

Replace spreadsheets and basic contact lists for small teams. Includes full pipeline/kanban support from day one.

**Client & Contact Management**
1. Client CRUD (person + organization)
2. Client list with search, sort, filters
3. Client detail view with activity timeline
4. Contact person CRUD linked to clients

**Lead Management**
5. Lead CRUD with sales fields (value, probability, close date)
6. Lead list with search, sort, filters
7. Lead detail view with activity timeline
8. Lead source tracking
9. Lead assignment to users

**Request Management**
10. Request CRUD with status lifecycle
11. Request list with status filters
12. Request detail view with client link

**Pipeline & Kanban**
13. Configurable pipelines (admin creates boards)
14. Pipeline stages with drag-and-drop
15. Default Sales Pipeline
16. Default Service Pipeline
17. Mixed entity pipelines (leads + requests on same board)
18. Pipeline view toggle (kanban / list)
19. Lead/request quick actions on cards

**My Work & Dashboard**
20. My Work view (personal workload: my leads, my requests)
21. Dashboard with counts and status distribution

**Admin Settings**
22. Nextcloud admin settings page
23. Pipeline management (CRUD)
24. Stage management per pipeline
25. Default pipeline selection

**Platform**
26. Nextcloud Contacts sync (read/write via IManager)
27. Notes on entities (via ICommentsManager)
28. RBAC via OpenRegister
29. Audit trail, WCAG AA, English + Dutch localization

### V1 (29 additional features)

Compete with EspoCRM/SuiteCRM for government teams.

30. Stage probability mapping (auto-populates lead probability)
31. Pipeline analytics (conversion rates, stage duration)
32. Pipeline funnel visualization (dashboard chart)
33. Stage revenue summary (total value per column header)
34. Stale lead detection (no activity for X days)
35. Aging indicator (days in current stage)
36. Multiple pipelines per team
37. Cross-app My Work (include Procest tasks)
38. Overdue item highlighting
39. Calendar integration (follow-ups, deadlines)
40. File attachments on entities
41. Talk integration (per-client/request chat)
42. Activity stream publishing
43. Notifications (assignment, status change)
44. Import/Export (CSV, vCard) for leads, clients, contacts
45. Duplicate detection
46. Contact segmentation (tags)
47. Saved views/filters
48. Request-to-case conversion (Procest bridge)
49. VNG Klantinteracties API mapping
50. VNG Verzoeken API mapping
51. Lead source configuration (admin)
52. Request channel configuration (admin)
53. Channel tracking
54. GDPR export + deletion
55. NL Design System theming
56. Email logging
57. Configurable list columns
58. Custom fields

### Enterprise (20 additional features)

Large municipalities and multi-organization deployments.

59. Federated client sharing
60. Pipeline templates
61. Automation on stage change
62. Nextcloud Flows automation
63. Automated lead assignment rules
64. Lead scoring/rating
65. Sales forecast summary (weighted pipeline value)
66. Win/loss reason tracking
67. Workload analytics
68. SLA management
69. BSN/KVK number lookup
70. Hierarchical organizations
71. Custom report builder
72. Dashboard customization
73. Data retention policies
74. Field-level access control
75. Webhook support
76. Public intake form
77. Contact merge + bulk operations
78. Multi-language (beyond EN/NL)
