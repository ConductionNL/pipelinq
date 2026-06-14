# Proposal: AVG-verzoeken (Article 15/16/17/18/20 GDPR) Workflow

## Problem

Citizens have inalienable rights under the General Data Protection Regulation (GDPR / AVG) to access, correct, erase, and port their personal data. Dutch municipalities must respond to these five operational rights within strict legal deadlines (30 days, extendable to 60 days with justification). In practice, handling these requests is complex:

- Citizen data is scattered across multiple systems (BRP, case systems, email, OpenConnector sources, archives)
- Handlers must gather evidence, redact third-party information, and export bundles — all within 30 days with documented proof
- Organizations must report to the Data Protection Authority (AP) and retain dossiers for 5 years
- Patterns of similar requests may indicate systemic data processing issues requiring privacy impact assessment (DPIA)

Currently, municipalities lack a structured workflow to handle these requests end-to-end, risking legal non-compliance and AP sanctions.

## Solution

Implement a complete AVG request workflow in Pipelinq with:

1. **Intake & Article Classification**: Web form or manual registration with automatic article type detection (art. 15 access, art. 16 correction, art. 17 erasure, art. 18 restriction, art. 20 portability)
2. **Deadline Tracking**: 30-day legal timer with automatic escalation at 3 days remaining, 7-day advance notice, and breach logging
3. **60-day Extension**: Justified extension with mandatory reasoning and automatic citizen notification
4. **Federated Evidence Collection**: Query OpenRegister, BRP (BSN validation), OpenConnector sources, linked apps (DocuDesk, Talk, case management)
5. **Data Export Bundle**: Structured JSON + legally signed PDF with integrity hash (PAdES-LTV)
6. **Redaction Tool**: Visual blackout of third-party data with before/after comparison for 4-eyes control
7. **Denial with Grounds**: Explicit refusal under GDPR Art. 23 exceptions with mandatory AP appeal reference
8. **AP Escalation Path**: Complete dossier bundle export on citizen complaint
9. **5-Year Retention**: Dossier preservation per RvIG guidelines; pseudonymization of evidence after 30 days
10. **DPIA Flagging**: Automatic detection of patterns (10+ similar erasure requests in 30 days) triggering FG review

## Scope

**In scope:**
- AvgVerzoek entity with full lifecycle (intake → in-treatment → resolved/denied)
- TermijnEvent tracking (deadlines, escalations, breaches)
- BewijsItem collection from federated sources
- ExportBundle generation with JSON + PDF formats
- RedactieActie for third-party data masking
- Weigering (denial) with GDPR Art. 23 grounds
- Deadline management (30 days + optional 60-day extension)
- DPIA-flag detection and FG notification
- 5-year retention policy

**Out of scope:**
- BRP-lookup implementation (existing capability dependency)
- OpenConnector endpoint development (organization responsibility)
- DocuDesk PDF rendering (existing app integration)
- AP API integration for direct complaint submission (future)
- Batch AI-based redaction (future enhancement)

## Success Criteria

- Intake form correctly classifies article type and calculates legal deadline
- Evidence collection queries OpenRegister and OpenConnector sources without blocking the handler
- PDF export is legally signed and verifiable with PAdES-LTV
- Redaction tool prevents accidental masking of citizen's own data
- Denial letters include mandatory AP complaint reference with active URL
- Deadline escalation notifies team lead at 3 days remaining
- DPIA flag triggers on pattern detection and creates Procest improvement task
- Dossier retains full audit trail for 5 years; evidence pseudonymized after 30 days
- Handler completes a standard art. 15 request (inzage) in <20 minutes of active work
