---
sidebar_position: 2
title: Installation
description: Install and configure Pipelinq on your Nextcloud instance.
---

# Installation

## Prerequisites

- **Nextcloud 29 or later** — Pipelinq requires Nextcloud 29+.
- **OpenRegister app** — Pipelinq stores all CRM data via OpenRegister. Install it first from the Nextcloud App Store or your admin's app repository.
- **Admin access** — You need Nextcloud admin rights to install and configure apps.

## Install from the App Store

1. Log in to your Nextcloud instance as an administrator.
2. Open the top-right menu and choose **Apps**.
3. Search for **Pipelinq**.
4. Click **Download and enable**.
5. Nextcloud installs the app and reloads. You will see the Pipelinq icon in the left navigation bar.

## Initial Configuration

### 1. Enable Pipelinq registers

On first launch, Pipelinq runs a repair step that creates the required OpenRegister registers and schemas:

- **pipelinq** register with schemas for `client`, `contact`, `lead`, `request`, `pipeline`, and `stage`.

If the repair step did not run automatically, trigger it manually:

```bash
php occ maintenance:repair --include-expensive
```

### 2. Verify the register setup

1. Navigate to **Pipelinq** in the left sidebar.
2. The dashboard should load without errors.
3. Check **Admin settings → Pipelinq** to confirm the register and schema references are populated.

### 3. Configure pipelines

Pipelinq ships with two default pipelines:

- **Sales Pipeline**: New, Contacted, Qualified, Proposal, Negotiation, Won, Lost
- **Service Requests Pipeline**: New, In Progress, Completed, Rejected, Converted to Case

You can add, rename, or reorder stages in **Admin settings → Pipelinq → Pipelines**.

### 4. Set user permissions

By default, all Nextcloud users can create clients and leads. Restrict access by assigning Nextcloud groups to Pipelinq roles in **Admin settings → Pipelinq → Permissions**.

## Troubleshooting

### Dashboard shows "Register not found"

The OpenRegister app is not installed or the repair step failed. Install OpenRegister and rerun:

```bash
php occ maintenance:repair --include-expensive
```

### "Class not found" errors after install

Clear the OPcache and restart:

```bash
php occ maintenance:mode --on
# Restart Apache/PHP-FPM
php occ maintenance:mode --off
```

### Can not see Pipelinq in the left navigation

The app might be disabled. Enable it in **Apps → Your apps → Pipelinq → Enable**.

## Upgrading

Pipelinq follows standard Nextcloud app upgrade procedures. Use the App Store **Update** button or the CLI:

```bash
php occ app:update pipelinq
php occ maintenance:repair --include-expensive
```

The repair step migrates any schema changes automatically.
