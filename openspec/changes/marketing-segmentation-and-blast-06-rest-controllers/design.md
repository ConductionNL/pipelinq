# Design: 06 REST Controllers

## Scope

`lib/Controller/BlastController.php`, `SegmentController.php`,
`TemplateController.php` + `appinfo/routes.php`. Delegate to BlastService (04),
SegmentService (02), ComplianceService (03). No Vue.

## Endpoints

- **BlastController**: `GET /api/blasts` (paginated + status filter),
  `POST /api/blasts` (create draft), `GET /api/blasts/:id`,
  `PATCH /api/blasts/:id` (name only), `POST /api/blasts/:id/send`,
  `POST /api/blasts/:id/cancel`, `GET /api/blasts/:id/deliveries`.
- **SegmentController**: `GET /api/segments`, `POST /api/segments` (validate
  rule tree via SegmentService before save), `GET /api/segments/:id` (with
  estimatedSize), `GET /api/segments/:id/members`, `POST /api/segments/:id/size`.
- **TemplateController**: `GET /api/templates`, `POST /api/templates`
  (validate via ComplianceService), `GET /api/templates/:id`,
  `PATCH /api/templates/:id`.

## Security / patterns

ADR-005: derive user from `IUserSession` — never trust frontend user ID;
generic error messages (no internal detail). ADR-003: controller methods
<10 lines, delegate to services. ADR-016: routes only in `appinfo/routes.php`,
specific before wildcard. `@spec` PHPDoc on every method.
