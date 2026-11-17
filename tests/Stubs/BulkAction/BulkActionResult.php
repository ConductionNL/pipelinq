<?php

/**
 * OpenRegister BulkActionResult
 *
 * Declaration-only stub of BulkActionResult, for the unit suite and static analysis; the
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
 * An immutable per-object outcome: applied, skipped, refused or failed.
 */
final class BulkActionResult implements BulkActionOutcomeInterface {

	/**
	 * Constructor.
	 *
	 * @param string      $outcome The outcome.
	 * @param string|null $reason  The reason.
	 */
	private function __construct(
		private readonly string $outcome,
		private readonly ?string $reason = null,
	) {
	}//end __construct()

	/**
	 * The action applied.
	 *
	 * @param string|null $reason An optional note.
	 *
	 * @return self The outcome.
	 */
	public static function applied(?string $reason = null): self {
		return new self(outcome: 'applied', reason: $reason);
	}//end applied()

	/**
	 * The action had nothing to do.
	 *
	 * @param string $reason Why.
	 *
	 * @return self The outcome.
	 */
	public static function skipped(string $reason): self {
		return new self(outcome: 'skipped', reason: $reason);
	}//end skipped()

	/**
	 * A rule refused the action.
	 *
	 * @param string $rule The rule.
	 *
	 * @return self The outcome.
	 */
	public static function refused(string $rule): self {
		return new self(outcome: 'refused', reason: $rule);
	}//end refused()

	/**
	 * The action failed.
	 *
	 * @param string $message The error.
	 *
	 * @return self The outcome.
	 */
	public static function failed(string $message): self {
		return new self(outcome: 'failed', reason: $message);
	}//end failed()

	/**
	 * The outcome name.
	 *
	 * @return string The outcome.
	 */
	public function getOutcome(): string {
		return $this->outcome;
	}//end getOutcome()

	/**
	 * The reason.
	 *
	 * @return string|null The reason.
	 */
	public function getReason(): ?string {
		return $this->reason;
	}//end getReason()

	/**
	 * Whether this outcome counts as a write.
	 *
	 * @return bool True when applied.
	 */
	public function isApplied(): bool {
		return $this->outcome === 'applied';
	}//end isApplied()
}//end class
