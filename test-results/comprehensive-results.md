# Pipelinq Comprehensive Browser Test Results

**Date:** 2026-02-27
**Environment:** http://localhost:8080/index.php/apps/pipelinq
**Browser:** Playwright Chromium (headless, 1920x1080)
**Auth:** admin/admin (already authenticated session)

---

## 1. Login / App Load

- **Status:** PASS
- **Screenshot:** `screenshots/login-complete.png`
- **Notes:** App loads directly without requiring login (session persisted). Title shows "Pipelinq - Nextcloud". The Pipelinq sidebar navigation renders correctly with all 8 main menu items and footer settings section. The default route lands on `/#/dashboard`.

---

## 2. Dashboard (`/#/dashboard`)

- **Status:** PASS
- **Screenshot:** `screenshots/dashboard.png`
- **Notes:**
  - **KPI Cards:** 4 cards render correctly:
    - Open Leads: 0
    - Open Requests: 0
    - Pipeline Value: EUR 0
    - Overdue: 0
  - **Quick Actions:** 3 buttons present: "+ New Lead", "+ New Request", "+ New Client"
  - **Refresh:** Refresh dashboard button present (circular arrow icon)
  - **Sections:** "Requests by Status" (shows "No requests yet") and "My Work" (shows "No items assigned to you")
  - **Welcome message:** "Welcome to Pipelinq! Get started by creating your first client, lead, or request using the buttons above."
  - **Sidebar:** All 8 navigation items visible: Dashboard, Clients, Contacts, Leads, Requests, Pipeline, My Work, Documentation. Footer shows "Instellingen" (Settings) button.

---

## 3. Clients (`/#/clients`)

- **Status:** PASS
- **Screenshot:** `screenshots/clients.png`
- **Notes:**
  - **Header:** "Clients" heading renders correctly
  - **Action buttons:** "Import from Contacts" and "New client" buttons present in top-right
  - **Filters:** Search text input and "All types" dropdown filter present
  - **Empty state:** Shows "No clients yet" with "Create your first client" CTA button
  - **Console warning:** `[NcSelect] An inputLabel or ...` -- minor accessibility warning about NcSelect missing `inputLabel` prop

---

## 4. Contacts (`/#/contacts`)

- **Status:** PASS
- **Screenshot:** `screenshots/contacts.png`
- **Notes:**
  - **Header:** "Contacts" heading renders correctly
  - **Action button:** "New contact" button present in top-right
  - **Search:** Search text input present
  - **Empty state:** Shows "No contacts yet" with "Create your first contact" CTA button
  - No additional errors specific to this page

---

## 5. Leads (`/#/leads`)

- **Status:** PASS
- **Screenshot:** `screenshots/leads.png`
- **Notes:**
  - **Header:** "Leads" heading renders correctly
  - **Action button:** "New lead" button present in top-right
  - **Filters:** Search text input, "All stages" dropdown, "All sources" dropdown
  - **Empty state:** Shows "No leads yet" with "Create first lead" CTA button
  - **Console warnings:** 2x `[NcSelect] An inputLabel` warnings for the filter dropdowns

---

## 6. Requests (`/#/requests`)

- **Status:** PARTIAL
- **Screenshot:** `screenshots/requests.png`
- **Notes:**
  - **Header:** "Requests" heading renders correctly
  - **Action button:** "New request" button present in top-right
  - **Filters:** Search text input, Status dropdown, Priority dropdown, Channel dropdown
  - **Empty state:** Shows "No requests found"
  - **API Error:** `Error fetching request collection` with HTTP 404 response. The request collection API endpoint returns a server error. The page still renders gracefully with the "No requests found" message rather than crashing.
  - **Console warnings:** 3x `[NcSelect] An inputLabel` warnings for the filter dropdowns

---

## 7. Pipeline (`/#/pipeline`)

