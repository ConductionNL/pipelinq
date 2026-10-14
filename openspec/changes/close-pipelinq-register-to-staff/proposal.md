# Close the pipelinq registers to staff

## Why

A live probe on the Zuiddrecht demo instance (2026-10-14) showed that any signed-in Nextcloud account reads pipelinq's records through OpenRegister's objects API. A self-registered member of the resident group `inwoners`, and an account in no group, both read all 96 tickets, 49 clients, 12 contacts, 29 leads, 63 consent records, 83 subscriptions, BRP lookups and BSN audit records. dossiq returns 0 for the same accounts since dossiq #3753 closed its register to its staff.

The cause: none of pipelinq's three registers had an authorization block, so OpenRegister treated the 120 schemas without a block of their own as open to every signed-in account, and 8 schemas named `authenticated` themselves. The demo lets anyone self-register an account, so this has to be closed before it goes public.

## What changes

- A last-sorting fragment, `register.d/99-zzz-register-access.json`, gives the `pipelinq`, `pipelinq-portal` and `sla` registers a block naming only the staff: the built-in group `sales` and `user:pipelinq-system`. The system account is the user `OrphanedLeadRepairService`, `DefaultPipelineService`, the web form intake and the held-object drain write as, with OpenRegister's checks on. A schema added later without a block is closed the day it ships.
- A schema's own block replaces the register's, so the 8 schemas that named `authenticated` (lead, ticket, webForm, enquiry, savedReply, channelMessage, channelConversation, messagingConsentRecord) name the staff instead. The messaging and portal service-account grants and the `public` create on `lead` (the website form) stay. `export` follows `read`.
- The shared vocabularies other apps read as the signed-in user (partyKind, partyFieldSet, partyIndicator for dossiq's party panel; product and productCategory for shillinq's catalog) stay readable by every signed-in account; they hold no personal data. Their writes, open to every account before, are closed to the staff.
- The register versions move 1.0.0 to 1.1.0, because OpenRegister writes a register's own fields only on a newer version.
- The deployment's CRM group (`crm_group`, the group `ObjectOwnerAccessPolicy` already treats as staff) cannot be named in a file. `RegisterAccessSync` grants it wherever `sales` is granted, after every import (`SettingsService::loadSettings`, which `InitializeSettings` calls on install and upgrade) and when the setting is saved, when the previous group is taken out. It never grants `authenticated`, `public`, `admin` or an empty value.

## Consequences

- dossiq embeds pipelinq's party panel and contact moments through providers that keep OpenRegister's checks on by design. A dossiq handler who is not in `sales` or the CRM group no longer sees them. That is the closure working; a deployment that wants handlers to see the customer view adds them to the CRM group.
