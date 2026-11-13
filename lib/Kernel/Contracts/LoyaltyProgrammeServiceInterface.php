<?php

/**
 * Contract of LoyaltyProgrammeService
 *
 * The public surface of LoyaltyProgrammeService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of LoyaltyProgrammeService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LoyaltyProgrammeServiceInterface {

	/**
	 * Activate a programme (transition concept -> actief).
	 *
	 * @param string $programmeId The programme UUID.
	 * @param string $activatedBy The user id who triggered activation.
	 *
	 * @return array<string, mixed> The activated programme.
	 *
	 * @throws RuntimeException When validation fails.
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-001
	 */
	public function activate(string $programmeId, string $activatedBy): array;
}//end interface
