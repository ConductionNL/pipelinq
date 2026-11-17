<?php

/**
 * OpenRegister BulkActionOutcomeInterface
 *
 * Declaration-only stub of BulkActionOutcomeInterface, for the unit suite and static analysis; the
 * real class wins whenever OpenRegister is installed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

/**
 * An immutable per-object outcome.
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */
interface BulkActionOutcomeInterface {
	/**
	 * The outcome name.
	 *
	 * @return string One of the BulkJobMember::OUTCOME_* constants.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getOutcome(): string;

	/**
	 * The reason behind the outcome.
	 *
	 * @return string|null The reason, or null when the outcome needs none.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getReason(): ?string;

	/**
	 * Whether this outcome counts as a write.
	 *
	 * @return bool True when the action applied.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function isApplied(): bool;
}//end interface
