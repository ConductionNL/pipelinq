<?php

/**
 * Pipelinq PosRefundService.
 *
 * Business logic for the POS refund / return lifecycle: server-authoritative
 * computation of refund amounts from the original transaction's persisted tax
 * data, the cumulative over-refund cap, the manager-gated confirm / reject
 * transitions, and CloudEvent emission (accounting reversal + stock back-in).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;

/**
 * Service for POS refund business operations.
 *
 * Every monetary figure is computed server-side from the original
 * posTransactionLine's persisted, server-authoritative taxAmount / lineTotal
 * (which PosTransactionService wrote on confirm). Client-supplied refund
 * amounts are never trusted. The cumulative over-refund cap prevents refunding
 * more than was originally sold, per line and in aggregate.
 *
 * Lifecycle transitions are applied through OpenRegister's TransitionEngine
 * (ADR-031): the engine enforces the declarative x-openregister-lifecycle table
 * on the posRefund schema and runs PosRefundManagerGuard, which owns the
 * manager-only authorization AND the cumulative over-refund cap on `complete`.
 * This service no longer mutates `status` directly or hand-rolls a manager gate.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a
 *  refund lifecycle service legitimately needs (OR container, app config,
 *  optional webhook dispatch, logger); splitting them would add indirection
 *  without reducing real coupling.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole refund lifecycle (proportional calc + two transition wrappers + two
 *  event emitters + OR persistence helpers) as many small, single-purpose
 *  methods; the cohesion is intentional and splitting it would scatter one
 *  transactional concern across several classes without reducing real complexity.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface is the
 *  refund calculation core (recalculateLine / recalculateTotals), the two
 *  transition wrappers (confirmRefund / rejectRefund) and the two event
 *  emitters — all single-purpose and unit-tested individually.
 *
 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.2
 */
class PosRefundService {
	/**
	 * CloudEvent type emitted on refund completion (accounting reversal).
	 *
	 * @var string
	 */
	public const EVENT_REFUND_COMPLETED = 'pipelinq.TransactionRefund.completed';

	/**
	 * CloudEvent type emitted per restocked line (inventory back-in).
	 *
	 * @var string
	 */
	public const EVENT_STOCK_MOVEMENT = 'shillinq.StockMovement';

