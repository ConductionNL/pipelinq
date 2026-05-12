---
sidebar_position: 4
title: Configure CRM workflows and automation
description: Define the request-type workflows, handling states, and automation rules that route work through Pipelinq.
---

# Configure CRM workflows and automation

Pipelinq's request types (info, quote, support, complaint, …) each carry a small **workflow** — a set of handling states and the transitions between them — plus optional **automation rules** that assign, escalate, or notify on the right event. This is where you set those up.

## Goal

Add or edit a request-type workflow and wire at least one automation rule (e.g. auto-assign on creation).

## Prerequisites

- You're a Nextcloud admin AND have Pipelinq's admin permission.

## Steps

### 1. Open Pipelinq admin settings

Settings menu → **Administration settings** → **Pipelinq**.

![Admin settings](/screenshots/tutorials/admin/04-admin-settings.png)

### 2. Open the **Workflows & automation** section

`{{TODO: confirm section name and whether request types and workflows are separate panels}}`

### 3. Define handling states per request type

`{{TODO: add-state flow, ordering, which state is the "open" entry state, which are "closed"}}`

![Workflow states](/screenshots/tutorials/admin/04-states.png)

### 4. Add automation rules

`{{TODO: rule builder — trigger (on create / on state change), condition, action (assign, notify, escalate)}}`

![Automation rule](/screenshots/tutorials/admin/04-rule.png)

### 5. Save

New requests of that type immediately follow the configured workflow; existing requests keep their current state.

## Verification

- Create a test request of the type — it lands at the configured entry state.
- The automation rule fires (e.g. the request is auto-assigned).
- The state list shows in the request's status picker for handlers.

## Common issues

| Symptom | Fix |
|---|---|
| Rule never fires | Check the trigger matches the event you expect (create vs. state change) and the condition isn't too narrow. |
| Handlers can't move a request forward | The target state has no transition *from* the current state — add it to the workflow. |

## Reference

- [CRM workflow automation feature reference](../../features/crm-workflow-automation.md)
- [Request management feature reference](../../features/request-management.md)
