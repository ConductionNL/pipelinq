# activity-stream-publishing Specification

## Purpose
TBD - created by archiving change pipelinq-activityservice-gate27. Update Purpose after archive.
## Requirements
### Requirement: Activity Stream Publishing Uses the Nextcloud First-Party Activity API

Pipelinq SHALL publish lifecycle activities (lead/request created, assigned,
stage-changed, status-changed, note-added, deal-won, deal-lost) to the Nextcloud
Activity stream through the first-party `OCP\Activity\IManager::publish()` API,
and SHALL NOT route activity-stream publishing through any cross-app RPC
mechanism (`getLeaf`, integration-registry `->call('<app>')`, a sibling app's
HTTP route, or a removed OpenRegister `ObjectService->publish()` method).

This surface is intentionally distinct from OpenRegister object publication: the
Activity stream is NOT the RBAC `publicatiedatum` anon-publication model, and is
NOT the `x-openregister-notifications` dialect (which emits `nc-notification`
channel notifications and does not feed the Activity stream). `IManager::publish()`
is a legitimate first-party API and MUST NOT be flagged by gate-27 Rule E.

**Feature tier**: MVP

#### Scenario: Lifecycle activity is published through IManager

- WHEN a tracked pipelinq object (lead or request) is created, assigned,
  changes stage/status, gains a note, or a deal is won or lost
- THEN `ActivityService` MUST assemble an `OCP\Activity\IEvent` with the app id,
  type, subject, parameters, object reference, and affected user
- AND it MUST publish that event via `OCP\Activity\IManager::publish()`
- AND the published activity MUST appear in the `oc_activity` stream with the
  same content and affected-user visibility as before this change

#### Scenario: Gate-27 does not flag the first-party Activity API

- WHEN the gate-27 detector scans `lib/Service/ActivityService.php`
- THEN it MUST NOT report any `phantom-foundation-call` finding for
  `$this->activityManager->publish()` or the internal `$this->publish()` helper
- AND the only `publish()` calls gate-27 Rule E flags MUST be those whose
  receiver is an OpenRegister handle (`$objectService`, `$objectEntity`,
  `$object`, `$or`)

