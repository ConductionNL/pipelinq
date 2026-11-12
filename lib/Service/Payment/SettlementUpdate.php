<?php
/**
 * Pipelinq SettlementUpdate.
 *
 * One settlement webhook applied to a POS transaction: the status the
 * provider reports, the status the transaction had, and the provider event.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Payment
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
 */
declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

/**
 * A settlement update and whether it moves the transaction into the
 * settled or refunded state for the first time.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
 */
final class SettlementUpdate {
	/**
	 * Constructor.
	 *
	 * @param string $paymentStatus  The normalised lifecycle status.
	 * @param string $previousStatus The transaction's status before this update.
	 * @param string $eventId        The provider event id (idempotency key, refund id on a refund).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $paymentStatus,
		public readonly string $previousStatus,
		public readonly string $eventId,
	) {
	}//end __construct()

	/**
	 * Whether this update settles a transaction that was not settled yet.
	 *
	 * @return bool True on a first settlement.
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
	 */
	public function settlesNow(): bool {
		return $this->paymentStatus === 'settled' && $this->previousStatus !== 'settled';
	}//end settlesNow()

	/**
	 * Whether this update refunds a transaction that was not refunded yet.
	 *
	 * @return bool True on a first refund.
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-011
	 */
	public function refundsNow(): bool {
		return $this->paymentStatus === 'refunded' && $this->previousStatus !== 'refunded';
	}//end refundsNow()
}//end class
