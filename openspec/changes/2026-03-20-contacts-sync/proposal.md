# Proposal: contacts-sync unlink and re-sync

## Problem

The contacts-sync spec identifies V1 gaps:
1. No "Unlink" action on the sync status indicator
2. No dedicated "Re-sync" button (sync only happens during save)

## Proposed Change

Add "Unlink" and "Re-sync" buttons next to the sync badge on ClientDetail and ContactDetail views.

### Out of Scope
- CATEGORIES property mapping (V1)
- Photo/avatar sync (V1)
- Multi-user sync isolation improvements (V1)

## Impact
- **Files modified**: 2 Vue files
- **Risk**: Low
