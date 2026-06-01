<?php

/**
 * Pipelinq CashShiftService.
 *
 * Business logic for POS cash drawer management: shift lifecycle transitions
 * (open / close / reconcile), mid-shift drop recording, blind count, variance
 * (diff) calculation, and CloudEvent emission on diff approval.
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
 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
 * @spec openspec/changes/pos-cash-management/tasks.md#3.2
 * @spec openspec/changes/pos-cash-management/tasks.md#3.3
 * @spec openspec/changes/pos-cash-management/tasks.md#4.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for POS cash drawer management.
 *
 * Handles all lifecycle transitions for cash shifts: opening a shift with a
 * declared float, recording mid-shift drops, performing a blind physical count
 * at close, computing the variance (diff) between expected and actual cash, and
 * emitting a pipelinq.CashDiff.confirmed CloudEvent to Shillinq on diff
 * approval. No custom database tables are used; all state is persisted via
 * OpenRegister's ObjectService.
 *
 * Diff formula:
 *   expectedAmount = floatAmount + salesTotal − dropsTotal
 *   diffAmount     = actualAmount − expectedAmount
 *   diffPercentage = (diffAmount / expectedAmount) × 100  (null if expected = 0)
 *   withinTolerance = |diffPercentage| ≤ tolerancePercentage (default 2.0)
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators a POS
 *  cash lifecycle service legitimately needs (OR container, app config, access
 *  policy, logger); splitting would add indirection without reducing real coupling.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Aggregates the whole cash drawer
 *  lifecycle (openShift, recordDrop, recordCount, calculateDiff, approveDiff,
 *  rejectDiff, CloudEvent) as many small single-purpose methods; cohesion is
 *  intentional.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Public surface matches the spec
 *  requirements for the lifecycle transitions; every method is unit-tested
 *  individually.
 * @SuppressWarnings(PHPMD.TooManyMethods) Same cohesion rationale: six lifecycle
 *  transitions + diff calculation + event emission + OR persistence helpers are
 *  one cash-management concern kept together intentionally.
 *
 * @spec openspec/changes/pos-cash-management/tasks.md#3.1
 */
class CashShiftService
{
    /**
     * CloudEvent type emitted on diff approval.
     *
     * @var string
     */
    public const EVENT_DIFF_CONFIRMED = 'pipelinq.CashDiff.confirmed';

    /**
     * CloudEvents source identifier for this feature.
     *
     * @var string
     */
    private const EVENT_SOURCE = 'pipelinq/cashShift';

    /**
     * Default variance tolerance percentage (±2%).
     *
     * @var float
     */
    private const DEFAULT_TOLERANCE = 2.0;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container.
     * @param IAppConfig         $appConfig The app config.
     * @param PosAccessPolicy    $policy    The shared POS access policy.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private PosAccessPolicy $policy,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Open a new cash shift with a declared float amount.
     *
     * Creates a cashShift object with status 'open', the declared float, and
     * the current timestamp as floatAt. A shift reference is auto-generated
     * when not supplied.
     *
     * @param string $drawer      The drawer / register identifier.
     * @param string $operator    The Nextcloud user UID opening the shift.
     * @param float  $floatAmount The opening float amount in EUR.
     * @param string $reference   Optional shift reference; generated if empty.
     * @param string $notes       Optional opening notes.
     *
     * @return array<string, mixed> The newly created cashShift object.
     *
     * @throws OCSBadRequestException When floatAmount is negative.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    public function openShift(
        string $drawer,
        string $operator,
        float $floatAmount,
        string $reference='',
        string $notes='',
    ): array {
        if ($floatAmount < 0) {
            throw new OCSBadRequestException('Openingsbedrag mag niet negatief zijn.');
        }

        if ($reference === '') {
            $reference = $this->generateReference();
        }

        [$register, $schema] = $this->config(schemaKey: 'cashShift_schema');

        $shift = [
            'reference'            => $reference,
            'drawer'               => $drawer,
            'operator'             => $operator,
            'currency'             => 'EUR',
            'floatAmount'          => $this->money(value: $floatAmount),
            'floatAt'              => $this->now(),
            'status'               => 'open',
            'reconciliationStatus' => 'pending',
        ];

        if ($notes !== '') {
            $shift['notes'] = $notes;
        }

        $saved = $this->getObjectService()->saveObject(
            object: $shift,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: null
        );

        return $this->toArray(object: $saved);
    }//end openShift()

