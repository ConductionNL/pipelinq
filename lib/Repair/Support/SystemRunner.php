<?php

/**
 * Pipelinq SystemRunner.
 *
 * An operation run as the system when the ObjectService supports it.
 *
 * @category Util
 * @package  OCA\Pipelinq\Repair\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair\Support;

/**
 * Runs the operation through the ObjectService's runAsSystem() when it has
 * one, or directly otherwise.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SystemRunner {

	/**
	 * Execute an OpenRegister write under the scoped system-operation context.
	 *
	 * Repair steps run without a user session (web-triggered upgrades, webcron),
	 * so OpenRegister RBAC denies every write as 'Anonymous'
	 * (NotAuthorizedException). ObjectService::runAsSystem scopes trusted-system
	 * elevation to the callable; older OpenRegister versions without it fall
	 * back to the direct call.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param callable $operation The write operation to execute.
	 *
	 * @return mixed Whatever the operation returns.
	 *
	 * @spec exclude system-context adoption — back-compat elevation shim around OR writes, no behavioural spec surface.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function execAsSystem(object $objectService, callable $operation): mixed {
		if (method_exists($objectService, 'runAsSystem') === true) {
			return $objectService->runAsSystem($operation);
		}

		return $operation();
	}//end execAsSystem()
}//end trait
