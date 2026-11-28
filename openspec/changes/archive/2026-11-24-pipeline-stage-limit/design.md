# Design: pipeline-stage-limit

## Context (read at pipelinq development cfe0a0a51, OpenRegister development 555af7212)

- **Stages.** `lib/Settings/pipelinq_register.json` schema `pipeline` has
  `title, description, deckBoardId, propertyMappings, stages, isDefault`. Each
  `stages[]` item has `name, order, deckStackId, probability, isClosed, isWon,
  isDefault` (around :886-935). `propertyMappings[]` says which schema a
  pipeline boards (`schemaSlug`) and which property holds the stage
  (`columnProperty`, default `stage`).
- **Board.** `src/views/pipeline/PipelineBoard.vue::onDrop()` (:1168) writes
  `{id, [columnProperty]: stage.name, stageOrder}` through
  `objectStore.saveObject()` and catches every error with the comment
  "Invalid drop", so a refused write is invisible. The editor is
  `src/dialogs/PipelineFormDialog.vue`.
- **Refusing a write.** OpenRegister's `ObjectUpdatingEvent` and
  `ObjectCreatingEvent` implement `StoppableEventInterface`; a listener calls
  `stopPropagation()` and `setErrors()` to reject the write
  (`lib/Event/ObjectUpdatingEvent.php`).

## Decisions

### D1. `maxItems` on the stage

`stages[].maxItems`: integer, minimum 1, optional. Empty means no limit. The
editor hides the field on a stage with `isClosed` true.

### D2. The server guards, the board explains

`StageLimitGuard` listens to both events. For an object whose schema is boarded
by a pipeline (`propertyMappings`), when the stage property is new or changed
into a stage with `maxItems`, it counts the open items of that pipeline already
in that stage across every mapped schema. At or above the limit it stops the
event with the error "Stage {name} is full ({count} of {max})". An item that
stays in its stage, or leaves one, is never refused. Counting uses the stage
facet with the pipeline's filters, so it is one count query per guarded write.

### D3. The board says what happened

`onDrop()` catches the refusal and shows its message in a toast, and the
column header shows `count / max` with a full state when they are equal. The
edit form shows the same server message under the stage field.

## Risks

- Two moves into the last free place at the same moment can both pass the count.
  The next move is then refused; the limit is a working agreement, not an
  accounting control, and the design accepts that race.
- A limit set below the current count does not move anything out. The header
  then shows, for example, 10 / 8 in the full state, and only moves out are
  accepted until it drops under.