    /**
     * Record a mid-shift cash drop (cash removed from the drawer).
     *
     * Creates a cashDrop object linked to the shift. Validates that the shift
     * is still open before recording the drop.
     *
     * @param string $shiftId   The UUID of the parent cashShift.
     * @param float  $amount    The drop amount in EUR (must be ≥ 0.01).
     * @param string $droppedBy The Nextcloud user UID performing the drop.
     * @param string $reason    Reason code, e.g. manager-deposit / bank-run.
     *
     * @return array<string, mixed> The newly created cashDrop object.
     *
     * @throws OCSNotFoundException   When the shift is not found.
     * @throws OCSBadRequestException When the shift is not open or amount is invalid.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    public function recordDrop(
        string $shiftId,
        float $amount,
        string $droppedBy,
        string $reason='',
    ): array {
        if ($amount < 0.01) {
            throw new OCSBadRequestException('Afdracht bedrag moet minimaal €0.01 zijn.');
        }

        $shift = $this->fetchShift(id: $shiftId);
        if (($shift['status'] ?? '') !== 'open') {
            throw new OCSBadRequestException('Afdrachtregistratie is alleen mogelijk voor een openstaande shift.');
        }

        [$register, $schema] = $this->config(schemaKey: 'cashDrop_schema');

        $drop = [
            'shift'     => $shiftId,
            'amount'    => $this->money(value: $amount),
            'droppedAt' => $this->now(),
            'droppedBy' => $droppedBy,
        ];

        if ($reason !== '') {
            $drop['reason'] = $reason;
        }

        $saved = $this->getObjectService()->saveObject(
            object: $drop,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: null
        );

        return $this->toArray(object: $saved);
    }//end recordDrop()

    /**
     * Record a blind count at shift close and calculate the variance diff.
     *
     * Creates a cashCount object, transitions the shift to 'closed', and
     * immediately calls calculateDiff() to create the cashDiff record. The
     * count is "blind" — the cashier does not see the expected amount.
     *
     * @param string $shiftId   The UUID of the parent cashShift.
     * @param float  $amount    The total cash counted in EUR (must be ≥ 0).
     * @param string $countedBy The Nextcloud user UID performing the count.
     * @param string $notes     Optional count notes.
     *
     * @return array{count: array<string, mixed>, diff: array<string, mixed>} The count and diff.
     *
     * @throws OCSNotFoundException   When the shift is not found.
     * @throws OCSBadRequestException When the shift is not open or amount is invalid.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    public function recordCount(
        string $shiftId,
        float $amount,
        string $countedBy,
        string $notes='',
    ): array {
        if ($amount < 0) {
            throw new OCSBadRequestException('Geteld bedrag mag niet negatief zijn.');
        }

        $shift = $this->fetchShift(id: $shiftId);
        if (($shift['status'] ?? '') !== 'open') {
            throw new OCSBadRequestException('Kasatelling is alleen mogelijk voor een openstaande shift.');
        }

        [$register, $schema] = $this->config(schemaKey: 'cashCount_schema');

        $countData = [
            'shift'     => $shiftId,
            'amount'    => $this->money(value: $amount),
            'countedAt' => $this->now(),
            'countedBy' => $countedBy,
        ];

        if ($notes !== '') {
            $countData['notes'] = $notes;
        }

        $savedCount = $this->getObjectService()->saveObject(
            object: $countData,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: null
        );

        $count = $this->toArray(object: $savedCount);

        // Transition shift to 'closed'.
        $closedAt       = $this->now();
        $shift['status']   = 'closed';
        $shift['closedAt'] = $closedAt;
        $this->saveShift(id: $shiftId, shift: $shift);

        // Calculate and persist the diff.
        $diff = $this->calculateDiff(shift: $shift, count: $count);

        return ['count' => $count, 'diff' => $diff];
    }//end recordCount()

    /**
     * Calculate the cash variance (diff) for a closed shift.
     *
     * Queries posTransaction objects within the shift window, sums their totals
     * as salesTotal. Queries all cashDrop objects for the shift, sums as
     * dropsTotal. Creates and persists a cashDiff object.
     *
     * @param array<string, mixed> $shift The cashShift data.
     * @param array<string, mixed> $count The cashCount data.
     *
     * @return array<string, mixed> The newly created cashDiff object.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    public function calculateDiff(array $shift, array $count): array
    {
        $shiftId     = (string) ($shift['id'] ?? $shift['uuid'] ?? '');
        $floatAmount = (float) ($shift['floatAmount'] ?? 0);
        $floatAt     = (string) ($shift['floatAt'] ?? '');
        $closedAt    = (string) ($shift['closedAt'] ?? $this->now());
        $actualAmount = (float) ($count['amount'] ?? 0);
        $countId      = (string) ($count['id'] ?? $count['uuid'] ?? '');

        $salesTotal = $this->fetchSalesTotal(
            shiftId: $shiftId,
            floatAt: $floatAt,
            closedAt: $closedAt
        );
        $dropsTotal = $this->fetchDropsTotal(shiftId: $shiftId);

        $expectedAmount = $this->money(value: ($floatAmount + $salesTotal - $dropsTotal));
        $diffAmount     = $this->money(value: ($actualAmount - $expectedAmount));

        $diffPercentage  = null;
        $withinTolerance = false;

        if (abs($expectedAmount) > 0.0) {
            $diffPercentage  = round(($diffAmount / $expectedAmount) * 100, 4);
            $withinTolerance = abs($diffPercentage) <= self::DEFAULT_TOLERANCE;
        }

        [$register, $schema] = $this->config(schemaKey: 'cashDiff_schema');

        $diffData = [
            'shift'               => $shiftId,
            'count'               => $countId,
            'expectedAmount'      => $expectedAmount,
            'actualAmount'        => $this->money(value: $actualAmount),
            'diffAmount'          => $diffAmount,
            'diffPercentage'      => $diffPercentage,
            'tolerancePercentage' => self::DEFAULT_TOLERANCE,
            'withinTolerance'     => $withinTolerance,
            'status'              => 'pending',
        ];

        $saved = $this->getObjectService()->saveObject(
            object: $diffData,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: null
        );

        return $this->toArray(object: $saved);
    }//end calculateDiff()

    /**
     * Approve a cash variance diff (manager action).
     *
     * Sets diff status to 'approved', records the approver and timestamp,
     * transitions the shift to 'reconciled', and emits the
     * pipelinq.CashDiff.confirmed CloudEvent to Shillinq.
     *
     * @param string $diffId   The UUID of the cashDiff to approve.
     * @param string $approver The Nextcloud user UID of the approving manager.
     *
     * @return array<string, mixed> The updated cashDiff object.
     *
     * @throws OCSNotFoundException   When the diff or shift is not found.
     * @throws OCSBadRequestException When the diff is not in 'pending' status.
     * @throws OCSForbiddenException  When the user is not a manager or admin.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.2
     */
    public function approveDiff(string $diffId, string $approver): array
    {
        $this->assertManager(userId: $approver);

        $diff = $this->fetchDiff(id: $diffId);
        if (($diff['status'] ?? '') !== 'pending') {
            throw new OCSBadRequestException('Alleen een openstaand kassaverschil kan worden goedgekeurd.');
        }

        $now = $this->now();

        $diff['status']     = 'approved';
        $diff['approvedBy'] = $approver;
        $diff['approvedAt'] = $now;

        // Emit CloudEvent first so eventId can be stored on the diff.
        $eventId = $this->emitDiffConfirmedEvent(diff: $diff);
        if ($eventId !== '') {
            $diff['cloudEventId'] = $eventId;
        }

        $savedDiff = $this->saveDiff(id: $diffId, diff: $diff);

        // Transition shift to reconciled.
        $shiftId = (string) ($diff['shift'] ?? '');
        if ($shiftId !== '') {
            $shift = $this->fetchShift(id: $shiftId);
            $shift['status']               = 'reconciled';
            $shift['reconciliationStatus'] = 'approved';
            $shift['managedBy']            = $approver;
            $this->saveShift(id: $shiftId, shift: $shift);
        }

        return $savedDiff;
    }//end approveDiff()

