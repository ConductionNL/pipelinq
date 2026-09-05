# Tasks

- [x] 1.1 Move the key, slug and register-list entry, and the mock register references
      **files**: lib/Settings/register.d/80-whatsapp-sms-channel.json, lib/Settings/pipelinq_mock_register.json
- [x] 2.1 Follow the slug in the two channel adapters and the cost reconciliation service
      **files**: lib/Service/WhatsAppAdapter.php, lib/Service/SmsAdapter.php, lib/Service/CostReconciliationService.php
- [x] 2.2 Follow the slug in the object-type map, leaving the two sibling slugs alone
      **files**: src/config/objectTypes.js
- [x] 3.1 Add the rename to the colliding-slug map and extend its test to seven slugs
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php, tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
- [x] 4.1 Repoint the one integration-test schema reference
      **files**: tests/Integration/OutboundMessagingContractTest.php
