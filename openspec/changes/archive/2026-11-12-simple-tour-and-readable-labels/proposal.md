# Proposal: simple-tour-and-readable-labels

kind: fix. Round 3 of the pipelinq review (cloud check of 8 October 2026), lane PQ-R3B.

## Summary

The cloud check found four things a user of the simple menu runs into.

1. **No tour in the simple menu.** The getting-started tour is a sales journey. `holdUnreachableTours` holds it back in the simple menu, because Contacts, Products, Leads and Contracts are not in that menu. That left the simple profile with no tour at all, so CnAppRoot also dropped the Walkthrough section from the user settings. A user could not start or replay a tour. The simple profile now declares its own tour, a contact centre tour that only points at entries the simple menu has: the dashboard, Questions and reports, My Work, Queue, Residents and businesses, and Modules and more. The sales tour stays held back there, and the full structure keeps the sales tour as before. Because a tour exists, the user settings offer start, continue and start over again.
2. **Raw task values.** The task Type, Status and Priority showed their stored codes (`callbackRequest`, `in_progress`, `normal`) in the create form and the task list. The crmTask schema now declares `x-enum-labels` for all three, with en and nl catalogue entries.
3. **Raw notification rule keys.** The notification preferences in the user settings print the rule key (`clientUpdated`, `newLead`). OpenRegister's preference list carries no label, and the library printed the key. pipelinq now hands CnAppRoot a translated label for every rule it declares (`notificationLabels`, keyed `<schema>.<rule>`). The library side (the `notificationLabels` prop and the pane that reads it) is built in the parallel nextcloud-vue lane (`notification-rule-labels-and-runtime-version`); until pipelinq runs that version the prop is unused.
4. **"pipelinq 0.1.0" in the user settings footer.** `@nextcloud/vue` prints `<app> <appVersion>` there, and pipelinq's webpack config defined `appVersion` from `package.json` (0.1.0, never bumped). The release workflow writes the release version into `appinfo/info.xml` after the bundle is built, so a build-time read of `info.xml` would be one release behind too. `appVersion` is now an expression that runs in the browser and reads the `version` initial state the page controller provides from the installed app. It falls back to the build-time `info.xml` version on a page without that state.

## Out of scope

- **The `sales` group of the newLead rule** (and newContact, leadWon, newEnquiry, newTicket). pipelinq has no setting that names a sales group for notifications. `crm_group` widens CRM access and is not a notification audience. Choosing a group, creating one, or dropping the group recipient is a decision for Ruben.
- Rule labels that come from OpenRegister itself (a `label` key in the notification dialect). The dialect has no such key today.
