# pos-kassakoppeling-audit Specification (delta)

## ADDED Requirements

### Requirement: An audit entry is a declared detail page under an English address

An audit entry SHALL open at `/cash-register-audit/{id}` and SHALL show its verification status, its summary with the
amounts in money, and its signature and hashes with copy buttons. Verify signature SHALL be offered while the entry is not
verified. The old `/kassakoppeling/audit` addresses SHALL redirect to the new ones.

#### Scenario: An old link

- GIVEN a bookmark to `/kassakoppeling/audit/a1`
- WHEN the auditor opens it
- THEN the page opens at `/cash-register-audit/a1` and shows entry a1