    /**
     * Reject a cash variance diff (manager action), reopening the shift.
     *
     * Sets diff status to 'rejected', reverts the shift status from 'closed'
     * back to 'open', and creates a task object asking the cashier to recount.
     *
     * @param string $diffId   The UUID of the cashDiff to reject.
     * @param string $approver The Nextcloud user UID of the rejecting manager.
     * @param string $reason   The rejection reason text.
     *
     * @return array<string, mixed> The updated cashDiff object.
     *
     * @throws OCSNotFoundException   When the diff or shift is not found.
     * @throws OCSBadRequestException When the diff is not in 'pending' status.
     * @throws OCSForbiddenException  When the user is not a manager or admin.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.3
     */
    public function rejectDiff(string $diffId, string $approver, string $reason): array
    {
        $this->assertManager(userId: $approver);

        $diff = $this->fetchDiff(id: $diffId);
        if (($diff['status'] ?? '') !== 'pending') {
            throw new OCSBadRequestException('Alleen een openstaand kassaverschil kan worden afgewezen.');
        }

        $now = $this->now();

        $diff['status']     = 'rejected';
        $diff['approvedBy'] = $approver;
        $diff['approvedAt'] = $now;

        $savedDiff = $this->saveDiff(id: $diffId, diff: $diff);

        // Revert shift to open so a recount can be performed.
        $shiftId = (string) ($diff['shift'] ?? '');
        if ($shiftId !== '') {
            $shift = $this->fetchShift(id: $shiftId);
            $operator                      = (string) ($shift['operator'] ?? '');
            $shift['status']               = 'open';
            $shift['closedAt']             = null;
            $shift['reconciliationStatus'] = 'pending';
            $this->saveShift(id: $shiftId, shift: $shift);

            // Create a recount task for the cashier.
            $this->createRecountTask(
                shiftId: $shiftId,
                operator: $operator,
                reason: $reason
            );
        }

        return $savedDiff;
    }//end rejectDiff()

