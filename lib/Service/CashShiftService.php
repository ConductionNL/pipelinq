<?php

/**
 * Pipelinq CashShiftService.
 *
 * Business logic for the POS cash-drawer lifecycle: opening a shift with a
 * declared float, recording mid-shift drops, recording a blind close count,
 * computing the server-authoritative cash variance against the sum of confirmed
 * POS transactions in the shift window, and the manager-gated approve / reject
 * of the variance (with CloudEvent emission to Shillinq on approval).
 *
 * Every monetary figure (expected, actual, diff, percentage, tolerance) is
 * computed server-side from persisted data; the operator / counter / approver
 * identity and all timestamps are taken from the session, never from the client.
 * Manager-only operations fail closed via PosAccessPolicy::isManager.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\WebhookService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;

/**
 * Service for POS cash-drawer (shift / drop / count / diff) operations.
 *
 * The diff is derived, never declared: expectedAmount is recomputed from the
 * shift's declared float, the sum of confirmed/settled posTransaction totals
 * whose confirmedAt falls inside the shift window, and the sum of the shift's
 * drops. A referenced transaction that has been deleted degrades gracefully —
 * it simply does not contribute to the sales total rather than aborting the
 * reconciliation.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the collaborators a
 *  cash lifecycle service legitimately needs (OR container, app config, the
 *  shared POS access policy, optional webhook dispatch, logger); splitting them
 *  would add indirection without reducing real coupling.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole cash lifecycle (open + drop + count + derive-diff + approve/reject +
 *  event emit) as small single-purpose methods; the cohesion is intentional.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The public surface mirrors
 *  the cash lifecycle verbs, each single-purpose and unit-tested individually.
 * @SuppressWarnings(PHPMD.TooManyMethods)           The private helpers (fetch /
 *  save / sum / time / uuid) are deliberately small and single-purpose; merging
 *  them would only obscure the lifecycle they support.
 *
 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
 */
class CashShiftService {
	/**
	 * CloudEvent type emitted when a cash variance is approved.
	 *
	 * @var string
	 */
	public const EVENT_CASH_DIFF_CONFIRMED = 'pipelinq.CashDiff.confirmed';

	/**
	 * CloudEvents source identifier for the cash-shift surface.
	 *
	 * @var string
	 */
	private const EVENT_SOURCE = 'pipelinq/cashShift';

	/**
	 * Default variance tolerance (percentage of the expected amount).
	 *
	 * @var float
	 */
	private const DEFAULT_TOLERANCE_PCT = 2.0;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param PosAccessPolicy $policy The shared POS access policy.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private PosAccessPolicy $policy,
		private LoggerInterface $logger,
		private readonly WebhookService $webhookService,
		private readonly ObjectServiceInterface $objectService,
		private readonly AggregationRunner $aggregationRunner,
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
	 * Open a new cash shift with a declared opening float (POS operator).
	 *
	 * The operator UID and floatAt timestamp are server-set from the session and
	 * the current time; the client only supplies the drawer and the declared
	 * float (which must be non-negative). The new shift starts in status `open`.
	 *
	 * @param string $drawer The drawer / register identifier.
	 * @param float $floatAmount The declared opening float.
	 * @param string $userId The acting operator UID (from the session).
	 * @param string $reference Optional human-readable reference.
	 * @param string $notes Optional opening notes.
	 *
	 * @return array<string, mixed> The created shift.
	 *
	 * @throws OCSForbiddenException If the user is not a POS operator.
	 * @throws OCSBadRequestException If the float is negative.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
	 */
	public function openShift(
		string $drawer,
		float $floatAmount,
		string $userId,
		string $reference = '',
		string $notes = '',
	): array {
		$this->requirePosUser(userId: $userId);

		if ($floatAmount < 0) {
			throw new OCSBadRequestException('Openingsbedrag mag niet negatief zijn.');
		}

		$shift = [
			'reference' => trim($reference),
			'drawer' => trim($drawer),
			'operator' => $userId,
			'currency' => 'EUR',
			'floatAmount' => $this->money(value: $floatAmount),
			'floatAt' => $this->now(),
			'status' => 'open',
			'reconciliationStatus' => 'pending',
			'notes' => trim($notes),
		];

		return $this->saveShift(id: '', shift: $shift);
	}//end openShift()

