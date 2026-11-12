# openregister-integration Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `auto-api`.

## ADDED Requirements

### Requirement: A script reads and writes the app's records through the API

The system SHALL let a script authenticated as a Nextcloud user read, create and update the app's records over the REST API, with the rights of that user.

#### Scenario: A script updates a client

- GIVEN a script authenticated as a user who may edit clients
- WHEN it lists clients and updates one client's phone number
- THEN the list holds the clients that user may read
- AND the new phone number shows on the client in the app
