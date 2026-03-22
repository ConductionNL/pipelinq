# KCC Werkplek

## Problem
No dedicated KCC agent workspace exists. No contactmoment schema, no citizen identification panel, no queue management. 100% of 52 klantinteractie-tenders require this capability.

## Proposed Solution
Build a unified agent screen combining citizen/business identification (BSN/KVK lookup), open case visibility, contact moment registration, and backoffice routing. Requires new contactmoment schema, agent dashboard, identification panel, and routing workflow.

## Impact
- New `contactmoment` schema in pipelinq_register.json
- New KCC werkplek view with agent dashboard
- BSN/KVK identification panel (extends existing KvkApiClient)
- Contact moment registration workflow
- Backoffice routing to teams/individuals
- Foundation for contactmomenten-rapportage and terugbel-taakbeheer
