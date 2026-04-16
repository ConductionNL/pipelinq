# Tasks: omnichannel-registratie

## 1. Data Model
- [x] 1.1 Add `contactmoment` schema to `pipelinq_register.json`
- [x] 1.2 Define per-channel sub-schemas for `channelMetadata` (telefoon, email, balie, chat, social, brief)
- [x] 1.3 Update register's schemas list to include contactmoment

## 2. Backend — Controller and Service
- [x] 2.1 Create `lib/Controller/ContactmomentController.php` with `destroy()` REST endpoint
- [x] 2.2 Create `lib/Service/ContactmomentService.php` with permission-checked delete logic
- [x] 2.3 Implement authorization checks: only creating agent or Nextcloud admin may delete
- [x] 2.4 Implement error handling: DoesNotExistException (404), NotPermittedException (403), Exception (500)
- [x] 2.5 Add @spec PHPDoc tags to Controller and Service methods

## 3. Backend — Tests
- [x] 3.1 Create `tests/Unit/Controller/ContactmomentControllerTest.php`
- [x] 3.2 Create `tests/Unit/Service/ContactmomentServiceTest.php`
- [x] 3.3 Test delete endpoint with valid user (success case)
- [x] 3.4 Test delete endpoint with unauthorized user (403 NotPermittedException)
- [x] 3.5 Test delete endpoint with missing contactmoment (404 DoesNotExistException)

## 4. Frontend Views
- [x] 4.1 Create `src/views/contactmomenten/ContactmomentenList.vue` using `CnIndexPage` wrapper
- [x] 4.2 Create `src/views/contactmomenten/ContactmomentForm.vue` with adaptive channel selection
- [x] 4.3 Create `src/views/contactmomenten/ContactmomentDetail.vue` using `CnDetailPage`/`CnDetailCard`
- [x] 4.4 Create `src/components/CallTimer.vue` with MM:SS timer controls
- [x] 4.5 Implement CSV export safeguard: escape formula-trigger characters in CSV output

## 5. Frontend — Integration and Permissions
- [x] 5.1 Wire ContactmomentDetail delete button to DELETE `/api/v1/contactmomenten/{id}` endpoint
- [x] 5.2 Enforce frontend authorization: show delete button only to agent/admin via ContactmomentController permission check
- [x] 5.3 Handle API error responses: 401 (auth), 403 (permission), 404 (not found), 500 (error)

## 6. Navigation and Routing
- [x] 6.1 Add contactmomenten routes to `src/router/index.js`
- [x] 6.2 Add Contact Moments entry to `src/navigation/MainMenu.vue`

## 7. Standards Compliance
- [x] 7.1 Add EUPL-1.2 SPDX headers to all new `.vue` and `.php` files
- [x] 7.2 Use `t()` helper for all user-visible strings in Views and Components

## 8. Verification
- [x] 8.1 Run `npm run build` with no errors
- [x] 8.2 Run `composer check:strict` to verify PHP code quality
- [x] 8.3 All unit tests pass (Controller, Service, optional integration tests)
