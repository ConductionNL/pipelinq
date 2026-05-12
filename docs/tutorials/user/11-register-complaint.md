---
sidebar_position: 11
title: Register a complaint
description: Record a klacht against a client, route it for handling, and track it through to resolution.
---

# Register a complaint

A **complaint** (klacht) is a specific request type with its own intake form and a small handling workflow — acknowledge, investigate, resolve. Registering it in Pipelinq keeps the complaint on the client's history and on the right person's queue.

## Goal

Register a complaint, route it to a handler, and verify it's tracked on the client and in the queue.

## Prerequisites

- The complainant exists as a client (or you can quick-create one during intake).
- Admin has configured the complaint request type — see [Configure CRM workflows and automation](../admin/04-configure-automation.md).

## Steps

### 1. Open **My Work** (or the client's detail page)

`{{TODO: confirm the canonical entry point — global "+ Add" menu vs. My Work "+ New request" with type = complaint}}`

### 2. Choose **Register complaint**

![Register complaint](/screenshots/tutorials/user/11-intake.png)

### 3. Fill the complaint form

- **From** — the complainant; pick or quick-create.
- **Subject** + **Description** — what went wrong.
- **Category / severity** — `{{TODO: confirm available fields}}`.
- **Channel** — phone, email, web form, in person.

![Complaint form](/screenshots/tutorials/user/11-form.png)

### 4. Submit and route

After save, the complaint enters the handling workflow at its first state. Assign a handler (or let automation route it).

![Routing](/screenshots/tutorials/user/11-route.png)

### 5. Progress to resolution

The handler moves the complaint through the states — acknowledge, investigate, resolve — logging contact moments along the way. Closing it records the outcome.

## Verification

- The complaint appears on the complainant's 360° view and history.
- It's in the handler's My Work queue at the correct state.
- On close, the resolution and outcome are recorded.

## Common issues

| Symptom | Fix |
|---|---|
| No **Register complaint** option | The complaint request type isn't configured yet — see the [admin automation guide](../admin/04-configure-automation.md). |
| Complaint isn't routed automatically | Routing automation isn't set up; assign the handler manually for now. |

## Reference

- [Klachtenregistratie feature reference](../../features/klachtenregistratie.md)
- [Request management feature reference](../../features/request-management.md)
