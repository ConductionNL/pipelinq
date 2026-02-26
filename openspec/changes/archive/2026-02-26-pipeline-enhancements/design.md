# Design: pipeline-enhancements

## Architecture Overview

Both features are changes to existing components — no new files needed.

1. **View toggle**: Add `viewMode` data property ('kanban'/'list') to PipelineBoard.vue. Kanban renders as-is; list mode renders a `<table>` with the same `allItems` data. Toggle buttons in header next to pipeline selector.

2. **Quick actions**: Add small icon buttons to PipelineCard.vue footer with `@click.stop` to prevent navigation. Stage dropdown shows all stages from parent pipeline (passed as prop). Assign dropdown fetches Nextcloud users.

## API Design

No new endpoints. Quick assign uses existing `saveObject` (spreads full item + new assignee). Quick stage change uses existing `saveObject` (spreads full item + new stage).

**Important**: OpenRegister PUT requires all required fields. Quick actions MUST spread the full item object before merging the changed field.

## File Structure

```
src/views/pipeline/
  PipelineBoard.vue  ← add view toggle + list table
  PipelineCard.vue   ← add quick action buttons
```

## Trade-offs

**User list source**: Using `/ocs/v2.php/cloud/users` for the assign dropdown (same as RequestDetail). Fetched once on mount and cached. Alternative was passing users as a prop from PipelineBoard, but the card component is simpler if it handles its own user fetching.

**Stage list as prop**: PipelineCard receives the stages array as a prop from PipelineBoard, so it can render the stage dropdown without another API call.
