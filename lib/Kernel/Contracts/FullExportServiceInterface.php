<?php

/**
 * Contract of FullExportService
 *
 * The public surface of FullExportService that other components call, so they depend
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
 * Contract of FullExportService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FullExportServiceInterface {

	/**
	 * Build the archive and store it in the user's Files.
	 *
	 * @param string $userId The administrator who asked for it.
	 *
	 * @return array{name: string, path: string, fileId: int, manifest: array<string, mixed>} Where it was stored and what it holds.
	 *
	 * @throws RuntimeException When an object read fails or the archive cannot be written or stored.
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	public function exportFor(string $userId): array;
}//end interface
