<?php
/**
 * Pipelinq PaymentStart.
 *
 * One payment a cashier starts for a confirmed POS transaction.
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
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */
declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

/**
 * The transaction, the provider and the payment method of one payment start.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */
final class PaymentStart {
	/**
	 * Constructor.
	 *
	 * @param string $transactionId The transaction id.
	 * @param string $providerName  The provider.
	 * @param string $paymentMethod The payment method.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $transactionId,
		public readonly string $providerName,
		public readonly string $paymentMethod,
	) {
	}//end __construct()
}//end class
