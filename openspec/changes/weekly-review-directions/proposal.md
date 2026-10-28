---
kind: code
---

# Proposal: weekly-review-directions

## Summary

pl3 PqWeekoverzicht 8-10: the board draws an arrow before each "What moved" line, up in green when the figure rose and down in red when it fell. The review stored its highlights as plain strings, so the page could not tell which way a line moved.

The weekly review gains `highlightDirections`, one `up`, `down` or `flat` per highlight line in the same order. WeeklyReviewService compares each source's figure with the week before: mail by clicks, social by views, search by clicks and the competitor watches by items seen. The page draws the arrow; a review stored before this change shows its lines without one.

Schema `weeklyReview` 1.0.0 to 1.1.0: one new optional property. `highlights` is unchanged.
