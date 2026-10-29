# Tasks: shared-label-list

- [x] 1.1 register.d fragment 98-shared-labels.json: schema `label` (name, description; read by the staff, written by administrators only); `lead.labels` and `client.labels` (array of string); `x-options-from` on their items and on `article.tags`
- [x] 1.2 PHPUnit SharedLabelListSchemaTest: the merged register carries it; no union types
- [x] 2.1 nc-vue: CnChipListManager, a chip-list manager over an OpenRegister schema (list, add, rename on double-click or F2, delete with a confirm)
- [x] 2.2 pipelinq: Beheer, Marketing gets the Labels card last, after Configuratie BI-export, as the board draws it
- [x] 3.1 pipelinq: the schema forms pick from the list through `x-options-from`; the hand-written lead form, client form and article editor through LabelSelect
- [x] 4.1 pipelinq: LabelRenameService rewrites a renamed label on the leads, clients and articles that carry it (a queued job past 200 items); the administrator check runs before anything is read or written, and every write goes through OpenRegister as the caller
- [x] 4.2 PHPUnit for the rename pass, red first
- [ ] 5.1 Zuiddrecht example set: the five board labels (not the product register)
- [ ] 5.2 Live: PqBeheerMarketing Labels card
