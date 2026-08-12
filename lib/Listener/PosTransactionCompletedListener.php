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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\LoyaltyEngineService;
use OCA\Pipelinq\Service\SchemaMapService;
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
 */
class PosTransactionCompletedListener implements IEventListener {
	use EntityAccessorTrait;

	/**
	 * Constructor.
	 *
	 * @param LoyaltyEngineService $loyaltyEngineService The loyalty engine.
	 * @param SchemaMapService $schemaMapService The schema map service.
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private LoyaltyEngineService $loyaltyEngineService,
		private SchemaMapService $schemaMapService,
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
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002-01
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$entity = $event->getObject();
			if ($this->isPosTransaction(entity: $entity) === false) {
				return;
			}

			$data = $this->getEntityData(entity: $entity);

			// Only fire for completed/settled transactions. The live posTransaction
			// status enum is draft|parked|confirmed|settled|refunded; 'completed'
			// and 'paid' are retained for payloads written by older POS clients.
			$status = (string)($data['status'] ?? '');
			if (in_array($status, ['completed', 'settled', 'paid'], true) === false) {
				return;
			}

			// The customer link on a posTransaction is `customer` — a uuid ref to a
			// pipelinq contact, which is exactly what processPosTransaction() calls
			// its $klantId. `klantId` / `customerId` / `contactUid` are a retired
			// vocabulary that the posTransaction schema has never declared and that
			// nothing in this codebase writes, so this read resolved to '' on every
			// real transaction and returned before awarding anything (pipelinq#807).
			$klantId = (string)($data['customer'] ?? $data['klantId'] ?? $data['customerId'] ?? $data['contactUid'] ?? '');
			if ($klantId === '') {
				// Anonymous transaction; no points to award.
				return;
			}

			// Same correction for the transaction context: the schema declares
			// total / settledAt / reference / terminalId. The Dutch keys below them
			// (totaalbedrag / voltooidOp / transactieId / kanaal) appear nowhere else
			// in the app and are kept only as a fallback for legacy payloads —
			// without the schema names an award would have been computed on amount 0.
			$transactionId = (string)($data['reference'] ?? $data['transactieId'] ?? $data['transactionId'] ?? '');
			if ($transactionId === '') {
				$transactionId = $this->getEntityUuid(entity: $entity);
			}

			$context = [
				'amount' => (float)($data['total'] ?? $data['totaalbedrag'] ?? $data['amount'] ?? 0),
				'category' => (string)($data['category'] ?? ''),
				'channel' => (string)($data['kanaal'] ?? $data['channel'] ?? 'offline'),
				'segment' => (string)($data['segment'] ?? ''),
				'timestamp' => (string)($data['settledAt'] ?? $data['voltooidOp'] ?? $data['timestamp'] ?? ''),
				'posTransactionId' => $transactionId,
				'posTerminalId' => (string)($data['terminalId'] ?? $data['posTerminalId'] ?? ''),
				'trigger' => 'purchase',
			];

			$this->loyaltyEngineService->processPosTransaction(klantId: $klantId, transaction: $context);
		} catch (Throwable $e) {
			// CRITICAL: never throw — POS flow must not be affected.
			$this->logger->warning(
				'Pipelinq: loyalty listener failed; POS flow unaffected',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

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
		$posSchema = $this->appConfig->getValueString(Application::APP_ID, 'posTransaction_schema', '');
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
