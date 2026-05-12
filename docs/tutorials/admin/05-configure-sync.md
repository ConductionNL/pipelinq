---
sidebar_position: 5
title: Connect contacts and calendar sync
description: Link Pipelinq to Nextcloud Contacts address books and Calendar so clients and callbacks stay in sync org-wide.
---

# Connect contacts and calendar sync

Pipelinq can keep its clients aligned with **Nextcloud Contacts** address books and surface callbacks in **Nextcloud Calendar**. As an admin you choose which address books and calendars are eligible and how conflicts are resolved; individual users then opt in per the [user sync tutorial](../user/07-sync-contacts.md).

## Goal

Enable contacts sync against one or more address books and turn on calendar sync for callbacks.

## Prerequisites

- You're a Nextcloud admin AND have Pipelinq's admin permission.
- The Nextcloud **Contacts** and **Calendar** apps are installed and enabled.

## Steps

### 1. Open Pipelinq admin settings

Settings menu → **Administration settings** → **Pipelinq**.

![Admin settings](/screenshots/tutorials/admin/05-admin-settings.png)

### 2. Open the **Contacts & calendar sync** section

`{{TODO: confirm section name}}`

### 3. Choose eligible address books

`{{TODO: address-book picker — system books vs. user books, which direction sync runs, dedupe behaviour}}`

![Address book selection](/screenshots/tutorials/admin/05-address-books.png)

### 4. Enable calendar sync for callbacks

`{{TODO: pick the calendar callbacks land in; whether it's per-user or one shared calendar}}`

![Calendar sync](/screenshots/tutorials/admin/05-calendar.png)

### 5. Save

Users can now enable contacts sync from their own settings, and new callbacks appear in the configured calendar.

## Verification

- A user enables sync (see [Sync with Nextcloud Contacts](../user/07-sync-contacts.md)) and their clients map to the chosen address book.
- Creating a callback adds an event to the configured calendar.
- The duplicate-detection warning fires when a sync would create a clone — see [Resolve a duplicate](../user/08-resolve-duplicate.md).

## Common issues

| Symptom | Fix |
|---|---|
| No address books listed | The Contacts app isn't enabled, or the admin account has no access to any address book. |
| Callbacks don't appear in the calendar | The Calendar app isn't enabled, or the selected calendar is read-only for the assignee. |

## Reference

- [Contacts sync feature reference](../../features/contacts-sync.md)
- [Email & calendar sync feature reference](../../features/email-calendar-sync.md)
