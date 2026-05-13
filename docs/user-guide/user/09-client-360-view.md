---
sidebar_position: 9
title: See the 360° client view
description: "Open the klantbeeld: one screen that pulls together a client's contacts, leads, requests, interactions, and open work."
---

# See the 360° client view

The **360° client view** (klantbeeld) is the single screen a KCC agent or account manager opens to see everything about one client: who the contacts are, which leads and requests are open, the full interaction timeline, and any callbacks or tasks still due.

## Goal

Open a client's 360° view and locate their open leads, recent contact moments, and pending callbacks from one screen.

## Prerequisites

- The client (person or organisation) already exists. See [Add a client](02-add-client.md).

## Steps

### 1. Open the client's detail page

From the Clients list, or by searching the client's name in the top search bar.

The 360° view IS the default detail page. Opening any client row lands you here. The sidebar tabs on the right are the per-record-type drill-downs (*Contacts*, *Leads*, *Requests*, *Contactmomenten*, *Tasks*, *History*); the page header + summary panels are the 360° view itself.

![Client 360° view](/screenshots/user-guide/user/09-klantbeeld.png)

### 2. Review the summary header

The header shows the client **Name** with **Type** (person / organisation) and **Status** badges, the **Owner** (account manager) avatar with a quick-reassign menu, and a row of open-work counts (*Open leads*, *Open requests*, *Pending callbacks*, *Open complaints*), each clickable as a filtered jump-link.

### 3. Scan the panels

- **Contacts**: linked contact persons for an organisation.
- **Pipeline**: open leads with their stage and probability.
- **Requests**: open requests from this client.
- **Timeline**: chronological contact moments and activity.
- **Callbacks / tasks**: anything still due.

![Client panels](/screenshots/user-guide/user/09-panels.png)

### 4. Drill into any item

Click a lead, request, or contact moment to open it in place. The 360° view stays as your home base.

## Verification

- Every open lead and request for the client is listed in the relevant panel.
- The timeline shows the most recent contact moment first.
- Callback / task counts match what's in your My Work queue for this client.

## Common issues

| Symptom | Fix |
|---|---|
| A panel is empty but you expect data | The data exists on a *linked* record (e.g. a lead on a contact person, not the organisation). Open that record's own 360° view. |
| Timeline stops part-way | The timeline is paginated. Scroll or click **Load more** at the bottom. |

## Reference

- [Klantbeeld 360° feature reference](../../Features/klantbeeld-360.md)
- [Activity timeline feature reference](../../Features/activity-timeline.md)
