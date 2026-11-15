<?php

/**
 * Contract of ExportAccessPolicy
 *
 * The public surface of ExportAccessPolicy that other components call, so they depend
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
 * Contract of ExportAccessPolicy.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExportAccessPolicyInterface {

	/**
	 * Whether a user may configure or operate the export pipeline.
	 *
	 * Grants access to a Nextcloud admin or a member of the configured
	 * export-analyst group. Fails closed for an empty user or an unconfigured
	 * group (admins only).
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user may use the export surface.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-001
	 */
	public function isExportAdmin(string $userId): bool;
}//end interface