- **Status:** PASS
- **Screenshot:** `screenshots/pipeline.png`
- **Notes:**
  - **Header:** "Pipeline" heading renders correctly
  - **Pipeline selector:** "Select pipeline" dropdown in top-right corner
  - **View toggles:** Kanban board view and list view toggle buttons present (two icons in top-right)
  - **Empty state:** Shows "Select a pipeline to view the board" (appropriate since no pipeline is selected)
  - **Kanban elements:** 7 kanban-related DOM elements detected, indicating the board structure is ready
  - **Console warning:** 1x `[NcSelect] An inputLabel` warning

---

## 8. My Work (`/#/my-work`)

- **Status:** PASS
- **Screenshot:** `screenshots/my-work.png`
- **Notes:**
  - **Header:** "My Work" heading renders correctly
  - **Filter tabs:** "All", "Leads", "Requests" tab buttons present
  - **Toggle:** "Show completed" checkbox present
  - **Empty state:** Shows "No items assigned to you"
  - No additional errors specific to this page

---

## 9. Pipelines (`/#/pipelines`) -- Footer Settings

- **Status:** PARTIAL
- **Screenshot:** `screenshots/pipelines.png`
- **Notes:**
  - **Header:** "Pipelines" heading renders correctly
  - **Action button:** "Add pipeline" button present
  - **Pipeline list:** Shows 2 configured pipelines:
    - **Sales Pipeline** (Leads): 7 stages -- New -> Contacted -> ... -> Won -> Lost
    - **Service Requests** (Requests): 5 stages -- New -> In Progress -> Completed -> Rejected -> Converted to Case
  - **BUG - Duplicate entries:** The pipeline list renders multiple duplicate copies of the same 2 pipelines. The screenshot shows the list repeating many times, suggesting a rendering/reactivity bug in the pipeline list component.
  - **Console warnings:** ~48x `[WARN] @nextcloud/vue: You need to fill ...` warnings, likely related to NcListItem components missing required props. This high volume of warnings correlates with the duplicate rendering issue.

---

## 10. Configuration (`/#/settings`) -- Footer Settings

- **Status:** PARTIAL
- **Screenshot:** `screenshots/configuration.png`
- **Notes:**
  - **Header:** "Pipelinq" heading renders correctly
  - **Documentation link:** Present
  - **Register Status:** Shows "Connected" with "Register: pipelinq (5)"
  - **Schemas table:** Correctly displays 5 schemas with Name, ID, and Status:
    - Client (28), Contact (29), Lead (30), Request (31), Pipeline (32)
    - All show green status indicators
  - **Pipelines section:** Same duplicate rendering bug as the Pipelines page
  - **Lead Sources:** "+ Add Source" button, with removable source tags (x buttons)
  - **Request Channels:** "+ Add Channel" button, with removable channel tags (x buttons)
  - **Actions:** "Re-import configuration" and "Save" buttons present
  - **Console warnings:** Same high volume of NcListItem warnings as Pipelines page

---

## 11. Admin Settings (`/settings/admin/pipelinq`)

- **Status:** PARTIAL
- **Screenshot:** `screenshots/admin-settings.png`
- **Notes:**
  - **Page title:** "Pipelinq - Beheerder instellingen - Nextcloud"
  - **Header:** "Pipelinq" heading with "Documentation" link
  - **Register Status:** Shows "Connected" with "Register: pipelinq (5)"
  - **Schemas table:** Same 5 schemas as in-app Configuration page, all with green status
  - **Pipelines section:** Shows loading spinner and "No pipelines configured" state with "Create first pipeline" CTA -- different behavior from in-app settings, possibly due to API context differences
  - **API Error:** `Error fetching pipeline collection` -- the settings page JS (`pipelinq-settings.js`) fails to fetch pipelines, hence the empty state
  - **Lead Sources:** Shows source tags: campaign, email, event, other, partner, phone, referral, social_media, website
  - **Request Channels:** Section visible (scrolled below viewport)
  - **Actions:** "Re-import configuration" and "Save" buttons present
  - Sidebar shows full Nextcloud admin settings navigation with "Pipelinq" selected at the bottom

---

## 12. Documentation (sidebar link)

- **Status:** CANNOT_TEST
- **Notes:** The Documentation sidebar link opens an external URL (pipelinq.app). This was not navigated to during testing to avoid leaving the test environment.

