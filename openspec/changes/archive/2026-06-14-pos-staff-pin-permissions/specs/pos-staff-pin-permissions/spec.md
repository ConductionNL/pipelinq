# Spec: POS Staff PIN + Role Permissions

## Purpose

Define the functional requirements for staff PIN authentication, role-based permission
enforcement, per-staff sales attribution, and the admin management surfaces for POS roles
and staff members.

**Demand signal**: 13/13 competitors implement staff authentication with role permissions
(P0-must). Cross-app dependency: per-staff sales feed to shillinq commission journal.

---

## Requirements

### REQ-PSP-001: Role CRUD

The admin MUST be able to create, view, update, and delete POS roles with their permission matrix.

#### Scenario: Create a role with full permission matrix

- GIVEN the admin opens the role creation form
- WHEN they enter name "Supervisor", set canVoid false, maxDiscountPercent 15,
  canRefund true, canNoSale true, and click Save
- THEN a posRole object MUST be created with those values
- AND the role MUST appear in the role list

#### Scenario: maxDiscountPercent is bounded to 0–100

- GIVEN the admin is creating or editing a role
- WHEN they set maxDiscountPercent to 110
- THEN a validation error MUST appear: "Maximum discount must be between 0 and 100"
- AND the role MUST NOT be saved

#### Scenario: Cannot delete a role assigned to active staff

- GIVEN posRole "Kassamedewerker" is assigned to 3 active staff members
- WHEN the admin attempts to delete the role
- THEN the delete MUST be blocked
- AND an error message MUST state: "This role is still assigned to staff members"

#### Scenario: Edit role updates permission matrix

- GIVEN posRole "Supervisor" with maxDiscountPercent 15
- WHEN the admin changes maxDiscountPercent to 20 and clicks Save
- THEN the role MUST be updated
- AND the next PIN authentication for a Supervisor-role staff MUST return maxDiscountPercent 20

---

### REQ-PSP-002: Staff CRUD

The admin MUST be able to create, view, update, and delete POS staff members.

#### Scenario: Create staff member with PIN

- GIVEN the admin opens the staff creation form
- WHEN they enter displayName "Anna de Vries", select role "Kassamedewerker",
  and enter PIN "1234"
- THEN a posStaff object MUST be created
- AND the stored `pinHash` MUST be a bcrypt hash, NOT the plain-text PIN
- AND the API response MUST NOT contain the `pinHash` field

#### Scenario: PIN length validation

- GIVEN the admin is creating a staff member
- WHEN they enter a PIN shorter than 4 digits or longer than 6 digits
- THEN a validation error MUST appear: "PIN must be between 4 and 6 digits"
- AND the staff member MUST NOT be created

#### Scenario: PIN is optional on edit

- GIVEN an existing staff member "Bob Janssen"
- WHEN the admin edits the display name without entering a new PIN
- THEN only the display name MUST be updated
- AND the existing PIN hash MUST be preserved

#### Scenario: Deactivate a staff member

- GIVEN an active staff member "Emma Bakker"
- WHEN the admin sets isActive to false
- THEN Emma's PIN MUST be rejected on the POS terminal with message: "Account is inactive"

---

### REQ-PSP-003: PIN Authentication at POS Terminal

The POS terminal MUST authenticate staff via their PIN before allowing protected operations.

#### Scenario: Successful PIN login

- GIVEN a POS terminal in unidentified state
- WHEN a staff member enters their correct 4–6 digit PIN
- THEN the terminal MUST enter an active staff session
- AND the session MUST contain: staffId, displayName, and the role's permission matrix
- AND the staff member's name MUST be displayed on the terminal header

#### Scenario: Incorrect PIN is rejected

- GIVEN a POS terminal in unidentified state
- WHEN a staff member enters an incorrect PIN
- THEN authentication MUST fail
- AND a failure message MUST be shown: "Incorrect PIN"
- AND the `failedPinAttempts` counter on the posStaff object MUST be incremented

#### Scenario: Account lockout after 5 failed attempts

- GIVEN a staff member with 4 consecutive failed PIN attempts
- WHEN they enter an incorrect PIN a fifth time
- THEN authentication MUST fail
- AND the `lockedUntil` field MUST be set to 15 minutes in the future
- AND subsequent PIN attempts MUST be rejected with: "Account locked. Try again after [time]."

#### Scenario: Successful login resets failed attempt counter

- GIVEN a staff member with 2 failed attempts on record
- WHEN they enter the correct PIN
- THEN `failedPinAttempts` MUST be reset to 0
- AND the session MUST open normally

#### Scenario: Login blocked for inactive account

