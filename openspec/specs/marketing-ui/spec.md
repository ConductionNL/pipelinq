---
status: done
---

# marketing-ui Specification

## Purpose
Provides the marketing blast user interface: a SegmentBuilder for visually composing AND/OR rule trees with live validation and member-size estimates, a BlastForm wizard that walks the marketer through name, segment, template, channel, schedule, and A/B and gates sending on compliance, and a BlastMonitor that polls for real-time send progress, totals, and events and can cancel a sending blast.
## Requirements
### Requirement: Segment Builder UI Composes Rule Trees

@e2e exclude UNWIRED COMPONENT — reported as a product bug, not worked around. `src/components/SegmentBuilder.vue` and `src/components/SegmentRuleNode.vue` are imported by NOTHING: the only occurrence of the identifier anywhere outside those two files is a prose comment at src/registry.js:228, so no page, route or registry entry mounts them and no browser can reach the rule-tree editor at all. The rules the component would enforce are asserted at the service boundary by tests/Unit/Service/SegmentServiceTest.php (testValidateRulesRejectsOperatorIncompatibleWithFieldType, testValidateRulesRejectsUnknownField, testValidateRulesRejectsUnsupportedOperator, testEstimateSizeReturnsMatchingCount), and the same validate-then-estimate contract is proven end to end over HTTP by tests/e2e/spec-coverage/marketing.spec.ts ("POST /api/segments validates the rule tree before saving"). This exclusion should be revisited the moment the component is mounted.

The SegmentBuilder Vue component SHALL allow marketers to construct rule
trees visually using AND/OR logic with leaf predicates, validate them, and
show a live size estimate before commit.

#### Scenario: Visual rule tree with live validation

- **GIVEN** a marketer opens SegmentBuilder for entityType "contact"
- **WHEN** they add a predicate with an invalid operator for the field type
- **THEN** the component SHALL display a field-level error and disable save until resolved

#### Scenario: Live size estimate shown

- **GIVEN** a valid rule tree
- **WHEN** the rules change
- **THEN** the component SHALL show "Estimated members: N" from a debounced backend call

### Requirement: Blast Creation Wizard Gates on Compliance

The BlastForm Vue component SHALL walk the marketer through name → segment →
template → channel → schedule → A/B and SHALL check compliance before send.

#### Scenario: Missing-consent modal on send

@e2e exclude the modal is raised only when the compliance preflight returns a non-empty missing-contacts list for the chosen segment, and the send it guards dispatches through openconnector, which the CI instance does not install (.github/workflows/code-quality.yml pins `additional-apps` to openregister only) — so the branch cannot be entered in a browser run. The preflight that decides it is asserted by tests/Unit/Service/ComplianceServiceTest.php (testCheckSegmentComplianceMissingContacts, testPreflightBlastReturnsValidWhenAllChecksPass) and tests/Unit/Service/BlastServiceTest.php (testSendBlastQueuesCompliantSkipsNonCompliant).

- **GIVEN** a segment with contacts lacking email consent
- **WHEN** the marketer attempts to send
- **THEN** the form SHALL show a modal listing missing contacts with options "Skip and send", "Request consent", "Cancel"

#### Scenario: Email template validated before save

- **GIVEN** an email channel blast
- **WHEN** the selected template is checked
- **THEN** the form SHALL call the template validation endpoint and surface errors for missing unsubscribe token or address

### Requirement: Live Send Monitor

The BlastMonitor Vue component SHALL show real-time send progress with live
counts and an event timeline.

#### Scenario: Progress bar and totals update by polling

- **GIVEN** a Blast mid-send
- **WHEN** BlastMonitor is open
- **THEN** it SHALL poll `GET /api/blasts/:id` every 2 seconds, update the progress bar and totals grid, prepend new events to the timeline, and stop polling when status is "sent" or "failed"

#### Scenario: Cancel a sending blast

- **GIVEN** a Blast with status "sending"
- **WHEN** the marketer clicks "Cancel send"
- **THEN** the component SHALL POST `/api/blasts/:id/cancel` and show a cancelling state

