# Proposal: round6-nextcloud-vue-2-78

kind: chore. Round 6 of the pipelinq review (cloud check of 10 October 2026), lane R6-BUMP-PQ.

## Summary

pipelinq moves from @conduction/nextcloud-vue ^2.76.0 to ^2.78.0. The library's dependencies are the same as in 2.76.0, so the lockfile changes in one entry. The new version brings:

1. Badge and link cells show the `x-enum-labels` text instead of the raw value.
2. The boolean check mark uses the success text colour.
3. The form draft indicator never says "Saved" next to a failed save. It says "Draft kept on this device".
4. The AI companion makes no health probe when hermiq is not installed.
5. The presence beacon sends the CSRF token in a FormData body.

The vendored manifest schema in `tests/schemas/app-manifest-v2.schema.json` is copied again from the library, because it differed.

pipelinq also stops asking buildiq for persisted manifest overrides when buildiq is not there. `loadPersistedOverrides()` sent `GET /apps/buildiq/api/app-overrides/pipelinq` on every page load and got a 404 back on every instance without buildiq. It now returns the bundled manifest straight away unless `useAppStatus('buildiq')` or `useAppStatus('openbuild')` reports the app enabled, the same keys the library's `useBuildiqEditAvailability` reads. The function moves from `src/main.js` to `src/services/persistedOverrides.js` so a test can load it.

## Out of scope

- pipelinq's own `enumLabel` formatter on badge columns. The library now does the same, but the formatter still works and removing it is not needed for this change.
- The PUT in `App.vue` `persistManifestDelta`. It only runs when someone saves an edit, which the library offers only with buildiq present.