    /**
     * Emit the pipelinq.CashDiff.confirmed CloudEvent (fire-and-forget).
     *
     * Dispatched through OpenRegister's WebhookService, which delivers to any
     * subscribed webhook (e.g. Shillinq's accounting consumer). If OR /
     * WebhookService is unavailable, or no consumer is configured, this is a
     * silent no-op — approval must never fail because a downstream subscriber
     * is missing.
     *
     * @param array<string, mixed> $diff The approved cashDiff.
     *
     * @return string The generated CloudEvents id, or empty string on failure.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#4.2
     */
    public function emitDiffConfirmedEvent(array $diff): string
    {
        $eventId = $this->uuid();
        $payload = $this->buildDiffConfirmedPayload(eventId: $eventId, diff: $diff);

        try {
            $webhookService = $this->container->get('OCA\OpenRegister\Service\WebhookService');
            $event          = new Event();
            $webhookService->dispatchEvent(_event: $event, eventName: self::EVENT_DIFF_CONFIRMED, payload: $payload);
            $this->logger->info('Pipelinq: CashDiff confirmed CloudEvent dispatched', ['eventId' => $eventId]);
            return $eventId;
        } catch (\Throwable $e) {
            // Fire-and-forget: a missing / failed downstream must not block approval.
            $this->logger->warning(
                'Pipelinq: CashDiff confirmed CloudEvent not dispatched (no consumer or OR unavailable)',
                ['exception' => $e->getMessage()]
            );
            return '';
        }//end try
    }//end emitDiffConfirmedEvent()

    /**
     * Build the CloudEvents 1.0 envelope for a confirmed diff.
     *
     * @param string               $eventId The CloudEvents id.
     * @param array<string, mixed> $diff    The approved cashDiff.
     *
     * @return array<string, mixed> The CloudEvent payload.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#4.2
     */
    private function buildDiffConfirmedPayload(string $eventId, array $diff): array
    {
        $shiftId    = (string) ($diff['shift'] ?? '');
        $approvedAt = (string) ($diff['approvedAt'] ?? $this->now());

        // Try to fetch drawer from the shift for the event subject/data.
        $drawer    = '';
        $reference = '';
        if ($shiftId !== '') {
            try {
                $shift     = $this->fetchShift(id: $shiftId);
                $drawer    = (string) ($shift['drawer'] ?? '');
                $reference = (string) ($shift['reference'] ?? '');
            } catch (\Throwable) {
                // Non-critical: event still emitted without drawer info.
            }
        }

        return [
            'specversion'     => '1.0',
            'type'            => self::EVENT_DIFF_CONFIRMED,
            'source'          => self::EVENT_SOURCE,
            'id'              => $eventId,
            'time'            => $approvedAt,
            'subject'         => $reference,
            'datacontenttype' => 'application/json',
            'data'            => [
                'shift_id'         => $shiftId,
                'drawer'           => $drawer,
                'diff_amount'      => (float) ($diff['diffAmount'] ?? 0),
                'diff_percentage'  => $diff['diffPercentage'],
                'expected_amount'  => (float) ($diff['expectedAmount'] ?? 0),
                'actual_amount'    => (float) ($diff['actualAmount'] ?? 0),
                'approved_by'      => (string) ($diff['approvedBy'] ?? ''),
                'approved_at'      => $approvedAt,
            ],
        ];
    }//end buildDiffConfirmedPayload()

