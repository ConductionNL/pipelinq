# Tasks: pl2-pos-bookings-server-rules

### Task 1: A cashier reads the payment methods
- **spec_ref**: `openspec/changes/pl2-pos-bookings-server-rules/specs/pos-payment-methods/spec.md#requirement-a-cashier-reads-the-payment-methods-of-the-active-providers`
- **files**: `lib/Service/PosPaymentService.php`, `lib/Controller/PosPaymentController.php`, `appinfo/routes.php`, `src/components/pos/PaymentMethodSelector.vue`, `src/services/posPaymentApi.js`
- [x] Implement: `GET /api/pos-payments/methods` (#[NoAdminRequired]), the selector reads it, hints by provider type, English source strings
- [x] Test: `tests/Unit/Service/PosPaymentServiceTest.php`, `tests/Unit/Controller/PosPaymentControllerTest.php`, `tests/vitest/paymentMethodSelector.spec.js`

### Task 2: The server expires cached availability
- **spec_ref**: `openspec/changes/pl2-pos-bookings-server-rules/specs/appointment-booking/spec.md#requirement-the-server-expires-cached-availability-when-a-resource-or-service-changes`
- **files**: `lib/Service/AvailabilityService.php`, `lib/Listener/AvailabilityCacheInvalidationListener.php`, `lib/BackgroundJob/DeferredObjectListenerJob.php`, `lib/AppInfo/Application.php`, `src/views/bookings/ResourceDetail.vue`, `src/views/bookings/ServiceDetail.vue`
- [x] Implement: the deferred listener, `invalidateResource()`, `invalidateForResourceTypes()`; the detail pages stop deleting cache rows
- [x] Test: `tests/Unit/Listener/AvailabilityCacheInvalidationListenerTest.php`, `tests/Unit/Service/AvailabilityServiceTest.php`

### Task 3: A payment is reversed only with a reason
- **spec_ref**: `openspec/changes/pl2-pos-bookings-server-rules/specs/pos-payment-methods/spec.md#requirement-a-payment-is-reversed-only-with-a-reason`
- **files**: `lib/Service/PosPaymentService.php`
- [x] Implement: an empty reason is refused with 422 (the reversal dialog's guard, now on the server)
- [x] Test: `tests/Unit/Service/PosPaymentServiceTest.php`
