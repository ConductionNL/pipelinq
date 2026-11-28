---
kind: code
---

# Proposal: store-publish-templates

## Summary

pl2 PqStore Publiceren: an administrator publishes a template built from this instance's own configuration to the store's registry.

- **Descriptor:** pipelinq's store descriptor becomes publishable. It sends title, description, kind, category, version, publisher, icon and components. Its publish group is `admin`; administrators always may publish (OpenRegister StoreActionAuthorizer).
- **`GET /api/store/publishable?kind=`:** lists the local configuration objects a template of that kind may carry. The kinds map to the install allowlist:

  | Kind | Schemas |
  |---|---|
  | pipeline | pipeline |
  | queue-routing | skill |
  | catalogue | product, productCategory |
  | pos-setup | posRole, posTenderType, receiptTemplate, refundReason |
  | loyalty-programme | loyaltyProgramme, pointsRule |
  | connector | berichtenboxTemplate |

- **`POST /api/store/publish`:** takes `{item, objects}`.
  - `StoreTemplateAssembler` turns the chosen objects into `components`, without their identity (id, uuid, @self, organisation, owner and the timestamps), as install expects them.
  - An object outside the kind, or not on this instance, refuses the publish (400).
  - The item then goes through OpenRegister's `GenericStoreService::publish()`, after `StoreActionAuthorizer::canPublish()`.
  - With the Zuiddrecht set's `local` registry, the item lands in this instance's own `commercial-template` objects.

## Depends on

- **OpenRegister** feat/store-publish-route: `publish()` into a `local` registry.
- **nextcloud-vue** feat/store-publish-dialog: the Publish dialog on the store page.
