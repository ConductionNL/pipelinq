---
kind: code
depends_on: []
---

# See the score of every lead in the list and on the board, and work the highest first

## Why

Leads in the pipeline already carry a score. The `lead` schema computes `qualificationScore` (0 to 100) on every save through an `x-openregister-calculations` annotation (`lib/Settings/pipelinq_register.json`, `lead.configuration`), from value, client, contact, source, expected close date, priority and description, and the lead detail page shows it. The matrix note that a score on a pipeline lead is "specified and not built" is out of date on the calculation and right on the rest: the Leads list has no score column (`src/manifest.json` `/leads` columns), the board card shows no score, neither can be sorted by it, and nobody can see why a lead got the number it got. The point of a score is to decide which lead to call first; today nobody can read it where that decision is made. HubSpot, Pipedrive and Odoo rate `yes`, and the row is in the core area (`pipeline`), so the missing half is built. The scoring model itself, and signals such as mail opens, are not part of this change.

This change covers 1 row(s) of the parity matrix (`openspec/parity/capabilities.json`), decided `build` on 2026-09-28.

**lead-scoring** (matrix `pipelinq`, area `pipeline`), "Let the app score a lead so you know which one to call first" Rated `partial` for us, `built.state` `building`.
- Demand: None. No demand row; the row comes from our own code or our own matrix.
- HubSpot CRM, yes: https://knowledge.hubspot.com/scoring/understand-the-lead-scoring-tool "build custom lead scores based on record actions or properties ... to evaluate which leads are likely to become customers" for contacts, companies and deals. Marketing Hub or Sales Hub Professional, Enterprise.
- Pipedrive, yes: https://support.pipedrive.com/en/article/scores "The Scores feature in Pipedrive increases your efficiency by helping you evaluate the likelihood of closing deals successfully" with your own criteria per pipeline ("Note: This feature is available on Premium and higher plans"); an AI scoring toggle is listed at https://www.pipedr
- Odoo CRM, yes: addons/crm/models/crm_lead.py:225 "automated_probability = fields.Float('Automated Probability'" computed from addons/crm/models/crm_lead_scoring_frequency.py, switched on under CRM Settings "Predictive Lead Scoring" (addons/crm/views/res_config_settings_views.xml:41) and shown on the lead form with a reset button (crm_lead_view
- Matrix note: Prospects are scored today. A score on a lead already in the pipeline is specified and not built.

## What changes

- The Leads list gets a "Score" column, sortable, rendered as a number with a colour band and a text label (not colour alone).
- The board card shows the score next to the value, and the board can be ordered by score within a column.
- A quick filter "Call first" sorts open leads by score, highest first, ties broken by the older last update.
- The score badge opens a small explanation listing which criteria added points, so a salesperson can see what to fix to raise it.
- No new field, no new calculation, no schema change.

## Capabilities

### Modified capabilities
- `lead-management`: adds the score column, board score, sort and explanation requirements.
