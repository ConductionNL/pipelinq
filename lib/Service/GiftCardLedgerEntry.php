<?php

/**
 * Pipelinq GiftCardLedgerEntry.
 *
 * The card, the movement type, the amount moved and the balance it leaves.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * One movement on a gift card, as written to its ledger.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006
 */
final class GiftCardLedgerEntry {
	/**
	 * Constructor.
	 *
	 * @param string $giftCardId   The card UUID.
	 * @param string $type         The transaction type.
	 * @param float  $amount       The movement amount.
	 * @param float  $balanceAfter The balance after the movement.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006
	 */
	public function __construct(
		public readonly string $giftCardId,
		public readonly string $type,
		public readonly float $amount,
		public readonly float $balanceAfter,
	) {
	}//end __construct()
}//end class
