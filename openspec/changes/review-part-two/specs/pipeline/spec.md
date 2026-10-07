# pipeline Specification (delta)

## ADDED Requirements

### Requirement: A repair step creates the default pipelines as the system account (REQ-RP2-010)

When the default pipelines are created with nobody signed in, as in the
repair step of `occ upgrade` or an app install, the system SHALL write them as
the dedicated `pipelinq-system` account with OpenRegister's access checks on.
It SHALL NOT write with `_rbac: false`. The account SHALL be created on first
use, disabled, with a random password, so nobody can sign in with it. The
previously active user SHALL be restored afterwards, also when the write
fails. When a user is signed in, as in the setup action, the pipelines SHALL
be written as that user.

#### Scenario: A fresh install

- GIVEN a fresh install where no user is signed in during the repair step
- WHEN the repair step creates the default pipelines
- THEN the Sales Pipeline and the Service Requests pipeline exist
- AND they were written as `pipelinq-system`

#### Scenario: The setup action

- GIVEN an admin runs the setup action
- WHEN the default pipelines are created
- THEN they are written as that admin