	/**
	 * Record a mid-shift cash drop (POS operator).
	 *
	 * The drop amount must be positive; the droppedBy UID and droppedAt timestamp
	 * are server-set. The shift must exist and be open. The drop is append-only.
	 *
	 * @param string $shiftId The parent shift UUID.
	 * @param float $amount The drop amount.
	 * @param string $reason The reason code / description.
	 * @param string $userId The acting operator UID (from the session).
	 *
	 * @return array<string, mixed> The created drop.
	 *
	 * @throws OCSForbiddenException If the user is not a POS operator.
	 * @throws OCSNotFoundException If the shift does not exist.
	 * @throws OCSBadRequestException If the shift is not open or the amount is not positive.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
	 */
	public function recordDrop(string $shiftId, float $amount, string $reason, string $userId): array {
		$this->requirePosUser(userId: $userId);

		$shift = $this->fetchShift(id: $shiftId);
		if ((string)($shift['status'] ?? '') !== 'open') {
			throw new OCSBadRequestException('Een drop kan alleen worden vastgelegd op een open shift.');
		}

		if ($amount <= 0) {
			throw new OCSBadRequestException('Het dropbedrag moet groter zijn dan nul.');
		}

		$drop = [
			'shift' => $shiftId,
			'amount' => $this->money(value: $amount),
			'reason' => trim($reason),
			'droppedAt' => $this->now(),
			'droppedBy' => $userId,
		];

		return $this->saveObjectFor(schemaKey: 'cashDrop_schema', id: '', object: $drop);
	}//end recordDrop()

	/**
	 * Record a blind close count, close the shift and compute the variance.
	 *
	 * Creates an append-only cashCount, flips the shift to `closed` with a
	 * server-set closedAt, then derives and persists a pending cashDiff via
	 * {@see self::calculateDiff()}. The counted amount must be non-negative; the
	 * countedBy UID and countedAt timestamp are server-set.
	 *
	 * @param string $shiftId The shift UUID.
	 * @param float $amount The counted cash amount.
	 * @param string $userId The acting counter UID (from the session).
	 * @param string $notes Optional counter notes.
	 *
	 * @return array{count: array<string, mixed>, diff: array<string, mixed>, shift: array<string, mixed>} The result.
	 *
	 * @throws OCSForbiddenException If the user is not a POS operator.
	 * @throws OCSNotFoundException If the shift does not exist.
	 * @throws OCSBadRequestException If the shift is not open or the amount is negative.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
	 */
	public function recordCount(string $shiftId, float $amount, string $userId, string $notes = ''): array {
		$this->requirePosUser(userId: $userId);

		$shift = $this->fetchShift(id: $shiftId);
		if ((string)($shift['status'] ?? '') !== 'open') {
			throw new OCSBadRequestException('Alleen een open shift kan worden afgesloten en geteld.');
		}

		if ($amount < 0) {
			throw new OCSBadRequestException('Het getelde bedrag mag niet negatief zijn.');
		}

		$closedAt = $this->now();
		$count = [
			'shift' => $shiftId,
			'amount' => $this->money(value: $amount),
			'countedAt' => $closedAt,
			'countedBy' => $userId,
			'notes' => trim($notes),
		];
		$count = $this->saveObjectFor(schemaKey: 'cashCount_schema', id: '', object: $count);

		$shift['status'] = 'closed';
		$shift['closedAt'] = $closedAt;
		$shift['reconciliationStatus'] = 'pending';
		$shift = $this->saveShift(id: $shiftId, shift: $shift);

		$diff = $this->calculateDiff(shift: $shift, count: $count);

		return [
			'count' => $count,
			'diff' => $diff,
			'shift' => $shift,
		];
	}//end recordCount()

