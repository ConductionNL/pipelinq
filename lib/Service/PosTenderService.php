<?php

/**
 * Pipelinq PosTenderService.
 *
 * Business logic for POS split-tender payments: configurable tender-type
 * registry (Contant / Betaalpas / Cadeaubon / ...), per-tender add / remove /
 * list operations on a posTransaction, server-authoritative tender-sum
 * validation against the transaction total, change calculation for cash
 * overpayment, and CloudEvent emission to Shillinq on settlement so each
 * tender posts to its configured GL account.
 *
 * Monetary invariant: the sum of posTender.amount on a posTransaction MUST
 * equal posTransaction.total before settlement. The service rejects with
 * an InvalidTenderException carrying HTTP 409 on mismatch, so a transaction
 * cannot transition to `settled` until tenders balance. Change for a CASH
 * tender that overpays is recorded on the tender (does not adjust the
 * stored amount).
 *
 * Per ADR-031, lifecycle transitions are owned by PosTransactionService /
 * TransitionEngine — this service is the tender-domain helper, not a
 * lifecycle owner. It is called from PosTenderController for tender CRUD
 * and from PosTransactionService::settleTransaction() for the pre-settle
 * gate + the post-settle CloudEvent emission.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-003
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-004
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-005
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Event\TenderPostedEvent;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Tender-domain service for POS split-tender payments.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The collaborators are the ones a
 *  tender service legitimately needs: OR container (ObjectService + WebhookService),
 *  IAppConfig for schema-key resolution, IEventDispatcher for TenderPostedEvent
 *  emission and logger. Splitting them would scatter one cohesive concern.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface mirrors
 *  REQ-PST-001..006 plus the schema-key lookups exposed for the retry job /
 *  controller. Each method is unit-tested independently.
 * @SuppressWarnings(PHPMD.TooManyMethods)           See TooManyPublicMethods
 *  rationale above; private helpers keep each public method small.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     One cohesive tender-domain
 *  service (REQ-PST-001..006); splitting would fragment a single monetary invariant.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity mirrors the six
 *  REQ-PST requirements; each is independently unit-tested.
 *
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
 */
class PosTenderService {
	/**
	 * Money comparison tolerance: floating-point amounts within this epsilon
	 * are treated as exactly equal. €0.005 = half a cent; below the rounding
	 * resolution of a NL POS line so any real mismatch will be greater.
	 *
	 * @var float
	 */
	public const MONEY_EPSILON = 0.005;

	/**
	 * CloudEvent type emitted per tender on settlement.
	 *
	 * @var string
	 */
	public const EVENT_TENDER_POSTED = 'nl.pipelinq.pos.tender.posted';

	/**
	 * CloudEvent source identifier for tender events.
	 *
	 * @var string
	 */
	public const EVENT_SOURCE = '/apps/pipelinq/pos/tender';

	/**
	 * Max number of GL-post emission attempts before the retry job soft-fails.
	 *
	 * @var int
	 */
	public const MAX_GL_POST_ATTEMPTS = 10;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container (resolves OR services).
	 * @param IAppConfig $appConfig App configuration (schema-key + register lookups).
	 * @param IEventDispatcher $eventDispatcher Event dispatcher for TenderPostedEvent.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IEventDispatcher $eventDispatcher,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	// ---------------------------------------------------------------------
	// Tender-type registry (REQ-PST-001).
	// ---------------------------------------------------------------------

