# OpenRegister integration

## ADDED Requirements

### Requirement: The last two colliding slugs are namespaced (REQ-ORI-048)

The billable expense schema SHALL be `billableExpense` and SHALL NOT be
`expense`. humaniq's employee reimbursement claim keeps the bare slug.

The master-entity merge schema SHALL be `masterMergeOperation` and SHALL NOT be
`mergeOperation`. openregister's object merge keeps the bare slug.

Neither pair SHALL be folded. `expense` shares eight fields with humaniq's, but
all eight are generic expense attributes and there is no receipt or expense
number to say the two rows are the same expense. `mergeOperation` shares its
merge mechanics with openregister's while recording a different id space:
uuids there, master ids here.

The rename SHALL NOT touch `expense` where it is a Nextcloud notification
`objectType`. `ExpenseApprovalListener` SHALL move, because it compares against
`SchemaMapService::resolveEntityType()`, whose value follows the slug.

#### Scenario: Both slugs are renamed in place

- **GIVEN** an install carrying `expense` and `mergeOperation`
- **WHEN** the repair step runs
- **THEN** each row keeps its schema id, and so its shard table and objects.

#### Scenario: The approval listener still recognises an expense

- **WHEN** an object on the billable expense schema is approved
- **THEN** the listener resolves its entity type and acts on it.
