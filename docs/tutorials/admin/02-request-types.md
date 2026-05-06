---
sidebar_position: 2
title: Configure request types
description: Define the request categories your team triages from My Work — info, quote, support, complaint, etc.
---

# Configure request types

Request types are the dropdown options users pick from when capturing an incoming request. They drive the My Work queue's filter chips and determine what kind of triage actions are available per category.

## Goal

Add, edit, or retire request types for your org.

## Prerequisites

- You're a Nextcloud admin with Pipelinq's admin permission.

## Steps

### 1. Open Pipelinq admin settings

Settings menu → **Administration settings** → **Pipelinq**.

### 2. Go to **Request types**

![Request types section](/screenshots/tutorials/admin/02-request-types.png)

### 3. Add a new type

`{{TODO: form fields — label, internal ID, default initial-stage / queue, default assignee}}`

### 4. Set the conversion behaviour

For each type, decide:
- Can this convert to a lead? (yes/no)
- What pipeline + stage does it default to?

### 5. Save

`{{TODO: where users see the change — the request-create form's type picker}}`

## Verification

- Open the request-create form as a regular user — your new type appears in the picker.
- The My Work queue shows a filter chip per active request type.

## Common issues

| Symptom | Fix |
|---|---|
| Type doesn't appear in the picker | Reload the page once — the picker reads from a cached list. |
| Existing requests with a retired type | Retired types stay valid on existing records; only new requests can't pick them. |

## Reference

- [Requests feature reference](../../features/requests.md)
