# Proposal: create-sales-group

kind: fix. Follow-up to the pipelinq review round 3 (Ruben's decision, 9 October 2026).

## Summary

Five notification rules address the Nextcloud group `sales`: newContact, newLead, leadWon, newEnquiry and newTicket. Nothing created that group. On an instance without it, OpenRegister resolved no recipient for that entry (the cloud check saw `recipient-unresolved` for newLead).

pipelinq now creates the group `sales`, display name "Sales", in an idempotent repair step `CreateSalesGroup`. The step runs on install and after every upgrade, so existing instances get the group too. When the group exists the step changes nothing. It never adds or removes members: an administrator decides who is in sales.

## Out of scope

- A setting that points the rules at another group.
- Filling the group.
