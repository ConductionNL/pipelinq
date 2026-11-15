---
status: done
---

# crm-access-groups Specification

## Purpose
Describes, after the fact (spec round of 2026-10-07), how pipelinq decides who may use which part of the CRM. Three Nextcloud-group predicates do it: a CRM-wide privileged group (`lib/Lifecycle/ObjectOwnerAccessPolicy.php`), the point-of-sale groups (`lib/Lifecycle/PosAccessPolicy.php`) and the export-analyst group (`lib/Service/Export/ExportAccessPolicy.php`). Every predicate fails closed: an empty user or an unconfigured group grants nothing beyond the explicit admin check. Read access to ordinary CRM records (clients, contacts, leads, tickets) is not decided here; it follows the `authorization` blocks the register declares and OpenRegister enforces, and today those let every authenticated user read them.
## Requirements

### Requirement: Company-wide CRM surfaces SHALL be limited to privileged users

`ObjectOwnerAccessPolicy::isPrivileged()` SHALL answer true for a member of the built-in groups `admin` or `sales`, or of the group named in the app config key `crm_group` (`ObjectOwnerAccessPolicy::CRM_GROUP_KEY`, default empty, so it adds nothing until set; default declared in `lib/Service/SettingsService.php:309`). Controllers that serve company-wide CRM data or configuration SHALL refuse a caller who is not privileged with HTTP 403, among them `AnalyticsController`, `ReportingController`, `SegmentController`, `BlastController`, `LoyaltyController`, `TemplateController` and the contract summary and renewal metrics on `ContractController` (`lib/Controller/ContractController.php:206`, `:232`).

@e2e exclude after-the-fact spec of shipped behaviour (spec round 2026-10-07).

#### Scenario: A sales colleague opens the analytics
- WHEN a member of the `sales` group requests the CRM analytics overview
- THEN the system MUST return the overview

#### Scenario: A colleague outside the CRM groups is refused
- WHEN an authenticated user in neither `admin`, `sales` nor the configured `crm_group` requests the CRM analytics overview
- THEN the system MUST answer HTTP 403

#### Scenario: A deployment names its own CRM group
- WHEN an administrator sets `crm_group` to `klantcontact` and a member of `klantcontact` requests the analytics overview
- THEN the system MUST return the overview
- AND members of `admin` and `sales` MUST still be let in

### Requirement: An owned record SHALL be acted on only by its owner or a privileged user

`ObjectOwnerAccessPolicy::mayAccess()` SHALL let a caller act on a loaded object when the object's owner field (default `ownerId`) equals the caller's user id, or when the caller is privileged. An object with an empty owner value SHALL be open to privileged users only. Today the sales contract is the schema that carries `ownerId`: the contract transition endpoint SHALL check it before changing a contract's state (`lib/Controller/ContractController.php:157`, `:292-293`), and so SHALL the semantic hand-off of a contract (`lib/Controller/SemanticHandoffController.php:391`). Creating a contract SHALL need a privileged user (`ContractController.php:100`).

@e2e exclude after-the-fact spec of shipped behaviour (spec round 2026-10-07).

#### Scenario: The contract owner moves their own contract on
- WHEN the user named in a contract's `ownerId` asks to transition that contract
- THEN the system MUST perform the transition

#### Scenario: Another colleague cannot move someone else's contract
- WHEN an authenticated user who is not the contract's owner and not privileged asks to transition it
- THEN the system MUST answer HTTP 403 and leave the contract unchanged

#### Scenario: A contract without an owner
- WHEN a contract has an empty `ownerId` and a user who is not privileged asks to transition it
- THEN the system MUST answer HTTP 403

### Requirement: Point-of-sale actions SHALL follow the configured POS groups

`PosAccessPolicy` SHALL let a user drive a cashier-level transition (confirm, settle, park, resume) on a POS transaction when the user is a Nextcloud admin, the transaction's own `cashier`, or a member of the group in app config `pos_group` (default `pos`). Refund and refund completion SHALL need a Nextcloud admin or a member of the group in `pos_manager_group` (default empty, so admins only). The detailed guard wiring is the `pos-lifecycle-guard-adoption` spec.

@e2e exclude after-the-fact spec of shipped behaviour (spec round 2026-10-07); covered by the POS guard PHPUnit tests.

#### Scenario: A cashier settles their own sale
- WHEN the user named as `cashier` on a POS transaction settles it
- THEN the system MUST allow the transition

#### Scenario: A refund without a manager group configured
- WHEN `pos_manager_group` is empty and a member of `pos` who is not an admin asks to refund a transaction
- THEN the system MUST refuse the refund

### Requirement: Export configuration SHALL be limited to export analysts

`ExportAccessPolicy::isExportAdmin()` SHALL let a Nextcloud admin, or a member of the group in app config `export_analyst_group` (default empty, so admins only), configure BI export jobs (`lib/Controller/ExportJobController.php`) and read their run history (`lib/Controller/ExportRunController.php`). The export surface itself is the `bi-export-and-data-warehouse-sink` spec.

@e2e exclude after-the-fact spec of shipped behaviour (spec round 2026-10-07).

#### Scenario: An analyst configures an export
- WHEN an administrator has set `export_analyst_group` to `bi` and a member of `bi` saves an export job
- THEN the system MUST accept it

#### Scenario: Nobody but admins before a group is set
- WHEN `export_analyst_group` is empty and an authenticated user who is not an admin opens the export run history
- THEN the system MUST refuse the request
