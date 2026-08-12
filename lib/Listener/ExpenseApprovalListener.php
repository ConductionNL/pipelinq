<?php

/**
 * Pipelinq ExpenseApprovalListener.
 *
 * Listens to OpenRegister object create/update events, detects expense
 * schema objects that have transitioned to status=approved, dispatches the
 * approval to the Shillinq AP webhook and records the outcome on the
 * expense's apSyncStatus / apSyncedAt fields.
 *
 * Idempotent: an expense already marked apSyncStatus=synced is skipped so a
 * re-fired update event cannot create a duplicate AP voucher (REQ-AP-002
 * Scenario 5).
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
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Event\ExpenseApprovedEvent;
use OCA\Pipelinq\Service\ApSyncNotifier;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\ShillinqApService;
use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listener that dispatches approved expenses to the Shillinq AP webhook.
 *
 * Filtered to the expense schema and status=approved transitions. Updates the
 * expense's apSyncStatus / apSyncedAt fields with the dispatch outcome and
 * notifies admins through ApSyncNotifier on final failure.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bridges the schema map,
 *  Shillinq AP service, admin notifier, event dispatcher, OR ObjectService,
 *  app-config and logger — the minimal collaborator set for AP fan-out.
 */
class ExpenseApprovalListener implements IEventListener {
	use EntityAccessorTrait;

	/**
	 * Expense UUIDs whose dispatch is currently on the stack.
	 *
	 * Static rather than per-instance on purpose: Nextcloud's event dispatcher
	 * resolves the listener from the container per dispatch, so a re-entrant
	 * dispatch is not guaranteed to reach the same object. Static state is safe
	 * here because PHP-FPM tears the process context down per request, and the
	 * `finally` in handle() releases the key even when dispatch throws.
	 *
	 * @var array<string, true>
	 */
	private static array $inFlight = [];

