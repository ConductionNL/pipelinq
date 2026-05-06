---
sidebar_position: 7
title: Sync with Nextcloud Contacts
description: Two-way sync between Pipelinq clients and the native Nextcloud Contacts app via CardDAV.
---

# Sync with Nextcloud Contacts

Pipelinq clients can sync to and from the native Nextcloud Contacts app. The sync is two-way over CardDAV — adding a contact in Contacts brings it into Pipelinq, and vice versa.

## Goal

Configure the contacts sync once, verify two-way propagation works.

## Prerequisites

- The Nextcloud Contacts app is installed and enabled on your account.
- You have at least one address book in Contacts.

## Steps

### 1. Open Pipelinq settings

`{{TODO: settings entry point — probably in a top-right menu or per-user settings panel}}`

![Settings](/screenshots/tutorials/user/07-settings.png)

### 2. Go to **Contact sync**

### 3. Pick the address book

`{{TODO: address-book picker behaviour, multiple-selection, default}}`

![Address book picker](/screenshots/tutorials/user/07-address-book.png)

### 4. Enable two-way sync

Toggle on. Pipelinq immediately fetches the address book and creates a client per contact (matching by email + name).

![Sync enabled](/screenshots/tutorials/user/07-sync-on.png)

### 5. Verify propagation

- Add a new contact in Nextcloud Contacts → check it appears in Pipelinq within ~1 minute.
- Add a new client in Pipelinq → check it appears in the Contacts address book.

## Verification

- The clients list grows by the count of contacts in the synced address book.
- Bidirectional changes propagate within the configured sync interval.

## Common issues

| Symptom | Fix |
|---|---|
| Address book picker is empty | The Nextcloud Contacts app isn't installed for your account. |
| Sync conflict between matching contacts | Resolve via [Resolve a duplicate-detection warning](08-resolve-duplicate.md). |
| Sync stops after a while | Check Pipelinq's admin settings — there may be a CardDAV rate-limit toggle. |

## Reference

- [Contacts sync feature reference](../../features/contacts-sync.md)
