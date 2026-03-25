## 1. Backend Service and Controller [MVP]

- [ ] 1.1 Create `lib/Service/ContactmomentService.php` with `delete(string $id, string $currentUserId)` method: fetch contactmoment from OpenRegister via SchemaMapService, check agent matches or user is admin via IGroupManager, delete or throw NotPermittedException
- [ ] 1.2 Create `lib/Controller/ContactmomentController.php` with `destroy(string $id)` action returning JSON response, using ContactmomentService and IUserSession
- [ ] 1.3 Add route `DELETE /api/contactmomenten/{id}` to `appinfo/routes.php`
- [ ] 1.4 Create `tests/Unit/Service/ContactmomentServiceTest.php` with tests: delete by creator, delete by admin, delete rejected for non-creator
- [ ] 1.5 Create `tests/Unit/Controller/ContactmomentControllerTest.php` with tests: destroy success, destroy forbidden, destroy not found

## 2. Fix Frontend Data Flow [MVP]

- [ ] 2.1 Fix router to import `ContactmomentenList` instead of `ContactmomentList` for the `/contactmomenten` route
- [ ] 2.2 Wire `ContactmomentForm.vue` save method to use object store `saveObject('contactmoment', data)` with proper error handling
- [ ] 2.3 Wire `ContactmomentDetail.vue` delete to call backend `DELETE /api/contactmomenten/{id}` instead of direct object store delete
- [ ] 2.4 Remove unused `src/views/contactmomenten/ContactmomentList.vue`

## 3. Quality and Cleanup [MVP]

- [ ] 3.1 Run `php -l` on all new/modified PHP files to verify syntax
- [ ] 3.2 Verify npm build succeeds with `npm run build`
