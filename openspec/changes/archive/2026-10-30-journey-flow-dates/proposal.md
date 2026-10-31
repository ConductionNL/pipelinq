---
kind: code
---

# Proposal: journey-flow-dates

## Summary

Board PqJourney: the Flow card reads "Op 2 okt omgezet naar een flow". journey schema 1.1.0 adds two optional dates:

- `compiledAt`: set by the first clean compile; a later compile of the same flow keeps it, a refusal sets none.
- `activatedAt`: set when the journey's status becomes active, on create, on an edit or through pause and resume.

The Flow card shows the date when the journey is compiled and has one, else the plain status line it showed before.
