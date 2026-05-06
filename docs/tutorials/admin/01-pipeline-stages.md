---
sidebar_position: 1
title: Configure pipeline stages
description: Define the kanban stages every lead moves through, with default close probabilities.
---

# Configure pipeline stages

Pipelinq supports multiple pipelines (e.g. Sales, Support, Onboarding), each with its own ordered list of stages. Each stage has a default close probability that drives the forecast aggregate.

## Goal

Add, reorder, or rename stages on an existing pipeline; tune the default close probability per stage.

## Prerequisites

- You're a Nextcloud admin AND have Pipelinq's admin permission.

## Steps

### 1. Open Pipelinq admin settings

Settings menu → **Administration settings** → **Pipelinq**.

![Admin settings](/screenshots/tutorials/admin/01-admin-settings.png)

### 2. Pick a pipeline

`{{TODO: pipeline picker UI}}`

### 3. Add or rename stages

`{{TODO: drag-to-reorder behaviour, add-stage flow, name validation}}`

![Stages editor](/screenshots/tutorials/admin/01-stages-editor.png)

### 4. Set default close probabilities per stage

Numbers between 0 and 100. Pipelinq uses these as the lead's default probability on stage transitions; users can override per-lead inline.

### 5. Save

The pipeline view immediately reflects the new stage list.

## Verification

- Open the pipeline view as a regular user — your stages appear in the column order you set.
- The forecast at the top recalculates using the new probabilities.

## Common issues

| Symptom | Fix |
|---|---|
| Reorder doesn't persist | Drag handle is on the stage card title, not the body. Try again from the title bar. |
| Default probability resets to 0 | The number was rejected (out of range or non-numeric) and silently dropped. |

## Reference

- [Pipeline feature reference](../../features/pipeline.md)
- [Admin settings feature reference](../../features/admin-settings.md)
