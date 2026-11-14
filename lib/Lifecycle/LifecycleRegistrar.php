<?php

/**
 * Pipelinq Lifecycle Registrar
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

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers the lifecycle guards the POS schemas name.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.nl
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
class LifecycleRegistrar {
	/**
	 * Register the POS lifecycle guards keyed by the FQCN tag the
	 * posTransaction / posRefund schemas reference in their
	 * x-openregister-lifecycle.transitions[*].requires.
	 *
	 * OpenRegister's LifecycleGuardRegistry resolves the `requires` tag to a
	 * concrete LifecycleGuardInterface instance via the app container (with the
	 * NC server container as FQCN fallback). The registry is fail-closed: a
	 * transition that references an unregistered guard tag cannot proceed, so
	 * these registrations are load-bearing for the POS lifecycle.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			PosAccessPolicy::class,
			static function ($c): PosAccessPolicy {
				return new PosAccessPolicy(
					appConfig: $c->get(IAppConfig::class),
					groupManager: $c->get(IGroupManager::class),
				);
			}
		);

		$context->registerService(
			PosTransactionAccessGuard::class,
			static function ($c): PosTransactionAccessGuard {
				return new PosTransactionAccessGuard(policy: $c->get(PosAccessPolicy::class));
			}
		);

		$context->registerService(
			PosTransactionConfirmGuard::class,
			static function ($c): PosTransactionConfirmGuard {
				return new PosTransactionConfirmGuard(
					policy: $c->get(PosAccessPolicy::class),
					container: $c->get(ContainerInterface::class),
					appConfig: $c->get(IAppConfig::class),
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			PosTransactionRefundGuard::class,
			static function ($c): PosTransactionRefundGuard {
				return new PosTransactionRefundGuard(policy: $c->get(PosAccessPolicy::class));
			}
		);

		$context->registerService(
			PosRefundManagerGuard::class,
			static function ($c): PosRefundManagerGuard {
				return new PosRefundManagerGuard(
					policy: $c->get(PosAccessPolicy::class),
					container: $c->get(ContainerInterface::class),
					appConfig: $c->get(IAppConfig::class),
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
	}//end register()
}//end class
