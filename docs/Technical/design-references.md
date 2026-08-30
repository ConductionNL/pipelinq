# Pipelinq: Design References & Dashboard Wireframes

## 1. Design Inspiration Sources

### Dashboard / Landing Page
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Dribbble | Search "CRM dashboard" (800+ results) | KPI cards, deal funnels, revenue charts, activity feeds |
| Twenty CRM | twenty.com | Open-source, clean dashboard with pipeline charts and activity |
| Muzli / 99designs | Design galleries | Curated CRM dashboard collections with card-based layouts |
| Figma Community | Search "CRM dashboard kit" | Free component kits for deal metrics, contact lists |
| HubSpot Free CRM | hubspot.com/products/crm | Industry standard dashboard with pipeline summary |

### Pipeline / Kanban Board
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| HubSpot Pipeline Board | hubspot.com/knowledge/deal-boards | Customizable kanban with deal cards, drag-and-drop between stages |
| EspoCRM Kanban | espocrm.com | Open-source kanban with color-coded cards and stage columns |
| Nextcloud Deck | apps.nextcloud.com/apps/deck | Board/Stack/Card: familiar Nextcloud kanban UX |
| Trello | trello.com | Gold standard kanban: minimal cards, smooth drag-and-drop |
| Behance "CRM Pipeline UI" | Search Behance | Modern list + kanban toggle views |
| Dribbble "kanban board CRM" | Search Dribbble | Deal cards with avatars, amounts, probability badges |

### Lead Management
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| HubSpot Contacts | hubspot.com | Contact detail with timeline, associated deals |
| Twenty CRM | twenty.com | Clean lead list with inline editing, custom fields |
| Dribbble "lead management" | Search Dribbble | Lead scoring, source breakdown, conversion funnels |
| EspoCRM Lead view | espocrm.com | Lead form with pipeline/stage selector |

### My Work / Workload
| Source | URL / Search | Key Patterns |
|--------|-------------|--------------|
| Jira "My Work" | atlassian.com/jira | Assigned issues with priority sorting, project labels |
| Asana "My Tasks" | asana.com | Today/upcoming/later sections, personal task view |
| Dribbble "workload dashboard" | Search Dribbble | Personal productivity views with task counts |

---

## 2. Missing Features Identified from Design Patterns

Features not currently in FEATURES.md but commonly present in CRM dashboards and workflows:

### MVP Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Pipeline view toggle (kanban / list) | HubSpot, EspoCRM, Twenty | Users need both visual (kanban) and data-dense (list) views |
| Lead/request card quick actions | HubSpot, Salesforce | Change stage, assign, set priority without opening detail view |
| Priority levels on leads | All CRMs | Lead CRUD already has priority but no explicit visual priority badge feature |

### V1 Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Stale lead detection (no activity for X days) | Salesforce, HubSpot | Highlights forgotten leads with "last activity" indicator |
| Stage revenue summary (total value per column header) | HubSpot, Pipedrive | Pipeline kanban columns show sum of deal values |
| Pipeline funnel visualization | HubSpot, Salesforce dashboards | Visual funnel showing conversion between stages |
| Activity log on lead/request cards | Twenty, HubSpot | Compact recent activity (last call, last email, last note) |
| Aging indicator (days in current stage) | Salesforce, Pipedrive | Shows how long a lead has been in its current stage |

### Enterprise Additions
| Feature | Source Pattern | Justification |
|---------|--------------|---------------|
| Sales forecast summary | Salesforce, HubSpot | Weighted pipeline value (sum of value * probability) |
| Team pipeline comparison | Management dashboards | Compare pipeline performance across team members |
| Win/loss reason tracking | Salesforce, EspoCRM | Record why a lead was won or lost for analysis |

---

## 3. Dashboard Wireframes

### 3.1 Main Dashboard (Landing Page)

