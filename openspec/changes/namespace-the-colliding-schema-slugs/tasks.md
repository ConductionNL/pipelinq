# Tasks

- [x] 1.1 Rename `cashCount` to `posCashCount` in the register fragments and mock register
      **files**: lib/Settings/register.d/40-pos-cash-management.json, lib/Settings/register.d/50-pos-end-of-day-bookkeeping.json, lib/Settings/register.d/60-pos-split-tender.json, lib/Settings/pipelinq_mock_register.json
- [x] 1.2 Rename `conversation` to `channelConversation` in the register fragment and mock register
      **files**: lib/Settings/register.d/80-whatsapp-sms-channel.json, lib/Settings/pipelinq_mock_register.json
- [x] 2.1 Follow the slugs in the frontend object-type map and the messaging view
      **files**: src/config/objectTypes.js, src/views/messaging/MessagingConversationSection.vue
- [x] 2.2 Follow the slug in the two channel adapters
      **files**: lib/Service/WhatsAppAdapter.php, lib/Service/SmsAdapter.php
- [x] 3.1 Move the slug in `SCHEMA_SLUGS` and pin the old config key
      **files**: lib/Service/SettingsLoadService.php
- [x] 4.1 Rename both rows in place before the import
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php, appinfo/info.xml
- [x] 4.2 Pin the write, the two refusals, and that a refusal does not stop the other rename
      **files**: tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