	/**
	 * CloudEvents source identifier for this app's POS surface.
	 *
	 * @var string
	 */
	private const EVENT_SOURCE = '/apps/pipelinq/pos';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param LoggerInterface $logger The logger.
	 * @param WebhookService $webhookService Dispatches POS webhooks.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param TransitionEngine $transitionEngine Runs the refund status transitions.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly WebhookService $webhookService,
		private readonly ObjectServiceInterface $objectService,
		private readonly TransitionEngine $transitionEngine,
	) {
	}//end __construct()

	/**
	 * Round a monetary value to 2 decimals.
	 *
	 * @param float $value The value to round.
	 *
	 * @return float The value rounded to cents.
	 */
	private function money(float $value): float {
		return round($value, 2);
	}//end money()

	/**
	 * Compute the proportional taxAmount and lineTotal for a refund line.
	 *
	 * Server-authoritative: trusts only the original line's persisted quantity,
	 * taxAmount and lineTotal (written by PosTransactionService on confirm) and
	 * the returned quantity. Any client-supplied taxAmount / lineTotal on the
	 * refund line is overwritten. The proportion is clamped to [0, 1] so a
	 * returnedQty exceeding the original quantity can never inflate the refund.
	 *
	 * @param array<string, mixed> $originalLine The original posTransactionLine.
	 * @param float $returnedQty The quantity being returned.
	 *
	 * @return array{taxAmount: float, lineTotal: float, ratio: float} The computed amounts.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	public function recalculateLine(array $originalLine, float $returnedQty): array {
		$originalQty = (float)($originalLine['quantity'] ?? 0);
		$returned = max(0.0, $returnedQty);

		$ratio = 0.0;
		if ($originalQty > 0) {
			$ratio = min(1.0, ($returned / $originalQty));
		}

		$origTax = (float)($originalLine['taxAmount'] ?? 0);
		$origTotal = (float)($originalLine['lineTotal'] ?? 0);

		return [
			'ratio' => $ratio,
			'taxAmount' => $this->money(value: ($origTax * $ratio)),
			'lineTotal' => $this->money(value: ($origTotal * $ratio)),
		];
	}//end recalculateLine()

	/**
	 * Recompute and persist a refund's totals from its lines.
	 *
	 * For each refund line, fetches the referenced original posTransactionLine
	 * and computes the proportional tax-exclusive amount and tax. The refund
	 * lineTotal includes tax; refundAmount is the tax-exclusive sum, totalTax the
	 * aggregate tax. Each line's computed taxAmount / lineTotal are persisted back
	 * so the detail view and downstream events reflect server-authoritative
	 * figures. Returns the updated refund object.
	 *
	 * @param string $refundId The refund UUID.
	 *
	 * @return array<string, mixed> The updated refund.
	 *
	 * @throws OCSNotFoundException If the refund does not exist.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	public function recalculateTotals(string $refundId): array {
		$refund = $this->fetchRefund(id: $refundId);
		$lines = $this->fetchRefundLines(refundId: $refundId);

		$refundAmount = 0.0;
		$totalTax = 0.0;

		foreach ($lines as $line) {
			$originalLine = $this->fetchOriginalLine(id: (string)($line['originalLine'] ?? ''));
			$returnedQty = (float)($line['returnedQuantity'] ?? 0);
			$computed = $this->recalculateLine(originalLine: $originalLine, returnedQty: $returnedQty);

			$line['taxAmount'] = $computed['taxAmount'];
			$line['lineTotal'] = $computed['lineTotal'];
			$this->saveRefundLine(id: (string)($line['id'] ?? $line['uuid'] ?? ''), line: $line);

			$refundAmount += ($computed['lineTotal'] - $computed['taxAmount']);
			$totalTax += $computed['taxAmount'];
		}

		$refund['refundAmount'] = $this->money(value: $refundAmount);
		$refund['totalTax'] = $this->money(value: $totalTax);

		return $this->saveRefund(id: $refundId, refund: $refund);
	}//end recalculateTotals()

	/**
	 * Confirm a pending refund (manager only).
	 *
	 * Validates manager permission (fail closed), that the refund is pending and
	 * non-empty, that the original transaction exists, recomputes the
	 * server-authoritative totals, enforces the cumulative over-refund cap
	 * against the original transaction, then sets status=completed + confirmedAt,
	 * persists, and emits the reversal + stock-movement CloudEvents
	 * (fire-and-forget).
	 *
	 * @param string $id The refund UUID.
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The completed refund.
	 *
	 * @throws OCSNotFoundException If the refund or its original transaction does not exist.
	 * @throws OCSForbiddenException If the user lacks manager permission.
	 * @throws OCSBadRequestException If the status is invalid, the cart is empty, or the cap is exceeded.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	public function confirmRefund(string $id, string $userId): array {
		$refund = $this->fetchRefund(id: $id);

		$lines = $this->fetchRefundLines(refundId: $id);
		if (count($lines) === 0) {
			throw new OCSBadRequestException('Selecteer ten minste één artikel om terug te geven.');
		}

		// Verify the original transaction exists (orphan guard, REQ-REF-014).
		$this->fetchTransaction(id: (string)($refund['originalTransaction'] ?? ''));

		// Server-authoritative recompute from the original lines' persisted tax.
		// This persists the computed refundAmount / totalTax that the
		// PosRefundManagerGuard then reads to enforce the cumulative over-refund
		// cap during the `complete` transition.
		$this->recalculateTotals(refundId: $id);

		// Stamp the completion timestamp before the engine flips the status.
		$refund = $this->fetchRefund(id: $id);
		$refund['confirmedAt'] = $this->now();
		$this->saveRefund(id: $id, refund: $refund);

		// Apply the transition through OpenRegister: PosRefundManagerGuard
		// enforces manager-only + the cumulative over-refund cap, the engine
		// validates the pending→completed move and fires ObjectTransitionedEvent.
		$saved = $this->transitionObject(id: $id, action: 'complete');

		$eventId = $this->emitRefundEvent(refund: $saved);
		if ($eventId !== '') {
			$saved['cloudEventId'] = $eventId;
			$saved = $this->saveRefund(id: $id, refund: $saved);
		}

		$this->emitStockMovementEvents(refundId: $id);

		$this->logger->info('Pipelinq: POS refund completed', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}//end confirmRefund()

	/**
	 * Reject a pending refund (manager only).
	 *
	 * Validates that a non-empty rejection reason was supplied, stamps
	 * rejectedAt + rejectionReason, then applies the pending→rejected transition
	 * through OpenRegister's TransitionEngine. Manager authorization is enforced
	 * by PosRefundManagerGuard inside the engine (fail closed). Emits no events
	 * and changes no stock.
	 *
	 * @param string $id The refund UUID.
	 * @param string $reason The rejection reason (required).
	 * @param string $userId The acting user UID.
	 *
	 * @return array<string, mixed> The rejected refund.
	 *
	 * @throws OCSNotFoundException If the refund does not exist.
	 * @throws OCSForbiddenException If the user lacks manager permission.
	 * @throws OCSBadRequestException If the status is invalid or the reason is empty.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.2
	 */
	public function rejectRefund(string $id, string $reason, string $userId): array {
		if (trim($reason) === '') {
			throw new OCSBadRequestException('Vul een reden in voor de afwijzing.');
		}

		$refund = $this->fetchRefund(id: $id);
		$refund['rejectedAt'] = $this->now();
		$refund['rejectionReason'] = trim($reason);
		$this->saveRefund(id: $id, refund: $refund);

		$saved = $this->transitionObject(id: $id, action: 'reject');

		$this->logger->info('Pipelinq: POS refund rejected', ['id' => $id, 'userId' => $userId]);

		return $saved;
	}//end rejectRefund()

	/**
	 * Apply a named lifecycle transition through OpenRegister's TransitionEngine.
	 *
	 * The engine validates the declarative x-openregister-lifecycle table on the
	 * posRefund schema, runs PosRefundManagerGuard (manager-only + over-refund
	 * cap on complete), saves through ObjectService and dispatches
	 * ObjectTransitionedEvent. Denials / invalid transitions are mapped to the
	 * correct OCS exceptions for the controller.
	 *
	 * @param string $id The refund UUID.
	 * @param string $action The transition action ('complete'|'reject').
	 *
	 * @return array<string, mixed> The saved refund as an array.
	 *
	 * @throws OCSForbiddenException When the guard / RBAC denies the transition.
	 * @throws OCSBadRequestException When the transition is invalid / cap exceeded.
	 * @throws OCSNotFoundException When the object cannot be resolved.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.2
	 */
	private function transitionObject(string $id, string $action): array {
		try {
			$saved = $this->getTransitionEngine()->transition(objectId: $id, action: $action);
		} catch (\Throwable $e) {
			throw $this->mapTransitionError(e: $e);
		}

		return $this->toArray(object: $saved);
	}//end transitionObject()

	/**
	 * Map a TransitionEngine throwable to the correct OCS exception.
	 *
	 * Authorization denials → 403; invalid-state / cap / guard denials → 422;
	 * missing object → 404; otherwise the original is rethrown for the
	 * controller's generic 500 handler.
	 *
	 * @param \Throwable $e The engine throwable.
	 *
	 * @return \Throwable The mapped OCS exception (or the original on no match).
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each branch maps one distinct
	 *  engine-error class (RBAC, manager deny, cap/invalid-state, missing object)
	 *  to its HTTP status; collapsing them would lose the status fidelity.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.3
	 */
	private function mapTransitionError(\Throwable $e): \Throwable {
		$short = (new ReflectionClass($e))->getShortName();
		$message = $e->getMessage();

		// Per-object RBAC denial → 403.
		if ($short === 'NotAuthorizedException' || stripos($message, 'permission') !== false) {
			$forbidden = 'Geen toegang tot deze retour.';
			if ($message !== '') {
				$forbidden = $message;
			}

			return new OCSForbiddenException($forbidden);
		}

		// Manager-authorization denial from PosRefundManagerGuard → 403.
		if (stripos($message, 'beheerder mag een retour') !== false) {
			return new OCSForbiddenException($message);
		}

		// Over-refund cap, invalid-state, or other precondition → 422.
		if (stripos($message, 'overschrijdt') !== false
			|| stripos($message, 'not allowed from') !== false
			|| stripos($message, 'not declared') !== false
			|| stripos($message, 'ontbreekt') !== false
			|| stripos($message, 'Originele kassabon niet gevonden') !== false
		) {
			return new OCSBadRequestException($message);
		}

		if (stripos($message, 'not found') !== false) {
			return new OCSNotFoundException($message);
		}

		return $e;
	}//end mapTransitionError()

	/**
	 * Emit the pipelinq.TransactionRefund.completed CloudEvent (fire-and-forget).
	 *
	 * Dispatched through OpenRegister's WebhookService for any subscriber (e.g.
	 * Shillinq's accounting consumer), which posts an offsetting journal entry
	 * from the negative reversal amounts. If OR / WebhookService is unavailable,
	 * or no consumer is configured, this is a silent no-op — completion must never
	 * fail because a downstream subscriber is missing.
	 *
	 * @param array<string, mixed> $refund The completed refund.
	 *
	 * @return string The generated CloudEvents id, or empty string on failure.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	public function emitRefundEvent(array $refund): string {
		$eventId = $this->uuid();
		$payload = $this->buildRefundPayload(eventId: $eventId, refund: $refund);

		try {
			$event = new Event();
			$this->webhookService->dispatchEvent(_event: $event, eventName: self::EVENT_REFUND_COMPLETED, payload: $payload);
			return $eventId;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: POS refund CloudEvent not dispatched (no consumer or OR unavailable)',
				['exception' => $e->getMessage()]
			);
			return '';
		}//end try
	}//end emitRefundEvent()

	/**
	 * Emit one shillinq.StockMovement CloudEvent per restocked refund line.
	 *
	 * Only lines with restock=true contribute a movement; the quantity is a
	 * positive number (the returned units flowing back into inventory). Each
	 * event is fire-and-forget through WebhookService; a missing consumer must
	 * never affect the completed refund.
	 *
	 * @param string $refundId The refund UUID.
	 *
	 * @return int The number of stock movement events emitted.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	public function emitStockMovementEvents(string $refundId): int {
		$refund = $this->fetchRefund(id: $refundId);
		$lines = $this->fetchRefundLines(refundId: $refundId);
		$emitted = 0;
		$original = (string)($refund['originalTransaction'] ?? '');

		foreach ($lines as $line) {
			if ((bool)($line['restock'] ?? true) === false) {
				continue;
			}

			$originalLine = $this->fetchOriginalLineSafe(id: (string)($line['originalLine'] ?? ''));
			$productId = (string)($originalLine['product'] ?? $originalLine['sku'] ?? '');
			$quantity = max(0.0, (float)($line['returnedQuantity'] ?? 0));

			$payload = $this->buildStockMovementPayload(
				refundId: $refundId,
				productId: $productId,
				quantity: $quantity,
				originalTransaction: $original
			);

			try {
				$event = new Event();
				$this->webhookService->dispatchEvent(
					_event: $event,
					eventName: self::EVENT_STOCK_MOVEMENT,
					payload: $payload
				);
				$emitted++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: POS stock movement CloudEvent not dispatched (no consumer or OR unavailable)',
					['exception' => $e->getMessage(), 'refundId' => $refundId]
				);
			}//end try
		}//end foreach

		return $emitted;
	}//end emitStockMovementEvents()

	/**
	 * Build the CloudEvents 1.0 envelope for a completed refund.
	 *
	 * The accounting amounts are emitted as negatives so the consumer posts a
	 * reversal (offsetting) journal entry.
	 *
	 * @param string $eventId The CloudEvents id.
	 * @param array<string, mixed> $refund The completed refund.
	 *
	 * @return array<string, mixed> The CloudEvent payload.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function buildRefundPayload(string $eventId, array $refund): array {
		$confirmedAt = (string)($refund['confirmedAt'] ?? '');
		$time = $this->now();
		if ($confirmedAt !== '') {
			$time = $confirmedAt;
		}

		$refundAmount = (float)($refund['refundAmount'] ?? 0);
		$totalTax = (float)($refund['totalTax'] ?? 0);

		return [
			'specversion' => '1.0',
			'type' => self::EVENT_REFUND_COMPLETED,
			'source' => self::EVENT_SOURCE,
			'id' => $eventId,
			'time' => $time,
			'datacontenttype' => 'application/json',
			'data' => [
				'refundId' => (string)($refund['id'] ?? $refund['uuid'] ?? ''),
				'reference' => (string)($refund['reference'] ?? ''),
				'originalTransactionId' => (string)($refund['originalTransaction'] ?? ''),
				'cashier' => (string)($refund['cashier'] ?? ''),
				'refundReason' => (string)($refund['refundReason'] ?? ''),
				'refundAmount' => $refundAmount,
				'totalTax' => $totalTax,
				// Reversal (negative) amounts for the offsetting journal entry.
				'reversalAmount' => $this->money(value: (-1 * $refundAmount)),
				'reversalTax' => $this->money(value: (-1 * $totalTax)),
				'paymentMethod' => (string)($refund['paymentMethod'] ?? ''),
				'paymentReference' => (string)($refund['paymentReference'] ?? ''),
				'confirmedAt' => $confirmedAt,
			],
		];
	}//end buildRefundPayload()

	/**
	 * Build the CloudEvents 1.0 envelope for a single stock-movement event.
	 *
	 * @param string $refundId The refund UUID.
	 * @param string $productId The product id / SKU from the original line.
	 * @param float $quantity The positive returned quantity.
	 * @param string $originalTransaction The original transaction UUID (for the note).
	 *
	 * @return array<string, mixed> The CloudEvent payload.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function buildStockMovementPayload(
		string $refundId,
		string $productId,
		float $quantity,
		string $originalTransaction,
	): array {
		return [
			'specversion' => '1.0',
			'type' => self::EVENT_STOCK_MOVEMENT,
			'source' => self::EVENT_SOURCE,
			'id' => $this->uuid(),
			'time' => $this->now(),
			'datacontenttype' => 'application/json',
			'data' => [
				'transactionType' => 'refund_return',
				'refundId' => $refundId,
				'productId' => $productId,
				'quantity' => $quantity,
				'unit' => 'piece',
				'notes' => 'Return from transaction ' . $originalTransaction,
			],
		];
	}//end buildStockMovementPayload()

	/**
	 * Fetch a refund from this app's register, as an array.
	 *
	 * @param string $id The refund UUID.
	 *
	 * @return array<string, mixed> The refund data.
	 *
	 * @throws OCSNotFoundException If the refund is not found in this app's posRefund schema.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function fetchRefund(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posRefund_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Retour niet gevonden.');
		}

		return $this->toArray(object: $object);
	}//end fetchRefund()

	/**
	 * Fetch all refund lines belonging to a refund.
	 *
	 * @param string $refundId The parent refund UUID.
	 *
	 * @return array<int, array<string, mixed>> The refund lines.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function fetchRefundLines(string $refundId): array {
		[$register, $schema] = $this->config(schemaKey: 'posRefundLine_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'refund' => $refundId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to fetch refund lines', ['exception' => $e->getMessage()]);
			return [];
		}

		$lines = [];
		foreach (($results ?? []) as $result) {
			$lines[] = $this->toArray(object: $result);
		}

		return $lines;
	}//end fetchRefundLines()

	/**
	 * Fetch the original posTransactionLine referenced by a refund line.
	 *
	 * @param string $id The original line UUID.
	 *
	 * @return array<string, mixed> The original line.
	 *
	 * @throws OCSNotFoundException If the original line is not found in this app's schema.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function fetchOriginalLine(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransactionLine_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Originele regel niet gevonden.');
		}

		return $this->toArray(object: $object);
	}//end fetchOriginalLine()

	/**
	 * Fetch the original line without throwing (used for stock-movement product
	 * resolution where a missing line should not abort event emission).
	 *
	 * @param string $id The original line UUID.
	 *
	 * @return array<string, mixed> The original line, or an empty array.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function fetchOriginalLineSafe(string $id): array {
		try {
			return $this->fetchOriginalLine(id: $id);
		} catch (\Throwable $e) {
			return [];
		}
	}//end fetchOriginalLineSafe()

	/**
	 * Assert the original transaction referenced by a refund exists (orphan guard).
	 *
	 * The cumulative over-refund cap (which needs the original total) is now
	 * enforced inside PosRefundManagerGuard during the `complete` transition;
	 * here the service only verifies the original transaction is present before
	 * recomputing/transitioning, so a refund can never be completed against a
	 * deleted sale (REQ-REF-014).
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException If the transaction is not found.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function fetchTransaction(string $id): void {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Originele kassabon niet gevonden. Kan refund niet verwerken.');
		}
	}//end fetchTransaction()

	/**
	 * Persist a refund object via the OR ObjectService.
	 *
	 * @param string $id The refund UUID.
	 * @param array<string, mixed> $refund The refund data.
	 *
	 * @return array<string, mixed> The saved refund as an array.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function saveRefund(string $id, array $refund): array {
		[$register, $schema] = $this->config(schemaKey: 'posRefund_schema');

		unset($refund['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $refund,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}//end saveRefund()

	/**
	 * Persist a refund line via the OR ObjectService.
	 *
	 * @param string $id The refund line UUID.
	 * @param array<string, mixed> $line The refund line data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function saveRefundLine(string $id, array $line): void {
		if ($id === '') {
			return;
		}

		[$register, $schema] = $this->config(schemaKey: 'posRefundLine_schema');

		unset($line['@self']);

		try {
			$this->getObjectService()->saveObject(
				object: $line,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to persist refund line', ['exception' => $e->getMessage()]);
		}
	}//end saveRefundLine()

	/**
	 * Resolve the register + a schema config key into their stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key (e.g. posRefund_schema).
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException If the register or schema is not configured.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 *
	 * @spec openspec/changes/pos-refund-return/tasks.md#2.1
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Get the OpenRegister lifecycle TransitionEngine.
	 *
	 * @return object The transition engine.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.2
	 */
	private function getTransitionEngine(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->transitionEngine;
	}//end getTransitionEngine()

	/**
	 * Normalise an OR object (entity or array) into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The object as an array.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}//end toArray()

	/**
	 * Current time as an ISO 8601 string.
	 *
	 * @return string The current timestamp.
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()

	/**
	 * Generate a v4 UUID.
	 *
	 * @return string The UUID.
	 */
	private function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end uuid()
}//end class
