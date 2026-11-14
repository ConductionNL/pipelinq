<?php

/**
 * Pipelinq PosTransactionCompletedListener.
 *
 * Trigger: a posTransaction object reaches the "settled" or "completed" status.
 * Action: invokes LoyaltyEngineService::processPosTransaction; failures are
 * caught and logged — POS flow MUST never be impacted (REQ-LOY-002-05).
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\LoyaltyEngineServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\SchemaMapServiceInterface;
use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listens for posTransaction created/updated events and triggers the loyalty engine.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */
class PosTransactionCompletedListener implements IEventListener {
	use EntityAccessorTrait;

	/**
	 * Constructor.
	 *
	 * @param LoyaltyEngineServiceInterface $loyaltyEngineService The loyalty engine.
	 * @param SchemaMapServiceInterface $schemaMapService The schema map service.
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private LoyaltyEngineServiceInterface $loyaltyEngineService,
		private SchemaMapServiceInterface $schemaMapService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a POS-transaction object event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002-01
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$this->awardPoints(entity: $event->getObject());
		} catch (Throwable $e) {
			// CRITICAL: never throw — POS flow must not be affected.
			$this->logger->warning(
				'Pipelinq: loyalty listener failed; POS flow unaffected',
				['exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Award loyalty points for a completed POS transaction with a customer.
	 *
	 * @param object $entity The OR entity of the event.
	 *
	 * @return void
	 */
	private function awardPoints(object $entity): void {
		if ($this->isPosTransaction(entity: $entity) === false) {
			return;
		}

		$data = $this->getEntityData(entity: $entity);
		if ($this->isCompleted(data: $data) === false) {
			return;
		}

		$customerId = $this->customerOf(data: $data);
		if ($customerId === '') {
			// Anonymous transaction; no points to award.
			return;
		}

		$this->loyaltyEngineService->processPosTransaction(
			customerId: $customerId,
			transaction: $this->transactionContext(entity: $entity, data: $data)
		);
	}//end awardPoints()

	/**
	 * Whether the transaction completed.
	 *
	 * Only fire for completed/settled transactions. The live posTransaction
	 * status enum is draft|parked|confirmed|settled|refunded; 'completed'
	 * and 'paid' are retained for payloads written by older POS clients.
	 *
	 * @param array<string, mixed> $data The transaction.
	 *
	 * @return bool True for a completed, settled or paid transaction.
	 */
	private function isCompleted(array $data): bool {
		$status = (string)($data['status'] ?? '');
		return in_array($status, ['completed', 'settled', 'paid'], true);
	}//end isCompleted()

	/**
	 * The contact the transaction is for, or '' for an anonymous one.
	 *
	 * @param array<string, mixed> $data The transaction.
	 *
	 * @return string The customer id.
	 */
	private function customerOf(array $data): string {
		// The customer link on a posTransaction is `customer` — a uuid ref to a
		// pipelinq contact, which is exactly what processPosTransaction() calls
		// its $customerId. `klantId` / `customerId` / `contactUid` are a retired
		// vocabulary that the posTransaction schema has never declared and that
		// nothing in this codebase writes, so this read resolved to '' on every
		// real transaction and returned before awarding anything (pipelinq#807).
		return (string)($data['customer'] ?? $data['klantId'] ?? $data['customerId'] ?? $data['contactUid'] ?? '');
	}//end customerOf()

	/**
	 * The transaction context the loyalty engine awards on.
	 *
	 * @param object               $entity The OR entity.
	 * @param array<string, mixed> $data   The transaction.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function transactionContext(object $entity, array $data): array {
		// Same correction for the transaction context: the schema declares
		// total / settledAt / reference / terminalId. The Dutch keys below them
		// (totaalbedrag / voltooidOp / transactieId / kanaal) appear nowhere else
		// in the app and are kept only as a fallback for legacy payloads —
		// without the schema names an award would have been computed on amount 0.
		return [
			'amount' => (float)($data['total'] ?? $data['totaalbedrag'] ?? $data['amount'] ?? 0),
			'category' => (string)($data['category'] ?? ''),
			'channel' => (string)($data['channel'] ?? 'offline'),
			'segment' => (string)($data['segment'] ?? ''),
			'timestamp' => (string)($data['settledAt'] ?? $data['voltooidOp'] ?? $data['timestamp'] ?? ''),
			'posTransactionId' => $this->transactionIdOf(entity: $entity, data: $data),
			'posTerminalId' => (string)($data['terminalId'] ?? $data['posTerminalId'] ?? ''),
			'trigger' => 'purchase',
		];
	}//end transactionContext()

	/**
	 * The transaction's reference, falling back to the entity uuid.
	 *
	 * @param object               $entity The OR entity.
	 * @param array<string, mixed> $data   The transaction.
	 *
	 * @return string The transaction id.
	 */
	private function transactionIdOf(object $entity, array $data): string {
		$transactionId = (string)($data['reference'] ?? $data['transactieId'] ?? $data['transactionId'] ?? '');
		if ($transactionId === '') {
			return $this->getEntityUuid(entity: $entity);
		}

		return $transactionId;
	}//end transactionIdOf()

	/**
	 * Whether the OR entity is a posTransaction.
	 *
	 * @param object $entity The OR entity.
	 *
	 * @return bool
	 */
	private function isPosTransaction(object $entity): bool {
		// `getSchema()` is served by Entity::__call, so method_exists() is FALSE
		// for it on a real ObjectEntity; probing with it made this guard reject
		// every POS transaction and switched the loyalty award path off
		// entirely. Read the value instead (pipelinq#807).
		$schemaId = $this->readEntityValue(entity: $entity, getter: 'getSchema');
		if ($schemaId === '') {
			return false;
		}

		$entityType = $this->schemaMapService->resolveEntityType(schemaId: $schemaId);
		if ($entityType === 'posTransaction') {
			return true;
		}

		// Fallback: direct compare against app-config posTransaction_schema.
		$posSchema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'posTransaction_schema', '');
		return $posSchema !== '' && $schemaId === $posSchema;
	}//end isPosTransaction()

	/**
	 * Get the entity data array.
	 *
	 * @param object $entity The OR entity.
	 *
	 * @return array<string, mixed>
	 */
	private function getEntityData(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		if (method_exists($entity, 'jsonSerialize') === true) {
			$serialized = $entity->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end getEntityData()

	/**
	 * Get the entity UUID.
	 *
	 * @param object $entity The entity.
	 *
	 * @return string
	 */
	private function getEntityUuid(object $entity): string {
		// `getUuid()` is magic too — the method_exists() probe here made this
		// return '' unconditionally, so the loyalty context carried an empty
		// posTransactionId whenever the payload had no reference (pipelinq#807).
		return $this->readEntityValue(entity: $entity, getter: 'getUuid');
	}//end getEntityUuid()
}//end class
