---
sidebar_position: 3
title: Manage user / group permissions
description: Decide which Nextcloud groups can create clients, edit leads, see all-org pipelines, or administer Pipelinq.
---

# Manage user / group permissions

Pipelinq permissions are layered on top of Nextcloud groups. A user's role is derived from group membership; your job as admin is to map groups to roles.

## Goal

Map a Nextcloud group to a Pipelinq role and verify the right capabilities are granted.

## Prerequisites

- You're a Nextcloud admin with Pipelinq's admin permission.
- The target Nextcloud group already exists.

## Steps

### 1. Open Pipelinq admin settings

Settings menu → **Administration settings** → **Pipelinq**.

### 2. Go to **Permissions** (or **Roles**)

`{{TODO: confirm section label}}`

![Permissions section](/screenshots/tutorials/admin/03-permissions.png)

### 3. Add a role mapping

For each role:

- **Group** — the Nextcloud group ID.
- **Role** — `viewer` / `editor` / `manager` / `admin` (verify exact role names against the actual UI).
- **Scope** — own / team / all-org.

`{{TODO: full role matrix and what each role can do}}`

![Add role mapping](/screenshots/tutorials/admin/03-add-mapping.png)

### 4. Save and verify

Log in as a member of the assigned group and confirm:

- Capabilities granted (e.g. can create clients, can see other users' leads).
- Capabilities denied (e.g. cannot delete a record, cannot see admin settings).

## Verification

- Member sees the right list of clients / leads / requests for their scope.
- Role label appears in their profile area (if the UI surfaces it).

## Common issues

| Symptom | Fix |
|---|---|
| Member doesn't get the new permission | Reload — permissions are cached on initial state. |
| Conflicting roles across multiple groups | The most-permissive role wins. There's no priority order. |

## Reference

- [Permissions feature reference](../../features/permissions.md)
