<?php

/**
 * Unit tests for the TenderPostedEvent CloudEvent envelope.
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

use OCA\Pipelinq\Event\TenderPostedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Event\TenderPostedEvent
 */
class TenderPostedEventTest extends TestCase {

	/**
	 * Build an event.
	 *
	 * @param float $amount The tender amount.
	 *
	 * @return TenderPostedEvent The event.
	 */
	private function event(float $amount = 12.5): TenderPostedEvent {
		return new TenderPostedEvent(
			eventId: 'evt-1',
			tenderUuid: 'tender-1',
			transactionUuid: 'tx-1',
			transactionReference: 'KB-2026-0001',
			tenderTypeCode: 'pin',
			amount: $amount,
			glAccount: '1100',
			emittedAt: '2026-11-12T10:00:00+00:00',
		);
	}//end event()

	/**
	 * The CloudEvent carries the fixed type, source and spec version, and the tender as data.
	 *
	 * @return void
	 */
	public function testToCloudEventBuildsTheFullEnvelope(): void {
		$this->assertSame(
			[
				'specversion'     => '1.0',
				'type'            => 'nl.pipelinq.pos.tender.posted',
				'source'          => '/apps/pipelinq/pos/tender',
				'id'              => 'evt-1',
				'time'            => '2026-11-12T10:00:00+00:00',
				'datacontenttype' => 'application/json',
				'data'            => [
					'tenderUuid'           => 'tender-1',
					'transactionUuid'      => 'tx-1',
					'transactionReference' => 'KB-2026-0001',
					'tenderType'           => 'pin',
					'amount'               => 12.5,
					'glAccount'            => '1100',
				],
			],
			$this->event()->toCloudEvent()
		);
	}//end testToCloudEventBuildsTheFullEnvelope()

	/**
	 * The envelope agrees with the getters, and a negative (refund) amount stays a float.
	 *
	 * @return void
	 */
	public function testEnvelopeMatchesTheGettersForARefundAmount(): void {
		$event = $this->event(amount: -3.0);
		$cloud = $event->toCloudEvent();

		$this->assertSame($event->getEventId(), $cloud['id']);
		$this->assertSame($event->getEmittedAt(), $cloud['time']);
		$this->assertSame(
			[
				$event->getTenderUuid(),
				$event->getTransactionUuid(),
				$event->getTransactionReference(),
				$event->getTenderTypeCode(),
				$event->getAmount(),
				$event->getGlAccount(),
			],
			array_values($cloud['data'])
		);
		$this->assertSame(-3.0, $cloud['data']['amount']);
	}//end testEnvelopeMatchesTheGettersForARefundAmount()
}//end class
