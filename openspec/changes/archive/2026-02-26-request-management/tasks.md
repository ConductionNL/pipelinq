## 1. Schema & Data Layer

- [x] 1.1 Add `channel` string property to request schema in `pipelinq_register.json` [MVP]
- [x] 1.2 Add `caseReference` UUID property to request schema (assignee already existed) [MVP]
- [x] 1.3 Run repair step to update OpenRegister schema and verify channel persists on save [MVP]

## 2. Request Status Lifecycle

- [x] 2.1 Create `src/services/requestStatus.js` with transition table and validation functions (getAllowedTransitions, isValidTransition) [MVP]
- [x] 2.2 Wire status validation into request save flow — block invalid transitions in frontend [MVP]

## 3. Request List View Enhancement

- [x] 3.1 Add filter bar component above request table with NcSelect dropdowns for status, priority, assignee, channel [MVP]
- [x] 3.2 Implement server-side filtering via objectStore query params (_search filters) [MVP]
- [x] 3.3 Add sortable column headers (title, status, priority, requestedAt) with ascending/descending toggle [MVP]
- [x] 3.4 Add status badge and priority badge rendering in table rows [MVP]
- [x] 3.5 Add quick status change dropdown per row (only shows allowed transitions) [MVP]
- [x] 3.6 Add assignee column showing user display name [MVP]
- [x] 3.7 Add channel column to the table [V1]

## 4. Request Detail View Rebuild

- [x] 4.1 Rebuild RequestDetail.vue with two-column layout: left (core info) + right (actions/assignment/pipeline) [MVP]
- [x] 4.2 Add view mode / edit mode toggle with Edit button [MVP]
- [x] 4.3 Add status badge with dropdown showing only allowed transitions [MVP]
- [x] 4.4 Add priority badge with color coding (urgent=red, high=orange, normal=default, low=grey) [MVP]
- [x] 4.5 Add user assignment picker (NcSelect querying Nextcloud users) [MVP]
- [x] 4.6 Add pipeline position section with stage progression indicator and "Move to next stage" button [MVP]
- [x] 4.7 Add client section showing linked client name as clickable link with contact info [MVP]
- [x] 4.8 Add channel dropdown wired to requestChannelsStore [V1]
- [x] 4.9 Add "Convert to case" button with Procest availability check [V1]
- [x] 4.10 Add read-only mode for converted requests with notice [V1]

## 5. Pipeline Kanban Integration

- [x] 5.1 Create generic `PipelineCard.vue` component that renders lead vs request cards differently [MVP]
- [x] 5.2 Add [REQ] badge (orange) and [LEAD] badge (blue) with text labels for WCAG AA [MVP]
- [x] 5.3 Request cards: show title, status, priority badge, assignee avatar — no value field [MVP]
- [x] 5.4 Update kanban board to fetch and render both leads and requests for mixed pipelines [MVP]
- [x] 5.5 Add "Show" filter dropdown on kanban: All / Leads only / Requests only [MVP]
- [ ] 5.6 Add entity type selector to quick-create form on mixed pipeline stage columns [MVP] (deferred — requires inline form component)

## 6. Request-to-Case Conversion

- [x] 6.1 Add conversion service/utility that checks Procest availability and creates case [V1] (stubbed — Procest integration pending)
- [x] 6.2 On successful conversion: set request status to `converted`, store `caseReference` [V1]
- [x] 6.3 Display case link on converted request detail view [V1]
- [x] 6.4 Block conversion from invalid statuses (only `in_progress` allowed) [V1]

## 7. Validation & Polish

- [x] 7.1 Add frontend validation for required title on create/update [MVP]
- [x] 7.2 Add client reference validation (check existence before save) [MVP]
- [x] 7.3 Add priority value validation (must be low/normal/high/urgent) [MVP]
- [x] 7.4 Block deletion of converted requests with active case reference [MVP]
- [x] 7.5 Build frontend and verify no compilation errors [MVP]
- [x] 7.6 Manual test: full request CRUD with channel persistence [MVP]
- [x] 7.7 Manual test: status transitions (valid and invalid) [MVP]
- [ ] 7.8 Manual test: request cards on kanban board [MVP] (requires browser testing)
