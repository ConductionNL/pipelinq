# Contact Relationship Mapping

## Problem
Pipelinq has no way to model relationships between contacts and clients (parent/child, partner, employer/employee). Government social domain requires family relationships for benefits eligibility. CRM sales requires understanding decision-making hierarchies, influencers, and gatekeepers.

## Proposed Solution
Add a `relationship` entity stored as an OpenRegister object with bidirectional typed links. Auto-create inverse relationships. Support both symmetric (partner-partner) and asymmetric (parent-child) relationship types. Add CRM-specific contact roles for deal management.

## Impact
- New `relationship` schema in `pipelinq_register.json`
- New relationship management UI components
- Integration with client and contact detail views
- V1 feature tier
