# Proposal: nextcloud-vue-2-73-1

kind: chore. Round 3 of the pipelinq review, lane BUMP-PQ.

## Summary

pipelinq moves from `@conduction/nextcloud-vue` ^2.71.0 to ^2.73.1. That release ships the two library halves that round 3 (`simple-tour-and-readable-labels`, pipelinq#2318) waited for.

1. **Notification rule labels show.** CnAppRoot now takes the `notificationLabels` prop that `App.vue` already passes, and the notification preferences in the user settings read it (`notificationRuleLabel`). A person reads "A new lead comes in" where the settings printed `newLead`. A rule with no label reads as its key split into words.
2. **One `appVersion` helper.** pipelinq#2318 carried its own copy of the `appVersion` define in `scripts/appVersionDefine.js`, until the library shipped it. `webpack.config.js` now calls `appVersionDefine('pipelinq', <info.xml version>)` from `@conduction/nextcloud-vue/webpack`, and the copy is gone. The info.xml reader it still needs lives in `scripts/readInfoXmlVersion.js`. `DashboardController` keeps providing the `version` initial state the expression reads.

The library's dependencies are the same as in 2.71.0. The vendored `tests/schemas/app-manifest-v2.schema.json` is byte-identical to the one 2.73.1 ships, so it stays as it is.

## Out of scope

- Other apps that carry their own `appVersion` define.
