---
status: in-progress
---

# marketing-ui Specification

**OpenSpec changes**: [marketing-segments-ui-repair](../../changes/marketing-segments-ui-repair/) _(in progress)_ — mounts `SegmentBuilder.vue` into a Segments page and adds a Templates page, both reachable from the Marketing menu; removes the `@e2e exclude` on "Segment Builder UI Composes Rule Trees" now that the component is reachable.

## Purpose
Provides the marketing blast user interface: a SegmentBuilder for visually composing AND/OR rule trees with live validation and member-size estimates, a BlastForm wizard that walks the marketer through name, segment, template, channel, schedule, and A/B and gates sending on compliance, and a BlastMonitor that polls for real-time send progress, totals, and events and can cancel a sending blast.

## Requirements

### Requirement: Segment Builder UI Composes Rule Trees

`src/components/SegmentBuilder.vue` and `src/components/SegmentRuleNode.vue` are mounted by `SegmentFormDialog` (`src/dialogs/SegmentFormDialog.vue`), the modal the Segments index page opens from its Add action and its row Edit action (marketing-segments-ui-repair, pipelinq#773). Both scenarios below are exercised end to end by `tests/e2e/spec-coverage/marketing.spec.ts` ("the Segment builder holds save until the rules are complete and valid, then estimates").

The SegmentBuilder Vue component SHALL allow marketers to construct rule
trees visually using AND/OR logic with leaf predicates, validate them, and
show a live size estimate before commit.

#### Scenario: Visual rule tree with live validation

- **GIVEN** a marketer opens SegmentBuilder for entityType "contact"
- **WHEN** a predicate is unfinished, or the backend validator rejects it
- **THEN** the component SHALL disable save until resolved, and SHALL display a rejection as an error on the predicate it names

#### Scenario: Live size estimate shown

- **GIVEN** a valid rule tree
- **WHEN** the rules change
- **THEN** the component SHALL show "Estimated members: N" from a debounced backend call

### Requirement: Blast Creation Wizard Gates on Compliance

The new-blast wizard (`BlastWizardDialog`, a modal the Blasts index page's Add
opens) SHALL walk the marketer through basics (name and channel) → audience →
content (template) → delivery (transport, connector source, schedule) → A/B →
review, and SHALL check compliance before send. The channel comes before the
template because the templates offered are the channel's own.

#### Scenario: Missing-consent modal on send

@e2e exclude the modal is raised only when the compliance preflight returns a non-empty missing-contacts list for the chosen segment, and the send it guards dispatches through openconnector, which the CI instance does not install (.github/workflows/code-quality.yml pins `additional-apps` to openregister only) — so the branch cannot be entered in a browser run. The preflight that decides it is asserted by tests/Unit/Service/ComplianceServiceTest.php (testCheckSegmentComplianceMissingContacts, testPreflightBlastReturnsValidWhenAllChecksPass) and tests/Unit/Service/BlastServiceTest.php (testSendBlastQueuesCompliantSkipsNonCompliant).

- **GIVEN** a segment with contacts lacking email consent
- **WHEN** the marketer attempts to send
- **THEN** the wizard SHALL show a modal listing missing contacts with options "Skip and send", "Request consent", "Cancel"

#### Scenario: Email template validated before save

- **GIVEN** an email channel blast
- **WHEN** the selected template is checked
- **THEN** the wizard SHALL call the template validation endpoint and surface errors for missing unsubscribe token or address

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
"index"` page over the `segment` schema whose Add action and row Edit action
both open `SegmentFormDialog`, a modal in the index page's `form-dialog` slot
that mounts SegmentBuilder and saves through `POST` / `PATCH /api/segments`.
The index has no row selection, and a row click opens nothing.
The Templates page SHALL be a declarative `type: "index"` page over the
`campaignTemplate` schema whose Add action and row Edit action both open
`TemplateFormDialog`, a modal in the index page's `form-dialog` slot that
saves through `POST` / `PATCH /api/templates`, and whose fields are
conditional on the selected channel (email adds subject, sender, reply-to and
footer fields; SMS does not). That index likewise has no row selection, and
a row click opens nothing.

#### Scenario: Marketing menu lists Segments and Templates first

- **GIVEN** a user with Pipelinq access opens the Marketing menu group
- **THEN** the menu SHALL list, in order: Segments, Templates, Blasts, Blast performance

#### Scenario: Creating a segment from the Segments page

- **GIVEN** a marketer on the Segments index page
- **WHEN** they choose "New segment"
- **THEN** a `SegmentFormDialog` modal SHALL open in which they choose an audience (contact or customer), compose a rule tree with SegmentBuilder, and SHALL NOT be able to save until the tree is valid

#### Scenario: Template save surfaces a compliance error as a field error

- **GIVEN** a marketer in the new-template modal for an email channel
- **WHEN** they submit a body with no `{{unsubscribe_link}}` token
- **THEN** the modal SHALL call `POST /api/templates`, which rejects the save, SHALL render the returned error against the body field rather than only a banner, and SHALL stay open

### Requirement: The Templates Form Lets a Marketer Pick Articles

The campaign template form SHALL let a marketer choose published articles and order them, and SHALL say where in the body they will appear. The form SHALL make the `{{articles}}` marker easy to place rather than expecting the marketer to remember it. Picking articles for a template whose body carries no marker SHALL warn the marketer that the articles will not be rendered, and SHALL still save.

#### Scenario: A marketer picks two articles for a template

- **GIVEN** two published articles
- **WHEN** a marketer opens a campaign template, picks both and saves
- **THEN** the template SHALL be stored with both article ids in the chosen order

#### Scenario: Picking articles for a body without the marker warns the marketer

- **GIVEN** a campaign template whose body carries no `{{articles}}` marker
- **WHEN** a marketer picks an article
- **THEN** the form SHALL warn that the articles will not appear until the marker is placed
- **AND** saving SHALL still succeed

#### Scenario: The blast preview shows the embedded articles

- **GIVEN** a campaign template naming two articles and carrying the marker
- **WHEN** a marketer previews a blast built on that template
- **THEN** the preview SHALL show both articles' titles and summaries where the marker stood

### Requirement: The Marketing Menu Reaches Social Publishing

The Marketing group SHALL carry Social accounts, Social posts and Social performance, after the mailing entries and before Search queries, so the section reads in the order the work happens: write, send, post, measure. Every one of the three SHALL be a real page reached by its own path, never a hash route.

#### Scenario: The Marketing group reaches the three social pages

- **WHEN** a marketer opens the Marketing group in the navigation
- **THEN** Social accounts, Social posts and Social performance SHALL be listed
- **AND** opening each SHALL land on its own page without a hard error

#### Scenario: The social posts page lists the seeded posts

- **WHEN** a marketer opens the Social posts page
- **THEN** the seeded posts SHALL be listed with their status
- **AND** a post an agent drafted SHALL be marked as such

### Requirement: A Marketer Composes One Post for Several Networks

The composer SHALL take one body, media, a link, the accounts the post goes to and a moment to send it, and SHALL let a marketer write a variant per network without retyping the rest. It SHALL show, per network, how much of the body fits, and SHALL refuse to submit a variant that does not fit. Submitting SHALL put the post up for approval rather than schedule it, and the approval SHALL be a visible step rather than a checkbox.

#### Scenario: A marketer writes a variant for one network only

- **GIVEN** a post with a body and two accounts on different networks
- **WHEN** the marketer writes a variant for one of the two and saves
- **THEN** the stored post SHALL carry that one variant
- **AND** the other network SHALL still use the post's own body

#### Scenario: The composer says when a variant does not fit

- **WHEN** a marketer types a variant longer than its network accepts
- **THEN** the composer SHALL say so and SHALL NOT let the post be submitted for approval

#### Scenario: The calendar shows what goes out when

- **WHEN** a marketer opens the Social posts page
- **THEN** scheduled posts SHALL be listed by the moment they go out
- **AND** a failed post SHALL show its reason and offer a retry
