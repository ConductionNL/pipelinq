<?php

/**
 * Pipelinq PspPayment.
 *
 * The transaction and amount of one payment initiation.
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
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

/**
 * The POS transaction and amount in cents of one payment a provider is asked to start.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */
final class PspPayment {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $transactionData The transaction data.
	 * @param int                  $cents           The amount in cents.
	 */
	public function __construct(
		public readonly array $transactionData,
		public readonly int $cents,
	) {
	}//end __construct()

	/**
	 * The transaction reference, or ''.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	public function reference(): string {
		return (string)($this->transactionData['reference'] ?? '');
	}//end reference()

	/**
	 * The transaction id, or ''.
	 *
	 * @return string The transaction id.
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	public function transactionId(): string {
		return (string)($this->transactionData['id'] ?? '');
	}//end transactionId()

	/**
	 * The payment description shown to the customer: "Pipelinq POS <reference>", or "transactie" without one.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	public function description(): string {
		$label = 'transactie';
		if ($this->reference() !== '') {
			$label = $this->reference();
		}

		return sprintf('Pipelinq POS %s', $label);
	}//end description()
}//end class
