<?php

/**
 * Pipelinq ExpenseApprovalListener.
 *
 * Dispatches approved expenses to the Shillinq AP webhook.
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
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
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
 * ADR-078: the approval is already stored when this runs. The three
 * `saveObject()` calls and the outbound AP webhook (with its own retry budget)
 * therefore no longer run inside the approving user's request — they run in
 * {@see DeferredObjectListenerJob} under that user.
 *
 * THE RE-ENTRANCY GUARD IS STILL THE LOAD-BEARING PART, and it moved to
 * {@see DeferredWorkGuard} so every converted listener in this app shares one
 * implementation. The mechanism is unchanged:
 *
 *   runDeferredWork() -> persist() -> ObjectService::saveObject(), and
 *   MagicMapper::update() dispatches ObjectUpdatedEvent for that write. The
 *   event carries the SAME expense, still status=approved, so every check in
 *   handle() passes again. The idempotency guard cannot stop it: it
 *   short-circuits only on apSyncStatus === 'synced', and the first re-entry
 *   sees 'pending' — the value persist() has just written.
 *
 * `silent: true` on saveObject() does NOT fix this and was measured: $silent
 * gates the audit trail and updateInverseRelations only (openregister
 * lib/Service/Object/SaveObject.php:3468, :3489, :5445, :5515). The lifecycle
 * dispatch at MagicMapper.php:8997 is gated solely by
 * suppressLifecycleEvents(), i.e. by SystemOperationContext::isActive(), which
 * a listener is not in.
 *
 * Deferral makes the guard MORE important, not less: without it the re-entrant
 * handle() would enqueue another job, whose write would re-enter again, and the
 * loop would move from one request's stack onto the cron queue — where
 * `cron.php` runs one job per web call and a self-re-queuing job starves
 * everything behind it.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bridges the schema map,
 *  Shillinq AP service, admin notifier, event dispatcher, OR ObjectService,
 *  deferral service, app-config and logger — the minimal collaborator set for
 *  AP fan-out.
 */
class ExpenseApprovalListener implements IEventListener, DeferredObjectWork {
	use EntityAccessorTrait;

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'expense-approval';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapService $schemaMapService The schema map service.
	 * @param ShillinqApService $apService The Shillinq AP service.
	 * @param ApSyncNotifier $notifier The admin failure notifier.
	 * @param IEventDispatcher $eventDispatcher The event dispatcher (for ExpenseApprovedEvent fan-out).
	 * @param ContainerInterface $container The DI container (OpenRegister ObjectService lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ListenerDeferralService $deferral The actor-forwarding deferral service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private SchemaMapService $schemaMapService,
		private ShillinqApService $apService,
		private ApSyncNotifier $notifier,
		private IEventDispatcher $eventDispatcher,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ListenerDeferralService $deferral,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object create/update event.
	 *
	 * Does no AP work: evaluates the preconditions and queues the dispatch.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DeferredWorkGuard is a process-scoped
	 *  re-entrancy guard: its `$inFlight` map MUST be shared across every listener
	 *  instance in the request, which is exactly what an injected per-instance
	 *  service cannot give. Static is the mechanism, not an accident.
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$dispatchable = $this->resolveDispatchable(event: $event);
			if ($dispatchable === null) {
				return;
			}

			[$uuid] = $dispatchable;

			// See the class docblock: our own persist() re-enters this listener
			// with a payload that passes every check above.
			if (DeferredWorkGuard::isRunning(key: DeferredWorkGuard::key(handler: self::HANDLER_KEY, uuid: $uuid)) === true) {
				return;
			}

			$this->deferral->defer(
				jobClass: DeferredObjectListenerJob::class,
				entry: [
					'handler' => self::HANDLER_KEY,
					'uuid' => $uuid,
				],
				dedupeKey: self::HANDLER_KEY . '|' . $uuid
			);
		} catch (Throwable $e) {
			// CRITICAL: never throw — approval workflow must not be affected.
			$this->logger->warning(
				'Pipelinq: AP listener failed; expense workflow unaffected',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Re-emit the approval event and dispatch the AP webhook, recording outcome.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-002
	 */
	public function runDeferredWork(array $entry): void {
		$uuid = (string)($entry['uuid'] ?? '');
		$data = $this->fetch(uuid: $uuid);
		if ($data === null) {
			// Expense gone since approval. Stale entry (ADR-078 Rule 7).
			return;
		}

		// Re-checked against current state: the expense may have been
		// un-approved, already synced, or the webhook unconfigured since.
		if (($data['status'] ?? '') !== 'approved'
			|| ($data['apSyncStatus'] ?? null) === 'synced'
			|| $this->apService->shouldDispatch() === false
		) {
			return;
		}

		$this->dispatchApproval(uuid: $uuid, data: $data);
	}//end runDeferredWork()

	/**
	 * Decide whether this event should produce an AP dispatch, and for what.
	 *
	 * Extracted from handle() so the preconditions live in one place. Every
	 * `null` here is a deliberate no-op named by REQ-AP-002.
	 *
	 * @param ObjectCreatedEvent|ObjectUpdatedEvent $event The dispatched event.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null The expense UUID and
	 *                                                        its data, or null when this event is not an approval to dispatch.
	 */
	private function resolveDispatchable(ObjectCreatedEvent|ObjectUpdatedEvent $event): ?array {
		$entity = $this->resolveEntity(event: $event);

		if ($this->isExpense(entity: $entity) === false) {
			return null;
		}

		$data = $entity->getObject();

		// Only fire for approved expenses (REQ-AP-002).
		if (($data['status'] ?? '') !== 'approved') {
			return null;
		}

		$uuid = (string)$entity->getUuid();
		if ($uuid === '') {
			return null;
		}

		// Idempotency: never re-dispatch an already-synced expense (REQ-AP-002 Scenario 5).
		if (($data['apSyncStatus'] ?? null) === 'synced') {
			return null;
		}

		// Webhook not configured: silent no-op (REQ-AP-002 Scenario 6).
		if ($this->apService->shouldDispatch() === false) {
			return null;
		}

		return [$uuid, $data];
	}//end resolveDispatchable()

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
		// The instanceof already settles it: ObjectUpdatedEvent declares
		// getNewObject() on both `main` and `development`, so the
		// method_exists() conjunct this replaces could never be false.
		if ($event instanceof ObjectUpdatedEvent) {
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
		if ($entityType === 'billableExpense') {
			return true;
		}

		// Fallback: direct compare against app-config expense_schema (REQ-AP-002).
		$expenseSchema = $this->appConfig->getValueString(Application::APP_ID, 'expense_schema', '');
		return $expenseSchema !== '' && $schemaId === $expenseSchema;
	}//end isExpense()

	/**
	 * Read the expense's current data.
	 *
	 * @param string $uuid The expense UUID.
	 *
	 * @return array<string, mixed>|null The expense data, or null when it is gone.
	 */
	private function fetch(string $uuid): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'expense_schema', '');
		if ($register === '' || $schema === '' || $uuid === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, register: $register, schema: $schema);
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to re-read expense for deferred AP dispatch',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetch()

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
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to persist expense AP sync status',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()
}//end class
