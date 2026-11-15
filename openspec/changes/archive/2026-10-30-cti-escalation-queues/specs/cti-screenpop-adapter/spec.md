## ADDED Requirements

### Requirement: The agent chooses the escalation queue at wrap-up

The configured escalation queues (app config `cti_escalation_queues`, each `{ id, label, groupId }`) MUST be listed by `GET /api/cti/escalation-queues` and offered in the wrap-up dialog when the outcome is Escalated. A disposition with outcome `escalated` and a `queue` MUST create the follow-up task assigned to that queue's group (`assigneeGroupId`). An unknown `queue` MUST be rejected with 400. Without a `queue`, the first configured queue MUST be used.

#### Scenario: The chosen queue receives the follow-up
- GIVEN the queues "Vergunningen, tweede lijn" (group `zuiddrecht-tweede-lijn-vergunningen`) and "Belastingen, tweede lijn"
- WHEN an agent closes a call as Escalated and chooses "Vergunningen, tweede lijn"
- THEN a `followUpTask` is created with `assigneeGroupId` `zuiddrecht-tweede-lijn-vergunningen`

#### Scenario: An unknown queue is refused
- GIVEN the same queues
- WHEN a disposition arrives with outcome `escalated` and queue `nope`
- THEN the endpoint answers 400 and creates no task

#### Scenario: The dialog lists the queues
- GIVEN the queues above
- WHEN the wrap-up dialog opens and the agent picks Escalated
- THEN "Escalatiewachtrij (optioneel)" lists both queues by label