	/**
	 * List all configured tender types.
	 *
	 * @param bool $activeOnly When true, filter to isActive=true.
	 *
	 * @return array<int, array<string, mixed>> The tender types sorted by sortOrder.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) public API consumed positionally
	 *  by PosTenderController and the retry job; changing the signature is out of
	 *  scope for this mechanical pass
	 */
	public function listTenderTypes(bool $activeOnly = false): array {
		[$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [],
					'register' => $register,
					'schema' => $schema,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to list tender types',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$types = [];
		foreach ($this->resultRows(result: $results) as $row) {
			if ($activeOnly === true && ($row['isActive'] ?? true) !== true) {
				continue;
			}

			$types[] = $row;
		}

		usort(
			$types,
			static function (array $a, array $b): int {
				return ((int)($a['sortOrder'] ?? 0)) <=> ((int)($b['sortOrder'] ?? 0));
			}
		);

		return $types;
	}//end listTenderTypes()

	/**
	 * Look up a tender type by its UUID.
	 *
	 * @param string $id The tender type UUID.
	 *
	 * @return array<string, mixed> The tender type.
	 *
	 * @throws TenderTypeNotFoundException When the id does not resolve.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	public function getTenderTypeById(string $id): array {
		if ($id === '') {
			throw new TenderTypeNotFoundException('Tender type not found');
		}

		[$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new TenderTypeNotFoundException('Tender type not found');
		}

		return $this->toArray(object: $object);
	}//end getTenderTypeById()

	/**
	 * Look up a tender type by its machine-readable code (CASH / CARD / VOUCHER / ...).
	 *
	 * @param string $code The tender type code.
	 *
	 * @return array<string, mixed> The tender type.
	 *
	 * @throws TenderTypeNotFoundException When the code does not resolve.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	public function getTenderTypeByCode(string $code): array {
		if ($code === '') {
			throw new TenderTypeNotFoundException('Tender type code is required');
		}

		foreach ($this->listTenderTypes(activeOnly: false) as $type) {
			if ((string)($type['code'] ?? '') === $code) {
				return $type;
			}
		}

		throw new TenderTypeNotFoundException(sprintf('Tender type "%s" not found', $code));
	}//end getTenderTypeByCode()

	/**
	 * Create a new tender type (admin path).
	 *
	 * @param array<string, mixed> $data The tender type payload.
	 *
	 * @return array<string, mixed> The persisted tender type.
	 *
	 * @throws OCSBadRequestException When required fields are missing or the code is not unique.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	public function createTenderType(array $data): array {
		$payload = $this->validateTenderTypePayload(data: $data, allowMissingCode: false);

		// Code uniqueness — controller-level admin path; enforce server-side.
		try {
			$existing = $this->getTenderTypeByCode(code: $payload['code']);
			if ($existing !== []) {
				throw new OCSBadRequestException(sprintf('Tender type code "%s" already exists', $payload['code']));
			}
		} catch (TenderTypeNotFoundException $e) {
			// Expected — code is free.
		}

		return $this->saveTenderType(id: '', payload: $payload);
	}//end createTenderType()

	/**
	 * Update an existing tender type (admin path). The `code` is preserved
	 * (immutable after creation).
	 *
	 * @param string $id The tender type UUID.
	 * @param array<string, mixed> $data The patch payload.
	 *
	 * @return array<string, mixed> The updated tender type.
	 *
	 * @throws TenderTypeNotFoundException When the id does not resolve.
	 * @throws OCSBadRequestException When required fields are missing.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	public function updateTenderType(string $id, array $data): array {
		$current = $this->getTenderTypeById(id: $id);
		$merged = array_merge($current, $data);
		$merged['code'] = (string)($current['code'] ?? '');
		$payload = $this->validateTenderTypePayload(data: $merged, allowMissingCode: true);

		return $this->saveTenderType(id: $id, payload: $payload);
	}//end updateTenderType()

	/**
	 * Delete a tender type (admin path). Rejects when active tenders reference it.
	 *
	 * @param string $id The tender type UUID.
	 *
	 * @return void
	 *
	 * @throws TenderTypeNotFoundException When the id does not resolve.
	 * @throws OCSBadRequestException When active tenders reference this type.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	public function deleteTenderType(string $id): void {
		// Resolve to confirm existence + carry a 404 when missing.
		$this->getTenderTypeById(id: $id);

		$count = $this->countTendersForType(tenderTypeId: $id);
		if ($count > 0) {
			throw new OCSBadRequestException(
				sprintf('Cannot delete tender type with active references (%d active tenders)', $count)
			);
		}

		[$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

		try {
			// The parameter is `$uuid`; `id:` is `Error: Unknown named parameter`.
			$this->getObjectService()->deleteObject(uuid: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to delete tender type',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			throw new OCSBadRequestException('Failed to delete tender type');
		}
	}//end deleteTenderType()

	// ---------------------------------------------------------------------
	// Tender CRUD on a transaction (REQ-PST-002 / REQ-PST-003).
	// ---------------------------------------------------------------------

	/**
	 * List all tenders attached to a transaction, sorted by sortOrder.
	 *
	 * `register` / `schema` MUST sit inside `filters`. OpenRegister's
	 * `ObjectService::prepareFindAllConfig()` resolves the query context from
	 * `$config['filters']['register']` / `['schema']` and from nowhere else,
	 * even though `findAll()`'s own docblock lists them as top-level keys. A
	 * top-level pair leaves `currentRegister` / `currentSchema` untouched, so
	 * `MagicMapper::findAll()` logs a warning and returns `[]` (pipelinq#793).
	 *
	 * That made this one line behave differently per endpoint: reached through
	 * `addTender()` a preceding `saveObject()` had already pinned the posTender
	 * context and the read worked, while `settle` — which is preceded only by a
	 * `find()`, and `find()` restores the context it borrowed — read nothing,
	 * so `assertBalancedForSettle()` saw `tenderSum = 0` and rejected every
	 * non-zero transaction with a 409 underpayment (pipelinq#799).
	 *
	 * @param string $transactionId The posTransaction UUID.
	 *
	 * @return array<int, array<string, mixed>> The tenders.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
	 */
	public function getTendersForTransaction(string $transactionId): array {
		if ($transactionId === '') {
			return [];
		}

		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'transaction' => $transactionId,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to list tenders',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$tenders = [];
		foreach ($this->resultRows(result: $results) as $row) {
			// OR filters can be approximate; double-check the link in PHP.
			if ((string)($row['transaction'] ?? '') !== $transactionId) {
				continue;
			}

			$tenders[] = $row;
		}

		usort(
			$tenders,
			static function (array $a, array $b): int {
				return ((int)($a['sortOrder'] ?? 0)) <=> ((int)($b['sortOrder'] ?? 0));
			}
		);

		return $tenders;
	}//end getTendersForTransaction()

	/**
	 * Add a tender to a transaction.
	 *
	 * Validates: transaction exists; transaction status is NOT `settled`;
	 * tender amount >= 0.01; tenderType id resolves and is active; if the
	 * type requires a reference, `reference` is non-empty. Copies the type's
	 * glAccount onto the tender. Computes change for CASH overpayment.
	 *
	 * @param string $transactionId The posTransaction UUID.
	 * @param array<string, mixed> $payload The tender payload (tenderType, amount, reference?).
	 *
	 * @return array<string, mixed> The created tender.
	 *
	 * @throws OCSNotFoundException When the transaction is not found.
	 * @throws TenderTypeNotFoundException When the tender type is not found.
	 * @throws InvalidTenderException When the tender fails its validation invariant.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-005
	 */
	public function addTender(string $transactionId, array $payload): array {
		$transaction = $this->fetchTransaction(id: $transactionId);
		$status = (string)($transaction['status'] ?? '');
		if ($status === 'settled') {
			throw new InvalidTenderException(
				'Cannot add tenders to a settled transaction',
				statusCode: 409
			);
		}

		$tenderTypeId = (string)($payload['tenderType'] ?? '');
		if ($tenderTypeId === '') {
			throw new InvalidTenderException('Tender type is required', statusCode: 400);
		}

		$type = $this->getTenderTypeById(id: $tenderTypeId);

		if (($type['isActive'] ?? true) !== true) {
			throw new InvalidTenderException(
				sprintf('Tender type "%s" is not active', (string)($type['code'] ?? '')),
				statusCode: 400
			);
		}

		$amount = (float)($payload['amount'] ?? 0);
		if ($amount < 0.01) {
			throw new InvalidTenderException(
				'Tender amount must be greater than €0.01',
				statusCode: 400
			);
		}

		$requiresReference = (bool)($type['requiresReference'] ?? false);
		$reference = trim((string)($payload['reference'] ?? ''));
		if ($requiresReference === true && $reference === '') {
			throw new InvalidTenderException(
				'Reference is required for this tender type',
				statusCode: 400
			);
		}

		// Change calculation for CASH overpayment (REQ-PST-005). Other tenders
		// record change = 0; an overpayment without an allowsChange tender
		// is blocked at settle (validateTenderSum / REQ-PST-004).
		$total = (float)($transaction['total'] ?? 0);
		$change = 0.0;
		if (($type['allowsChange'] ?? false) === true) {
			$change = $this->calculateChange(cashTenderedAmount: $amount, transactionTotal: $total);
		}

		$tender = [
			'transaction' => $transactionId,
			'tenderType' => $tenderTypeId,
			'amount' => round($amount, 2),
			'reference' => $reference,
			'glAccount' => (string)($type['glAccount'] ?? ''),
			'change' => round($change, 2),
			'notes' => (string)($payload['notes'] ?? ''),
			'sortOrder' => (int)($payload['sortOrder'] ?? (count($this->getTendersForTransaction(transactionId: $transactionId)) + 1)),
			'glPosted' => false,
			'glPostAttempts' => 0,
		];

		return $this->saveTender(id: '', payload: $tender);
	}//end addTender()

	/**
	 * Remove a tender from a transaction.
	 *
	 * @param string $transactionId The posTransaction UUID (validates ownership).
	 * @param string $tenderId The posTender UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException When the transaction or tender is not found.
	 * @throws InvalidTenderException When the transaction is already settled.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-003
	 */
	public function removeTender(string $transactionId, string $tenderId): void {
		$transaction = $this->fetchTransaction(id: $transactionId);
		$status = (string)($transaction['status'] ?? '');
		if ($status === 'settled') {
			throw new InvalidTenderException(
				'Cannot remove tenders from a settled transaction',
				statusCode: 409
			);
		}

		$tender = $this->fetchTender(id: $tenderId);
		if ((string)($tender['transaction'] ?? '') !== $transactionId) {
			throw new OCSNotFoundException('Tender not found on this transaction');
		}

		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			// The parameter is `$uuid`; `id:` is `Error: Unknown named parameter`.
			$this->getObjectService()->deleteObject(uuid: $tenderId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to delete tender',
				['tenderId' => $tenderId, 'exception' => $e->getMessage()]
			);
			throw new InvalidTenderException('Failed to remove tender', statusCode: 400);
		}
	}//end removeTender()

