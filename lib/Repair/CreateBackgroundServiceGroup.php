<?php

/**
 * Pipelinq CreateBackgroundServiceGroup repair step.
 *
 * Creates the `pipelinq-background-service` group the `mirroredCall` schema
 * grants, and puts the configured background service account in it. It never
 * picks an account: an admin does that in the Pipelinq settings, and until
 * then no call is mirrored and the admin overview says so.
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
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\BackgroundServiceAccount;

/**
 * Creates the background service group and enrols the configured account.
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */
class CreateBackgroundServiceGroup extends CreateServiceGroup {
	/**
	 * Constructor.
	 *
	 * @param BackgroundServiceAccount $serviceAccount Creates the group and enrols the account.
	 */
	public function __construct(BackgroundServiceAccount $serviceAccount) {
		parent::__construct(serviceAccount: $serviceAccount);
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
	 */
	public function getName(): string {
		return 'Create the group Pipelinq background work writes with';
	}//end getName()

	/**
	 * The warning while no account is picked yet.
	 *
	 * @return string The warning.
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
	 */
	protected function noAccountWarning(): string {
		return 'Pipelinq background work has no service account yet, so incoming calls are not logged. Pick one in the Pipelinq settings.';
	}//end noAccountWarning()
}//end class
