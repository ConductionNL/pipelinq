# Test Flow: Lead-to-Pipeline Journey

**App:** Pipelinq
**Pages:** `/apps/pipelinq/leads`, `/apps/pipelinq/pipeline`, `/apps/pipelinq/my-work`
**Priority:** High
**Tags:** crud, leads, pipeline, kanban, my-work
**Personas:** sales-rep, sales-manager
**Requires seed data:** Yes (lead, pipeline, client schemas)

## Preconditions
- Logged in as admin
- Pipelinq and OpenRegister apps enabled
- A client "Gemeente Tilburg" exists (from flow 03)
- A pipeline with stages exists (or create one first)

## Journey: Create a lead, track it through the pipeline

### 1. Create a pipeline (if none exists)
**Navigate to** `/apps/pipelinq/pipeline`

**Verify:**
- [ ] Heading "Pipeline" (h2)
- [ ] Pipeline selector combobox visible
- [ ] Kanban/List view toggle buttons
- [ ] Pipeline settings button

**If "No pipeline selected" empty state:**
- Click "New pipeline" in sidebar
- Create pipeline "Verkoopproces" with stages: Nieuw, Gekwalificeerd, Offerte, Onderhandeling, Gewonnen, Verloren
- Save

### 2. Create a new lead
**Navigate to** `/apps/pipelinq/leads/new`

**Verify form fields:**
- [ ] Title* (with validation "Title is required")
- [ ] Description
- [ ] Value (EUR) (spinbutton)
- [ ] Probability % (spinbutton)
- [ ] Source (combobox)
- [ ] Priority (combobox, default "normal")
- [ ] Expected close date
- [ ] Client (combobox)
- [ ] Pipeline (combobox)
- [ ] Stage (disabled until pipeline selected)
- [ ] Create button disabled until Title filled

**Fill in:**
- Title: "Zaaksysteem implementatie Tilburg"
- Description: "Implementatie procest voor gemeente Tilburg"
- Value: 50000
- Probability: 60
- Priority: select "high"
- Client: select "Gemeente Tilburg"
- Pipeline: select "Verkoopproces"
- Stage: select "Nieuw" (now enabled)

**Click Create**

**Verify:**
- [ ] Lead is created successfully
- [ ] Redirected to lead detail or list

### 3. Verify lead appears in list
**Navigate to** `/apps/pipelinq/leads`

**Verify:**
- [ ] Lead "Zaaksysteem implementatie Tilburg" visible in table
- [ ] Value shows EUR 50.000
- [ ] Priority shows "high"

### 4. Verify lead appears on pipeline kanban
**Navigate to** `/apps/pipelinq/pipeline`
**Select pipeline** "Verkoopproces"

**Click "Kanban view"**

**Verify:**
- [ ] Kanban columns visible for each stage
- [ ] Lead card appears in "Nieuw" column
- [ ] Card shows title and client name

### 5. Verify lead appears in My Work
**Navigate to** `/apps/pipelinq/my-work`

**Verify:**
- [ ] Heading "My Work" (h2) visible
- [ ] Filter buttons: All, Leads, Requests
- [ ] "Show completed" checkbox visible
- [ ] Lead appears in the list (if assigned to current user)

**Click "Leads" filter**

**Verify:**
- [ ] Only leads shown (no requests)

### 6. Verify dashboard KPIs update
**Navigate to** `/apps/pipelinq/`

**Verify:**
- [ ] "Open Leads" KPI card shows count > 0
- [ ] "Pipeline Value" shows EUR > 0
