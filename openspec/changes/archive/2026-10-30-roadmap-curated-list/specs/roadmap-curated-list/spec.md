# roadmap-curated-list Delta

## ADDED Requirements

### Requirement: The page reads the curated feature list

The Features & roadmap page SHALL show the features of `lib/Settings/roadmap.json`, in its order, grouped by its
categories, each with its status and its title and summary in the reader's language, and linked to its spec when it
names one. Only when that file is absent SHALL the page fall back to the `status: done` specs.

#### Scenario: Board PqRoadmap

- **WHEN** a Dutch reader opens Functies en roadmap
- **THEN** the page shows 36 features in Basis-CRM, Klantcontact en overheid, Analyse en inzicht, Automatisering en
  organisatie and Infrastructuur
- **AND** the filter counts read Alle 36, Beschikbaar 19, Beta 3, Gepland 14
