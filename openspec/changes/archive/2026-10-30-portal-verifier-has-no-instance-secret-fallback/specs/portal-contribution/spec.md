# portal-contribution Specification (delta)

**Status**: planned
**OpenSpec changes**:
- [portal-verifier-has-no-instance-secret-fallback](../../) (this delta)

## ADDED Requirements

### Requirement: A portal assertion is verified with portaliq's dedicated secret only (REQ-PVS-001)

The app SHALL verify a portal assertion only with portaliq's dedicated `jwt_signing_secret`, read decrypted through `IAppConfig`. It SHALL NOT fall back to the Nextcloud instance `secret` or any other key. When portaliq's secret is empty or shorter than 16 characters, every assertion SHALL be refused.

#### Scenario: Portaliq's secret is missing
- GIVEN portaliq has no dedicated signing secret
- WHEN a request arrives with an assertion signed with the Nextcloud instance secret
- THEN the app refuses it as if it carried no assertion
- @e2e exclude pinned by `PortalAssertionVerifierTest::testNoFallbackToTheInstanceSecret`
