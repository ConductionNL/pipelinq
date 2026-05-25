# Proposal: pos-staff-pin-permissions

## Problem

Pipelinq's POS module has no per-staff authentication mechanism — all operators share a session
or operate without identification. This creates three compounding problems:

1. **No access control**: any operator can void transactions, apply unlimited discounts, process
   refunds, or open the cash drawer without a transaction (no-sale). There is no differentiation
   between a cashier, supervisor, and manager.

2. **No sales attribution**: because staff are unidentified, sales cannot be attributed per person.
   This blocks per-staff revenue reports and makes it impossible to calculate commissions or
   evaluate individual performance.

3. **Blocked shillinq integration**: the cross-app commission journal in shillinq requires a staff
   reference on each sale. Without staff identification at point of sale, no commission data can
   be fed to shillinq.

13 of 13 surveyed POS competitors implement staff PIN login with role-based permissions (P0-must
demand signal, 100% competitor coverage).

## Solution

Implement a staff authentication and role permission system with:

1. **Role management** — admin CRUD for POS roles with a granular permission matrix
   (canVoid, maxDiscountPercent, canRefund, canNoSale)
2. **Staff management** — admin CRUD for staff members, each linked to a role and
   authenticated by a 4–6 digit PIN stored as bcrypt hash
3. **PIN login flow** — PIN entry modal at the POS terminal that opens a staff session
4. **Permission enforcement** — action guards check the active staff session's role permissions
   before allowing void, discount, refund, and no-sale operations
5. **Per-staff sales attribution** — every transaction is tagged with the active staff member,
   enabling per-staff sales reports and shillinq commission feed

## Scope

- `posRole` schema: CRUD, permission matrix (canVoid, maxDiscountPercent, canRefund, canNoSale)
- `posStaff` schema: CRUD, PIN management (bcrypt hash stored, never returned), role assignment,
  active/inactive toggle
- PIN login modal and session management (client-side, per POS session)
- Permission enforcement at void, discount, refund, and no-sale action points
- Per-staff sales report (transactions grouped and totalled per staff member)
- Shillinq commission journal feed (staff reference per transaction line)

## Out of scope

- Time clock / clock-in / clock-out (V2)
- Biometric or card-swipe authentication (V2)
- Staff scheduling and shift management (V2)
- Multi-store staff assignment (V2)
- Staff performance dashboards beyond revenue totals (V2)
- Manager PIN override flow — manager re-authenticates to approve blocked actions (V2,
  initial V1 simply blocks the action)
