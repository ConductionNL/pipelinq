# Tasks: services-and-resources-at-a-glance

## 1. Eligible resources endpoint
- [ ] 1.1 `GET /api/appointment-services/{id}/eligible-resources` in `lib/Controller/` (route in `appinfo/routes.php`, `#[NoAdminRequired]` with a per-object read check), calling `EligibilityService::getEligibleResources()` (`lib/Service/EligibilityService.php:100`, REQ-APT-004); list endpoint variant returning a count per service id for the list page
  - spec_ref: `openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002`
  - Verify: PHPUnit over the real eligibility service; a resource on another type or without the skill is not counted; inactive or non-bookable resources are not counted

## 2. Busy figures
- [ ] 2.1 Service figures (bookings this week, no-shows this month) and resource figures (bookings this week, booked over bookable minutes) in one backend call per page, using OpenRegister list queries
  - spec_ref: `#requirement-a-service-page-shows-how-busy-the-service-is-req-srg-003`, `#requirement-a-resource-page-shows-how-much-of-its-bookable-time-is-booked-req-srg-004`
  - Verify: PHPUnit with a week spanning a vacation; a week with zero bookable minutes returns no share

## 3. Pages
- [ ] 3.1 `src/manifest.d/80-appointment-booking-admin.json`: one Services and resources page with tabs; `/services` and `/resources` open their tab; services columns, views with counts, footnote
  - spec_ref: `#requirement-services-and-resources-share-one-page-with-tabs-req-srg-001`
- [ ] 3.2 Match line cell on the services list (count from task 1)
- [ ] 3.3 `src/views/bookings/ServiceDetail.vue`: Bookings figure, Appointments for this service, Resources that can do this
- [ ] 3.4 `src/views/bookings/ResourceDetail.vue`: This week figure
- [ ] 3.5 l10n nl and en for every new string; colours through CSS variables
  - Verify: vitest for the new cells and cards; one Playwright test on the example services and Fatma Yildiz

## 4. Example data
- [ ] 4.1 `lib/Settings/pipelinq_example_register.json`: the board's seven services and the resources they match, with bookings in the current week
