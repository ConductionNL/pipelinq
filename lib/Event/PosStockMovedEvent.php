<?php

/**
 * Pipelinq PosStockMovedEvent.
 *
 * Emitted by PosTransactionService::emitStockMovedEvent() once per settled
 * posTransaction, from the SAME commit path as TenderPostedEvent (the settle
 * transition) so a POS sale posts payment AND stock atomically. Downstream
 * consumers (Shillinq's PosStockDecrementListener) decrement on-hand
 * quantity and post COGS for each sold line. Listeners MUST treat the
 * payload as read-only and MUST NOT throw back into the settle path — the
 * settlement write has already completed by the time this event fires.
 *
 * Idempotency: `posTxnId` (the settled transaction's UUID) is the shared
 * idempotency key a consumer keys de-duplication on — a redelivered event
 * for the same posTxnId MUST be treated as a no-op by the consumer.
 * Event-type follows the `nl.pipelinq.pos.stock.moved` reverse-domain
 * convention required by CloudEvents 1.0, mirroring
 * `nl.pipelinq.pos.tender.posted`.
 *
 * @category Event
 * @package  OCA\Pipelinq\Event
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
 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired when a settled posTransaction's sold lines have moved stock.
 *
 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
 */
class PosStockMovedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $eventId The CloudEvents id (stable UUID per emission).
	 * @param string $transactionUuid The settled posTransaction UUID (the shared idempotency key / `posTxnId`).
	 * @param string $transactionReference Human-readable transaction reference (e.g. TXN-2026-0003).
	 * @param string $administrationId The shillinq administration/tenant id this sale posts to.
	 * @param array<int, array<string, mixed>> $lines Sold lines: each `{productRef, qty, unit, location}`.
	 * @param string $emittedAt ISO 8601 UTC emission timestamp.
	 */
	public function __construct(
		private string $eventId,
		private string $transactionUuid,
		private string $transactionReference,
		private string $administrationId,
		private array $lines,
		private string $emittedAt,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The CloudEvents id (stable per emission).
	 *
	 * @return string The event id.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	public function getEventId(): string {
		return $this->eventId;
	}//end getEventId()

	/**
	 * The settled transaction UUID — the shared idempotency key (`posTxnId`).
	 *
	 * @return string The transaction UUID.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	public function getTransactionUuid(): string {
		return $this->transactionUuid;
	}//end getTransactionUuid()

	/**
	 * The human-readable transaction reference (e.g. TXN-2026-0001).
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	public function getTransactionReference(): string {
		return $this->transactionReference;
	}//end getTransactionReference()

	/**
	 * The shillinq administration/tenant id this sale posts to.
	 *
	 * @return string The administration id.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	public function getAdministrationId(): string {
		return $this->administrationId;
	}//end getAdministrationId()

	/**
	 * The sold lines: each `{productRef, qty, unit, location}`.
	 *
	 * @return array<int, array<string, mixed>> The lines.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	public function getLines(): array {
		return $this->lines;
	}//end getLines()

	/**
	 * The ISO 8601 UTC emission timestamp.
	 *
	 * @return string The timestamp.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	public function getEmittedAt(): string {
		return $this->emittedAt;
	}//end getEmittedAt()

	/**
	 * CloudEvents-shaped payload for the `nl.pipelinq.pos.stock.moved` event.
	 *
	 * @return array<string, mixed> The CloudEvents envelope.
	 *
	 * @spec openspec/changes/pos-stock-moved-event/specs/pos-stock-moved-event/spec.md#Requirement:-A-settled-POS-sale-SHALL-emit-a-typed-stock-moved-CloudEvent
	 */
	public function toCloudEvent(): array {
		return [
			'specversion' => '1.0',
			'type' => 'nl.pipelinq.pos.stock.moved',
			'source' => '/apps/pipelinq/pos/stock',
			'id' => $this->eventId,
			'time' => $this->emittedAt,
			'datacontenttype' => 'application/json',
			'data' => [
				'posTxnId' => $this->transactionUuid,
				'transactionReference' => $this->transactionReference,
				'administrationId' => $this->administrationId,
				'lines' => array_map(
					static function (array $line): array {
						return [
							'productRef' => (string)($line['productRef'] ?? ''),
							'qty' => (float)($line['qty'] ?? 0),
							'unit' => (string)($line['unit'] ?? ''),
							// Reserved for future multi-location POS support;
							// pipelinq is single-location today (see the
							// pos-stock-moved-event proposal's Out of scope).
							'location' => (string)($line['location'] ?? ''),
						];
					},
					$this->lines
				),
			],
		];
	}//end toCloudEvent()
}//end class
