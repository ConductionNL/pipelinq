# Tasks: 06 REST Controllers

## BlastController (Task 2.6 of giant)

- [ ] Create `lib/Controller/BlastController.php` extending `Controller`
- [ ] Implement GET /api/blasts (paginated + status filter), POST /api/blasts (create draft), GET /api/blasts/:id, PATCH /api/blasts/:id (name), POST /api/blasts/:id/send, POST /api/blasts/:id/cancel, GET /api/blasts/:id/deliveries
- [ ] Derive user from `IUserSession`; generic error messages; methods <10 lines delegating to services
- [ ] Add routes to `appinfo/routes.php` before wildcard routes; add `@spec` PHPDoc

## SegmentController (Task 2.7 of giant)

- [ ] Create `lib/Controller/SegmentController.php`
- [ ] Implement GET /api/segments, POST /api/segments (validate rule tree via SegmentService), GET /api/segments/:id (with estimatedSize), GET /api/segments/:id/members, POST /api/segments/:id/size
- [ ] Add routes and `@spec` PHPDoc

## TemplateController (Task 2.8 of giant)

- [ ] Create `lib/Controller/TemplateController.php`
- [ ] Implement GET /api/templates, POST /api/templates (validate via ComplianceService), GET /api/templates/:id, PATCH /api/templates/:id
- [ ] On POST/PATCH call `validateTemplate()` before save; return proper errors
- [ ] Add routes and `@spec` PHPDoc
