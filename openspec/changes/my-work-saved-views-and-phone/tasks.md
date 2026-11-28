# Tasks: my-work-saved-views-and-phone

Moved from `my-work-roadmap` (archived 25 Nov 2026) with their numbers.

## 7. Saved views (REQ-MW-150)

- [x] 7.1 `CnSavedViewsControl` from nextcloud-vue on My work, storing type, Show completed, priority, pipeline and scope; the "Saved views" panel with counts as on `PqMijnWerk`.

## 9. Phone (REQ-MW-170)

- [ ] 9.1 Below 768 px: filter chips with counts as on `PqMobiel`, sticky group headers with counts, 44 px targets kept; quick actions folded into one "Actions" button in the header, as on `PqMobiel`, once block 2 is built.

## 3. Close

- [x] 3.1 Set `built.state` of `work-saved-view` in `openspec/parity/capabilities.json` when block 7 lands.
  - Done (28 Nov 2026): the row reads built, owned by pipelinq, with the Tickets toolbar save-and-share (`allowSavedViews`, `savedViewShare`) and the My work `my-saved-views` widget as evidence and REQ-MW-150 as its requirement. Test: `tests/vitest/workSavedViewRow.spec.js`.
- [ ] 3.2 Archive this change into `openspec/specs/my-work` once both blocks are built.