	// ---------------------------------------------------------------------
	// Validation + change calculation (REQ-PST-004 / REQ-PST-005).
	// ---------------------------------------------------------------------

	/**
	 * Validate the tender-sum invariant: sum of posTender.amount MUST equal
	 * the transaction total before settlement. Returns the comparison.
	 *
	 * @param string $transactionId The posTransaction UUID.
	 *
	 * @return array{tenderSum: float, transactionTotal: float, variance: float, balanced: bool}
	 *
	 * @throws OCSNotFoundException When the transaction is not found.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-004
	 */
	public function validateTenderSum(string $transactionId): array {
		$transaction = $this->fetchTransaction(id: $transactionId);
		$total = round((float)($transaction['total'] ?? 0), 2);

		$tenders = $this->getTendersForTransaction(transactionId: $transactionId);
		$tenderSum = 0.0;
		foreach ($tenders as $tender) {
			$tenderSum += (float)($tender['amount'] ?? 0);
		}

		$tenderSum = round($tenderSum, 2);
		$variance = round($total - $tenderSum, 2);

		return [
			'tenderSum' => $tenderSum,
			'transactionTotal' => $total,
			'variance' => $variance,
			'balanced' => (abs($variance) <= self::MONEY_EPSILON),
		];
	}//end validateTenderSum()

