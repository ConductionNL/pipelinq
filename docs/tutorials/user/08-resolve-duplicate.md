---
sidebar_position: 8
title: Resolve a duplicate-detection warning
description: Pipelinq flags likely duplicate clients. Decide whether to merge, keep both, or override.
---

# Resolve a duplicate-detection warning

Pipelinq watches for two clients with similar names or matching emails and surfaces a warning when one looks like a duplicate of an existing record. The warning shows on the create form and on the existing client's banner.

## Goal

Triage a duplicate warning — merge if it's the same client, keep both if not.

## Prerequisites

- A duplicate warning is currently visible (i.e. you tried to create a likely-duplicate or one was flagged by a sync).

## Steps

### 1. Read the warning details

The banner lists which existing client matches and on which fields (name, email, phone).

![Duplicate warning](/screenshots/tutorials/user/08-warning.png)

### 2. Pick an action

- **Merge** — combines the duplicate into the existing client. All linked leads, requests, contact moments move over. Destructive on the duplicate.
- **Keep both** — overrides the warning. Both records persist; the warning is suppressed for this pair.
- **Cancel** — discard the new record entirely.

![Action picker](/screenshots/tutorials/user/08-actions.png)

### 3. Confirm

`{{TODO: confirmation dialog details, especially for merge — irreversible}}`

## Verification

- **Merged**: only one client remains; its history shows the merge event.
- **Kept both**: both records exist; warnings on this pair stay suppressed.
- **Cancelled**: the new record is gone.

## Common issues

| Symptom | Fix |
|---|---|
| Merge button is disabled | You don't have edit-permission on the existing client — ask its assignee. |
| Warning re-appears after kept-both | A different field matched on a later edit — re-trigger keep-both. |

## Reference

- [Duplicate detection reference](../../features/duplicate-detection.md)
- [Clients feature reference](../../features/clients.md)
