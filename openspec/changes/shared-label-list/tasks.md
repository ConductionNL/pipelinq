# Tasks: shared-label-list

- [ ] 1.1 register.d fragment: schema `label` (name unique case-insensitive, description); `lead.labels` and `client.labels` (array of string)
- [ ] 1.2 PHPUnit: the fragment imports; no union types
- [ ] 2.1 nc-vue: a chip-list manager widget over an OpenRegister schema (list, add, rename on double-click, delete with a confirm), board look
- [ ] 2.2 pipelinq: Beheer, Marketing gets the Labels card as config, after "Marktinzicht"
- [ ] 3.1 pipelinq: article, lead and client forms pick `tags` / `labels` from the label list
- [ ] 4.1 pipelinq: LabelRenameService rewrites a renamed label on the items that carry it (background job past 200 items); access checked per item by OpenRegister before the write
- [ ] 4.2 PHPUnit for the rename pass, red first
- [ ] 5.1 Zuiddrecht example set: the five board labels
- [ ] 5.2 Live: PqBeheerMarketing Labels card
