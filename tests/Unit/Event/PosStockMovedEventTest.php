<?php

/**
 * Unit tests for the PosStockMovedEvent CloudEvent envelope.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Event;

use OCA\Pipelinq\Event\PosStockMovedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Event\PosStockMovedEvent
 */
class PosStockMovedEventTest extends TestCase {

	/**
	 * Build an event with the given lines.
	 *
	 * @param array<int, array<string, mixed>> $lines The sold lines.
	 *
	 * @return PosStockMovedEvent The event.
	 */
	private function event(array $lines): PosStockMovedEvent {
		return new PosStockMovedEvent(
			eventId: 'evt-9',
			transactionUuid: 'tx-9',
			transactionReference: 'TXN-2026-0009',
			administrationId: 'admin-9',
			lines: $lines,
			emittedAt: '2026-11-13T09:30:00+00:00',
		);
	}//end event()

	/**
	 * The envelope is exactly the CloudEvents 1.0 shape, with the lines normalised.
	 *
	 * @return void
	 */
	public function testToCloudEventBuildsTheFullEnvelope(): void {
		$event = $this->event(
			lines: [
				['productRef' => 'SKU-1', 'qty' => 2.5, 'unit' => 'kg', 'location' => 'shop'],
			]
		);

		$this->assertSame(
			expected: [
				'specversion'     => '1.0',
				'type'            => 'nl.pipelinq.pos.stock.moved',
				'source'          => '/apps/pipelinq/pos/stock',
				'id'              => 'evt-9',
				'time'            => '2026-11-13T09:30:00+00:00',
				'datacontenttype' => 'application/json',
				'data'            => [
					'posTxnId'             => 'tx-9',
					'transactionReference' => 'TXN-2026-0009',
					'administrationId'     => 'admin-9',
					'lines'                => [
						['productRef' => 'SKU-1', 'qty' => 2.5, 'unit' => 'kg', 'location' => 'shop'],
					],
				],
			],
			actual: $event->toCloudEvent()
		);
	}//end testToCloudEventBuildsTheFullEnvelope()

	/**
	 * A line missing keys gets empty strings and a zero quantity, never a missing key.
	 *
	 * @return void
	 */
	public function testMissingLineKeysFallBackToEmptyValues(): void {
		$lines = $this->event(lines: [[]])->toCloudEvent()['data']['lines'];

		$this->assertSame(
			expected: [['productRef' => '', 'qty' => 0.0, 'unit' => '', 'location' => '']],
			actual: $lines
		);
	}//end testMissingLineKeysFallBackToEmptyValues()

	/**
	 * Scalar line values are cast to the envelope's types: quantity to float, the rest to string.
	 *
	 * @return void
	 */
	public function testLineValuesAreCastToTheEnvelopeTypes(): void {
		$line = $this->event(
			lines: [
				['productRef' => 1001, 'qty' => '3', 'unit' => 'pcs', 'location' => 7],
			]
		)->toCloudEvent()['data']['lines'][0];

		$this->assertSame(expected: '1001', actual: $line['productRef']);
		$this->assertSame(expected: 3.0, actual: $line['qty']);
		$this->assertSame(expected: '7', actual: $line['location']);
	}//end testLineValuesAreCastToTheEnvelopeTypes()

	/**
	 * Extra keys on a line are dropped, and the lines keep their order.
	 *
	 * @return void
	 */
	public function testExtraLineKeysAreDroppedAndOrderIsKept(): void {
		$lines = $this->event(
			lines: [
				['productRef' => 'A', 'qty' => 1, 'unit' => 'pcs', 'price' => 9.99, 'productId' => 'uuid-a'],
				['productRef' => 'B', 'qty' => -2, 'unit' => 'pcs'],
			]
		)->toCloudEvent()['data']['lines'];

		$this->assertSame(
			expected: [
				['productRef' => 'A', 'qty' => 1.0, 'unit' => 'pcs', 'location' => ''],
				['productRef' => 'B', 'qty' => -2.0, 'unit' => 'pcs', 'location' => ''],
			],
			actual: $lines
		);
	}//end testExtraLineKeysAreDroppedAndOrderIsKept()

	/**
	 * Normalising the envelope leaves the typed lines as they were handed in.
	 *
	 * @return void
	 */
	public function testTheEnvelopeDoesNotRewriteTheTypedLines(): void {
		$raw   = [['productRef' => 1001, 'qty' => '3', 'extra' => true]];
		$event = $this->event(lines: $raw);

		$event->toCloudEvent();

		$this->assertSame(expected: $raw, actual: $event->getLines());
		$this->assertSame(expected: [], actual: $this->event(lines: [])->toCloudEvent()['data']['lines']);
	}//end testTheEnvelopeDoesNotRewriteTheTypedLines()
}//end class
