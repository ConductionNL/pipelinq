---
kind: code
---

# Proposal: pipeline-board-leftovers

## Summary

pl3 PqPipeline: what the board draws past the columns, measured against the live pipeline board.

- The header: the title sat at 88 (board 96), the Download and New lead buttons were 34px (board 40) and the count line sat 9.6px under the title (board 13). The content now starts 24px down, the header 4px under that, the buttons are 40px on the title row and the count line takes the row 6px under it.
- The view chips stood on a white band (8px 12px); the board draws them on the page.
- The settings link took Nextcloud's button ground and margin, and read "Pipeline instellingen"; the board draws a bare link, "Pijplijninstellingen".
- The stage heading sat on a 22.5px line (board 18), its count pill was 20px 11/600 (board 1px 8px, 12/700, soft ink), and the total line was not 18px in under the title.
- The owner chip on a lead card was 24px grey (board 26px in the light primary).

Not in this change: the leads the board draws in each stage (example data), the orphaned-leads notice (residue of the mock pipelines; the notice stays), the filter count badge (a pseudo-element the measurement does not see) and the board's "Map" view label (a board fix).
