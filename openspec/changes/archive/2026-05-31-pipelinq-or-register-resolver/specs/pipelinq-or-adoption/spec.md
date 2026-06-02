# Capability — pipelinq-or-adoption (register-resolver slice)

## ADDED Requirements

### Requirement: Register lookups go through RegisterResolverService

All eight call sites of `$appConfig->getValueString(APP_ID, 'register', '')` SHALL be
migrated to `RegisterResolverService::resolve(...)` per the OR-side
`register-resolver-service` spec.

#### Scenario: Queue service uses resolver

- **GIVEN** the OR `register-resolver-service` spec is satisfied
- **WHEN** `QueueService` resolves its register at lines 57, 145, 236, or 292
- **THEN** the resolution SHALL go through `RegisterResolverService::resolve('queue')`
- **AND** no `getValueString(APP_ID, 'register', '')` call SHALL exist in
  `lib/Service/QueueService.php`.

#### Scenario: Default queue service uses resolver

- **GIVEN** the resolver service is available
- **WHEN** `DefaultQueueService` reads its register at lines 122 or 179
- **THEN** the resolution SHALL go through `RegisterResolverService`.

#### Scenario: Contact vCard services use resolver

- **GIVEN** the resolver service is available
- **WHEN** `ContactVcardService` (line 102) or `ContactVcardWriterService` (line 139)
  reads its register
- **THEN** the resolution SHALL go through `RegisterResolverService::resolve('contact')`.

#### Scenario: No remaining direct register reads

- **GIVEN** the migration is applied
- **WHEN** a developer greps `lib/` for `getValueString(APP_ID, 'register', '')`
- **THEN** zero matches SHALL be found.
