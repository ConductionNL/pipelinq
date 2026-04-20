# Test Flow: Dashboard Overview

**App:** Pipelinq
**Page:** `/apps/pipelinq/`
**Priority:** Critical
**Tags:** smoke, dashboard, kpi
**Personas:** all

## Preconditions
- Logged in as admin
- Pipelinq and OpenRegister apps enabled

## Steps

### 1. Dashboard loads with correct structure
**Navigate to** `/apps/pipelinq/`

**Verify:**
- [ ] Heading "Dashboard" (h2) is visible
- [ ] No "Internal Server Error" or blank page
- [ ] Sidebar navigation is visible with 8 items

### 2. Quick-create buttons are present
- [ ] "New Lead" button visible and clickable
- [ ] "New Request" button visible and clickable
- [ ] "New Client" button visible and clickable
- [ ] "Refresh dashboard" button visible

### 3. KPI cards display and link correctly
**Verify 4 KPI cards:**
- [ ] "Open Leads" — links to `/apps/pipelinq/leads?status=open`
- [ ] "Open Requests" — links to `/apps/pipelinq/requests?status=open`
- [ ] "Pipeline Value" — links to `/apps/pipelinq/pipeline`
- [ ] "Overdue" — links to `/apps/pipelinq/leads?overdue=true`
- [ ] Each card shows a count or "No items found"

### 4. Dashboard sections are present
- [ ] "Requests by Status" (h3) with chart or "No requests yet"
- [ ] "My Work" (h3) with items or "No items assigned to you"
- [ ] "Client Overview" (h3) with data or "No clients yet"

### 5. Quick-create buttons navigate to forms
**Click "New Client"** — verify navigates to `/apps/pipelinq/clients/new`
**Go back, click "New Lead"** — verify navigates to `/apps/pipelinq/leads/new`
**Go back, click "New Request"** — verify navigates to `/apps/pipelinq/requests/new`