	/**
	 * Compute the change due on a cash overpayment.
	 *
	 * Returns the positive difference when the cash tender exceeds the total,
	 * or 0 when the tender is exact / under (an underpayment is blocked at
	 * settle, not here). Uses straightforward subtraction — both arguments
	 * are already EUR-rounded by the caller.
	 *
	 * @param float $cashTenderedAmount The CASH tender amount in EUR.
	 * @param float $transactionTotal The transaction total in EUR.
	 *
	 * @return float The change due, in EUR (>= 0).
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-005
	 */
	public function calculateChange(float $cashTenderedAmount, float $transactionTotal): float {
		if ($cashTenderedAmount <= $transactionTotal) {
			return 0.0;
		}

		return round($cashTenderedAmount - $transactionTotal, 2);
	}//end calculateChange()

	/**
	 * Assert the tender-sum invariant: throws if tenderSum !== total, taking
	 * change-eligible tenders into account.
	 *
	 * Settlement rule:
	 *   - tenderSum == total  -> OK
	 *   - tenderSum  < total  -> underpayment, reject (HTTP 409)
	 *   - tenderSum  > total  -> overpayment, accept ONLY when at least one
	 *     tender has change > 0 (i.e. allowsChange + amount > variance).
	 *     Otherwise reject with the "without change tender" message.
	 *
	 * @param string $transactionId The transaction UUID.
	 *
	 * @return void
	 *
	 * @throws OCSNotFoundException When the transaction is not found.
	 * @throws InvalidTenderException When the tender sum does not match the total.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-004
	 */
	public function assertBalancedForSettle(string $transactionId): void {
		$validation = $this->validateTenderSum(transactionId: $transactionId);

		if ($validation['balanced'] === true) {
			return;
		}

		$tenderSum = (float)$validation['tenderSum'];
		$total = (float)$validation['transactionTotal'];
		$variance = (float)$validation['variance'];

		// Underpayment -- variance > 0 (total > sum). Always blocked.
		if ($variance > 0) {
			throw new InvalidTenderException(
				sprintf(
					'Tender sum (€%.2f) does not equal transaction total (€%.2f). Underpayment: €%.2f',
					$tenderSum,
					$total,
					abs($variance)
				),
				statusCode: 409
			);
		}

		// Overpayment -- variance < 0 (sum > total). Accepted only when a
		// CASH (allowsChange) tender records the change so the overpayment is
		// accounted for in the change line of the receipt.
		$changeSum = 0.0;
		foreach ($this->getTendersForTransaction(transactionId: $transactionId) as $tender) {
			$changeSum += (float)($tender['change'] ?? 0);
		}

		$changeSum = round($changeSum, 2);
		$excess = round(abs($variance), 2);

		if ($changeSum + self::MONEY_EPSILON >= $excess) {
			return;
		}

		throw new InvalidTenderException(
			sprintf(
				'Tender sum (€%.2f) exceeds transaction total. Overpayment: €%.2f without change tender',
				$tenderSum,
				$excess
			),
			statusCode: 409
		);
	}//end assertBalancedForSettle()

