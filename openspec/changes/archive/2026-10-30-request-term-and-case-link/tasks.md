# Tasks

- [x] nc-vue: CnFactsWidget footer action that runs a declared header action (open-form patch with `includeFields`, or an object search that writes one field), gated by the record's update right
- [x] nc-vue: a facts row `as: "title"` with a `size` (26px), the deadline written out
- [x] pipelinq: ticket schema `slaDeadline` description covers requests (no migration)
- [x] pipelinq: menu-layout.simple.json Termijn card: figure row + "Termijn instellen" (patch slaDeadline)
- [x] pipelinq: Gekoppelde zaak card: "Bestaande zaak koppelen" while caseReference is empty (case search over the case app's register, as ConvertToCaseDialog's caseTypeSource; patch caseReference)
- [x] vitest: the side-card config (ticketSideCardsBoard) and the dialogs; jest in nc-vue for the footer action and the title row

Built as: nc-vue `feat/pq-c3-facts-term-card` (openspec facts-title-size-and-update-right): a facts `title` takes `size`, and an action takes `requires: "update"`. The footer actions are the facts card's existing `actions` (open-form with an `endpoint` PATCH on the ticket's OpenRegister object), so no new action kind was needed.
