# Test Flow: Request Intake (Terugbelverzoek)

**App:** Pipelinq
**Pages:** `/apps/pipelinq/requests`, `/apps/pipelinq/requests/new`, `/apps/pipelinq/my-work`
**Priority:** High
**Tags:** crud, requests, intake, kcc
**Personas:** kcc-medewerker, teamleider
**Requires seed data:** Yes (request, client schemas)

## Preconditions
- Logged in as admin
- Pipelinq and OpenRegister apps enabled
- A client exists (from flow 03)

## Journey: Register a callback request (terugbelverzoek)

### 1. Create a new request
**Navigate to** `/apps/pipelinq/requests/new`

**Verify form fields:**
- [ ] Heading "New request" (h2)
- [ ] Title* (with validation)
- [ ] Description
- [ ] Status (combobox, default "new")
- [ ] Priority (combobox, default "normal")
- [ ] Channel (combobox "Select channel")
- [ ] Category (text)
- [ ] Requested at (date)
- [ ] Client (combobox "Select client")
- [ ] Pipeline / Stage (combobox pair)
- [ ] Create button disabled until Title filled

**Fill in:**
- Title: "Terugbelverzoek - vraag over WMO aanvraag"
- Description: "Burger wil teruggebeld worden over status WMO aanvraag"
- Status: "new" (default)
- Priority: select "high"
- Channel: select "phone" (or first available)
- Category: "WMO"
- Client: select existing client

**Click Create**

**Verify:**
- [ ] Request created successfully
- [ ] Redirected to detail or list

### 2. Verify request in list
**Navigate to** `/apps/pipelinq/requests`

**Verify:**
- [ ] Request "Terugbelverzoek - vraag over WMO aanvraag" visible
- [ ] Status shows "new"
- [ ] Priority shows "high"

### 3. Change request status
**Click on the request to open detail**
**Change status** from "new" to "open" or "in_progress"
**Save**

**Verify:**
- [ ] Status is updated
- [ ] Activity timeline shows the change

### 4. Verify in My Work
**Navigate to** `/apps/pipelinq/my-work`
**Click "Requests" filter**

**Verify:**
- [ ] Request appears in personal work queue
- [ ] Shows correct priority and status

### 5. Verify dashboard KPI updates
**Navigate to** `/apps/pipelinq/`

**Verify:**
- [ ] "Open Requests" KPI card shows count > 0
- [ ] "Requests by Status" section shows data

### 6. Complete the request
**Navigate back to request detail**
**Change status** to "completed"
**Save**

**Verify:**
- [ ] Status updated to completed
- [ ] Request no longer shows in "Open Requests" KPI
- [ ] My Work shows it only when "Show completed" is checked
