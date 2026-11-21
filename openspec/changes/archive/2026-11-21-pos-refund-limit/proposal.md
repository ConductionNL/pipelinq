---
kind: code
depends_on: []
---

# A refund limit below which any till user may refund

## Why

Ruben decided on 12 Nov (decision 390) that a refund needs a manager only above a configurable
amount. Today every refund needs a POS manager (REQ-PLG-004 in `pos-lifecycle-guard-adoption`),
so a till user cannot hand back two euros without calling one.

### Matrix rows (pipelinq `openspec/parity/capabilities.json`)

| row | what changes |
|---|---|
| `pos-refund-guard` | refunds up to the limit need no manager; above it a manager approves |

## What changes

- A refund limit in the POS admin settings, in euros. Empty means every refund needs a manager,
  as today.
- `PosRefundManagerGuard` allows any till user with access to the transaction to refund and
  complete a refund whose gross is at or below the limit. Above it the manager rule stays.
- The over-refund cap and the per-object access check stay as they are.

## Impact

- `lib/Lifecycle/PosRefundManagerGuard.php`, the POS settings (board PqBeheerKassa), the refund
  screen (board PqKassaRetour). Not built yet: the matrix row moves to `specified`.
