# The agent chooses the escalation queue at wrap-up

## Why
Board PqGesprekAfronden draws an "Escalatiewachtrij" choice in the after-call wrap-up: when the outcome is Escalated, the agent picks where the follow-up goes ("Vergunningen, tweede lijn"). The dialog already has the field, but nothing feeds it and nothing carries the choice: `ctiApi.submitDisposition` drops `queue`, the endpoint has no `queue` parameter, and the follow-up task is written with a `queueName` the task schema doesn't have, so it reaches nobody.

## What changes
- **A queue is configuration, not a schema.** App config `cti_escalation_queues` is a JSON list of `{ id, label, groupId }`. Each queue routes to a Nextcloud group, which the task schema already carries as `assigneeGroupId` (team assignment). There is no new schema and no procedure name in code.
- `GET /api/cti/escalation-queues` lists the queues (`id`, `label`) for the wrap-up dialog. Same privilege check as the other CTI surfaces.
- `POST /api/cti/contactmoment/{id}/disposition` accepts an optional `queue`. With outcome `escalated`, the follow-up task gets the queue's group as `assigneeGroupId`. An unknown queue answers 400. Without a choice, the first configured queue is used. With no queues configured, the task has no group, as before.
- The wrap-up dialog lists the queues (IncomingCallCard loads them and passes `queueOptions`), and `submitDisposition` sends `queue`.
- The Zuiddrecht example set seeds the board's queues and their groups.

## Out of scope
The legacy single key `cti_escalation_queue` is no longer read, because it only fed the dropped `queueName`.
