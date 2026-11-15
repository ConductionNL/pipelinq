# Tasks: review-finish

## 1. Money in the reporting currency (B4)

- [x] 1.1 Add `src/services/reportingCurrency.js` (`reportingCurrency`, `currencyOr`)
  - Verify: `tests/vitest/reportingCurrency.spec.js`; the formatter cases fail on the old `commercialFormat.js`
- [x] 1.2 `formatCurrency`, `formatEur` and `formatEurCompact` default to the reporting currency
- [x] 1.3 My Work, forecast dashboard and trend, open pipeline KPI, service form, service and booking pages, booking portal pages use it
- [x] 1.4 Services index price uses the `objectCurrency` cell formatter, registered on CnAppRoot
- [x] 1.5 `AttributionService` labels links with the deal's or the reporting currency
  - Verify: `AttributionServiceTest::testAttributionCurrencyFollowsTheDealOrTheReportingCurrency` fails on the old code

## 2. Booking names

- [x] 2.1 Add `src/services/nameFormatters.js`; register `bookingCustomerName` and `bookingServiceName`
  - Verify: `tests/vitest/nameFormatters.spec.js`
- [x] 2.2 Booking data widget overrides use them

## 3. Default pipeline (R5)

- [x] 3.1 `PipelineStageData` leaves out null `totalsProperty`, `viewId` and `totalsLabel`; the sales totals label is the reporting currency
  - Verify: `PipelineStageDataTest::testDefaultPipelinesSatisfyThePipelineSchema` validates against `pipelinq_register.json` and fails on the old code with the reported message
- [x] 3.2 Pipeline form payload through `src/services/pipelinePayload.js`
  - Verify: `tests/vitest/pipelinePayload.spec.js` checks against the real schema fragment

## 4. Lead form

- [x] 4.1 Remove the probability input and its validation
  - Verify: `tests/vitest/leadFormNoProbability.spec.js` fails on the old form
