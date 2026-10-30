# Lead rotation

**Status:** Implemented

## Overview

New leads reach your sales team in turn. A lead that arrives without an owner goes to the colleague who had the fewest leads in the last 30 days. Nobody gets more than their cap.

## Switch it on

1. Open the Pipelinq admin settings and go to Agent profiles.
2. Switch on **Assign new leads automatically**.

Rotation only picks colleagues with an agent profile whose skill covers the lead's category.

## Per colleague

Edit an agent profile to set:

- **Lead cap per 30 days**: the most new leads this colleague gets from rotation in 30 days. Leave it empty for no cap.
- **Pause lead assignment**: while on, the colleague gets no new leads from rotation, for example during leave.

A colleague marked as not available for routing is passed over as well.

## Who gets the lead

Rotation counts each eligible colleague's leads of the last 30 days. The one with the fewest gets the lead. On a tie, the colleague whose last lead is oldest goes first, so someone back from leave does not get a backlog in one morning.

A lead you create with an owner filled in is left alone.

## Why this owner

The lead shows **Why this owner** in its deal facts, for example "Rotation: fewest leads in 30 days (3 of cap 10), skill Solar". When nobody is eligible, the lead stays without an owner and says so. The suggested colleagues on the lead still work as before.

## Specification

- Spec: `openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md`
- Design: `openspec/changes/pipeline-lead-rotation/design.md`
- Tasks: `openspec/changes/pipeline-lead-rotation/tasks.md`
