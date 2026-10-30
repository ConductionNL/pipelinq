# appointment-booking Specification (delta)

## ADDED Requirements

### Requirement: The server expires cached availability when a resource or service changes

When a resource is created, changed or deleted, the server SHALL delete every cached availability row of that resource.
When a service is created, changed or deleted, the server SHALL delete the cached availability of every resource whose
type is one of the service's required resource types before or after the change, or of every resource when the service
names none. This SHALL hold for every writer (the pages, the API, an import, a sync) and SHALL run after the write, outside
the request. The pages SHALL NOT delete cache rows themselves.

#### Scenario: Working hours change through the API

- GIVEN a resource with cached availability for the next week
- WHEN its working hours are changed through the OpenRegister API
- THEN the cached days of that resource are deleted and the next slot search computes them again

#### Scenario: A service needs another resource type

- GIVEN a service that needed rooms and now needs rooms and advisers
- WHEN the service is saved
- THEN the cached availability of every room and every adviser is deleted
