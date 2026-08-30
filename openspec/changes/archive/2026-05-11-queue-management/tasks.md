# Tasks: queue-management

## Group 1: Backend Queue Service [Enterprise]

### Task 1.1: Create QueueService for backend queue operations
- **spec_ref**: specs/queue-management/spec.md#queue-entity-crud, #queue-item-membership, #priority-based-queue-ordering
- **files**: lib/Service/QueueService.php
- **acceptance_criteria**:
  - GIVEN a queue UUID and register/schema config, WHEN getQueueDepth() is called, THEN it returns the count of items in that queue
  - GIVEN a queue with maxCapacity 50 and 50 items, WHEN isAtCapacity() is called, THEN it returns true
  - GIVEN a request UUID and queue UUID, WHEN assignToQueue() is called, THEN the request's queue field is updated via ObjectService
  - GIVEN a queue UUID, WHEN removeFromQueue() is called for a request, THEN the request's queue field is cleared
- [x] Done

### Task 1.2: Create QueueOverflowJob background job
- **spec_ref**: specs/queue-management/spec.md#queue-item-membership (capacity limit scenario)
- **files**: lib/BackgroundJob/QueueOverflowJob.php
- **acceptance_criteria**:
  - GIVEN a queue with overflowQueue set and items exceeding maxCapacity, WHEN the job runs, THEN excess items are moved to the overflow queue
  - GIVEN a queue without overflowQueue set, WHEN items exceed capacity, THEN the job logs a warning but takes no action
  - GIVEN all queues are within capacity, WHEN the job runs, THEN no items are moved
- [x] Done

### Task 1.3: Register QueueOverflowJob in info.xml
- **spec_ref**: design.md#backend
- **files**: appinfo/info.xml
- **acceptance_criteria**:
  - GIVEN the app manifest, WHEN Nextcloud reads background-jobs, THEN QueueOverflowJob is listed
- [x] Done

## Group 2: Unit Tests [Enterprise]

### Task 2.1: Unit tests for QueueService
- **spec_ref**: specs/queue-management/spec.md#queue-entity-crud, #queue-item-membership
- **files**: tests/Unit/Service/QueueServiceTest.php
- **acceptance_criteria**:
  - GIVEN the test suite, WHEN QueueServiceTest runs, THEN at least 5 test methods pass covering getQueueDepth, isAtCapacity, assignToQueue, removeFromQueue, and error handling
- [x] Done

### Task 2.2: Unit tests for QueueOverflowJob
- **spec_ref**: specs/queue-management/spec.md#queue-item-membership (capacity scenario)
- **files**: tests/Unit/BackgroundJob/QueueOverflowJobTest.php
- **acceptance_criteria**:
  - GIVEN the test suite, WHEN QueueOverflowJobTest runs, THEN at least 3 test methods pass covering overflow execution, no-overflow skip, and missing config skip
- [x] Done

## Group 3: Quality and Documentation [Enterprise]

### Task 3.1: Verify all quality checks pass
- **spec_ref**: design.md
- **files**: (all modified files)
- **acceptance_criteria**:
  - GIVEN the codebase, WHEN composer check:strict runs, THEN all checks pass (PHPCS, PHPMD, Psalm, PHPStan)
  - GIVEN the codebase, WHEN php -l is run on all new PHP files, THEN no syntax errors
- [x] Done