	// ---------------------------------------------------------------------
	// CloudEvent emission (REQ-PST-006).
	// ---------------------------------------------------------------------

	/**
	 * Emit a TenderPostedEvent for every tender on a settled transaction.
	 *
	 * Called from PosTransactionService::settleTransaction() AFTER the status
	 * transition has completed. Each emission persists the event-id on the
	 * tender (for idempotency) and increments glPostAttempts. Listeners MUST
	 * NOT throw; this method swallows downstream errors and lets the retry
	 * job pick up unposted tenders later.
	 *
	 * @param string $transactionId The settled transaction UUID.
	 *
	 * @return array<int, string> The emitted event ids.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
	 */
	public function emitTendersPosted(string $transactionId): array {
		$transaction = $this->fetchTransactionOrNull(id: $transactionId);
		if ($transaction === null) {
			return [];
		}

		$reference = (string)($transaction['reference'] ?? '');
		$tenders = $this->getTendersForTransaction(transactionId: $transactionId);
		$emitted = [];

		foreach ($tenders as $tender) {
			$tenderId = $this->extractId(entity: $tender);
			if ($tenderId === '') {
				continue;
			}

			$eventId = $this->emitSingleTenderPosted(
				transactionUuid: $transactionId,
				transactionReference: $reference,
				tender: $tender
			);
			if ($eventId !== '') {
				$emitted[] = $eventId;
			}
		}

		return $emitted;
	}//end emitTendersPosted()

