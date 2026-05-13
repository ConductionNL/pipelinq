# Callback Management (Terugbelverzoeken)

## Overview

Callback management enables KCC agents to schedule, assign, and track callback requests (terugbelverzoeken). Agents can log callback attempts, claim group-assigned tasks, complete callbacks, and reassign tasks to other users or groups.

## Standards

| Standard | Usage |
|----------|-------|
| VNG Klantinteracties (InterneTaak) | Task entity mapping (gevraagdeHandeling, status, toegewezenAanMedewerker) |
| Schema.org (Action, ScheduleAction) | Semantic type annotations |
| GEMMA Terugbelverzoeken | Workflow: Nieuw > Ingepland > Gebeld > Afgehandeld / Niet bereikt |

## Components

| Component | Path | Purpose |
|-----------|------|---------|
| CallbackService | `lib/Service/CallbackService.php` | Business logic: attempts, claims, transitions, thresholds |
| CallbackController | `lib/Controller/CallbackController.php` | API endpoints for callback operations |
| CallbackOverdueJob | `lib/BackgroundJob/CallbackOverdueJob.php` | Background job for overdue detection |
| Task Schema | `lib/Settings/pipelinq_register.json` (task) | Data model with callback-specific properties |

## API Endpoints

| Method | URL | Purpose |
|--------|-----|---------|
| POST | `/api/callbacks/{id}/attempts` | Log a callback attempt |
| POST | `/api/callbacks/{id}/claim` | Claim a group-assigned task |
| POST | `/api/callbacks/{id}/complete` | Complete a callback task |
| POST | `/api/callbacks/{id}/reassign` | Reassign to another user/group |

## Status Transitions

```
open -> in_behandeling (via claim or manual)
in_behandeling -> afgerond (via complete)
in_behandeling -> verlopen (via overdue job)
afgerond -> open (reopen)
verlopen -> open (reopen)
```

## Implementation Date

2026-03-25

## OpenSpec Reference

- Proposal: `openspec/changes/archive/2026-03-25-callback-management/proposal.md`
- Spec: `openspec/specs/callback-management/spec.md`
