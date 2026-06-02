# Appointment Booking — Compliance & i18n (Member 12) Delta Spec

## Purpose

Close out AVG right-to-be-forgotten pseudonymization, NL Boekhoudplicht retention,
WCAG 2.1 AA accessibility, code quality, and English/Dutch internationalisation.

---

## ADDED Requirements

### Requirement: REQ-APT-017 Compliance and Audit Trails

The system MUST provide AVG right-to-be-forgotten (pseudonymize, not delete), NL
Boekhoudplicht 7-year retention, and WCAG 2.1 AA accessibility on the public portal.

**Feature tier**: V1

#### Scenario: AVG right-to-be-forgotten pseudonymizes data

- **GIVEN** a customer exercises right-to-be-forgotten
- **WHEN** the request is processed
- **THEN** customer name, email, and phone on Bookings MUST be replaced with hashes (e.g. `sha256(email)`), but Booking records MUST NOT be deleted (7-year retention)
- **AND** aggregates (counts, totals) MUST remain unchanged

#### Scenario: Bookings are never auto-deleted

- **GIVEN** Bookings in terminal statuses (completed/cancelled/no-show)
- **WHEN** any retention sweep runs
- **THEN** the Booking records MUST be retained (no auto-delete), with the audit trail preserved

#### Scenario: Portal is WCAG 2.1 AA accessible

- **GIVEN** the public booking portal is tested with axe-core and keyboard navigation
- **WHEN** the tests run
- **THEN** zero WCAG AA violations MUST be found, all form fields MUST have associated labels, and colour MUST NOT be the sole carrier of information

### Requirement: REQ-APT-019 Unit Tests and Code Quality

Every new PHP service, controller, and background job MUST have PHPUnit tests; code
MUST pass phpcs, phpstan, and phpmd.

**Feature tier**: V1

#### Scenario: Code passes linters

- **GIVEN** all new PHP code is committed
- **WHEN** pre-commit hooks run
- **THEN** `phpcs`, `phpstan`, and `phpmd` MUST report zero violations

#### Scenario: Services meet coverage threshold

- **GIVEN** the booking services have unit tests
- **WHEN** `composer test` runs
- **THEN** all tests MUST pass with ≥80% coverage for services

### Requirement: REQ-APT-020 Internationalization (i18n)

All user-visible strings in the portal, admin UI, and email templates MUST have
English and Dutch translations. No hardcoded strings.

**Feature tier**: V1

#### Scenario: Portal displays in Dutch when locale is nl

- **GIVEN** the Nextcloud instance is configured for Dutch locale
- **WHEN** a customer visits `/book/haircut`
- **THEN** all labels, buttons, and placeholders MUST display in Dutch

#### Scenario: Translation key parity

- **GIVEN** `l10n/en.json` and `l10n/nl.json`
- **WHEN** the files are compared
- **THEN** they MUST have identical key sets (no missing keys) and no component MUST contain hardcoded user-visible strings
