# kcc-werkplek

**Status:** Planned

## Overview

The KCC werkplek (frontoffice workspace) is the unified agent screen for KCC (Klant Contact Centrum) employees. It combines citizen/business identification, open case visibility, contact moment registration, and backoffice routing into a single interface. This is the most demanded capability in Dutc


## Serving several municipalities

One KCC desk can answer for several municipalities, for example a shared
service run by a joint arrangement (gemeenschappelijke regeling). Every client,
contact and ticket can then name the municipality it belongs to.

**Keep the list.** Open the settings foldout (the gear at the bottom of the
navigation) and choose **Municipalities**. Add each municipality the desk
serves with its name, its four digit CBS municipality code and, when it has a
portal of its own, that portal.

**Where the municipality comes from.**

- A request submitted through a municipality's portal carries that
  municipality from the start.
- A new ticket for a client takes the client's municipality, unless the
  handler picks another one. The handler can change it on the ticket
  afterwards; the client keeps its own.
- When the app is upgraded, existing tickets without a municipality get
  their client's municipality. A municipality that is already set is never
  overwritten.

**Seeing and filtering.** Clients, Contacts, Questions and reports and the
Queue each have a Municipality column and a Municipality filter in the
sidebar. The column is hidden by default, so a desk that serves one
municipality does not see an empty column; switch it on in the column
settings.

**One organisation per municipality instead.** The municipality is a label,
not an access boundary: every employee of the desk sees the records of every
municipality it serves. When the municipalities' records must be kept apart,
so that one municipality's staff cannot see another's, run one OpenRegister
organisation per municipality instead. Each employee then works in the
organisation of their municipality, and you can still fill in the
municipality's organisation on its entry in the list.

## Specification

Full specification: `openspec/specs/kcc-werkplek/spec.md`

## Related Files

- Spec: `openspec/changes/kcc-werkplek/specs/kcc-werkplek/spec.md`
- Design: `openspec/changes/kcc-werkplek/design.md`
- Tasks: `openspec/changes/kcc-werkplek/tasks.md`
