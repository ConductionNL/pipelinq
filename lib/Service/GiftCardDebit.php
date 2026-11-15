<?php

/**
 * Pipelinq GiftCardDebit.
 *
 * The arithmetic of one gift card debit: what is applied, what is handed
 * back as change, the balance left and the ledger type.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A debit against a card balance.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-02
 */
final class GiftCardDebit {
	/**
	 * Constructor.
	 *
	 * @param float  $applied      The amount taken from the card.
	 * @param float  $change       The part of the request the card could not cover.
	 * @param float  $balanceAfter The balance left on the card.
	 * @param string $type         The ledger type: redeem or partial_redeem.
	 * @param bool   $depleted     Whether the debit empties the card.
	 */
	public function __construct(
		public readonly float $applied,
		public readonly float $change,
		public readonly float $balanceAfter,
		public readonly string $type,
		public readonly bool $depleted,
	) {
	}//end __construct()

	/**
	 * Debit an amount from a balance.
	 *
	 * The card covers at most its balance; the rest is change. A debit that
	 * leaves nothing depletes the card, and is a full "redeem" only when the
	 * amount asked equals the balance exactly.
	 *
	 * @param float $balance The current balance.
	 * @param float $amount  The amount asked.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-02
	 */
	public static function fromBalance(float $balance, float $amount): self {
		$applied = min($amount, $balance);
		$change = max(0.0, $amount - $applied);
		$after = round($balance - $applied, 2);
		$depleted = $after <= 0.0;

		$type = 'partial_redeem';
		if ($depleted === true && abs($applied - $balance) < 0.005 && $change === 0.0) {
			$type = 'redeem';
		}

		if ($depleted === true) {
			$after = 0.0;
		}

		return new self(applied: $applied, change: $change, balanceAfter: $after, type: $type, depleted: $depleted);
	}//end fromBalance()
}//end class
