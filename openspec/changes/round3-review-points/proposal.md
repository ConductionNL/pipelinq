# Proposal: round3-review-points

## Why

The cloud check of 8 October found five points that still do not work for a
user:

1. Editing a client's phone in the client Edit dialog fails with "Cannot modify
   readOnly property: phone". The schema still marks name, email and phone as
   read-only mirrors of the Nextcloud Contact, while Ruben decided that they are
   edited on the client page and written back to the contact (#2214).
2. A user picked in a user field shows as a uid (`cluade`) in the task list and
   the task page, and "Created by" stays empty.
3. A new service loses the product of its first composition step: the create
   form drops `productId`, `quantity` and `unit` when it saves.
4. A deal line item is named after the product uuid, so the deal's Related card
   lists it as "Lead Product". The Line items count also says 0 right after a
   line is added, until the page is loaded again.
5. The "Client changed" notification has no link, so clicking it goes nowhere.

## What changes

| Item | Change |
| --- | --- |
| 1 | Register fragment `98-party-identity-editable.json` drops `readOnly` on `name`, `email` and `phone` of `client` and `contact`. The write-back to the Nextcloud Contact stays as it is. |
| 2 | A `userDisplayName` cell formatter shows a user's display name. Every user field in a list column or a data widget of the manifest uses it. A pre-save listener fills `createdBy` of a new task with the user who creates it. |
| 3 | The service create form keeps a step's product, quantity and unit. |
| 4 | `leadProduct.configuration.objectNameField` becomes the template `{{ product }}`, which OpenRegister's MetadataHydrationHandler resolves to the product's name. A line item that is created on any page refreshes the page's widgets, so the count follows. |
| 5 | Every pipelinq notification rule gets an "Open" action that targets the object's detail page (`target.kind: object-detail`). |

## Out of scope

OpenRegister sets no `link` on the notification itself: AnnotationNotifier only
adds action buttons. Clicking the notification text needs that link; the
report names what OpenRegister would have to add.
