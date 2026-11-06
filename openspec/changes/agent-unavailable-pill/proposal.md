---
kind: code
screens: [PqBeheer]
---

# Proposal: agent-unavailable-pill

## Summary

Board PqBeheer marks an agent who is not available with a "Niet beschikbaar" pill in the warning tone, 13px semibold.
Live drew a hand-made 11px tag in solid red, which reads as an error rather than a state.

## What changes

- AgentProfileSettings: the availability tag is the library `CnStatusBadge` with `variant="warning"`, 8px after the
  name. The hand-made `.unavailable-tag` style is gone.
