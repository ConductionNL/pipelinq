<?php

/**
 * Pipelinq Lifecycle Contract Registrar
 *
 * @category  Lifecycle
 * @package   OCA\Pipelinq\Lifecycle
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\Pipelinq\Kernel\Contracts\BillingHandoffAccessPolicyInterface;
use OCA\Pipelinq\Kernel\Contracts\ForecastAccessInterface;
use OCA\Pipelinq\Kernel\Contracts\PosAccessPolicyInterface;
use OCA\Pipelinq\Kernel\Contracts\SessionUserInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Aliases the Kernel contracts of the lifecycle classes other components call
 * to their implementations.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.nl
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
final class LifecycleContractRegistrar {
	/**
	 * Alias every lifecycle contract to its implementation.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(BillingHandoffAccessPolicyInterface::class, BillingHandoffAccessPolicy::class);
		$context->registerServiceAlias(ForecastAccessInterface::class, ForecastAccess::class);
		$context->registerServiceAlias(PosAccessPolicyInterface::class, PosAccessPolicy::class);
		$context->registerServiceAlias(SessionUserInterface::class, SessionUser::class);
	}//end register()
}//end class
