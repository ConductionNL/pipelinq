# Tasks: portal-verifier-has-no-instance-secret-fallback

### Task 1: Verify with portaliq's dedicated secret only
- **spec_ref**: `openspec/changes/portal-verifier-has-no-instance-secret-fallback/specs/portal-contribution/spec.md#requirement-a-portal-assertion-is-verified-with-portaliqs-dedicated-secret-only-req-pvs-001`
- **files**: `lib/Portal/PortalAssertionVerifier.php`, `tests/Unit/Portal/PortalAssertionVerifierTest.php`
- **acceptance_criteria**:
  - GIVEN portaliq's secret is set WHEN an assertion signed with it arrives THEN it is accepted
  - GIVEN portaliq's secret is empty or shorter than 16 characters WHEN an assertion signed with the instance secret arrives THEN it is refused
- [x] Implement
- [x] Test
