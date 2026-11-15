---
kind: code
---

# Proposal: pl2-pos-bookings-server-rules

## Summary

Two rules that ran in the browser move to the server, so every writer and every user gets the same result.

- **Payment methods for cashiers.** The till's payment-method dropdown read the provider list from an admin-only
  endpoint. A cashier is not an admin, so the dropdown never showed a card provider. A new endpoint,
  `GET /api/pos-payments/methods`, names the methods of the active providers (provider, label, type, method) and nothing
  else, for any signed-in user. A provider's methods come from its config (`methods`), else a default list. The hint
  under the dropdown follows the provider type (online or terminal), not a provider name written into the code, and
  the dropdown's source strings are English.
- **Availability cache.** ResourceDetail and ServiceDetail deleted the cached availability from the browser, row by row,
  ignoring failures; an API write, an import or a sync left stale slots. A deferred listener
  (`AvailabilityCacheInvalidationListener`, ADR-078) now expires the cache of a changed resource, and of every resource a
  changed service runs on, for every writer.

Two user-facing strings lose their em-dash (Z report, tender panel).

## Scope

- `lib/Service/PosPaymentService.php`, `lib/Controller/PosPaymentController.php`, `appinfo/routes.php`
- `lib/Service/AvailabilityService.php`, `lib/Listener/AvailabilityCacheInvalidationListener.php`,
  `lib/BackgroundJob/DeferredObjectListenerJob.php`, `lib/AppInfo/Application.php`
- `src/components/pos/PaymentMethodSelector.vue`, `src/services/posPaymentApi.js`
- `src/views/bookings/ResourceDetail.vue`, `src/views/bookings/ServiceDetail.vue` (client invalidation removed)
- `l10n/` (en source, nl)

The provider configuration endpoints stay admin-only.
