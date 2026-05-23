# ADR-001: International First, Dutch API Mapping Layer

**Status:** accepted
**Scope:** pipelinq
**Applies to:** specs, design
**Last updated:** 2026-03-19

## Context

Pipelinq is a CRM built on Nextcloud that serves Dutch government organizations but is also positioned as an open-source international CRM. Dutch government APIs (VNG Klantinteracties, Verzoeken) define specific data models, but these are local standards that would limit international adoption if used as the primary data model.

Industry CRM standards (schema.org, vCard, iCalendar) are well-documented, widely understood, and enable integration with global tools. The Dutch government API specifications can be served as a mapping layer on top of international standards.

## Decision

- Contact data MUST be stored using schema.org (`schema:Person`, `schema:Organization`) and vCard properties (`fn`, `email`, `tel`, `adr`) as the primary vocabulary.
- Pipeline and deal data MUST align with schema.org types where applicable (`schema:Offer`, `schema:Action`).
- Dutch government API endpoints (Klantinteracties, Verzoeken) MUST be implemented as a **separate mapping layer** that translates between internal schema.org models and the Dutch API specification.
- The mapping layer MUST NOT pollute the core data model — Dutch-specific fields are derived/computed, not stored.
- Specs MUST describe data models in international terms first, with a separate section for Dutch API mapping where applicable.

## Consequences

- Spec authors MUST use schema.org/vCard property names in requirements (e.g., `fn` not `naam`, `email` not `emailadres`).
- Design documents for Dutch API features MUST include a mapping table (schema.org property → Dutch API field).
- This extends company-wide ADR-011 (Schema Standards) with Pipelinq-specific vocabulary choices.

## Exceptions

- BSN (Burgerservicenummer) is a Dutch-specific identifier with no international equivalent and MAY be stored as a custom property.
- Dutch government process types (zaaktypen) that have no international equivalent MAY use VNG terminology directly.
