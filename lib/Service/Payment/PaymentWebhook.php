<?php
/**
 * Pipelinq PaymentWebhook.
 *
 * One delivery to the public payment webhook: the provider it is for,
 * the raw body and the signature that came with it.
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
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
 */
declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

/**
 * The provider name, raw body and signature of one payment webhook delivery.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
 */
final class PaymentWebhook {
	/**
	 * Constructor.
	 *
	 * @param string $providerName The provider the delivery is for.
	 * @param string $rawPayload   The raw request body.
	 * @param string $signature    The signature header.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $providerName,
		public readonly string $rawPayload,
		public readonly string $signature,
	) {
	}//end __construct()
}//end class