	/**
	 * Derive and persist the cash variance for a shift's count.
	 *
	 * Server-authoritative: expectedAmount = floatAmount + salesTotal -
	 * dropsTotal, where salesTotal is the sum of confirmed/settled posTransaction
	 * totals whose confirmedAt falls in the shift window, and dropsTotal is the
	 * sum of the shift's drops. diffAmount = actualAmount - expectedAmount.
	 * diffPercentage is null when expectedAmount is 0 (division-by-zero guard),
	 * in which case withinTolerance is false. A pre-existing pending diff for the
	 * same shift is updated in place rather than duplicated.
	 *
	 * @param array<string, mixed> $shift The shift object.
	 * @param array<string, mixed> $count The recorded count object.
	 *
	 * @return array<string, mixed> The persisted cashDiff.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
	 */
	public function calculateDiff(array $shift, array $count): array {
		$shiftId = (string)($shift['id'] ?? $shift['uuid'] ?? '');
		$floatAmount = (float)($shift['floatAmount'] ?? 0);
		$salesTotal = $this->sumConfirmedSales(
			from: (string)($shift['floatAt'] ?? ''),
			to: (string)($shift['closedAt'] ?? $count['countedAt'] ?? '')
		);
		$dropsTotal = $this->sumDrops(shiftId: $shiftId);

		$expected = $this->money(value: ($floatAmount + $salesTotal - $dropsTotal));
		$actual = $this->money(value: (float)($count['amount'] ?? 0));
		$diff = $this->money(value: ($actual - $expected));

		$tolerance = self::DEFAULT_TOLERANCE_PCT;
		$diffPercentage = null;
		$withinTolerance = false;
		if ($expected !== 0.0) {
			$diffPercentage = round((($diff / $expected) * 100), 2);
			$withinTolerance = (abs($diffPercentage) <= $tolerance);
		}

		$diffObject = [
			'shift' => $shiftId,
			'count' => (string)($count['id'] ?? $count['uuid'] ?? ''),
			'expectedAmount' => $expected,
			'actualAmount' => $actual,
			'diffAmount' => $diff,
			'diffPercentage' => $diffPercentage,
			'tolerancePercentage' => $tolerance,
			'withinTolerance' => $withinTolerance,
			'status' => 'pending',
		];

		$existing = $this->findPendingDiff(shiftId: $shiftId);
		$existingId = (string)($existing['id'] ?? $existing['uuid'] ?? '');

		return $this->saveObjectFor(schemaKey: 'cashDiff_schema', id: $existingId, object: $diffObject);
	}//end calculateDiff()

	/**
	 * Approve a pending variance (POS manager only) and emit the CloudEvent.
	 *
	 * Stamps approvedBy / approvedAt, flips the diff to `approved`, marks the
	 * shift `reconciled` / reconciliationStatus `approved`, then emits the
	 * pipelinq.CashDiff.confirmed CloudEvent (fire-and-forget) for Shillinq.
	 *
	 * @param string $diffId The cashDiff UUID.
	 * @param string $userId The acting manager UID (from the session).
	 *
	 * @return array<string, mixed> The approved diff.
	 *
	 * @throws OCSForbiddenException If the user is not a POS manager.
	 * @throws OCSNotFoundException If the diff or its shift does not exist.
	 * @throws OCSBadRequestException If the diff is not pending.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.2
	 */
	public function approveDiff(string $diffId, string $userId): array {
		$this->requireManager(userId: $userId);

		$diff = $this->fetchDiff(id: $diffId);
		if ((string)($diff['status'] ?? '') !== 'pending') {
			throw new OCSBadRequestException('Alleen een openstaand kasverschil kan worden goedgekeurd.');
		}

		$shift = $this->fetchShift(id: (string)($diff['shift'] ?? ''));

		$approvedAt = $this->now();
		$diff['status'] = 'approved';
		$diff['approvedBy'] = $userId;
		$diff['approvedAt'] = $approvedAt;
		$diff = $this->saveObjectFor(schemaKey: 'cashDiff_schema', id: $diffId, object: $diff);

		$shift['status'] = 'reconciled';
		$shift['reconciliationStatus'] = 'approved';
		$shift['managedBy'] = $userId;
		$shift = $this->saveShift(id: (string)($shift['id'] ?? $shift['uuid'] ?? ''), shift: $shift);

		$eventId = $this->emitDiffConfirmedEvent(shift: $shift, diff: $diff, userId: $userId, approvedAt: $approvedAt);
		if ($eventId !== '') {
			$diff['cloudEventId'] = $eventId;
			$diff = $this->saveObjectFor(schemaKey: 'cashDiff_schema', id: $diffId, object: $diff);
		}

		$this->logger->info('Pipelinq: cash diff approved', ['id' => $diffId, 'userId' => $userId]);

		return $diff;
	}//end approveDiff()

