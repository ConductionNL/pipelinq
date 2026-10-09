# Tasks: create-sales-group

- [x] 1.1 `lib/Repair/CreateSalesGroup.php`: create `sales` (display name "Sales") through IGroupManager when `groupExists('sales')` is false, log the creation, change nothing otherwise, never touch members
- [x] 1.2 Register the step in `appinfo/info.xml` under `<install>` and `<post-migration>`
- [x] 1.3 `tests/Unit/Repair/CreateSalesGroupTest.php`: created when missing, untouched when present, registered in both blocks, and every group the register's notification rules address is the group the step creates
  - Verify: fails on development (the class does not exist)
- [x] 1.4 `RepairStepRegistrationTest` stays green
