---
sidebar_position: 5
title: Log an interaction (contact moment)
description: Record a call, email, visit, or other interaction so the client's history stays complete.
---

# Log an interaction

A **contact moment** is one entry in a client's interaction history. Calls, emails, visits, demos — anything worth remembering.

## Goal

Log a contact moment on a client and verify it lands on the client's timeline.

## Prerequisites

- The client (person or organisation) already exists.

## Steps

### 1. Open the client's detail page

### 2. Click **+ Log contact moment**

`{{TODO: confirm exact button label}}`

![Add contact moment](/screenshots/tutorials/user/05-add-button.png)

### 3. Pick the type

`{{TODO: list types — call, email, visit, video call, etc.}}`

### 4. Fill in the details

- **Date** — defaults to now.
- **Subject** — short summary.
- **Notes** — free-form body.
- **Linked lead / request** — optional; surfaces the moment on those records too.

![Form](/screenshots/tutorials/user/05-form.png)

### 5. Save

The moment appears at the top of the client's timeline.

![Saved](/screenshots/tutorials/user/05-saved.png)

## Verification

- The moment is on the client's timeline with the right type icon.
- If you linked a lead/request, the moment also shows there.
- The history tab logs the create event.

## Common issues

| Symptom | Fix |
|---|---|
| Type list is empty | Admin hasn't configured contact-moment types yet — see [admin guide](../admin/02-request-types.md). |
| Moment doesn't show on linked lead | Reload the lead — the timeline cache refreshes only on next view. |

## Reference

- [Contact moments feature reference](../../features/contact-moments.md)