```
┌─────────────────────────────────────────────────────────────────────┐
│  PIPELINQ                                          [Search...] [+] │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────┤
│ Dashboard│ Pipeline │  Leads   │ Requests │ Clients  │   My Work    │
├──────────┴──────────┴──────────┴──────────┴──────────┴──────────────┤
│                                                                     │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐ ┌─────────────┐  │
│  │ OPEN LEADS  │ │ OPEN        │ │  PIPELINE   │ │   OVERDUE   │  │
│  │             │ │ REQUESTS    │ │  VALUE      │ │   ITEMS     │  │
│  │     12      │ │      8      │ │  €145,200   │ │      3      │  │
│  │  +2 today   │ │  +1 today   │ │  +€12k week │ │  ⚠ urgent   │  │
│  └─────────────┘ └─────────────┘ └─────────────┘ └─────────────┘  │
│                                                                     │
│  ┌────────────────────────────────┐ ┌──────────────────────────┐   │
│  │ Pipeline Funnel                │ │ Recent Activity          │   │
│  │                                │ │                          │   │
│  │  New          ████████████  5  │ │ • Lead "Acme Corp"       │   │
│  │  Contacted    ██████████    4  │ │   moved to Qualified     │   │
│  │  Qualified    ████████      3  │ │   2 min ago              │   │
│  │  Proposal     ██████        2  │ │                          │   │
│  │  Negotiation  ████          1  │ │ • Request #42 assigned   │   │
│  │  Won          ██            1  │ │   to Jan de Vries        │   │
│  │                                │ │   15 min ago             │   │
│  │  Conversion: 20%              │ │                          │   │
│  └────────────────────────────────┘ │ • New lead from website  │   │
│                                      │   "TechCorp inquiry"     │   │
│  ┌────────────────────────────────┐ │   1 hour ago             │   │
│  │ My Work (Top 5)               │ │                          │   │
│  │                                │ │ • Client "Gemeente XYZ"  │   │
│  │ ⚡ Lead: Gemeente ABC          │ │   updated phone number   │   │
│  │   Proposal · Due: 2 days      │ │   3 hours ago            │   │
│  │                                │ │                          │   │
│  │ 🔴 Request: IT support #12    │ │ [View all activity →]    │   │
│  │   In Progress · Overdue 1 day │ └──────────────────────────┘   │
│  │                                │                                │
│  │ ⚡ Lead: TechCorp deal         │                                │
│  │   Negotiation · Due: 5 days   │                                │
│  │                                │                                │
│  │ [View all my work →]          │                                │
│  └────────────────────────────────┘                                │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ Leads by Source                │ Requests by Status          │  │
│  │                                │                             │  │
│  │ Website    ████████████   40%  │ New          ████████   3   │  │
│  │ Referral   ████████       27%  │ In Progress  ██████████ 4  │  │
│  │ Phone      ██████         20%  │ Completed    ████       1   │  │
│  │ Campaign   ████           13%  │ Rejected     ██         1   │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.2 Pipeline / Kanban Board

```
┌─────────────────────────────────────────────────────────────────────┐
│  PIPELINQ > Sales Pipeline              [Kanban | List]  [Filter]  │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────┤
│ Dashboard│ Pipeline │  Leads   │ Requests │ Clients  │   My Work    │
├──────────┴──────────┴──────────┴──────────┴──────────┴──────────────┤
│                                                                     │
│ Pipeline: [Sales Pipeline ▾]          Show: [All ▾] [+ Add Lead]   │
│                                                                     │
│ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐  │
│ │ NEW      │ │CONTACTED │ │QUALIFIED │ │ PROPOSAL │ │NEGOTIATION│ │
│ │ 3 items  │ │ 2 items  │ │ 4 items  │ │ 2 items  │ │ 1 item   │  │
│ │ €12,500  │ │ €24,000  │ │ €58,000  │ │ €35,200  │ │ €15,500  │  │
│ │──────────│ │──────────│ │──────────│ │──────────│ │──────────│  │
│ │┌────────┐│ │┌────────┐│ │┌────────┐│ │┌────────┐│ │┌────────┐│  │
│ ││ LEAD   ││ ││ LEAD   ││ ││ LEAD   ││ ││ LEAD   ││ ││ LEAD   ││  │
│ ││Acme    ││ ││TechCo  ││ ││Gemeente││ ││BigDeal ││ ││FinalCo ││  │
│ ││Corp    ││ ││        ││ ││ABC     ││ ││BV      ││ ││        ││  │
│ ││€5,000  ││ ││€14,000 ││ ││€20,000 ││ ││€25,200 ││ ││€15,500 ││  │
│ ││📅 Mar 5││ ││📅 Mar 12││ ││📅 Mar 1 ││ ││📅 Mar 20││ ││📅 Mar 8 ││  │
│ ││👤 Jan  ││ ││👤 Maria ││ ││👤 Jan  ││ ││👤 Pieter││ ││👤 Maria ││  │
│ │└────────┘│ │└────────┘│ │└────────┘│ │└────────┘│ │└────────┘│  │
│ │┌────────┐│ │┌────────┐│ │┌────────┐│ │┌────────┐│ │          │  │
│ ││ REQ    ││ ││ LEAD   ││ ││ LEAD   ││ ││ REQ    ││ │          │  │
│ ││IT Help ││ ││SmallBiz││ ││WebDev  ││ ││Consult ││ │          │  │
│ ││#42     ││ ││        ││ ││project ││ ││#55     ││ │          │  │
│ ││⚡ high ││ ││€10,000 ││ ││€18,000 ││ ││⚡ urgent││ │          │  │
│ ││📅 Feb28││ ││📅 Mar 15││ ││📅 Mar 10││ ││📅 Mar 1 ││ │          │  │
│ ││🔴overdue││ ││👤 Jan  ││ ││👤 Maria ││ ││👤 Pieter││ │          │  │
│ │└────────┘│ │└────────┘│ │└────────┘│ │└────────┘│ │          │  │
│ │┌────────┐│ │          │ │┌────────┐│ │          │ │          │  │
│ ││ LEAD   ││ │          │ ││ LEAD   ││ │          │ │          │  │
│ ││NewLead ││ │          │ ││GovDeal ││ │          │ │          │  │
│ ││€2,500  ││ │          │ ││€15,000 ││ │          │ │          │  │
│ ││📅 Mar 20││ │          │ ││📅 Apr 1 ││ │          │ │          │  │
│ ││👤 -    ││ │          │ ││👤 Jan  ││ │          │ │          │  │
│ │└────────┘│ │          │ │└────────┘│ │          │ │          │  │
│ │          │ │          │ │┌────────┐│ │          │ │          │  │
│ │          │ │          │ ││ REQ    ││ │          │ │          │  │
│ │          │ │          │ ││Maint   ││ │          │ │          │  │
│ │          │ │          │ ││#38     ││ │          │ │          │  │
│ │          │ │          │ ││normal  ││ │          │ │          │  │
│ │          │ │          │ │└────────┘│ │          │ │          │  │
│ │ [+ Add] │ │ [+ Add]  │ │ [+ Add] │ │ [+ Add]  │ │ [+ Add]  │  │
│ └──────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘  │
│                                                                     │
│ ┌────────┐ ┌────────┐  ◀ Collapsed final stages                   │
│ │ WON  1 │ │ LOST 2 │                                              │
│ └────────┘ └────────┘                                              │
└─────────────────────────────────────────────────────────────────────┘
```

**Card anatomy:**
```
┌──────────────────┐
│ [LEAD] or [REQ]  │  ← Entity type badge (color-coded)
│ Entity Title     │  ← Title (clickable → detail view)
│ €12,500          │  ← Value (leads only)
│ ⚡ high          │  ← Priority badge (if not normal)
│ 📅 Mar 5  👤 Jan │  ← Due date + assignee avatar
│ 🔴 2 days overdue│  ← Overdue warning (if applicable)
│ ⏱ 5d in stage   │  ← Days in current stage
└──────────────────┘
```

### 3.3 Pipeline List View

```
┌─────────────────────────────────────────────────────────────────────┐
│  PIPELINQ > Sales Pipeline              [Kanban | List]  [Filter]  │
├──────────────────────────────────────────────────────────────────────┤
│                                                                     │
│ Pipeline: [Sales Pipeline ▾]     Show: [All ▾]  [+ Add Lead]       │
│                                                                     │
│ ┌───┬──────┬─────────────┬──────────┬──────────┬────────┬────────┐ │
│ │   │ Type │ Title       │ Stage    │ Value    │ Due    │Assigned│ │
│ ├───┼──────┼─────────────┼──────────┼──────────┼────────┼────────┤ │
│ │ ⚡│ LEAD │ Acme Corp   │ New      │ €5,000   │ Mar 5  │ Jan    │ │
│ │🔴│ REQ  │ IT Help #42 │ New      │ -        │ Feb 28 │ Jan    │ │
│ │   │ LEAD │ NewLead     │ New      │ €2,500   │ Mar 20 │ -      │ │
│ │   │ LEAD │ TechCo      │ Contacted│ €14,000  │ Mar 12 │ Maria  │ │
│ │   │ LEAD │ SmallBiz    │ Contacted│ €10,000  │ Mar 15 │ Jan    │ │
│ │   │ LEAD │ Gemeente ABC│ Qualified│ €20,000  │ Mar 1  │ Jan    │ │
│ │   │ LEAD │ WebDev proj │ Qualified│ €18,000  │ Mar 10 │ Maria  │ │
│ │   │ LEAD │ GovDeal     │ Qualified│ €15,000  │ Apr 1  │ Jan    │ │
│ │   │ REQ  │ Maint #38   │ Qualified│ -        │ -      │ -      │ │
│ │ ⚡│ LEAD │ BigDeal BV  │ Proposal │ €25,200  │ Mar 20 │ Pieter │ │
│ │ ⚡│ REQ  │ Consult #55 │ Proposal │ -        │ Mar 1  │ Pieter │ │
│ │   │ LEAD │ FinalCo     │ Negotiat.│ €15,500  │ Mar 8  │ Maria  │ │
│ └───┴──────┴─────────────┴──────────┴──────────┴────────┴────────┘ │
│                                                                     │
│  Showing 12 items · Total value: €145,200 · Weighted: €58,080      │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.4 Lead Detail View

