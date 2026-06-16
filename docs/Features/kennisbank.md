# kennisbank

**Status:** Deprecated — superseded by the xWiki integration.

> The bespoke in-app kennisbank (Pipelinq's original Markdown article
> editor + `kennisartikel` / `kenniscategorie` / `kennisfeedback` schemas)
> was removed as part of the `migrate-kennisbank-to-xwiki-leaf` change.
> Knowledge content now lives in xWiki and is surfaced via the OpenRegister
> `integration-xwiki` leaf when configured, with the in-app
> `xwiki-integration` proxy (this change) providing a graceful fallback
> for tenants whose leaf environment is not yet wired. See
> `openspec/changes/xwiki-integration/proposal.md` and
> `openspec/changes/migrate-kennisbank-to-xwiki-leaf/proposal.md` for
> details.

## Overview

The kennisbank (knowledge base) provides KCC agents with a searchable repository of articles, FAQs, and procedures to answer citizen questions quickly and consistently. Articles are categorized, versioned, and linked to zaaktypen so agents can find the right information for each type of inquiry. Thi


## Specification

Full specification: `openspec/specs/kennisbank/spec.md`

## Related Files

- Spec: `openspec/changes/kennisbank/specs/kennisbank/spec.md`
- Design: `openspec/changes/kennisbank/design.md`
- Tasks: `openspec/changes/kennisbank/tasks.md`
