<?php

/**
 * Pipelinq CreatePortalServiceGroup repair step.
 *
 * Creates the `pipelinq-portal-service` group the portal schemas grant create
 * and update to, and puts the configured portal service account in it. It
 * never picks an account: an admin does that in the portal settings, and
 * until then the portal answers 503 and the admin overview says so.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\Portal\PortalServiceAccount;

/**
 * Creates the portal service group and enrols the configured account.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class CreatePortalServiceGroup extends CreateServiceGroup {
	/**
	 * Constructor.
	 *
	 * @param PortalServiceAccount $serviceAccount Creates the group and enrols the account.
	 */
	public function __construct(PortalServiceAccount $serviceAccount) {
		parent::__construct(serviceAccount: $serviceAccount);
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function getName(): string {
		return 'Create the group the customer portal service account writes with';
	}//end getName()

	/**
	 * The warning while no account is picked yet.
	 *
	 * @return string The warning.
	 */
	protected function noAccountWarning(): string {
		return 'The customer portal has no service account yet, so it refuses every write. Pick one in the Pipelinq admin settings.';
	}//end noAccountWarning()
}//end class
