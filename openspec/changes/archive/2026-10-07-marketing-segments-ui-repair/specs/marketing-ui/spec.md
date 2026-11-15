# marketing-ui Specification Delta

## Purpose

Wires `SegmentBuilder.vue` (previously mounted by nothing) into a Segments
page and a Templates page reachable from the Marketing menu, and removes the
`@e2e exclude` on the requirement it blocked once the component is reachable.

## MODIFIED Requirements

### Requirement: Segment Builder UI Composes Rule Trees

The SegmentBuilder Vue component SHALL allow marketers to construct rule
trees visually using AND/OR logic with leaf predicates, validate them, and
show a live size estimate before commit.

`src/components/SegmentBuilder.vue` and `src/components/SegmentRuleNode.vue`
are now mounted by `SegmentFormDialog` (`src/dialogs/SegmentFormDialog.vue`),
the modal the Segments index page opens from its Add action and its row Edit
action. The
`@e2e exclude` this requirement previously carried is removed:
`tests/e2e/spec-coverage/marketing.spec.ts` now exercises the visual rule
tree and live-estimate scenarios through the mounted UI.

#### Scenario: Visual rule tree with live validation

- **GIVEN** a marketer opens SegmentBuilder for entityType "contact"
- **WHEN** a predicate is unfinished, or the backend validator rejects it
- **THEN** the component SHALL disable save until resolved, and SHALL display a rejection as an error on the predicate it names

#### Scenario: Live size estimate shown

- **GIVEN** a valid rule tree
- **WHEN** the rules change
- **THEN** the component SHALL show "Estimated members: N" from a debounced backend call

## ADDED Requirements

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
