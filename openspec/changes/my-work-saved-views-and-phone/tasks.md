# Tasks: my-work-saved-views-and-phone

Moved from `my-work-roadmap` (archived 25 Nov 2026) with their numbers.

## 7. Saved views (REQ-MW-150)

- [x] 7.1 `CnSavedViewsControl` from nextcloud-vue on My work, storing type, Show completed, priority, pipeline and scope; the "Saved views" panel with counts as on `PqMijnWerk`.

## 9. Phone (REQ-MW-170)

- [x] 9.1 Below 768 px: filter chips with counts as on `PqMobiel`, sticky group headers with counts, 44 px targets kept; quick actions folded into one "Actions" button in the header, as on `PqMobiel`, once block 2 is built.
  - Checked live 28 Nov 2026 at 390 px (sandbox NC 34.0.6 + MariaDB, OpenRegister main): chips with counts were there ("All 10", "Tickets 9", "Leads 1", "Follow-ups 0"), but the chosen chip's label was main ink on main ink, the header drew Refresh, Arrange page and Actions while New ticket and New contact were nowhere, and the group headings scrolled away. Fixed in nextcloud-vue#2444 (the off-viewport header actions and Arrange page fold into the Actions menu, the chosen pill reads, `stickyGroupHeadings`) and here (`stickyGroupHeadings: true` on `my-items-phone`). After: the header holds the title and one Actions button whose menu offers New ticket, New contact, Download and Arrange page; the Overdue heading holds at the top while its rows scroll.
  - Board, not spec, on two points (boards are canon): PqMobiel draws its group headings without counts and its controls at 34 px (pills) and 40 px (Actions), so REQ-MW-170 now asks for those sizes instead of 44 px and drops the heading counts.
  - Test: `tests/e2e/spec-coverage/my-work-phone.spec.ts`, 3/3 green on the sandbox.

## 3. Close

- [x] 3.1 Set `built.state` of `work-saved-view` in `openspec/parity/capabilities.json` when block 7 lands.
  - Done (28 Nov 2026): the row reads built, owned by pipelinq, with the Tickets toolbar save-and-share (`allowSavedViews`, `savedViewShare`) and the My work `my-saved-views` widget as evidence and REQ-MW-150 as its requirement. Test: `tests/vitest/workSavedViewRow.spec.js`.
- [ ] 3.2 Archive this change into `openspec/specs/my-work` once both blocks are built.