	/**
	 * Emit a single TenderPostedEvent for one tender + persist its event-id +
	 * increment glPostAttempts. Used both by the settle path and the retry
	 * job.
	 *
	 * @param string $transactionUuid The transaction UUID.
	 * @param string $transactionReference The human-readable transaction reference.
	 * @param array<string, mixed> $tender The tender object.
	 *
	 * @return string The emitted event id, or empty when the emission was a no-op.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
	 */
	public function emitSingleTenderPosted(string $transactionUuid, string $transactionReference, array $tender): string {
		$tenderId = $this->extractId(entity: $tender);
		if ($tenderId === '') {
			return '';
		}

		$attempts = (int)($tender['glPostAttempts'] ?? 0);
		if ($attempts >= self::MAX_GL_POST_ATTEMPTS) {
			$this->logger->warning(
				'Pipelinq POS tender: max GL-post attempts reached; soft-failing',
				['tenderId' => $tenderId, 'attempts' => $attempts]
			);
			return '';
		}

		// Resolve the tender-type code (for the event payload).
		$tenderTypeCode = '';
		try {
			$type = $this->getTenderTypeById(id: (string)($tender['tenderType'] ?? ''));
			$tenderTypeCode = (string)($type['code'] ?? '');
		} catch (Throwable $e) {
			// Code is best-effort metadata; emit anyway with empty code.
		}

		$eventId = $this->uuid();
		$event = new TenderPostedEvent(
			eventId: $eventId,
			tenderUuid: $tenderId,
			transactionUuid: $transactionUuid,
			transactionReference: $transactionReference,
			tenderTypeCode: $tenderTypeCode,
			amount: (float)($tender['amount'] ?? 0),
			glAccount: (string)($tender['glAccount'] ?? ''),
			emittedAt: $this->nowIso(),
		);

		try {
			$this->eventDispatcher->dispatchTyped(event: $event);
		} catch (Throwable $e) {
			$this->logger->info(
				'Pipelinq POS tender: typed dispatch failed; falling back to webhook',
				['tenderId' => $tenderId, 'exception' => $e->getMessage()]
			);
		}

		// Fire-and-forget to OR WebhookService so external subscribers (Shillinq)
		// get the CloudEvent over their configured webhook URL.
		$this->dispatchWebhook(event: $event);

		// Persist the event-id + attempt counter on the tender (idempotency).
		$patched = $tender;
		unset($patched['@self']);
		$patched['cloudEventId'] = $eventId;
		$patched['glPostAttempts'] = ($attempts + 1);

		try {
			$this->saveTender(id: $tenderId, payload: $patched);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to persist event-id on tender',
				['tenderId' => $tenderId, 'exception' => $e->getMessage()]
			);
		}