- GIVEN posStaff "Emma Bakker" with isActive false
- WHEN she enters her (correct) PIN
- THEN authentication MUST fail with: "Account is inactive"

---

### REQ-PSP-004: Void Permission Enforcement

The system MUST prevent staff from voiding transactions when their role does not permit it.

#### Scenario: Staff without canVoid is blocked

- GIVEN an active session for staff with role canVoid false
- WHEN the operator triggers the void action on a completed transaction
- THEN the void MUST NOT proceed
- AND a dialog MUST appear: "This action requires manager authorisation"

#### Scenario: Staff with canVoid may proceed

- GIVEN an active session for staff with role canVoid true
- WHEN the operator triggers the void action
- THEN the void confirmation dialog MUST appear normally
- AND the void MUST complete if confirmed

---

### REQ-PSP-005: Discount Permission Enforcement

The system MUST limit line-item discounts to the staff role's maxDiscountPercent.

#### Scenario: Discount within limit is accepted

- GIVEN an active session for staff with maxDiscountPercent 15
- WHEN the operator applies a 10% discount to a line item
- THEN the discount MUST be accepted and applied

#### Scenario: Discount exceeding limit is rejected

- GIVEN an active session for staff with maxDiscountPercent 15
- WHEN the operator attempts to apply a 20% discount
- THEN the discount MUST be rejected
- AND a validation message MUST appear: "Maximum discount for your role is 15%"

#### Scenario: Role with maxDiscountPercent 0 cannot apply any discount

- GIVEN an active session for staff with maxDiscountPercent 0
- WHEN the operator attempts to apply any discount (even 1%)
- THEN the discount field MUST be disabled
- AND a tooltip MUST indicate: "Discounts not permitted for your role"

---

### REQ-PSP-006: Refund Permission Enforcement

The system MUST prevent staff from processing refunds when their role does not permit it.

#### Scenario: Staff without canRefund is blocked

- GIVEN an active session for staff with role canRefund false
- WHEN the operator initiates a refund
- THEN the refund MUST NOT proceed
- AND a dialog MUST appear: "This action requires manager authorisation"

#### Scenario: Staff with canRefund may proceed

- GIVEN an active session for staff with role canRefund true
- WHEN the operator initiates a refund and confirms
- THEN the refund MUST complete normally

---

### REQ-PSP-007: No-sale (Cash Drawer) Permission Enforcement

The system MUST prevent unauthorised opening of the cash drawer outside of a transaction.

#### Scenario: Staff without canNoSale is blocked

- GIVEN an active session for staff with role canNoSale false
- WHEN the operator triggers the No-sale (open drawer) action
- THEN the drawer MUST NOT open
- AND a dialog MUST appear: "This action requires manager authorisation"

#### Scenario: Staff with canNoSale may open drawer

- GIVEN an active session for staff with role canNoSale true
- WHEN the operator triggers No-sale
- THEN the cash drawer open command MUST be sent

---

### REQ-PSP-008: Per-staff Sales Attribution

Every POS transaction MUST record which staff member processed it.

#### Scenario: Transaction is tagged with active staff member

- GIVEN a POS session with active staff "Carla Peters" (staffId: uuid-carla)
- WHEN a transaction is completed
- THEN the transaction object MUST have `staffMemberId: uuid-carla`

#### Scenario: Unidentified session cannot complete a transaction

- GIVEN a POS terminal with no active staff session
- WHEN an operator attempts to complete a transaction
- THEN the system MUST require PIN authentication before proceeding

#### Scenario: Per-staff sales report aggregates by staff member

- GIVEN 5 completed transactions for "Anna de Vries" totalling EUR 342.50
  and 3 transactions for "Bob Janssen" totalling EUR 178.00
- WHEN the manager views the per-staff sales report
- THEN Anna's row MUST show 5 transactions and EUR 342.50
- AND Bob's row MUST show 3 transactions and EUR 178.00

---

### REQ-PSP-009: Shillinq Commission Feed

Per-staff transaction data MUST be available for the shillinq commission journal integration.

#### Scenario: Commission feed includes staff reference

- GIVEN a completed transaction attributed to posStaff "David Visser" (uuid-david)
- WHEN the shillinq commission feed processes the transaction
- THEN the feed record MUST include `staffMemberId: uuid-david`
- AND the feed MUST include transaction amount and line items

#### Scenario: Feed excludes transactions without staff attribution

- GIVEN a legacy transaction with no `staffMemberId`
- WHEN the commission feed runs
- THEN that transaction MUST be excluded from the feed
- AND an audit log entry MUST note the exclusion with the transaction ID