---

## Console Errors Summary

### Pipelinq-Specific Errors

| Error | Pages Affected | Severity |
|-------|---------------|----------|
| `@nextcloud/vue: appName not set` | All pages | Low -- cosmetic warning about library configuration |
| `@nextcloud/vue: appVersion not set` | All pages | Low -- cosmetic warning about library configuration |
| `[NcSelect] An inputLabel or ...` | Clients, Leads, Requests, Pipeline | Low -- accessibility warning, missing inputLabel prop on NcSelect components |
| `Error fetching request collection` (404) | Requests | Medium -- API endpoint returns 404, but page handles gracefully |
| `Error fetching pipeline collection` | Admin Settings | Medium -- pipelines fail to load in admin settings context |
| `@nextcloud/vue: You need to fill ...` (x48+) | Pipelines, Configuration | Medium -- likely related to duplicate rendering bug |

### Non-Pipelinq Errors (from other apps)

| Error | Source | Notes |
|-------|--------|-------|
| `Error fetching case/caseType/statusType/task collection` | Procest | Procest dashboard widget errors, unrelated to Pipelinq |

---

## Summary Table

| # | Page | Route | Status | Screenshot | Key Issues |
|---|------|-------|--------|------------|------------|
| 1 | Login/Load | `/apps/pipelinq/` | PASS | `login-complete.png` | None |
| 2 | Dashboard | `/#/dashboard` | PASS | `dashboard.png` | None -- all KPIs, actions, sections render |
| 3 | Clients | `/#/clients` | PASS | `clients.png` | Minor NcSelect a11y warning |
| 4 | Contacts | `/#/contacts` | PASS | `contacts.png` | None |
| 5 | Leads | `/#/leads` | PASS | `leads.png` | Minor NcSelect a11y warnings |
| 6 | Requests | `/#/requests` | PARTIAL | `requests.png` | API 404 on request collection fetch; page handles gracefully |
| 7 | Pipeline | `/#/pipeline` | PASS | `pipeline.png` | None -- kanban board structure ready, view toggle present |
| 8 | My Work | `/#/my-work` | PASS | `my-work.png` | None |
| 9 | Pipelines | `/#/pipelines` | PARTIAL | `pipelines.png` | Duplicate pipeline entries rendered (rendering bug); many NcListItem warnings |
| 10 | Configuration | `/#/settings` | PARTIAL | `configuration.png` | Same duplicate pipeline rendering bug; otherwise fully functional |
| 11 | Admin Settings | `/settings/admin/pipelinq` | PARTIAL | `admin-settings.png` | Pipeline fetch error; shows "No pipelines configured" despite pipelines existing in-app |
| 12 | Documentation | External link | CANNOT_TEST | N/A | External link to pipelinq.app |

---

## Overall Assessment

**7 PASS / 4 PARTIAL / 0 FAIL / 1 CANNOT_TEST**

The Pipelinq app is largely functional. All core pages load and render their primary UI elements correctly (headings, buttons, filters, empty states). The main issues found are:

1. **Pipeline list duplicate rendering (Medium):** Both the `/#/pipelines` and `/#/settings` pages render the pipeline list with many duplicate entries of the same 2 pipelines (Sales Pipeline and Service Requests). This appears to be a Vue reactivity/rendering bug.

2. **Request collection API 404 (Medium):** The `/#/requests` page encounters a 404 when fetching the request collection. The page handles this gracefully showing "No requests found" instead of crashing.

3. **Admin Settings pipeline fetch failure (Medium):** The admin settings page at `/settings/admin/pipelinq` fails to load pipelines (different API context than the in-app settings), showing "No pipelines configured" with a loading spinner.

4. **NcSelect accessibility warnings (Low):** Multiple NcSelect components across Clients, Leads, Requests, and Pipeline pages are missing the `inputLabel` prop, generating Vue warnings.

5. **NcListItem prop warnings (Low):** The pipeline list items generate many `@nextcloud/vue: You need to fill ...` warnings, potentially related to the duplicate rendering issue.
