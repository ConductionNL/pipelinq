---
status: in-progress
---

# marketing-ui Specification

**OpenSpec changes**: [marketing-segments-ui-repair](../../changes/marketing-segments-ui-repair/) _(in progress)_ — mounts `SegmentBuilder.vue` into a Segments page and adds a Templates page, both reachable from the Marketing menu; removes the `@e2e exclude` on "Segment Builder UI Composes Rule Trees" now that the component is reachable.

## Purpose
Provides the marketing blast user interface: a SegmentBuilder for visually composing AND/OR rule trees with live validation and member-size estimates, a BlastForm wizard that walks the marketer through name, segment, template, channel, schedule, and A/B and gates sending on compliance, and a BlastMonitor that polls for real-time send progress, totals, and events and can cancel a sending blast.
## Requirements
### Requirement: Segment Builder UI Composes Rule Trees

`src/components/SegmentBuilder.vue` and `src/components/SegmentRuleNode.vue` are mounted by `SegmentFormView` (`src/views/segments/SegmentForm.vue`), reachable at `/segments/new` (`SegmentNew`) and `/segments/:id` (`SegmentEdit`), both linked from the Marketing menu's Segments entry (marketing-segments-ui-repair, pipelinq#773). Both scenarios below are exercised end to end by `tests/e2e/spec-coverage/marketing.spec.ts` ("the Segment builder blocks save on an invalid predicate, then validates and estimates once fixed").

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

### Requirement: Segments and Templates Pages Are Reachable From the Marketing Menu

The Marketing menu group SHALL list Segments and Templates ahead of Blasts
and Blast performance. The Segments page SHALL be a declarative `type:
"index"` page over the `segment` schema whose Add action and row action both
navigate to a custom `SegmentFormView` page (`SegmentNew` / `SegmentEdit`,
one component, edit mode driven by a route `:id` param) that mounts
SegmentBuilder. The Templates page SHALL be a declarative `type: "index"`
page over the `campaignTemplate` schema whose Add action and row action both
navigate to a custom `TemplateFormView` page (`TemplateNew` / `TemplateEdit`)
whose fields are conditional on the selected channel (email adds subject,
sender, reply-to and footer fields; SMS does not).

#### Scenario: Marketing menu lists Segments and Templates first

- **GIVEN** a user with Pipelinq access opens the Marketing menu group
- **THEN** the menu SHALL list, in order: Segments, Templates, Blasts, Blast performance

#### Scenario: Creating a segment from the Segments page

- **GIVEN** a marketer on the Segments index page
- **WHEN** they choose "New segment"
- **THEN** they SHALL land on `SegmentFormView`, choose an audience (contact or customer), compose a rule tree with SegmentBuilder, and SHALL NOT be able to save until the tree is valid

#### Scenario: Template save surfaces a compliance error as a field error

- **GIVEN** a marketer on the Templates New page for an email channel
- **WHEN** they submit a body with no `{{unsubscribe_link}}` token
- **THEN** the page SHALL call `POST /api/templates`, which rejects the save, and SHALL render the returned error against the body field rather than only a page-level banner

