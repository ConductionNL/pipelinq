<?php

/**
 * Pipelinq PspCall.
 *
 * One provider API call: where it goes and what a failure is logged as.
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
 * The URL of one provider API call and the context a transport failure is logged under.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-001
 */
final class PspCall {
	/**
	 * Constructor.
	 *
	 * @param string $url     The full URL.
	 * @param string $context The log context of a failure ("initiate failed").
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $context,
	) {
	}//end __construct()
}//end class
