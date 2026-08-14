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
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listens for posTransaction created/updated events and triggers the loyalty engine.
 *
 * @implements IEventListener<Event>
 */
class PosTransactionCompletedListener implements IEventListener {
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

			// Only fire for completed/settled transactions.
			$status = (string)($data['status'] ?? '');
			if (in_array($status, ['completed', 'settled', 'paid'], true) === false) {
				return;
			}

			$klantId = (string)($data['klantId'] ?? $data['customerId'] ?? $data['contactUid'] ?? '');
			if ($klantId === '') {
				// Anonymous transaction; no points to award.
				return;
			}

			$context = [
				'amount' => (float)($data['totaalbedrag'] ?? $data['amount'] ?? 0),
				'category' => (string)($data['category'] ?? ''),
				'channel' => (string)($data['kanaal'] ?? $data['channel'] ?? 'offline'),
				'segment' => (string)($data['segment'] ?? ''),
				'timestamp' => (string)($data['voltooidOp'] ?? $data['timestamp'] ?? ''),
				'posTransactionId' => (string)($data['transactieId'] ?? $data['transactionId'] ?? $this->getEntityUuid(entity: $entity)),
				'posTerminalId' => (string)($data['posTerminalId'] ?? $data['terminalId'] ?? ''),
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
		if (method_exists($entity, 'getSchema') === false) {
			return false;
		}

		$entityType = $this->schemaMapService->resolveEntityType(schemaId: (string)$entity->getSchema());
		if ($entityType === 'posTransaction') {
			return true;
		}

		// Fallback: direct compare against app-config posTransaction_schema.
		$posSchema = $this->appConfig->getValueString(Application::APP_ID, 'posTransaction_schema', '');
		return $posSchema !== '' && (string)$entity->getSchema() === $posSchema;
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
		if (method_exists($entity, 'getUuid') === true) {
			return (string)$entity->getUuid();
		}

		return '';
	}//end getEntityUuid()
}//end class
