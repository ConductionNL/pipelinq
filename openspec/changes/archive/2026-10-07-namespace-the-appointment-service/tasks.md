# Tasks

- [x] 1.1 Move the key, slug, register-list entry and schema references
      **files**: lib/Settings/register.d/45-appointment-booking.json, lib/Settings/pipelinq_mock_register.json, src/manifest.d/80-appointment-booking-admin.json
- [x] 2.1 Follow the slug in the object-type map, settings list and booking views
      **files**: src/config/objectTypes.js, lib/Service/SettingsLoadService.php, src/views/bookings/ServiceDetail.vue, src/components/bookings/BookingsCard.vue, src/components/bookings/BookingDetailSection.vue, src/store/modules/services.js
- [x] 3.1 Add the rename to the colliding-slug map, pin the config key, extend the test to nine slugs
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php, lib/Service/SettingsLoadService.php, tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
- [x] 4.1 Repoint the test stubs, leaving product types and complaint categories alone
      **files**: tests/Integration/AppointmentBookingRegisterTest.php, tests/Unit/, tests/e2e/spec-coverage/appointment-booking.spec.ts
