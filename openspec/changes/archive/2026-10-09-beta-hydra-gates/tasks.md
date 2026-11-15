# Tasks: beta-hydra-gates

## 1. Repair steps

- [x] 1.1 Mark `CreateServiceGroup` as held in `appinfo/info.xml`, with the reason
  - Verify: `RepairStepRegistrationTest` (fails on the old info.xml); gate-98 prints HELD, not FAIL
- [x] 1.2 Test that every concrete repair step is in `<post-migration>` and no abstract one is registered
  - Verify: removing `RelinkOrphanedLeads` from info.xml makes the test fail

## 2. Schema titles

- [x] 2.1 Carry the merged title into every overlay property that sets a description
  - Verify: the merged register is identical before and after; gate-51 lists none of these files; `npm run check:schema-l10n` exit 0

## 3. Spec coverage

- [x] 3.1 Tag `contactWriteBack.js::setup`, `PipelineBoard.vue::selectedPipeline`, `TenderEntryPanel.vue::mounted/onTenderAdded`, `PortalSettings.vue` (4) and `PartyIndicatorPanel.vue::handler`
  - Verify: gate-16 lists none of them; `npm run check:spec-links` exit 0
