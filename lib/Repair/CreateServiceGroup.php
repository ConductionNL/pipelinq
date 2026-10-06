<?php

/**
 * Pipelinq CreateServiceGroup repair step base.
 *
 * Creates a service account's group and puts the configured account in it.
 * It never picks an account: an admin does that in the settings, and until
 * then the public endpoint answers 503 and the admin overview says so.
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
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use InvalidArgumentException;
use OCA\Pipelinq\Service\ServiceAccount;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Creates a service group and enrols the configured account.
 *
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
 */
abstract class CreateServiceGroup implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param ServiceAccount $serviceAccount Creates the group and enrols the account.
	 */
	public function __construct(
		private readonly ServiceAccount $serviceAccount,
	) {
	}//end __construct()

	/**
	 * The warning while no account is picked yet.
	 *
	 * @return string The warning.
	 */
	abstract protected function noAccountWarning(): string;

	/**
	 * Create the group and enrol the configured account.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
 * @spec exclude shared plumbing of the portal and messaging service accounts; no requirement owns the acting identity
	 */
	public function run(IOutput $output): void {
		$group = $this->serviceAccount->group();
		if ($this->serviceAccount->ensureGroup() === null) {
			$output->warning('Could not create group '.$group.'.');
			return;
		}

		$userId = $this->serviceAccount->configuredUserId();
		if ($userId === '') {
			$output->warning($this->noAccountWarning());
			return;
		}

		try {
			$this->serviceAccount->assign(userId: $userId);
			$output->info('The service account '.$userId.' is in group '.$group.'.');
		} catch (InvalidArgumentException $e) {
			$output->warning('The service account '.$userId.' cannot be used: '.$e->getMessage());
		}
	}//end run()
}//end class
