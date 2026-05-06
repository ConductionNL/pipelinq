---
sidebar_position: 2
title: Add a new client
description: Create a new person or organisation in Pipelinq, with contact details and notes.
---

# Add a new client

A **client** in Pipelinq is either a person or an organisation. Each carries contact details, notes, and a complete history of every lead, request, and contact moment linked to them.

## Goal

Create a new client record — person or organisation — and verify it lands in the right list with duplicate detection running.

## Prerequisites

- You can edit clients (default for users; admins manage permission groups).

## Steps

### 1. Click **+ Add client** from the Clients view

`{{TODO: confirm the exact label — could be "+ New client" or "Add"}}`

![Add client button](/screenshots/tutorials/user/02-add-client-button.png)

### 2. Pick **Person** or **Organisation**

The form fields differ — persons get first/last name, organisations get a single name field plus org-specific fields like KvK / VAT number.

![Type picker](/screenshots/tutorials/user/02-type-picker.png)

### 3. Fill the contact details

`{{TODO: list the fields, mark required vs optional}}`

![Form filled](/screenshots/tutorials/user/02-form-filled.png)

### 4. Watch for duplicate-detection warnings

If Pipelinq finds a likely match (similar name, matching email), a warning appears. See [Resolve a duplicate-detection warning](08-resolve-duplicate.md).

### 5. Click **Save**

The client appears at the top of the list, and you land on its detail page.

![Saved client](/screenshots/tutorials/user/02-saved.png)

## Verification

- The new client is in the Clients list.
- Its detail page shows your input.
- The history tab shows the creation event.

## Common issues

| Symptom | Fix |
|---|---|
| Save button is disabled | A required field is empty. The form highlights which one. |
| Duplicate warning appears even though this is a real new client | Confirm via [Resolve a duplicate-detection warning](08-resolve-duplicate.md) — the override path keeps both records. |

## Reference

- [Clients feature reference](../../features/clients.md)
- [Duplicate detection reference](../../features/duplicate-detection.md)
