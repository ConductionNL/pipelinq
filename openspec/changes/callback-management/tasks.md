# Tasks: callback-management

## 1. Backend Services

- [ ] 1.1 Create CallbackService with attempt logging, claim validation, status transitions, and threshold checks
  - acceptance_criteria: addAttempt appends to attempts array; isAttemptThresholdReached returns true at 3+ attempts; validateClaim checks IGroupManager membership; validateStatusTransition enforces allowed transitions
  - spec_ref: specs/callback-management/spec.md#Requirement: Callback Service
  - files: lib/Service/CallbackService.php

- [ ] 1.2 Update task schema in pipelinq_register.json with callback-specific properties
  - acceptance_criteria: task schema includes callbackPhoneNumber, preferredTimeSlot, attempts, completedAt, resultText properties
  - spec_ref: specs/callback-management/spec.md#Requirement: Register Schema Update for Callbacks
  - files: lib/Settings/pipelinq_register.json

## 2. API Controller

- [ ] 2.1 Create CallbackController with attempt, claim, complete, and reassign endpoints
  - acceptance_criteria: POST /api/callbacks/{id}/attempts logs attempt; POST /api/callbacks/{id}/claim claims task; POST /api/callbacks/{id}/complete marks afgerond; POST /api/callbacks/{id}/reassign updates assignment
  - spec_ref: specs/callback-management/spec.md#Requirement: Callback Controller API
  - files: lib/Controller/CallbackController.php, appinfo/routes.php

## 3. Background Jobs

- [ ] 3.1 Create CallbackOverdueJob for overdue callback detection and notification
  - acceptance_criteria: Runs every 15 minutes; detects overdue terugbelverzoek tasks; sends notification via NotificationService; skips already-notified tasks within 24h window
  - spec_ref: specs/callback-management/spec.md#Requirement: Callback Overdue Check Job
  - files: lib/BackgroundJob/CallbackOverdueJob.php, appinfo/info.xml

## 4. Unit Tests

- [ ] 4.1 Write unit tests for CallbackService
  - acceptance_criteria: Tests for addAttempt, isAttemptThresholdReached, validateClaim (eligible and ineligible), validateStatusTransition (valid and invalid)
  - spec_ref: specs/callback-management/spec.md#Requirement: Callback Service
  - files: tests/Unit/Service/CallbackServiceTest.php

- [ ] 4.2 Write unit tests for CallbackController
  - acceptance_criteria: Tests for attempt, claim, complete, reassign endpoints with mocked dependencies
  - spec_ref: specs/callback-management/spec.md#Requirement: Callback Controller API
  - files: tests/Unit/Controller/CallbackControllerTest.php

- [ ] 4.3 Write unit tests for CallbackOverdueJob
  - acceptance_criteria: Tests for run method with configured and unconfigured register; tests for skip-already-notified logic
  - spec_ref: specs/callback-management/spec.md#Requirement: Callback Overdue Check Job
  - files: tests/Unit/BackgroundJob/CallbackOverdueJobTest.php

## 5. Documentation and i18n

- [ ] 5.1 Add English and Dutch translations for callback-related strings
  - acceptance_criteria: All user-facing strings in CallbackController use t('pipelinq', '...'); l10n/en.json and l10n/nl.json updated
  - spec_ref: specs/callback-management/spec.md
  - files: l10n/en.json, l10n/nl.json
