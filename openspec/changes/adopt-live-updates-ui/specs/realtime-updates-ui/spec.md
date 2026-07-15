# Realtime Updates UI (leaf adoption)

## ADDED Requirements

### Requirement: Store-rendered views MUST subscribe to live updates for their scope

Views that render from Pipelinq's `createObjectStore`-based object store MUST subscribe to
live updates for the data they display: collection-scoped views subscribe to
`or-collection-{register-slug}-{schema-slug}` per rendered object type, object-scoped views
subscribe to `or-object-{uuid}`. Subscriptions MUST be re-scoped when the viewed scope
changes and released when the view is destroyed. Events are refetch HINTS only: views MUST
refetch through their existing fetch paths and MUST NOT patch rendered state from an event
payload.

@e2e exclude Requires a second concurrent authenticated session plus a notify_push (or poll-tick) round-trip; covered by the shared library's transport tests and manual two-browser verification.

#### Scenario: Pipeline board refreshes when a mapped object changes elsewhere

- **GIVEN** the pipeline board is open with a pipeline mapping the `lead` type
- **WHEN** another user updates a lead in that pipeline
- **THEN** the board receives the `or-collection-{register}-{schema}` hint and re-runs its
  existing `fetchPipelineItems()` path (debounced), so the card moves/updates without a
  manual refresh

#### Scenario: Detail view refreshes when the viewed object changes elsewhere

- **GIVEN** a project / resource / service detail view is open for object `{uuid}`
- **WHEN** another user updates that object
- **THEN** the `or-object-{uuid}` hint triggers the plugin's `fetchObject` refetch into the
  same store cache the view renders from, and the view re-renders the fresh data

#### Scenario: Subscription released on scope change and destroy

- **GIVEN** a live subscription is active for the current scope
- **WHEN** the user switches pipelines (or navigates away)
- **THEN** the previous subscription is released — including one still in flight, which is
  invalidated via an epoch counter and unsubscribes itself on resolution
