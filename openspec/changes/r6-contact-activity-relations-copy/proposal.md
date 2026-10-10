# Proposal: r6-contact-activity-relations-copy

kind: fix. Round 6 of the pipelinq review (cloud check of 10 October 2026, `audit/cloud-check-r5/pipelinq/NOTES.md`), lane R6-PQ.

## Summary

1. **Creating a contact published "Lead created: " with no name.** `ActivityService::publishCreated()` mapped every entity type except `request` to `lead_created`, and `ObjectEventHandlerService` read the name from `title`, which a contact does not have. Only a lead and a request now get a pipelinq created activity, each with its own subject. A contact gets none: the activity specs name lead and request creation only, and OpenRegister already writes "Contact <name> created". The handler reads a contact's name from `name`.
2. **The Messages card on a client said "No contacts linked" after a contact was linked.** The card fetched the client's contacts once, on mount. A contact added on the Contacts tab goes through the contact-first create (`ContactAwareObjectListWidget`), which bypassed the library's create and so announced nothing. The widget now announces the create the way the library does (`cn-walkthrough:object-created`), the page refresh listener answers a new contact with a page refresh and a widget refresh, and the Messages card refetches the client's contacts on a page refresh. The empty-state copy loses its em-dash (en + nl).
3. **The contact create dialog showed Dutch help text in the English UI.** The `verifiedBSN` and `secrecy` descriptions were Dutch engineering notes, used as English source keys. The longer one was cut at "art." because the form shows the first sentence of a long description. Both get a short English description with a Dutch translation, and the engineering rationale moves to `x-notes`. A sweep of the visible contact and client fields found no other Dutch-only description.
4. **The tour's last step said "Click Modules and more in the menu"**, but in the simple menu that item sits in the Advanced foldout at the bottom of the navigation, which is closed. The step now says to open Advanced first. The tour library expands collapsed navigation groups, not the Advanced foldout, so changing the text is the clean fix.
5. **Contacts did not show in the client's Related card.** A contact's `client` reference is in its `_relations` (checked on the :8099 database: 16 of 16 contacts), so OpenRegister's `/used` lists contacts. The card loaded once and listened only to `cn:widget:refresh`, so a contact added on the page did not appear. The widget refresh in point 2 reloads it.

## Out of scope

- Other English descriptions on the contact form that read as engineering notes (`marketingConsent`, `doNotContact`, `surveyOptOut` mention service class names). They are English, not Dutch, so they are listed for a follow-up.
- The ticket supertype has no created activity at all (`isRelevantEntityType` lists `request`, which no schema maps to since unify-ticket-supertype). Not reported, left as is.