	/**
	 * Reject a pending variance (POS manager only) and reopen the shift.
	 *
	 * Requires a non-empty reason. Stamps approvedBy / approvedAt /
	 * rejectionReason, flips the diff to `rejected`, reverts the shift to `open`
	 * (status only; the rejected count is retained for the recount history) and
	 * creates an opvolgtaak for the operator to recount.
	 *
	 * @param string $diffId The cashDiff UUID.
	 * @param string $reason The rejection reason (required).
	 * @param string $userId The acting manager UID (from the session).
	 *
	 * @return array<string, mixed> The rejected diff.
	 *
	 * @throws OCSForbiddenException If the user is not a POS manager.
	 * @throws OCSNotFoundException If the diff or its shift does not exist.
	 * @throws OCSBadRequestException If the diff is not pending or the reason is empty.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.3
	 */
	public function rejectDiff(string $diffId, string $reason, string $userId): array {
		$this->requireManager(userId: $userId);

		if (trim($reason) === '') {
			throw new OCSBadRequestException('Vul een reden in voor de afwijzing.');
		}

		$diff = $this->fetchDiff(id: $diffId);
		if ((string)($diff['status'] ?? '') !== 'pending') {
			throw new OCSBadRequestException('Alleen een openstaand kasverschil kan worden afgewezen.');
		}

		$shift = $this->fetchShift(id: (string)($diff['shift'] ?? ''));

		$diff['status'] = 'rejected';
		$diff['approvedBy'] = $userId;
		$diff['approvedAt'] = $this->now();
		$diff['rejectionReason'] = trim($reason);
		$diff = $this->saveObjectFor(schemaKey: 'cashDiff_schema', id: $diffId, object: $diff);

		$shift['status'] = 'open';
		$shift['reconciliationStatus'] = 'rejected';
		$shift = $this->saveShift(id: (string)($shift['id'] ?? $shift['uuid'] ?? ''), shift: $shift);

		$this->createRecountTask(shift: $shift, reason: trim($reason), userId: $userId);

		$this->logger->info('Pipelinq: cash diff rejected', ['id' => $diffId, 'userId' => $userId]);

		return $diff;
	}//end rejectDiff()

	/**
	 * Sum the totals of confirmed/settled POS transactions inside a time window.
	 *
	 * Queries posTransaction objects and sums `total` for those with status
	 * confirmed or settled whose confirmedAt falls in [from, to]. A transaction
	 * that cannot be read (e.g. deleted) simply does not contribute — the sum
	 * degrades gracefully rather than failing. When OR is unavailable the sales
	 * total is 0 and the variance still computes from float and drops.
	 *
	 * @param string $from The window start (ISO 8601), inclusive.
	 * @param string $to The window end (ISO 8601), inclusive.
	 *
	 * @return float The sum of in-window confirmed/settled transaction totals.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is the
	 *  OpenRegister value-object factory; it carries no state to inject.
	 */
	private function sumConfirmedSales(string $from, string $to): float {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		// Push the windowed SUM(total) into OpenRegister: SUM over `total` where
		// status IN (confirmed, settled) AND confirmedAt in [from, to]. The prior
		// PHP path bounded the window via toTimestamp() (strtotime → integer
		// comparison); OpenRegister's native date-range path resolves the same
		// ISO-8601 bounds identically (verified live, boundary windows included),
		// so the total is preserved while the per-row hydrate is eliminated.
		// Degrades to 0.0 when OpenRegister is unavailable, mirroring the prior
		// findAll-failure path.
		try {
			$query = AggregationQuery::create(
				metric: 'sum',
				field: 'total',
				filter: [
					'status' => ['in' => ['confirmed', 'settled']],
					'confirmedAt' => ['gte' => $from, 'lte' => $to],
				],
			);
			$result = $this->getAggregationRunner()->runAdhocByRef(
				registerRef: $register,
				schemaRef: $schema,
				query: $query
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to read POS transactions for cash diff; sales total assumed 0',
				['exception' => $e->getMessage()]
			);
			return 0.0;
		}//end try

		// Empty result set yields null from the runner; treat as 0.0.
		$sum = (float)($result['value'] ?? 0.0);

		return $this->money(value: $sum);
	}//end sumConfirmedSales()

	/**
	 * Sum the amounts of all drops recorded against a shift.
	 *
	 * @param string $shiftId The shift UUID.
	 *
	 * @return float The drops total.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
	 */
	private function sumDrops(string $shiftId): float {
		$sum = 0.0;
		foreach ($this->fetchByShift(schemaKey: 'cashDrop_schema', shiftId: $shiftId) as $drop) {
			$sum += (float)($drop['amount'] ?? 0);
		}

		return $this->money(value: $sum);
	}//end sumDrops()

