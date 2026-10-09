# Proposal: round4-readable-values-and-tour-titles

kind: fix. Round 4 of the pipelinq review (cloud check of 9 October 2026), lane R4-PQB.

## Summary

The cloud check found four things a user reads that are not words, or not in their language.

1. **Tour steps without a title.** Steps 2 to 7 of the simple menu tour `pipelinq:contact-centre` had no title, so the dialog read "Step 2 of 8:" and nothing after it. Steps 2 to 11 of the full menu tour `pipelinq:getting-started` had the same gap. Every step now has a short title, with en and nl catalogue entries.
2. **Stored codes on screen.** The client Type showed `person` and `organization`, the lead Status and Priority `open` and `normal`, and the service page `free` (cancellation policy) and `staff` (resource type). A register fragment, `99-zz-readable-values.json`, gives every enum on the client, lead, service and resource schemas an `x-enum-labels` map, the way the crmTask fix did. Three places pipelinq draws itself printed the code directly: the client create form, the lead create form and the service page. They now read the same labels from `src/utils/enumLabels.js`, and a test keeps those maps equal to the register.
3. **Dutch text in the English interface.** The client page's contact person list had an Add button reading "Contactpersoon toevoegen". CnObjectListWidget prints `content.addLabel` as written, so the manifest now holds the English "Add contact person" and pipelinq translates every object-list Add label before the manifest reaches CnAppRoot. Dutch users still read "Contactpersoon toevoegen". The Requests list on the same page had the same problem ("Nieuw verzoek") and now reads "New request".
4. **Em-dashes in the Credentials text.** That text is `CnCredentials` in @conduction/nextcloud-vue, not pipelinq. It is left to the library lane (R4-LIB).

While on the service page: the text for a service without composition steps carried an em-dash. It now reads "This service has one step."

## Out of scope

- The Credentials text (item 4): library lane R4-LIB.
- Enum fields inside lists of objects (contact channel kinds, social networks, working hours days, step units). pipelinq's own editors draw those with their own labels.
- The library translating `content.addLabel` itself. Once it does, pipelinq's pre-translation stays harmless: a translation is not a catalogue key, so it comes back unchanged.
