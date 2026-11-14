<?php

/**
 * Contract of LabelRenameService
 *
 * The public surface of LabelRenameService that other components call, so they depend
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

use OCA\Pipelinq\Service\Labels\LabelRenameException;

/**
 * Contract of LabelRenameService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LabelRenameServiceInterface {

	/**
	 * Rename a label and rewrite its old name on the items that carry it.
	 *
	 * @param string $labelId The label's id.
	 * @param string $name    The new name.
	 *
	 * @return array{id: string, name: string, rewritten: int, queued: bool}
	 *
	 * @throws LabelRenameException When the caller is not an administrator (403), the name is
	 *                              empty or too long (400), the label is unknown (404), another
	 *                              label already has the name (409) or OpenRegister is missing (503).
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	public function rename(string $labelId, string $name): array;

	/**
	 * Rewrite every remaining item, for the background job of a large rename.
	 *
	 * The job puts the administrator who asked in the session first, so the
	 * writes pass OpenRegister's access check as that person.
	 *
	 * @param string $from   The old name.
	 * @param string $to     The new name.
	 * @param string $userId The administrator who renamed the label.
	 *
	 * @return integer How many items were rewritten; 0 when the user is no longer an administrator.
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#scenario-a-renamed-label-follows-its-items
	 */
	public function rewriteAll(string $from, string $to, string $userId): int;
}//end interface
