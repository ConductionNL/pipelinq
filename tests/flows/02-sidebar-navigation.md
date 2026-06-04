# Test Flow: Sidebar Navigation

**App:** Pipelinq
**Page:** all pages
**Priority:** Critical
**Tags:** navigation, sidebar, settings
**Personas:** all

## Preconditions
- Logged in as admin
- Pipelinq app enabled

## Steps

### 1. All navigation items visible
**Navigate to** `/apps/pipelinq/`

**Verify sidebar contains these items in order:**
- [ ] Dashboard (link to `/apps/pipelinq/`)
- [ ] Clients (link to `/apps/pipelinq/clients`)
- [ ] Contacts (link to `/apps/pipelinq/contacts`)
- [ ] Leads (link to `/apps/pipelinq/leads`)
- [ ] Requests (link to `/apps/pipelinq/requests`)
- [ ] Products (link to `/apps/pipelinq/products`)
- [ ] Pipeline (link to `/apps/pipelinq/pipeline`)
- [ ] My Work (link to `/apps/pipelinq/my-work`)
- [ ] Documentation (link to `#`, placeholder)

### 2. Settings expands sub-menu
**Click Settings button** (bottom of sidebar)

**Verify sub-menu appears with:**
- [ ] "Pipelines" (link to `/apps/pipelinq/pipelines`)
- [ ] "Configuration" (link to `#`, placeholder)

### 3. Navigation works for each item
**Click each sidebar link and verify:**
- [ ] Clients → URL contains `/clients`, shows Cards/Table toggle
- [ ] Leads → URL contains `/leads`, shows Cards/Table toggle
- [ ] Pipeline → URL contains `/pipeline`, shows pipeline selector
- [ ] My Work → URL contains `/my-work`, shows All/Leads/Requests filters
- [ ] Dashboard → URL is `/apps/pipelinq/`, shows KPI cards
