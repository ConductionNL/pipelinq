# message-unknown-consent-tone Delta: send-message

## ADDED Requirements

### Requirement: An unknown consent reads as unknown

The consent line under the channel switch SHALL use the ok tone only when the contact opted in on that channel. When the
consent is unknown, the line SHALL use the warning tone, also when sending on that channel is allowed.

#### Scenario: SMS to a contact without a recorded consent

- **GIVEN** a contact whose SMS consent is unknown
- **WHEN** the employee picks SMS
- **THEN** the line SHALL read "Toestemming voor sms: onbekend" in the warning tone, and sending SHALL stay possible
