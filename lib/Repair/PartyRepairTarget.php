<?php

/**
 * Pipelinq PartyRepairTarget.
 *
 * The ObjectService, register and schema a party type is saved to, and the admin the save runs as.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/client-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCP\IUser;

/**
 * Where one party type's repaired rows are saved, and as whom.
 *
 * @spec openspec/specs/client-management/spec.md
 */
final class PartyRepairTarget {
	/**
	 * Constructor.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The schema id.
	 * @param ?IUser $actingAdmin   The admin to save as.
	 *
	 * @spec openspec/specs/client-management/spec.md
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly ?IUser $actingAdmin,
	) {
	}//end __construct()
}//end class
