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

use InvalidArgumentException;
use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Creates the portal service group and enrols the configured account.
 *
 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
 *   sessions, tokens, delegation, documents, invoices, orders, exports and
 *   audit are all unspecified
 */
class CreatePortalServiceGroup implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param PortalServiceAccount $serviceAccount Creates the group and enrols the account.
	 */
	public function __construct(
		private readonly PortalServiceAccount $serviceAccount,
	) {
	}//end __construct()

	/**
	 * The repair step name.
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
	 * Create the group, then enrol the configured account when there is one.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 * @spec exclude the portal backend has no owning requirement. customer-portal specifies
	 *   ONLY the widget-mode origin allow-list (REQ-PORTAL-ORIGIN); auth, MFA,
	 *   sessions, tokens, delegation, documents, invoices, orders, exports and
	 *   audit are all unspecified
	 */
	public function run(IOutput $output): void {
		if ($this->serviceAccount->ensureGroup() === null) {
			$output->warning('Could not create group '.PortalServiceAccount::GROUP.'.');
			return;
		}

		$userId = $this->serviceAccount->configuredUserId();
		if ($userId === '') {
			$output->warning(
				'The customer portal has no service account yet, so it refuses every write. Pick one in the Pipelinq admin settings.'
			);
			return;
		}

		try {
			$this->serviceAccount->assign(userId: $userId);
			$output->info('The portal service account '.$userId.' is in group '.PortalServiceAccount::GROUP.'.');
		} catch (InvalidArgumentException $e) {
			$output->warning('The portal service account cannot be used: '.$e->getMessage());
		}
	}//end run()
}//end class
