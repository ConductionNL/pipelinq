<?php
/**
 * Pipelinq PaymentRefund.
 *
 * One refund a POS manager makes, with its reason.
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
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
 */
declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

/**
 * The transaction refunded and the reason written to the reversal.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
 */
final class PaymentRefund {
	/**
	 * Constructor.
	 *
	 * @param string $transactionId The transaction id.
	 * @param string $reason        The refund reason.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $transactionId,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
