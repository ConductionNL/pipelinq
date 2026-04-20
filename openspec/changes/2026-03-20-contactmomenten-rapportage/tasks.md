# Tasks: contactmomenten-rapportage

## 1. Backend
- [x] 1.1 Create `lib/Service/ReportingService.php` with KPI calculation, channel distribution, and CSV export
- [x] 1.2 Create `lib/Controller/ReportingController.php` with reporting and SLA endpoints

## 2. Routes
- [x] 2.1 Add reporting API routes to `appinfo/routes.php`

## 3. Frontend Views
- [x] 3.1 Create `src/views/rapportage/RapportageDashboard.vue` — KPI widgets with auto-refresh
- [x] 3.2 Create `src/views/rapportage/ChannelAnalytics.vue` — Channel distribution
- [x] 3.3 Create `src/views/rapportage/AgentPerformance.vue` — Agent stats

## 4. Navigation and Routing
- [x] 4.1 Add reporting routes to `src/router/index.js`
- [x] 4.2 Add Rapportage entry to `src/navigation/MainMenu.vue`

## 5. Verification
- [x] 5.1 Run `npm run build` and verify no errors
