# Tasks

- [x] 1.1 Move the key, slug, register-list entry and schema references
      **files**: lib/Settings/register.d/45-appointment-booking.json, lib/Settings/pipelinq_mock_register.json, src/manifest.json, src/manifest.d/80-appointment-booking-admin.json
- [x] 2.1 Follow the slug in the booking service, portal provider and settings list
      **files**: lib/Service/BookingService.php, lib/Portal/PortalContributionProvider.php, lib/Service/SettingsLoadService.php
- [x] 2.2 Follow the slug in the object-type map and the booking components
      **files**: src/config/objectTypes.js, src/components/bookings/BookingsCard.vue, src/components/bookings/BookingDetailSection.vue
- [x] 3.1 Add the rename to the colliding-slug map and pin the old config key
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php, lib/Service/SettingsLoadService.php
- [x] 3.2 Extend the repair test to eight slugs
      **files**: tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
- [x] 4.1 Repoint the test stubs, leaving log and template context keys alone
      **files**: tests/Integration/AppointmentBookingRegisterTest.php, tests/Unit/, tests/e2e/spec-coverage/appointment-booking.spec.ts
