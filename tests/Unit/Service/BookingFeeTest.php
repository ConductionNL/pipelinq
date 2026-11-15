<?php

/**
 * Unit tests for BookingFee.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BookingFee;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BookingFee.
 */
class BookingFeeTest extends TestCase {

	/**
	 * A positive fee on a booking is rounded to cents.
	 *
	 * @return void
	 */
	public function testCentsRoundsTheAmount(): void {
		$fee = new BookingFee(bookingId: 'b-1', amount: 12.345, kind: 'no-show', stampField: 'noShowFeeChargedAt');

		$this->assertSame(expected: 1235, actual: $fee->cents());
		$this->assertSame(expected: 'noShowFeeChargedAt', actual: $fee->stampField);
	}//end testCentsRoundsTheAmount()

	/**
	 * Without a booking or a positive amount nothing is chargeable.
	 *
	 * @return void
	 */
	public function testNothingIsChargeableWithoutABookingOrAmount(): void {
		$this->assertSame(expected: 0, actual: (new BookingFee(bookingId: '', amount: 5.0, kind: 'no-show', stampField: 'x'))->cents());
		$this->assertSame(expected: 0, actual: (new BookingFee(bookingId: 'b-1', amount: 0.0, kind: 'no-show', stampField: 'x'))->cents());
		$this->assertSame(expected: 0, actual: (new BookingFee(bookingId: 'b-1', amount: -1.0, kind: 'no-show', stampField: 'x'))->cents());
	}//end testNothingIsChargeableWithoutABookingOrAmount()
}//end class
