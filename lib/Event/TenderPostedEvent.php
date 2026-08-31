<?php

/**
 * Pipelinq TenderPostedEvent.
 *
 * Emitted by PosTenderService::emitTenderPosted() once per posTender on the
 * `settle` transition of its parent posTransaction. Downstream consumers
 * (most notably Shillinq, which posts the debit/credit ledger entry to the
 * tender's GL account) MUST treat the payload as read-only and MUST NOT
 * throw — the settlement write has already completed by the time this event
 * fires, and a posting failure is recovered by the TenderPostedRetryJob
 * rather than by aborting the settle path.
 *
 * Idempotency: the event-id is a stable UUID generated per emission and
 * persisted on the tender object's cloudEventId field so the retry job and
 * any downstream receiver can de-duplicate replays. Event-type follows the
 * `nl.pipelinq.pos.tender.posted` reverse-domain convention required by
 * CloudEvents 1.0.
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
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired when a tender on a settled posTransaction has been queued for GL posting.
 *
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
 */
class TenderPostedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $eventId The CloudEvents id (stable UUID per emission).
	 * @param string $tenderUuid The tender object UUID.
	 * @param string $transactionUuid The parent transaction UUID.
	 * @param string $transactionReference Human-readable transaction reference (e.g. TXN-2026-0003).
	 * @param string $tenderTypeCode The tender type code (CASH / CARD / VOUCHER / ...).
	 * @param float $amount The tendered amount in EUR.
	 * @param string $glAccount The GL account the tender posts to.
	 * @param string $emittedAt ISO 8601 UTC emission timestamp.
	 */
	public function __construct(
		private string $eventId,
		private string $tenderUuid,
		private string $transactionUuid,
		private string $transactionReference,
		private string $tenderTypeCode,
		private float $amount,
		private string $glAccount,
		private string $emittedAt,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The CloudEvents id (stable per emission; persisted on the tender for idempotency).
	 *
	 * @return string The event id.
	 * @spec exclude split tender has no owning spec: the class already cites
	 *   openspec/changes/pos-split-tender, which is ARCHIVED, so that reference
	 *   resolves to nothing
	 */
	public function getEventId(): string {
		return $this->eventId;
	}//end getEventId()

	/**
	 * The tender object UUID.
	 *
	 * @return string The tender UUID.
	 * @spec exclude split tender has no owning spec: the class already cites
	 *   openspec/changes/pos-split-tender, which is ARCHIVED, so that reference
	 *   resolves to nothing
	 */
	public function getTenderUuid(): string {
		return $this->tenderUuid;
	}//end getTenderUuid()

	/**
	 * The parent transaction UUID.
	 *
	 * @return string The transaction UUID.
	 * @spec exclude split tender has no owning spec: the class already cites
	 *   openspec/changes/pos-split-tender, which is ARCHIVED, so that reference
	 *   resolves to nothing
	 */
	public function getTransactionUuid(): string {
		return $this->transactionUuid;
	}//end getTransactionUuid()

	/**
	 * The human-readable transaction reference (e.g. TXN-2026-0001).
	 *
	 * @return string The reference.
	 * @spec exclude split tender has no owning spec: the class already cites
	 *   openspec/changes/pos-split-tender, which is ARCHIVED, so that reference
	 *   resolves to nothing
	 */
	public function getTransactionReference(): string {
		return $this->transactionReference;
	}//end getTransactionReference()

	/**
	 * The tender type code (CASH / CARD / VOUCHER / ...).
	 *
	 * @return string The code.
	 * @spec exclude split tender has no owning spec: the class already cites
	 *   openspec/changes/pos-split-tender, which is ARCHIVED, so that reference
	 *   resolves to nothing
	 */
	public function getTenderTypeCode(): string {
		return $this->tenderTypeCode;
	}//end getTenderTypeCode()

	/**
	 * The tendered amount in EUR.
	 *
	 * @return float The amount.
	 * @spec exclude split tender has no owning spec: the class already cites
	 *   openspec/changes/pos-split-tender, which is ARCHIVED, so that reference
	 *   resolves to nothing
	 */
	public function getAmount(): float {
		return $this->amount;
	}//end getAmount()

	/**
	 * The GL account this tender posts to.
	 *
	 * @return string The GL account.
	 * @spec exclude split tender has no owning spec: the class already cites
	 *   openspec/changes/pos-split-tender, which is ARCHIVED, so that reference
	 *   resolves to nothing
	 */
	public function getGlAccount(): string {
		return $this->glAccount;
	}//end getGlAccount()

	/**
	 * The ISO 8601 UTC emission timestamp.
	 *
	 * @return string The timestamp.
	 * @spec exclude split tender has no owning spec: the class already cites
	 *   openspec/changes/pos-split-tender, which is ARCHIVED, so that reference
	 *   resolves to nothing
	 */
	public function getEmittedAt(): string {
		return $this->emittedAt;
	}//end getEmittedAt()

	/**
	 * CloudEvents-shaped payload for the `nl.pipelinq.pos.tender.posted` event.
	 *
	 * @return array<string, mixed> The CloudEvents envelope.
	 * @spec exclude split tender has no owning spec: the class already cites
	 *   openspec/changes/pos-split-tender, which is ARCHIVED, so that reference
	 *   resolves to nothing
	 */
	public function toCloudEvent(): array {
		return [
			'specversion' => '1.0',
			'type' => 'nl.pipelinq.pos.tender.posted',
			'source' => '/apps/pipelinq/pos/tender',
			'id' => $this->eventId,
			'time' => $this->emittedAt,
			'datacontenttype' => 'application/json',
			'data' => [
				'tenderUuid' => $this->tenderUuid,
				'transactionUuid' => $this->transactionUuid,
				'transactionReference' => $this->transactionReference,
				'tenderType' => $this->tenderTypeCode,
				'amount' => $this->amount,
				'glAccount' => $this->glAccount,
			],
		];
	}//end toCloudEvent()
}//end class
