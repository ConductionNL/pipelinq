<?php

/**
 * Pipelinq DepositSessionRequest.
 *
 * The booking, amount and texts a deposit payment session is asked for.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The booking id, deposit amount, currency, description and return URL of one deposit session request.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
final class DepositSessionRequest {
	/**
	 * Constructor.
	 *
	 * @param string $bookingId   Booking UUID.
	 * @param int    $amountCents Deposit amount in integer cents.
	 * @param string $currency    ISO-4217 currency code.
	 * @param string $description Short description for the payment session.
	 * @param string $returnUrl   The portal URL the customer returns to.
	 */
	public function __construct(
		public readonly string $bookingId,
		public readonly int $amountCents,
		public readonly string $currency,
		public readonly string $description,
		public readonly string $returnUrl,
	) {
	}//end __construct()
}//end class
