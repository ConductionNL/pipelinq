<?php

/**
 * Contract of OpenRegisterSyncService
 *
 * The public surface of OpenRegisterSyncService that other components call, so they depend
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
 * Contract of OpenRegisterSyncService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OpenRegisterSyncServiceInterface {

	/**
	 * Sync a Master Entity's golden record to its OR schema instance.
	 *
	 * Finds the canonical OR object via existing masterEntityRef linkage and
	 * writes the golden-record attributes plus the MDM markers. When no OR
	 * object is linked yet, a new canonical one is created.
	 *
	 * @param string $masterId The master entity uuid.
	 *
	 * @return array<string, mixed>|null The synced OR object, or null when the
	 *                                   entity is missing / its type is unmapped.
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-011-sync-golden-record-to-openregister
	 */
	public function syncMasterToRegister(string $masterId): ?array;
}//end interface
