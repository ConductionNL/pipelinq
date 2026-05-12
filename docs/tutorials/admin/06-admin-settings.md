---
sidebar_position: 6
title: Manage Pipelinq settings
description: Tour the Pipelinq admin settings page — where the OpenRegister register lives, plus the global options that aren't pipeline-, request-, or sync-specific.
---

# Manage Pipelinq settings

Beyond pipelines, request workflows, permissions, and sync, the Pipelinq admin page holds the **global settings**: which OpenRegister register and schemas back the app, default landing page, retention and notification defaults, and the integration toggles. This tutorial is the orientation tour.

## Goal

Find the Pipelinq admin settings page, confirm the OpenRegister wiring, and review the global options.

## Prerequisites

- You're a Nextcloud admin AND have Pipelinq's admin permission.
- OpenRegister is installed (Pipelinq stores its data there).

## Steps

### 1. Open Pipelinq admin settings

Settings menu → **Administration settings** → **Pipelinq**.

![Admin settings](/screenshots/tutorials/admin/06-overview.png)

### 2. Check the OpenRegister wiring

`{{TODO: confirm where the register / schema selectors are and what the sensible defaults are}}`

![Register settings](/screenshots/tutorials/admin/06-register.png)

### 3. Review global options

`{{TODO: list the global toggles — default landing page (dashboard vs. My Work), retention, notification defaults, metrics/Prometheus toggle}}`

![Global options](/screenshots/tutorials/admin/06-options.png)

### 4. Save

Changes apply immediately for all users on their next page load.

## Verification

- The configured register exists in OpenRegister and contains the Pipelinq schemas.
- A regular user's landing page matches the default you set.
- Disabling an integration toggle hides the related UI for users.

## Common issues

| Symptom | Fix |
|---|---|
| Pipelinq pages are empty / error | The register or schemas aren't created — re-run the Pipelinq setup, or pick an existing register here. |
| Settings page is blank for an admin | They have the Nextcloud admin role but not Pipelinq's own admin permission — grant it under [Manage user / group permissions](03-permissions.md). |

## Reference

- [Admin settings feature reference](../../features/admin-settings.md)
- [OpenRegister integration feature reference](../../features/openregister-integration.md)
