<?php

/**
 * Pipelinq BookingFee.
 *
 * One no-show or late-cancellation fee AppointmentPaymentProvider charges.
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
 * The booking, amount, kind and stamp field of one fee charge.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
final class BookingFee {
	/**
	 * Constructor.
	 *
	 * @param string $bookingId  Booking UUID.
	 * @param float  $amount     Fee amount (euros).
	 * @param string $kind       Charge kind tag (no-show / late-cancellation).
	 * @param string $stampField Booking field to stamp on success.
	 */
	public function __construct(
		public readonly string $bookingId,
		public readonly float $amount,
		public readonly string $kind,
		public readonly string $stampField,
	) {
	}//end __construct()

	/**
	 * The fee in integer cents, or 0 when nothing is chargeable.
	 *
	 * @return int The cents.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function cents(): int {
		if ($this->bookingId === '' || $this->amount <= 0.0) {
			return 0;
		}

		return (int)round(($this->amount * 100));
	}//end cents()
}//end class
