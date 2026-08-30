# Test Flow: Pipeline Configuration and Kanban

**App:** Pipelinq
**Pages:** `/apps/pipelinq/pipeline`, `/apps/pipelinq/pipelines`
**Priority:** High
**Tags:** pipeline, kanban, settings, stages
**Personas:** sales-manager
**Requires seed data:** Yes (pipeline schema)

## Preconditions
- Logged in as admin
- Pipelinq and OpenRegister apps enabled

## Journey: Configure and use the sales pipeline

### 1. Pipeline page structure
**Navigate to** `/apps/pipelinq/pipeline`

**Verify:**
- [ ] Heading "Pipeline" (h2) visible
- [ ] Pipeline selector combobox ("Select pipeline")
- [ ] Kanban view button
- [ ] List view button
- [ ] Pipeline settings button (gear icon)
- [ ] Sidebar with "Details" tab (selected) and "Stages" tab

### 2. Empty state
**If no pipelines exist:**
- [ ] Sidebar shows "No pipeline selected"
- [ ] Message: "Select a pipeline from the dropdown or create a new one."
- [ ] "New pipeline" button visible in sidebar

### 3. Create pipeline via sidebar
**Click "New pipeline"**
**Fill in pipeline details** (name, description)
**Save**

**Verify:**
- [ ] Pipeline created
- [ ] Pipeline selector now shows the new pipeline
- [ ] Stages tab becomes relevant

### 4. Add stages to pipeline
**Click "Stages" tab in sidebar**
**Add stages:** Nieuw → Gekwalificeerd → Offerte → Gewonnen → Verloren

**Verify:**
- [ ] Each stage appears in the sidebar list
- [ ] Stages are ordered correctly

### 5. Kanban view renders columns
**Select the pipeline from dropdown**
**Click "Kanban view"**

**Verify:**
- [ ] One column per stage
- [ ] Column headers show stage names
- [ ] If leads exist in pipeline, cards appear in correct columns

### 6. List view renders table
**Click "List view"**

**Verify:**
- [ ] Table replaces kanban
- [ ] Same data shown in tabular format

### 7. Pipeline settings
**Click "Pipeline settings" button**

**Verify:**
- [ ] Settings panel or page opens
- [ ] Can manage pipeline stages, colors, etc.
