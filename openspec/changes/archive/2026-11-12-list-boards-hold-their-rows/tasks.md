# Tasks: list-boards-hold-their-rows

## 1. Page config

- [x] 1.1 `pagination.limit` 5 on Campaigns and Contacts, 8 on Bookings and Clients
- [x] 1.2 Templates `sortKey: name`, `sortOrder: asc`
- [x] 1.3 Cash shifts: a View row action to CashShiftDetail
- [x] 1.4 Organisations preset: the DqOrganisaties columns (no Industry, no Phone)
  - Test: tests/vitest/listPageSizes.spec.js

## 2. Example data

- [x] 2.1 `zuiddrecht.d/85-week-bookings.json`: sixteen bookings this week after Friday 15:30, one of Pieter's, two awaiting a deposit
- [x] 2.2 Club-app, basis as `draft`
  - Test: tests/Unit/Settings/ZuiddrechtListRowsTest.php
