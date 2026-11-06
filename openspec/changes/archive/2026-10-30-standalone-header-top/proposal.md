---
kind: code
---

# Proposal: standalone-header-top

## Summary

PqWeekoverzicht, PqZoekinzicht and PqForecast draw the page title at 96, as
the Dq page headers stand live (Dq wins where boards differ). The three
custom views padded their header 24px from the content top, so the title read
92. Their top padding is now 28px. The line under the title (13) and the 40px
header buttons come from nc-vue fix/board-standalone-header-rhythm.