		return $eventId;
	}//end emitSingleTenderPosted()

	/**
	 * Mark a tender as confirmed-posted by Shillinq (idempotent).
	 *
	 * @param string $tenderId The tender UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
	 */
	public function markTenderGlPosted(string $tenderId): void {
		if ($tenderId === '') {
			return;
		}

		try {
			$tender = $this->fetchTender(id: $tenderId);
		} catch (Throwable $e) {
			return;
		}

		if (($tender['glPosted'] ?? false) === true) {
			return;
		}

		unset($tender['@self']);
		$tender['glPosted'] = true;
		$this->saveTender(id: $tenderId, payload: $tender);
	}//end markTenderGlPosted()

	/**
	 * List tenders for which the GL CloudEvent has not yet been confirmed
	 * received by Shillinq (glPosted=false, attempts<MAX). The retry job
	 * iterates this list and re-emits.
	 *
	 * @return array<int, array<string, mixed>> The unposted tenders.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-006
	 */
	public function listUnpostedTenders(): array {
		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [],
					'register' => $register,
					'schema' => $schema,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to list unposted tenders',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$unposted = [];
		foreach ($this->resultRows(result: $results) as $row) {
			$posted = (bool)($row['glPosted'] ?? false);
			$attempts = (int)($row['glPostAttempts'] ?? 0);
			if ($posted === false && $attempts < self::MAX_GL_POST_ATTEMPTS && $attempts > 0) {
				$unposted[] = $row;
			}
		}

		return $unposted;
	}//end listUnpostedTenders()

	// ---------------------------------------------------------------------
	// Internals — validation helpers.
	// ---------------------------------------------------------------------

	/**
	 * Validate + normalise a tender-type payload.
	 *
	 * @param array<string, mixed> $data The input payload.
	 * @param bool $allowMissingCode When true, do not require `code` (PUT path).
	 *
	 * @return array<string, mixed> The normalised payload.
	 *
	 * @throws OCSBadRequestException
	 */
	private function validateTenderTypePayload(array $data, bool $allowMissingCode): array {
		$name = trim((string)($data['name'] ?? ''));
		$code = trim((string)($data['code'] ?? ''));
		$glAccount = trim((string)($data['glAccount'] ?? ''));

		if ($name === '') {
			throw new OCSBadRequestException('Name is required');
		}

		if ($allowMissingCode === false && $code === '') {
			throw new OCSBadRequestException('Code is required');
		}

		if ($glAccount === '') {
			throw new OCSBadRequestException('GL account is required');
		}

		$resolvedCode = $code;
		if ($code === '') {
			$resolvedCode = (string)($data['code'] ?? '');
		}

		return [
			'name' => $name,
			'code' => $resolvedCode,
			'description' => (string)($data['description'] ?? ''),
			'glAccount' => $glAccount,
			'requiresReference' => (bool)($data['requiresReference'] ?? false),
			'requiresPin' => (bool)($data['requiresPin'] ?? false),
			'allowsChange' => (bool)($data['allowsChange'] ?? false),
			'isActive' => (bool)($data['isActive'] ?? true),
			'sortOrder' => (int)($data['sortOrder'] ?? 0),
		];
	}//end validateTenderTypePayload()

	/**
	 * Count active tenders that reference a tender type.
	 *
	 * @param string $tenderTypeId The tender type UUID.
	 *
	 * @return int The count.
	 */
	private function countTendersForType(string $tenderTypeId): int {
		if ($tenderTypeId === '') {
			return 0;
		}

		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => ['tenderType' => $tenderTypeId],
					'register' => $register,
					'schema' => $schema,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to count tenders for type',
				['tenderTypeId' => $tenderTypeId, 'exception' => $e->getMessage()]
			);
			return 0;
		}

		$count = 0;
		foreach ($this->resultRows(result: $results) as $row) {
			if ((string)($row['tenderType'] ?? '') === $tenderTypeId) {
				$count++;
			}
		}

		return $count;
	}//end countTendersForType()

	// ---------------------------------------------------------------------
	// Internals — OR ObjectService accessors.
	// ---------------------------------------------------------------------

	/**
	 * Save (create or update) a tender type via OR ObjectService.
	 *
	 * @param string $id The UUID (empty for create).
	 * @param array<string, mixed> $payload The normalised payload.
	 *
	 * @return array<string, mixed> The saved tender type.
	 */
	private function saveTenderType(string $id, array $payload): array {
		[$register, $schema] = $this->config(schemaKey: 'posTenderType_schema');

		try {
			$saved = $this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to save tender type',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			throw new OCSBadRequestException('Failed to save tender type');
		}

		return $this->toArray(object: $saved);
	}//end saveTenderType()

	/**
	 * Save (create or update) a tender via OR ObjectService.
	 *
	 * @param string $id The UUID (empty for create).
	 * @param array<string, mixed> $payload The tender payload.
	 *
	 * @return array<string, mixed> The saved tender.
	 */
	private function saveTender(string $id, array $payload): array {
		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$saved = $this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $id
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq POS tender: failed to save tender',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			throw new InvalidTenderException('Failed to save tender', statusCode: 400);
		}

		return $this->toArray(object: $saved);
	}//end saveTender()

	/**
	 * Fetch one tender by UUID.
	 *
	 * @param string $id The tender UUID.
	 *
	 * @return array<string, mixed> The tender.
	 *
	 * @throws OCSNotFoundException
	 */
	private function fetchTender(string $id): array {
		if ($id === '') {
			throw new OCSNotFoundException('Tender not found');
		}

		[$register, $schema] = $this->config(schemaKey: 'posTender_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('Tender not found');
		}

		return $this->toArray(object: $object);
	}//end fetchTender()

	/**
	 * Fetch one posTransaction by UUID.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return array<string, mixed> The transaction.
	 *
	 * @throws OCSNotFoundException
	 */
	private function fetchTransaction(string $id): array {
		$transaction = $this->fetchTransactionOrNull(id: $id);
		if ($transaction === null) {
			throw new OCSNotFoundException('Transaction not found');
		}

		return $transaction;
	}//end fetchTransaction()

	/**
	 * Fetch one posTransaction by UUID (null on miss instead of throw).
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fetchTransactionOrNull(string $id): ?array {
		if ($id === '') {
			return null;
		}

		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end fetchTransactionOrNull()

	/**
	 * Resolve the register + schema-key into their stored IDs.
	 *
	 * @param string $schemaKey The schema app-config key (e.g. `posTender_schema`).
	 *
	 * @return array{0: string, 1: string} The [register, schema] pair.
	 *
	 * @throws OCSNotFoundException When the register or schema is unconfigured.
	 */
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Tender register of schema is not configured');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Resolve the OR ObjectService.
	 *
	 * @return object The OR ObjectService.
	 *
	 * @throws RuntimeException When OR is not available.
	 */
	private function getObjectService(): object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister is not available');
		}
	}//end getObjectService()

	/**
	 * Best-effort CloudEvent dispatch through OR WebhookService.
	 *
	 * @param TenderPostedEvent $event The event to dispatch.
	 *
	 * @return void
	 */
	private function dispatchWebhook(TenderPostedEvent $event): void {
		try {
			$webhookService = $this->container->get('OCA\\OpenRegister\\Service\\WebhookService');
		} catch (Throwable $e) {
			$this->logger->debug(
				'Pipelinq POS tender: WebhookService unavailable; skipping external dispatch',
				['tenderId' => $event->getTenderUuid()]
			);
			return;
		}

		try {
			$webhookService->dispatchEvent(
				_event: new Event(),
				eventName: self::EVENT_TENDER_POSTED,
				payload: $event->toCloudEvent()
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'Pipelinq POS tender: webhook dispatch failed (no consumer or OR offline)',
				['tenderId' => $event->getTenderUuid()]
			);
		}
	}//end dispatchWebhook()

	/**
	 * Normalise a findAll result envelope into a plain list of rows.
	 *
	 * @param mixed $result The OR result.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function resultRows(mixed $result): array {
		if (is_array($result) === false) {
			return [];
		}

		$rows = $result;
		if (isset($result['results']) === true && is_array($result['results']) === true) {
			$rows = $result['results'];
		}

		$out = [];
		foreach ($rows as $row) {
			$arr = $this->toArray(object: $row);
			if ($arr !== []) {
				$out[] = $arr;
			}
		}

		return $out;
	}//end resultRows()

	/**
	 * Normalise an OR entity/array into a plain array.
	 *
	 * @param mixed $object The OR object.
	 *
	 * @return array<string, mixed> The array.
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}//end toArray()

	/**
	 * Extract the canonical UUID from an OR object envelope.
	 *
	 * @param array<string, mixed> $entity The OR object.
	 *
	 * @return string The UUID, or empty when not present.
	 */
	private function extractId(array $entity): string {
		$self = ($entity['@self'] ?? []);
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		if (isset($entity['id']) === true) {
			return (string)$entity['id'];
		}

		if (isset($entity['uuid']) === true) {
			return (string)$entity['uuid'];
		}

		return '';
	}//end extractId()

	/**
	 * Generate a v4-ish UUID string.
	 *
	 * @return string The UUID.
	 */
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end uuid()

	/**
	 * Now in ISO-8601 UTC.
	 *
	 * @return string The timestamp.
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}//end nowIso()
}//end class
