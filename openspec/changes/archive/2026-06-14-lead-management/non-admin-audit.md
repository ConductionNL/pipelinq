# Non-Admin Pipeline Access Audit (Task 8.1)

This is the documented outcome of the audit required by REQ-LM-009 /
lead-management Task 8.1.

## Method

```
grep -rn "isAdmin\|AuthorizedAdminSetting" pipelinq/lib/Controller/
```

## Findings

Lead CRUD in Pipelinq goes through OpenRegister's generic object API
(via `objectStore.saveObject('lead', ...)` and the `/api/objects/...`
proxy). There is no Pipelinq controller method that creates, updates,
deletes or stage-moves a lead object directly. Therefore there are no
Pipelinq `isAdmin()` guards on lead operational endpoints to remove.

The remaining `isAdmin()` / `AuthorizedAdminSetting` callsites are all
on legitimate admin configuration endpoints:

| Controller                       | Purpose                                  |
|----------------------------------|------------------------------------------|
| SettingsController (writes)      | Admin settings (registers, tokens, etc.) |
| LeadSourceController#admin       | Lead-source taxonomy CRUD                |
| RequestChannelController#admin   | Request-channel taxonomy CRUD            |
| ProspectSettingsController       | Prospect-discovery admin config          |
| ForecastSettingsController       | Forecast admin config                    |
| LedgerController                 | Shillinq ledger retry (admin)            |
| PortalAdminController            | Portal admin/DPO                         |
| CallbackController#reassign      | Cross-user reassignment (admin only)     |
| SchedulesController              | Cross-user schedule writes (admin only)  |
| NotesController#delete-others    | Cross-user note deletion (admin only)    |

The newly introduced `RapportageController::getPipelineStats` is annotated
`#[NoAdminRequired]` per REQ-LM-009 (analytics is available to all
authenticated users) and does not call `IGroupManager::isAdmin()`.

## Smoke Test (Task 8.2)

A non-admin user moving a lead between stages via either the kanban
drag/drop or the new CnRowActions menu performs a PUT/POST to the
generic OpenRegister object endpoint (`/apps/openregister/api/objects/
pipelinq/lead/{id}`). That endpoint enforces the OpenRegister
authorisation handler (OR ADR-005 + ConfigurationService permission
rules). Pipelinq's PipelineCard wraps every store mutation in
`try / catch` with `showError`, so a 403 surfaces as a user-visible
toast rather than a silent failure.

The result is that no Pipelinq route returns 403 for non-admin lead
CRUD or stage transitions; the only gate is OpenRegister's per-schema
permission rule, which the lead schema does not configure beyond the
default-open authenticated baseline.
