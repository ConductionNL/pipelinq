<?php

/**
 * A booking names the request it belongs to.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.pipelinq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/booking-ticket-link/specs/appointment-booking/spec.md#requirement-a-booking-can-name-its-request
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Board PqAfspraak draws a "Gekoppeld verzoek" card: the request a booking is
 * for. The booking schema, as OpenRegister's import composes it, carries that
 * relation as an optional ticket reference, and its version moves with it.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class BookingTicketLinkSchemaTest extends TestCase {

	/**
	 * The booking schema as the import composes it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function bookingSchema(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema = ($register['components']['schemas']['appointmentBooking'] ?? null);
		$this->assertIsArray($schema, 'The booking schema was not read.');

		return $schema;
	}//end bookingSchema()

	/**
	 * The booking carries an optional reference to a ticket.
	 *
	 * @return void
	 */
	public function testABookingCanNameItsRequest(): void {
		$schema = $this->bookingSchema();

		$this->assertArrayHasKey('ticket', $schema['properties']);
		$this->assertSame('ticket', $schema['properties']['ticket']['$ref']);
		$this->assertSame('uuid', $schema['properties']['ticket']['format']);
		$this->assertNotContains('ticket', ($schema['required'] ?? []));
	}//end testABookingCanNameItsRequest()

	/**
	 * The schema version moves with the new property, so the import updates it.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionMoves(): void {
		$this->assertSame('1.2.0', $this->bookingSchema()['version']);
	}//end testTheSchemaVersionMoves()
}//end class
