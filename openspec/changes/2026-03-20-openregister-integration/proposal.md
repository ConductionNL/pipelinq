# Proposal: OpenRegister Integration Tests

## Problem
The ConfigFileLoaderService and SettingsMapBuilder services lack unit test coverage. These are critical for the register initialization flow.

## Solution
Add unit tests for both services covering the key scenarios from the spec:
- SettingsMapBuilder: schema slug map building, register ID extraction, view ID extraction
- ConfigFileLoaderService: sourceType handling, file-not-found exception

## Scope
- `tests/Unit/Service/SettingsMapBuilderTest.php` — new test file
- `tests/Unit/Service/ConfigFileLoaderServiceTest.php` — new test file
