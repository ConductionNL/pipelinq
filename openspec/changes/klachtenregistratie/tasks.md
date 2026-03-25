# Klachtenregistratie — Tasks

## Section 1: Backend Services

### Task 1.1: Create ComplaintSlaService [MVP]
- **Spec ref**: REQ-KL-009
- **Files**: `lib/Service/ComplaintSlaService.php`
- **Acceptance**: Service calculates SLA deadlines from app config, returns null when no SLA configured
- [x] Create `ComplaintSlaService` with `calculateDeadline()`, `getSlaHoursForCategory()`, `isOverdue()`
- [x] Register in DI container (auto-wired via Nextcloud DI)

### Task 1.2: Create ComplaintSlaJob background job [MVP]
- **Spec ref**: REQ-KL-010
- **Files**: `lib/BackgroundJob/ComplaintSlaJob.php`
- **Acceptance**: Job runs periodically, checks open complaints for SLA violations, logs overdue
- [x] Create `ComplaintSlaJob` extending `TimedJob`
- [x] Register in `appinfo/info.xml` background-jobs section

## Section 2: Unit Tests

### Task 2.1: Unit tests for ComplaintSlaService [MVP]
- **Spec ref**: REQ-KL-009
- **Files**: `tests/Unit/Service/ComplaintSlaServiceTest.php`
- **Acceptance**: 3+ test methods covering deadline calculation, missing config, overdue detection
- [ ] Test `calculateDeadline()` with configured hours
- [ ] Test `calculateDeadline()` returns null for unconfigured category
- [ ] Test `isOverdue()` with past/future deadlines

### Task 2.2: Unit tests for ComplaintSlaJob [MVP]
- **Spec ref**: REQ-KL-010
- **Files**: `tests/Unit/BackgroundJob/ComplaintSlaJobTest.php`
- **Acceptance**: 2+ test methods covering job execution and no-op when no overdue complaints
- [ ] Test job runs without error
- [ ] Test job logs overdue complaints

## Section 3: Client Detail Integration

### Task 3.1: Verify client detail complaints section [V1]
- **Spec ref**: REQ-KL-007
- **Files**: `src/views/clients/ClientDetail.vue`
- **Acceptance**: Client detail shows complaint history with links
- [ ] Verify or add complaints section to client detail view
- [ ] Add "Register complaint" button linking to complaint form with pre-linked client
