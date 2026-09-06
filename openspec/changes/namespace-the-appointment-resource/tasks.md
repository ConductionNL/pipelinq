# Tasks

- [x] 1.1 Move the key, slug, register-list entry and schema references
      **files**: lib/Settings/register.d/45-appointment-booking.json, lib/Settings/pipelinq_mock_register.json, src/manifest.d/80-appointment-booking-admin.json
- [x] 2.1 Follow the slug through the booking views and the object-type map
      **files**: src/config/objectTypes.js, src/views/bookings/ResourceDetail.vue, src/views/bookings/ServiceDetail.vue, src/components/bookings/BookingsCard.vue, src/components/bookings/BookingDetailSection.vue
- [x] 3.1 Move the slug in `SCHEMA_SLUGS` and pin the old config key
      **files**: lib/Service/SettingsLoadService.php
- [x] 4.1 Add the rename to the colliding-slug map and extend its test to six slugs
      **files**: lib/Repair/RenameCollidingSchemaSlugs.php, tests/Unit/Repair/RenameCollidingSchemaSlugsTest.php
- [x] 5.1 Repoint the test stubs, leaving the NRC notification fixtures alone
      **files**: tests/Integration/AppointmentBookingRegisterTest.php, tests/Unit/Service/, tests/Unit/BackgroundJob/