	/**
	 * Constructor.
	 *
	 * @param SchemaMapService $schemaMapService The schema map service.
	 * @param ShillinqApService $apService The Shillinq AP service.
	 * @param ApSyncNotifier $notifier The admin failure notifier.
	 * @param IEventDispatcher $eventDispatcher The event dispatcher (for ExpenseApprovedEvent fan-out).
	 * @param ContainerInterface $container The DI container (OpenRegister ObjectService lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private SchemaMapService $schemaMapService,
		private ShillinqApService $apService,
		private ApSyncNotifier $notifier,
		private IEventDispatcher $eventDispatcher,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object create/update event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$entity = $this->resolveEntity(event: $event);

			if ($this->isExpense(entity: $entity) === false) {
				return;
			}

			$data = $entity->getObject();

			// Only fire for approved expenses (REQ-AP-002).
			if (($data['status'] ?? '') !== 'approved') {
				return;
			}

			$uuid = (string)$entity->getUuid();
			if ($uuid === '') {
				return;
			}

			// Idempotency: never re-dispatch an already-synced expense (REQ-AP-002 Scenario 5).
			if (($data['apSyncStatus'] ?? null) === 'synced') {
				return;
			}

			// Webhook not configured: silent no-op (REQ-AP-002 Scenario 6).
			if ($this->apService->shouldDispatch() === false) {
				return;
			}

			// Re-entrancy: our own persist() re-enters this listener.
			//
			// dispatchApproval() -> persist() -> ObjectService::saveObject(), and
			// MagicMapper::update() dispatches ObjectUpdatedEvent for that write.
			// The event carries the SAME expense, still status=approved, so every
			// check above passes again. The idempotency guard cannot stop it: it
			// short-circuits only on apSyncStatus === 'synced', and the first
			// re-entry sees 'pending' — the value persist() has just written. The
			// terminating write is therefore never reached and each level fires
			// another outbound AP webhook with its own retries.
			//
			// `silent: true` on saveObject() does NOT fix this and was measured:
			// $silent gates the audit trail and updateInverseRelations only
			// (openregister lib/Service/Object/SaveObject.php:3468, :3489, :5445,
			// :5515). The lifecycle dispatch at MagicMapper.php:8997 is gated
			// solely by suppressLifecycleEvents(), i.e. by
			// SystemOperationContext::isActive(), which a listener is not in.
			//
			// Until now the only thing preventing the loop was the method_exists()
			// probe this branch repairs: isExpense() was permanently false, so
			// handle() never reached dispatchApproval() at all. Reviving the
			// listener without this guard arms the loop. Today it stays bounded
			// only because shouldDispatch() is false without a configured webhook
			// URL — i.e. it would arm the moment anyone configures AP sync.
			if (isset(self::$inFlight[$uuid]) === true) {
				return;
			}

			self::$inFlight[$uuid] = true;
			try {
				$this->dispatchApproval(uuid: $uuid, data: $data);
			} finally {
				unset(self::$inFlight[$uuid]);
			}
		} catch (Throwable $e) {
			// CRITICAL: never throw — approval workflow must not be affected.
			$this->logger->warning(
				'Pipelinq: AP listener failed; expense workflow unaffected',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Resolve the event's target entity.
	 *
	 * OR's ObjectUpdatedEvent exposes both getObject() and getNewObject();
	 * ObjectCreatedEvent only has getObject(). Prefer the typed accessor.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The dispatched event.
	 *
	 * @return object The OR entity.
	 */
	private function resolveEntity(ObjectCreatedEvent|ObjectUpdatedEvent $event): object {
		if ($event instanceof ObjectUpdatedEvent && method_exists($event, 'getNewObject') === true) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end resolveEntity()

	/**
	 * Re-emit the approval event and dispatch the AP webhook, recording outcome.
	 *
	 * @param string $uuid The expense UUID.
	 * @param array<string, mixed> $data The approved expense data.
	 *
	 * @return void
	 */
	private function dispatchApproval(string $uuid, array $data): void {
		$approvedBy = (string)($data['approvedBy'] ?? '');
		$approvedAt = (string)($data['approvedAt'] ?? $this->apService->now());

		// Re-emit a domain event so consumers downstream of the listener can
		// observe the same approval signal without coupling to OR events
		// (REQ-AP-002 — consumer-facing contract).
		$this->eventDispatcher->dispatchTyped(
			new ExpenseApprovedEvent(
				expenseUuid: $uuid,
				expense: $data,
				approvedBy: $approvedBy,
				approvedAt: $approvedAt
			)
		);

		// Mark pending and persist before the dispatch begins (REQ-AP-002 Scenario 4).
		$data['apSyncStatus'] = 'pending';
		$this->persist(uuid: $uuid, data: $data);

		$success = $this->apService->dispatchApEvent(
			expense: $data + ['uuid' => $uuid],
			approvedBy: $approvedBy,
			approvedAt: $approvedAt
		);

		if ($success === true) {
			$data['apSyncStatus'] = 'synced';
			$data['apSyncedAt'] = $this->apService->now();
			$this->persist(uuid: $uuid, data: $data);
			return;
		}

		// Dispatch failed after retries (REQ-AP-003 Scenario 10): mark failed and notify admins.
		$data['apSyncStatus'] = 'failed';
		$this->persist(uuid: $uuid, data: $data);
		$this->notifier->notifyFailure(
			expenseTitle: (string)($data['title'] ?? ''),
			uuid: $uuid
		);
	}//end dispatchApproval()

	/**
	 * Whether the entity belongs to the expense schema.
	 *
	 * @param object $entity The OR entity.
	 *
	 * @return bool True when the entity is an expense.
	 */
	private function isExpense(object $entity): bool {
		// `getSchema()` is served by Entity::__call, so method_exists() is FALSE
		// for it on a real ObjectEntity; probing with it turned this listener —
		// and the whole AP dispatch behind it — permanently off. Read the value
		// instead and treat '' as "no schema" (pipelinq#807).
		$schemaId = $this->readEntityValue(entity: $entity, getter: 'getSchema');
		if ($schemaId === '') {
			return false;
		}

		$entityType = $this->schemaMapService->resolveEntityType(schemaId: $schemaId);
		if ($entityType === 'expense') {
			return true;
		}

		// Fallback: direct compare against app-config expense_schema (REQ-AP-002).
		$expenseSchema = $this->appConfig->getValueString(Application::APP_ID, 'expense_schema', '');
		return $expenseSchema !== '' && $schemaId === $expenseSchema;
	}//end isExpense()

	/**
	 * Persist the mutated expense data back to OpenRegister.
	 *
	 * @param string $uuid The expense UUID.
	 * @param array<string, mixed> $data The mutated expense data.
	 *
	 * @return void
	 */
	private function persist(string $uuid, array $data): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'expense_schema', '');
		if ($register === '' || $schema === '' || $uuid === '') {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuid
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to persist expense AP sync status',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()
}//end class
