# Klantbeeld 360 - Full Enrichment

## Problem
The client detail view provides basic profile display (name, type, email, phone, website, address, notes, contacts sync badge), but the full 360-degree view is missing: interaction history timeline, linked cases (zaken), document management, BRP/KVK enrichment on demand, and aggregated contactmomenten.

## Current State (Implemented)
- ClientDetail.vue displays basic client profile fields
- "Synced with Contacts" badge when linked to Nextcloud Contacts
- Contact persons table on organization clients

## Proposed Solution
Extend ClientDetail.vue with klantbeeld tabs: interaction history (aggregated timeline), linked zaken (via ZGW/OpenConnector), documents (Nextcloud Files), BRP/KVK enrichment buttons, and aggregated contactmomenten. 83% of klantinteractie-tenders require this.

## Impact
- Extend ClientDetail.vue with multiple new tabs/sections
- BRP integration via OpenConnector
- KVK integration via existing KvkApiClient (extend for detail enrichment)
- ZGW Zaken API integration for case visibility
- Document linking via Nextcloud Files
- Depends on: kcc-werkplek (contactmoment schema), activity-timeline-v2