    /**
     * Fetch the total sales amount within a shift's time window.
     *
     * Queries posTransaction objects where confirmedAt is within the shift
     * window and status is confirmed or settled. Returns the sum of totals.
     *
     * @param string $shiftId  The shift UUID (used for fallback lookup).
     * @param string $floatAt  The shift opening timestamp (ISO 8601).
     * @param string $closedAt The shift close timestamp (ISO 8601).
     *
     * @return float The total sales in EUR.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    private function fetchSalesTotal(string $shiftId, string $floatAt, string $closedAt): float
    {
        try {
            [$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');
        } catch (\Throwable) {
            // posTransaction schema not configured — no sales contribution.
            return 0.0;
        }

        $salesTotal = 0.0;

        foreach (['confirmed', 'settled'] as $status) {
            $filters = [
                'register' => $register,
                'schema'   => $schema,
                'status'   => $status,
            ];

            try {
                $results = $this->getObjectService()->findAll(config: ['filters' => $filters]);
            } catch (\Throwable $e) {
                $this->logger->warning('Pipelinq: failed to fetch POS transactions for diff', ['exception' => $e->getMessage()]);
                continue;
            }

            foreach (($results ?? []) as $result) {
                $tx           = $this->toArray(object: $result);
                $confirmedAt  = (string) ($tx['confirmedAt'] ?? '');

                if ($confirmedAt === '') {
                    continue;
                }

                // Only count transactions within the shift window.
                if ($confirmedAt >= $floatAt && $confirmedAt <= $closedAt) {
                    $salesTotal += (float) ($tx['total'] ?? 0);
                }
            }
        }

        return $this->money(value: $salesTotal);
    }//end fetchSalesTotal()

    /**
     * Fetch the total of all mid-shift drops for a given shift.
     *
     * @param string $shiftId The shift UUID.
     *
     * @return float The total drops amount in EUR.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    private function fetchDropsTotal(string $shiftId): float
    {
        try {
            [$register, $schema] = $this->config(schemaKey: 'cashDrop_schema');
        } catch (\Throwable) {
            return 0.0;
        }

        $filters = [
            'register' => $register,
            'schema'   => $schema,
            'shift'    => $shiftId,
        ];

        try {
            $results = $this->getObjectService()->findAll(config: ['filters' => $filters]);
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch cash drops for diff', ['exception' => $e->getMessage()]);
            return 0.0;
        }

        $total = 0.0;
        foreach (($results ?? []) as $result) {
            $drop   = $this->toArray(object: $result);
            $total += (float) ($drop['amount'] ?? 0);
        }

        return $this->money(value: $total);
    }//end fetchDropsTotal()

    /**
     * Create a recount task assigned to the cashier after a rejection.
     *
     * @param string $shiftId  The UUID of the rejected shift.
     * @param string $operator The cashier's Nextcloud user UID.
     * @param string $reason   The rejection reason text.
     *
     * @return void
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.3
     */
    private function createRecountTask(string $shiftId, string $operator, string $reason): void
    {
        try {
            [$register, $schema] = $this->config(schemaKey: 'task_schema');
        } catch (\Throwable) {
            // Task schema not configured — skip task creation.
            return;
        }

        $description = 'Hercount verplicht; vorige telling afgewezen';
        if ($reason !== '') {
            $description .= ' — Reden: '.$reason;
        }

        $task = [
            'type'        => 'opvolgtaak',
            'title'       => 'Kassatelling afgewezen — hercount vereist',
            'description' => $description,
            'assignedTo'  => $operator,
            'relatedType' => 'cashShift',
            'relatedId'   => $shiftId,
            'status'      => 'open',
        ];

        try {
            $this->getObjectService()->saveObject(
                object: $task,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: null
            );
        } catch (\Throwable $e) {
            // Non-critical; log and continue.
            $this->logger->warning('Pipelinq: failed to create recount task', ['exception' => $e->getMessage()]);
        }
    }//end createRecountTask()

    /**
     * Assert that the user is a POS manager or Nextcloud admin.
     *
     * @param string $userId The user UID to check.
     *
     * @return void
     *
     * @throws OCSForbiddenException When the user is not a manager or admin.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#10.1
     */
    private function assertManager(string $userId): void
    {
        if ($userId === '') {
            throw new OCSForbiddenException('Authenticatie vereist.');
        }

        if ($this->policy->isManager(userId: $userId) !== true) {
            throw new OCSForbiddenException('Manager-rechten vereist voor kassalade-goedkeuring.');
        }
    }//end assertManager()