	/**
	 * Find the most recent pending diff for a shift (for in-place update).
	 *
	 * @param string $shiftId The shift UUID.
	 *
	 * @return array<string, mixed> The pending diff, or an empty array.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
	 */
	private function findPendingDiff(string $shiftId): array {
		foreach ($this->fetchByShift(schemaKey: 'cashDiff_schema', shiftId: $shiftId) as $diff) {
			if ((string)($diff['status'] ?? '') === 'pending') {
				return $diff;
			}
		}

		return [];
	}//end findPendingDiff()

	/**
	 * Create an opvolgtaak for the operator to recount after a rejection.
	 *
	 * Best-effort: a failure to create the task is logged but never aborts the
	 * rejection itself.
	 *
	 * @param array<string, mixed> $shift The reopened shift.
	 * @param string $reason The rejection reason.
	 * @param string $userId The rejecting manager UID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#3.3
	 */
	private function createRecountTask(array $shift, string $reason, string $userId): void {
		$operator = (string)($shift['operator'] ?? '');
		$reference = (string)($shift['reference'] ?? ($shift['id'] ?? ''));

		$task = [
			'type' => 'followUpTask',
			'subject' => 'Hercount verplicht voor shift ' . $reference,
			'description' => 'Hercount verplicht; vorige telling afgewezen — Reden: ' . $reason,
			'status' => 'open',
			'assigneeUserId' => $operator,
			'createdBy' => $userId,
		];

		try {
			$this->saveObjectFor(schemaKey: 'task_schema', id: '', object: $task);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to create recount task after cash diff rejection',
				['exception' => $e->getMessage()]
			);
		}
	}//end createRecountTask()

	/**
	 * Emit the pipelinq.CashDiff.confirmed CloudEvent (fire-and-forget).
	 *
	 * Dispatched through OpenRegister's WebhookService for any subscriber (e.g.
	 * Shillinq's accounting consumer), which posts the variance as a GL
	 * adjustment. A missing consumer or unavailable OR is a silent no-op —
	 * approval must never fail because a downstream subscriber is absent.
	 *
	 * @param array<string, mixed> $shift The reconciled shift.
	 * @param array<string, mixed> $diff The approved diff.
	 * @param string $userId The approving manager UID.
	 * @param string $approvedAt The approval timestamp.
	 *
	 * @return string The generated CloudEvents id, or empty string on failure.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#4.2
	 */
	public function emitDiffConfirmedEvent(array $shift, array $diff, string $userId, string $approvedAt): string {
		$eventId = $this->uuid();
		$payload = [
			'specversion' => '1.0',
			'type' => self::EVENT_CASH_DIFF_CONFIRMED,
			'source' => self::EVENT_SOURCE,
			'id' => $eventId,
			'time' => $approvedAt,
			'subject' => (string)($shift['reference'] ?? ($shift['id'] ?? '')),
			'datacontenttype' => 'application/json',
			'data' => [
				'shift_id' => (string)($shift['id'] ?? $shift['uuid'] ?? ''),
				'drawer' => (string)($shift['drawer'] ?? ''),
				'diff_amount' => (float)($diff['diffAmount'] ?? 0),
				'diff_percentage' => $diff['diffPercentage'] ?? null,
				'expected_amount' => (float)($diff['expectedAmount'] ?? 0),
				'actual_amount' => (float)($diff['actualAmount'] ?? 0),
				'approved_by' => $userId,
				'approved_at' => $approvedAt,
			],
		];

		try {
			$event = new Event();
			$this->webhookService->dispatchEvent(_event: $event, eventName: self::EVENT_CASH_DIFF_CONFIRMED, payload: $payload);
			return $eventId;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: cash diff CloudEvent not dispatched (no consumer or OR unavailable)',
				['exception' => $e->getMessage()]
			);
			return '';
		}//end try
	}//end emitDiffConfirmedEvent()

	/**
	 * Assert the user is a POS operator (fail closed).
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException If the user is not a POS operator.
	 */
	private function requirePosUser(string $userId): void {
		if ($this->policy->isPosUser(userId: $userId) === false) {
			throw new OCSForbiddenException('Alleen kassamedewerkers mogen kassalade-acties uitvoeren.');
		}
	}//end requirePosUser()

	/**
	 * Assert the user is a POS manager (fail closed).
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException If the user is not a POS manager.
	 */
	private function requireManager(string $userId): void {
		if ($this->policy->isManager(userId: $userId) === false) {
			throw new OCSForbiddenException('Alleen een beheerder mag een kasverschil goedkeuren of afwijzen.');
		}
	}//end requireManager()

	/**
	 * Fetch a shift, scoped to this app's cashShift schema.
	 *
	 * @param string $id The shift UUID.
	 *
	 * @return array<string, mixed> The shift.
	 *
	 * @throws OCSNotFoundException If the shift is not found.
	 */
	private function fetchShift(string $id): array {
		return $this->fetchOne(schemaKey: 'cashShift_schema', id: $id, label: 'Shift niet gevonden.');
	}//end fetchShift()

	/**
	 * Fetch a diff, scoped to this app's cashDiff schema.
	 *
	 * @param string $id The diff UUID.
	 *
	 * @return array<string, mixed> The diff.
	 *
	 * @throws OCSNotFoundException If the diff is not found.
	 */
	private function fetchDiff(string $id): array {
		return $this->fetchOne(schemaKey: 'cashDiff_schema', id: $id, label: 'Kasverschil niet gevonden.');
	}//end fetchDiff()

	/**
	 * Fetch a single object by UUID for a schema config key.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param string $id The object UUID.
	 * @param string $label The not-found error message.
	 *
	 * @return array<string, mixed> The object.
	 *
	 * @throws OCSNotFoundException If the object is not found.
	 */
	private function fetchOne(string $schemaKey, string $id, string $label): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException($label);
		}

		return $this->toArray(object: $object);
	}//end fetchOne()

	/**
	 * Fetch all objects of a schema linked to a shift.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param string $shiftId The parent shift UUID.
	 *
	 * @return array<int, array<string, mixed>> The linked objects.
	 */
	private function fetchByShift(string $schemaKey, string $shiftId): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'shift' => $shiftId,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to fetch cash objects for shift',
				['exception' => $e->getMessage(), 'schemaKey' => $schemaKey]
			);
			return [];
		}

		$rows = [];
		foreach (($results ?? []) as $result) {
			$row = $this->toArray(object: $result);
			// Defensive: the filter may be ignored by a backend that doesn't
			// facet `shift`; keep only the rows that actually belong to it.
			if ((string)($row['shift'] ?? '') === $shiftId) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end fetchByShift()

	/**
	 * Persist a shift via the OR ObjectService.
	 *
	 * @param string $id The shift UUID (empty to create).
	 * @param array<string, mixed> $shift The shift data.
	 *
	 * @return array<string, mixed> The saved shift.
	 */
	private function saveShift(string $id, array $shift): array {
		return $this->saveObjectFor(schemaKey: 'cashShift_schema', id: $id, object: $shift);
	}//end saveShift()

	/**
	 * Persist an object via the OR ObjectService for a schema config key.
	 *
	 * @param string $schemaKey The app-config schema key.
	 * @param string $id The object UUID (empty to create).
	 * @param array<string, mixed> $object The object data.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	private function saveObjectFor(string $schemaKey, string $id, array $object): array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);

		unset($object['@self']);

		$saved = $this->getObjectService()->saveObject(
			object: $object,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $id
		);

		return $this->toArray(object: $saved);
	}//end saveObjectFor()

	/**
	 * Resolve the register + a schema config key into their stored IDs.
	 *
	 * @param string $schemaKey The app-config schema key (e.g. cashShift_schema).
	 *
	 * @return array{0: string, 1: string} The [register, schema] IDs.
	 *
	 * @throws OCSNotFoundException If the register or schema is not configured.
	 */
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('Kassalade-register of -schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}//end config()

	/**
	 * Get the OpenRegister ObjectService.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Get the OpenRegister ad-hoc AggregationRunner.
	 *
	 * The runner is constructor-injected, so reporting paths can push
	 * SUM/COUNT/group work down into OpenRegister (ADR-022) instead of hydrating
	 * and reducing in PHP. It used to be resolved from the DI container inside a
	 * try/catch; since the migration to injection the catch was unreachable —
	 * phpstan: "Dead catch - Throwable is never thrown in the try block".
	 * OpenRegister-absence is now a construction-time failure.
	 *
	 * @return object The aggregation runner.
	 */
	private function getAggregationRunner(): object {
		return $this->aggregationRunner;
	}//end getAggregationRunner()

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