```
┌─────────────────────────────────────────────────────────────────────┐
│  PIPELINQ > Leads > Acme Corp deal                    [Edit] [···] │
├──────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌──────────────────────────────┐ ┌──────────────────────────────┐ │
│  │ CORE INFO                    │ │ PIPELINE PROGRESS            │ │
│  │                              │ │                              │ │
│  │ Title:     Acme Corp deal    │ │ ● New                        │ │
│  │ Value:     €5,000            │ │ ● Contacted                  │ │
│  │ Probab.:   40%               │ │ ◉ Qualified  ← current      │ │
│  │ Source:    Website           │ │ ○ Proposal                   │ │
│  │ Priority:  Normal            │ │ ○ Negotiation                │ │
│  │ Category:  Consulting        │ │ ○ Won / Lost                 │ │
│  │                              │ │                              │ │
│  │ Expected close: 2026-03-05   │ │ [Move to Proposal →]        │ │
│  │ Created:       2026-02-10    │ │                              │ │
│  │ Days in stage: 5             │ │ Pipeline: Sales Pipeline     │ │
│  │                              │ └──────────────────────────────┘ │
│  │ CLIENT                       │                                  │
│  │ 🏢 Acme Corporation         │ ┌──────────────────────────────┐ │
│  │    info@acme.nl              │ │ ASSIGNED TO                  │ │
│  │    +31 20 555 0123           │ │                              │ │
│  │                              │ │ 👤 Jan de Vries              │ │
│  │ CONTACT                      │ │    [Reassign]                │ │
│  │ 👤 Petra Jansen              │ └──────────────────────────────┘ │
│  │    petra@acme.nl             │                                  │
│  │    Sales Manager             │                                  │
│  └──────────────────────────────┘                                  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ ACTIVITY TIMELINE                                [+ Add note]│  │
│  │                                                              │  │
│  │ Feb 20 · Stage changed to "Qualified"                        │  │
│  │           by Jan de Vries                                    │  │
│  │                                                              │  │
│  │ Feb 18 · Note added                                          │  │
│  │           "Had a great call with Petra. They need consulting │  │
│  │            for their digital transformation project."        │  │
│  │           by Jan de Vries                                    │  │
│  │                                                              │  │
│  │ Feb 15 · Stage changed to "Contacted"                        │  │
│  │           by Jan de Vries                                    │  │
│  │                                                              │  │
│  │ Feb 10 · Lead created                                        │  │
│  │           Source: Website · by system                         │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.5 My Work View

```
┌─────────────────────────────────────────────────────────────────────┐
│  PIPELINQ > My Work                              [Filter ▾] [Sort] │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────┤
│ Dashboard│ Pipeline │  Leads   │ Requests │ Clients  │   My Work    │
├──────────┴──────────┴──────────┴──────────┴──────────┴──────────────┤
│                                                                     │
│  Showing: [All ▾]  Leads (5) · Requests (3)          8 items total  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ 🔴 OVERDUE                                                   │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [REQ] IT Support #42          ⚡ HIGH    🔴 1 day over │  │  │
│  │ │ Stage: In Progress · Service Pipeline                  │  │  │
│  │ │ Due: Feb 28, 2026                                      │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [LEAD] Gemeente ABC deal      ⚡ HIGH   🔴 2 days over │  │  │
│  │ │ Stage: Qualified · Sales Pipeline · €20,000            │  │  │
│  │ │ Expected close: Mar 1, 2026                            │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ DUE THIS WEEK                                                │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [LEAD] Acme Corp deal                         normal   │  │  │
│  │ │ Stage: Qualified · Sales Pipeline · €5,000             │  │  │
│  │ │ Expected close: Mar 5, 2026  (3 days)                  │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [LEAD] FinalCo negotiation                    normal   │  │  │
│  │ │ Stage: Negotiation · Sales Pipeline · €15,500          │  │  │
│  │ │ Expected close: Mar 8, 2026  (6 days)                  │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ UPCOMING                                                     │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [LEAD] TechCo inquiry                         normal   │  │  │
│  │ │ Stage: Contacted · Sales Pipeline · €14,000            │  │  │
│  │ │ Expected close: Mar 12, 2026                           │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [REQ] Consultation request #55    ⚡ URGENT            │  │  │
│  │ │ Status: In Progress · Service Pipeline                 │  │  │
│  │ │ No due date                                            │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [LEAD] SmallBiz starter          normal                │  │  │
│  │ │ Stage: Contacted · Sales Pipeline · €10,000            │  │  │
│  │ │ Expected close: Mar 15, 2026                           │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ [REQ] Maintenance request #38               normal     │  │  │
│  │ │ Status: New · Service Pipeline                         │  │  │
│  │ │ No due date                                            │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.6 Client Detail View

