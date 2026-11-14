<?php

/**
 * Contract of RegisterResolverService
 *
 * The public surface of RegisterResolverService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of RegisterResolverService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RegisterResolverServiceInterface {

	/**
	 * Resolve the OpenRegister register id for a consumer domain.
	 *
	 * The result is memoised per logical name for the lifetime of the request.
	 * Every logical name currently maps to the same instance-scoped `register`
	 * app-config value, so behaviour is identical to a direct read of that key.
	 *
	 * @param string $logicalName The consumer domain (e.g. `queue`, `contact`).
	 *                            Caches resolution per domain; it does NOT
	 *                            influence which app-config key is read and so
	 *                            cannot be abused to read an arbitrary value.
	 *
	 * @return string The configured register id, or an empty string when the
	 *                app has not been configured with a register yet.
	 *
	 * @spec openspec/changes/archive/2026-05-31-pipelinq-or-register-resolver/tasks.md#task-1.1
	 */
	public function resolve(string $logicalName = 'default'): string;
}//end interface