    /**
     * Fetch a cashShift from this app's register, as an array.
     *
     * @param string $id The shift UUID.
     *
     * @return array<string, mixed> The shift data.
     *
     * @throws OCSNotFoundException When the shift is not found.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    public function fetchShift(string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: 'cashShift_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Kassalade-shift niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end fetchShift()

    /**
     * Fetch a cashDiff from this app's register, as an array.
     *
     * @param string $id The diff UUID.
     *
     * @return array<string, mixed> The diff data.
     *
     * @throws OCSNotFoundException When the diff is not found.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.2
     */
    private function fetchDiff(string $id): array
    {
        [$register, $schema] = $this->config(schemaKey: 'cashDiff_schema');

        try {
            $object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
        } catch (\Throwable) {
            $object = null;
        }

        if ($object === null) {
            throw new OCSNotFoundException('Kassaverschil niet gevonden.');
        }

        return $this->toArray(object: $object);
    }//end fetchDiff()

    /**
     * Fetch all cashDrop objects for a shift.
     *
     * @param string $shiftId The shift UUID.
     *
     * @return array<int, array<string, mixed>> The drops.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    public function fetchDropsForShift(string $shiftId): array
    {
        try {
            [$register, $schema] = $this->config(schemaKey: 'cashDrop_schema');
        } catch (\Throwable) {
            return [];
        }

        $filters = [
            'register' => $register,
            'schema'   => $schema,
            'shift'    => $shiftId,
        ];

        try {
            $results = $this->getObjectService()->findAll(config: ['filters' => $filters]);
        } catch (\Throwable $e) {
            $this->logger->warning('Pipelinq: failed to fetch drops for shift', ['exception' => $e->getMessage()]);
            return [];
        }

        $drops = [];
        foreach (($results ?? []) as $result) {
            $drops[] = $this->toArray(object: $result);
        }

        return $drops;
    }//end fetchDropsForShift()

    /**
     * Persist a cashShift object via ObjectService.
     *
     * @param string               $id    The shift UUID.
     * @param array<string, mixed> $shift The shift data.
     *
     * @return array<string, mixed> The saved shift.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    private function saveShift(string $id, array $shift): array
    {
        [$register, $schema] = $this->config(schemaKey: 'cashShift_schema');
        unset($shift['@self']);

        $saved = $this->getObjectService()->saveObject(
            object: $shift,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end saveShift()

    /**
     * Persist a cashDiff object via ObjectService.
     *
     * @param string               $id   The diff UUID.
     * @param array<string, mixed> $diff The diff data.
     *
     * @return array<string, mixed> The saved diff.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.2
     */
    private function saveDiff(string $id, array $diff): array
    {
        [$register, $schema] = $this->config(schemaKey: 'cashDiff_schema');
        unset($diff['@self']);

        $saved = $this->getObjectService()->saveObject(
            object: $diff,
            extend: [],
            register: $register,
            schema: $schema,
            uuid: $id
        );

        return $this->toArray(object: $saved);
    }//end saveDiff()

    /**
     * Resolve the register + a schema config key into their stored IDs.
     *
     * @param string $schemaKey The app-config schema key, e.g. cashShift_schema.
     *
     * @return array{0: string, 1: string} The [register, schema] IDs.
     *
     * @throws OCSNotFoundException When the register or schema is not configured.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    private function config(string $schemaKey): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('Kassalade register of schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws RuntimeException When OpenRegister is not available.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#3.1
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
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

        return (array) $object;
    }//end toArray()

    /**
     * Round a monetary value to 2 decimals.
     *
     * @param float $value The value to round.
     *
     * @return float The value rounded to cents.
     */
    private function money(float $value): float
    {
        return round($value, 2);
    }//end money()

    /**
     * Current time as an ISO 8601 string.
     *
     * @return string The current time.
     */
    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }//end now()

    /**
     * Generate a UUID v4.
     *
     * @return string The UUID.
     */
    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }//end uuid()

    /**
     * Generate a shift reference based on date and random suffix.
     *
     * @return string The generated reference, e.g. SHIFT-2026-0521-01234.
     */
    private function generateReference(): string
    {
        $date   = (new DateTimeImmutable())->format('Y-md');
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        return 'SHIFT-'.$date.'-'.$suffix;
    }//end generateReference()
}//end class