```
┌─────────────────────────────────────────────────────────────────────┐
│  PIPELINQ > Clients > Acme Corporation               [Edit] [···]  │
├──────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌──────────────────────────────┐ ┌──────────────────────────────┐ │
│  │ CLIENT INFO                  │ │ SUMMARY                      │ │
│  │                              │ │                              │ │
│  │ 🏢 Acme Corporation         │ │ Open leads:    2  (€25,000)  │ │
│  │    Organization              │ │ Open requests: 1             │ │
│  │                              │ │ Won leads:     3  (€42,000)  │ │
│  │ 📧 info@acme.nl             │ │ Total value:   €67,000       │ │
│  │ 📞 +31 20 555 0123          │ │                              │ │
│  │ 🌐 www.acme.nl              │ │ Last activity: 2 days ago    │ │
│  │ 📍 Keizersgracht 100        │ │ Client since:  Jan 2025      │ │
│  │    1015 AA Amsterdam         │ └──────────────────────────────┘ │
│  │                              │                                  │
│  │ KVK: 12345678               │                                  │
│  └──────────────────────────────┘                                  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ CONTACT PERSONS                                   [+ Add]    │  │
│  │                                                              │  │
│  │ 👤 Petra Jansen · Sales Manager · petra@acme.nl             │  │
│  │ 👤 Mark de Groot · CTO · mark@acme.nl                      │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌───────────────────┐ ┌────────────────────────────────────────┐  │
│  │ LEADS             │ │ REQUESTS                               │  │
│  │                   │ │                                        │  │
│  │ • Acme Corp deal  │ │ • IT Support #42  [In Progress] 🔴    │  │
│  │   Qualified €5k   │ │ • Consultation #55 [New]              │  │
│  │                   │ │                                        │  │
│  │ • Acme expansion  │ │                                        │  │
│  │   New €20k        │ │                                        │  │
│  │                   │ │                                        │  │
│  │ [View all →]      │ │ [View all →]                          │  │
│  └───────────────────┘ └────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ ACTIVITY TIMELINE                                            │  │
│  │                                                              │  │
│  │ Feb 22 · Lead "Acme Corp deal" moved to Qualified            │  │
│  │ Feb 20 · Request #42 created                                 │  │
│  │ Feb 18 · Note: "Great meeting with Petra..." by Jan          │  │
│  │ Feb 10 · Lead "Acme Corp deal" created                       │  │
│  │ Jan 15 · Client created                                      │  │
│  │                                                              │  │
│  │ [Load more...]                                               │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### 3.7 Admin Settings

```
┌─────────────────────────────────────────────────────────────────────┐
│  Administration > Pipelinq                                          │
├──────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ PIPELINES                                    [+ Add Pipeline] │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │ ★ Sales Pipeline (default)           7 stages  [Edit] │  │  │
│  │ │   Entities: Leads                                      │  │  │
│  │ │   Stages: New → Contacted → Qualified → Proposal →     │  │  │
│  │ │           Negotiation → Won → Lost                     │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  │                                                              │  │
│  │ ┌────────────────────────────────────────────────────────┐  │  │
│  │ │   Service Pipeline                   5 stages  [Edit] │  │  │
│  │ │   Entities: Requests                                   │  │  │
│  │ │   Stages: New → In Progress → Completed → Rejected →   │  │  │
│  │ │           Converted to Case                            │  │  │
│  │ └────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ LEAD SOURCES                            [+ Add Source]       │  │
│  │                                                              │  │
│  │   Website · Email · Phone · Referral · Partner ·             │  │
│  │   Campaign · Social Media · Event · Other                    │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │ REQUEST CHANNELS                        [+ Add Channel]      │  │
│  │                                                              │  │
│  │   Phone · Email · Website · Counter · Post                   │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```
