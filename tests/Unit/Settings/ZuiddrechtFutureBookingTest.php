<?php

/**
 * Example data for PqAfspraakVerplaatsen: Sanne de Vries has a confirmed site
 * visit on the Parkstraat the day after the install, so the booking page offers
 * Verzetten (a confirmed booking that starts more than an hour from now).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Sanne's future site visit.
 */
class ZuiddrechtFutureBookingTest extends TestCase {

	/**
	 * A confirmed booking in the future, for Sanne, of the site-visit service.
	 *
	 * @return void
	 */
	public function testSanneHasAConfirmedSiteVisitTomorrow(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$part    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/50-bookings.json'), true);
		$objects = array_column(array_merge($set['objects'], $part['objects']), null, 'key');

		$booking = ($objects['booking-locatiebezoek-parkstraat'] ?? null);
		$this->assertNotNull(actual: $booking);
		$this->assertSame(expected: 'appointmentBooking', actual: $booking['schema']);
		$this->assertSame(expected: 'confirmed', actual: $booking['data']['status']);
		$this->assertSame(expected: '{{object:client-sanne.id}}', actual: $booking['data']['customerId']);
		$this->assertSame(expected: '{{object:service-locatiebezoek.id}}', actual: $booking['data']['serviceId']);
		$this->assertSame(expected: ['{{datetime:1 14:00}}', '{{datetime:1 15:00}}'], actual: [$booking['data']['startAt'], $booking['data']['endAt']]);
		$this->assertSame(expected: 'active', actual: $objects['service-locatiebezoek']['data']['status']);
	}//end testSanneHasAConfirmedSiteVisitTomorrow()
}//end class
