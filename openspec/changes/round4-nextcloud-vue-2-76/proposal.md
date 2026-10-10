# Proposal: round4-nextcloud-vue-2-76

kind: chore. Round 4 of the pipelinq review (cloud check of 9 October 2026), lane BUMP2-PQ.

## Summary

pipelinq moves from @conduction/nextcloud-vue ^2.73.1 to ^2.76.0. The new library version fixes three things the cloud check found in pipelinq screens:

1. **The object lock.** The lock request on a detail page put the text of a getter function in the URL and came back 404. The library now resolves a getter to its value, so the request goes to `/apps/openregister/api/objects/pipelinq/client/<uuid>/lock`.
2. **The Related card.** CnRelatedObjectsWidget waited for every relation call before it drew anything. It now fills its sections one by one and shows "Still loading" for the ones still on their way.
3. **Add buttons.** CnObjectListWidget now runs `content.addLabel` through the app's translate function.
4. **Credentials text.** The CnCredentials copy text no longer carries em-dashes.

The library's dependencies are the same as in 2.73.1.

Because the library now translates `content.addLabel`, pipelinq's own pre-translation (`src/utils/widgetAddLabels.js`, added in the change round4-readable-values-and-tour-titles) is removed. It was harmless (no Dutch Add label is itself a catalogue key) but it did the same work twice. The manifest keeps the English source text.

The vendored manifest schema in `tests/schemas/app-manifest-v2.schema.json` is copied again from the library, because it differed.

## Out of scope

- Other library changes between 2.73.1 and 2.76.0 that pipelinq does not use.
