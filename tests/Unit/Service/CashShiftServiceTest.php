<?php

/**
 * Unit tests for CashShiftService.
 *
 * Exercises the server-authoritative cash-drawer lifecycle end-to-end against an
 * in-memory fake of OpenRegister's ObjectService and WebhookService, with the
 * REAL PosAccessPolicy (operator / manager predicates) wired through a mocked
 * group manager and app config. The core focus is the money math in
 * calculateDiff (expected = float + confirmed sales - drops; diff and percentage;
 * the ±2% tolerance band; and the division-by-zero guard) plus the
 * approve/reject reconciliation transitions, the CloudEvent emission shape and
 * the manager gate failing closed.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\WebhookService;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\CashShiftService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A fake OpenRegister ObjectService capturing saves and answering finds from an
 * in-memory store keyed by schema id + object id. New objects (empty uuid) get a
 * deterministic generated id so the lifecycle can chain count -> diff -> approve.
 */
class CashFakeObjectService {
	/** @var array<string, array<string, array<string, mixed>>> */
	public array $store = [];

	/** @var int */
	private int $seq = 0;

	/**
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, string $register, string $schema): ?array {
		return $this->store[$schema][$id] ?? null;
	}

	/**
	 * @param array<string, mixed> $config
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config): array {
		$filters = $config['filters'] ?? [];
		$schema = (string)($filters['schema'] ?? '');
		$rows = array_values($this->store[$schema] ?? []);

		return array_values(array_filter($rows, function (array $row) use ($filters): bool {
			foreach (['shift', 'status'] as $key) {
				if (isset($filters[$key]) === true && ($row[$key] ?? null) !== $filters[$key]) {
					return false;
				}
			}

			return true;
		}));
	}

	/**
	 * @param array<string, mixed> $object
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array {
		if ($uuid === '') {
			$this->seq++;
			$uuid = $schema . '-' . $this->seq;
		}

		$object['id'] = $uuid;
		$this->store[$schema][$uuid] = $object;

		return $object;
	}
}

/**
 * A fake WebhookService capturing dispatched CloudEvents.
 */
class CashFakeWebhookService extends WebhookService {
	/** @var array<int, array{eventName: string, payload: array<string, mixed>}> */
	public array $events = [];

	/**
	 * @param array<string, mixed> $payload
	 */
	public function dispatchEvent(object $_event, string $eventName, array $payload): void {
		$this->events[] = ['eventName' => $eventName, 'payload' => $payload];
	}
}

/**
 * Tests for CashShiftService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     The cash lifecycle has many
 *  small, single-purpose behaviours each asserted independently.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the fakes the cash
 *  lifecycle legitimately exercises.
 */
class CashShiftServiceTest extends TestCase {
	private CashShiftService $service;

	private CashFakeObjectService $objects;

	private CashFakeWebhookService $webhooks;

	private IGroupManager $groupManager;

	private IAppConfig $appConfig;

	/**
	 * UIDs that belong to the POS group in a given test.
	 *
	 * @var array<int, string>
	 */
	private array $posGroupMembers = [];

	/**
	 * UIDs that are Nextcloud admins in a given test.
	 *
	 * @var array<int, string>
	 */
	private array $admins = [];

	/**
	 * Build the service with the fakes and the real access policy.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new CashFakeObjectService();
		$this->webhooks = new CashFakeWebhookService();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				if ($key === 'register') {
					return 'reg';
				}

				if ($key === PosAccessPolicy::POS_GROUP_KEY) {
					return $default !== '' ? $default : PosAccessPolicy::POS_GROUP_DEFAULT;
				}

				if ($key === PosAccessPolicy::MANAGER_GROUP_KEY) {
					return $default;
				}

				// Every *_schema key resolves to the key itself as a stable id.
				return $key;
			}
		);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(
			fn (string $uid): bool => in_array($uid, $this->admins, true)
		);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $group): bool => $group === PosAccessPolicy::POS_GROUP_DEFAULT
				&& in_array($uid, $this->posGroupMembers, true)
		);

		$policy = new PosAccessPolicy(
			appConfig: $this->appConfig,
			groupManager: $this->groupManager,
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) {
			if ($id === 'OCA\OpenRegister\Service\ObjectService') {
				return $this->objects;
			}

			if ($id === 'OCA\OpenRegister\Service\WebhookService') {
				return $this->webhooks;
			}

			if ($id === 'OCA\OpenRegister\Service\Aggregation\AggregationRunner') {
				// Aggregate over the live posTransaction store so the pushed-down
				// SUM is computed from the same rows the PHP path used to read.
				return new FakeAggregationRunner(
					array_values($this->objects->store['posTransaction_schema'] ?? [])
				);
			}

			throw new \RuntimeException('unknown service ' . $id);
		});

		$this->service = new CashShiftService($this->appConfig,
			$policy,
			$this->createMock(LoggerInterface::class),
			webhookService: $this->webhooks,
			objectService: $key,
			aggregationRunner: $this->createMock(AggregationRunner::class),
		);
	}//end setUp()

	/**
	 * Make the "boss" uid a Nextcloud admin (both POS-user and manager predicates
	 * then pass for it).
	 *
	 * @return void
	 */
	private function asAdmin(): void {
		$this->admins[] = 'boss';
	}

	/**
	 * Seed a shift and a confirmed-sales transaction inside its window.
	 *
	 * @param float $floatAmount The opening float.
	 * @param float $salesTotal The confirmed sales total in-window.
	 *
	 * @return string The seeded shift id.
	 */
	private function seedShift(float $floatAmount, float $salesTotal): string {
		$this->objects->store['cashShift_schema']['shift-1'] = [
			'id' => 'shift-1',
			'reference' => 'SHIFT-1',
			'drawer' => 'kassa-01',
			'operator' => 'clerk',
			'floatAmount' => $floatAmount,
			'floatAt' => '2026-05-21T06:00:00+02:00',
			'status' => 'open',
		];

		$this->objects->store['posTransaction_schema']['txn-1'] = [
			'id' => 'txn-1',
			'status' => 'confirmed',
			'total' => $salesTotal,
			'confirmedAt' => '2026-05-21T12:00:00+02:00',
		];

		return 'shift-1';
	}

	/**
	 * No drops, expected == actual: diff 0, percentage 0, within tolerance.
	 *
	 * float 100 + sales 500 - drops 0 = 600; count 600.
	 *
	 * @return void
	 */
	public function testCalculateDiffNoDropsExact(): void {
		$this->seedShift(100.00, 500.00);
		$shift = $this->objects->store['cashShift_schema']['shift-1'];
		$shift['closedAt'] = '2026-05-21T22:00:00+02:00';
		$count = ['id' => 'count-1', 'amount' => 600.00, 'countedAt' => '2026-05-21T22:00:00+02:00'];

		$diff = $this->service->calculateDiff($shift, $count);

		$this->assertSame(600.00, $diff['expectedAmount']);
		$this->assertSame(600.00, $diff['actualAmount']);
		$this->assertSame(0.0, $diff['diffAmount']);
		$this->assertSame(0.0, $diff['diffPercentage']);
		$this->assertTrue($diff['withinTolerance']);
		$this->assertSame('pending', $diff['status']);
	}//end testCalculateDiffNoDropsExact()

	/**
	 * With drops, an overage: expected 650, count 660 => +10 (1.54%), within tol.
	 *
	 * float 100 + sales 800 - drops 250 = 650.
	 *
	 * @return void
	 */
	public function testCalculateDiffWithDropsOverage(): void {
		$shiftId = $this->seedShift(100.00, 800.00);
		$this->objects->store['posTransaction_schema']['txn-1']['total'] = 800.00;
		$this->objects->store['cashDrop_schema']['drop-1'] = [
			'id' => 'drop-1',
			'shift' => $shiftId,
			'amount' => 250.00,
		];

		$shift = $this->objects->store['cashShift_schema'][$shiftId];
		$shift['closedAt'] = '2026-05-21T22:00:00+02:00';
		$count = ['id' => 'count-1', 'amount' => 660.00];

		$diff = $this->service->calculateDiff($shift, $count);

		$this->assertSame(650.00, $diff['expectedAmount']);
		$this->assertSame(10.00, $diff['diffAmount']);
		$this->assertSame(1.54, $diff['diffPercentage']);
		$this->assertTrue($diff['withinTolerance']);
	}//end testCalculateDiffWithDropsOverage()

	/**
	 * Shortage within tolerance: expected 100, actual 98.50 => -1.5%, within tol.
	 *
	 * @return void
	 */
	public function testCalculateDiffShortageWithinTolerance(): void {
		$this->seedShift(100.00, 0.00);
		$this->objects->store['posTransaction_schema']['txn-1']['total'] = 0.00;

		$shift = $this->objects->store['cashShift_schema']['shift-1'];
		$shift['closedAt'] = '2026-05-21T22:00:00+02:00';
		$count = ['id' => 'count-1', 'amount' => 98.50];

		$diff = $this->service->calculateDiff($shift, $count);

		$this->assertSame(100.00, $diff['expectedAmount']);
		$this->assertSame(-1.50, $diff['diffAmount']);
		$this->assertSame(-1.50, $diff['diffPercentage']);
		$this->assertTrue($diff['withinTolerance']);
	}//end testCalculateDiffShortageWithinTolerance()

	/**
	 * Overage beyond tolerance: expected 500, actual 515 => 3.0%, outside tol.
	 *
	 * @return void
	 */
	public function testCalculateDiffBeyondTolerance(): void {
		$this->seedShift(500.00, 0.00);
		$this->objects->store['posTransaction_schema']['txn-1']['total'] = 0.00;

		$shift = $this->objects->store['cashShift_schema']['shift-1'];
		$shift['closedAt'] = '2026-05-21T22:00:00+02:00';
		$count = ['id' => 'count-1', 'amount' => 515.00];

		$diff = $this->service->calculateDiff($shift, $count);

		$this->assertSame(500.00, $diff['expectedAmount']);
		$this->assertSame(15.00, $diff['diffAmount']);
		$this->assertSame(3.0, $diff['diffPercentage']);
		$this->assertFalse($diff['withinTolerance']);
	}//end testCalculateDiffBeyondTolerance()

	/**
	 * Division by zero: expected 0 => diffPercentage null, withinTolerance false.
	 *
	 * @return void
	 */
	public function testCalculateDiffDivisionByZero(): void {
		$this->seedShift(0.00, 0.00);
		$this->objects->store['posTransaction_schema']['txn-1']['total'] = 0.00;

		$shift = $this->objects->store['cashShift_schema']['shift-1'];
		$shift['closedAt'] = '2026-05-21T22:00:00+02:00';
		$count = ['id' => 'count-1', 'amount' => 12.00];

		$diff = $this->service->calculateDiff($shift, $count);

		$this->assertSame(0.0, $diff['expectedAmount']);
		$this->assertNull($diff['diffPercentage']);
		$this->assertFalse($diff['withinTolerance']);
	}//end testCalculateDiffDivisionByZero()

	/**
	 * A confirmed transaction OUTSIDE the shift window is excluded from sales.
	 *
	 * @return void
	 */
	public function testSalesOutsideWindowExcluded(): void {
		$this->seedShift(100.00, 500.00);
		// Move the transaction before the shift opened.
		$this->objects->store['posTransaction_schema']['txn-1']['confirmedAt'] = '2026-05-20T12:00:00+02:00';

		$shift = $this->objects->store['cashShift_schema']['shift-1'];
		$shift['closedAt'] = '2026-05-21T22:00:00+02:00';
		$count = ['id' => 'count-1', 'amount' => 100.00];

		$diff = $this->service->calculateDiff($shift, $count);

		// Sales excluded -> expected is just the float.
		$this->assertSame(100.00, $diff['expectedAmount']);
		$this->assertSame(0.0, $diff['diffAmount']);
	}//end testSalesOutsideWindowExcluded()

	/**
	 * A non-confirmed transaction (e.g. parked) is excluded from sales.
	 *
	 * @return void
	 */
	public function testNonConfirmedSalesExcluded(): void {
		$this->seedShift(100.00, 500.00);
		$this->objects->store['posTransaction_schema']['txn-1']['status'] = 'parked';

		$shift = $this->objects->store['cashShift_schema']['shift-1'];
		$shift['closedAt'] = '2026-05-21T22:00:00+02:00';
		$count = ['id' => 'count-1', 'amount' => 100.00];

		$diff = $this->service->calculateDiff($shift, $count);

		$this->assertSame(100.00, $diff['expectedAmount']);
	}//end testNonConfirmedSalesExcluded()

	/**
	 * openShift fails closed for a non-POS user.
	 *
	 * @return void
	 */
	public function testOpenShiftRejectsNonPosUser(): void {
		// 'stranger' is neither an admin nor a POS-group member.
		$this->expectException(OCSForbiddenException::class);
		$this->service->openShift('kassa-01', 100.00, 'stranger');
	}//end testOpenShiftRejectsNonPosUser()

	/**
	 * openShift rejects a negative float.
	 *
	 * @return void
	 */
	public function testOpenShiftRejectsNegativeFloat(): void {
		$this->asAdmin();

		$this->expectException(OCSBadRequestException::class);
		$this->service->openShift('kassa-01', -1.00, 'boss');
	}//end testOpenShiftRejectsNegativeFloat()

	/**
	 * openShift sets server-authoritative fields and status open.
	 *
	 * @return void
	 */
	public function testOpenShiftSetsServerFields(): void {
		$this->asAdmin();

		$shift = $this->service->openShift('kassa-01', 100.00, 'boss');

		$this->assertSame('open', $shift['status']);
		$this->assertSame('boss', $shift['operator']);
		$this->assertSame(100.00, $shift['floatAmount']);
		$this->assertNotEmpty($shift['floatAt']);
	}//end testOpenShiftSetsServerFields()

	/**
	 * recordDrop rejects a non-positive amount.
	 *
	 * @return void
	 */
	public function testRecordDropRejectsNonPositive(): void {
		$this->asAdmin();
		$this->seedShift(100.00, 0.00);

		$this->expectException(OCSBadRequestException::class);
		$this->service->recordDrop('shift-1', 0.0, 'bank-run', 'boss');
	}//end testRecordDropRejectsNonPositive()

	/**
	 * recordCount closes the shift and persists a pending diff.
	 *
	 * @return void
	 */
	public function testRecordCountClosesShiftAndCreatesDiff(): void {
		$this->asAdmin();
		$this->seedShift(100.00, 500.00);

		$result = $this->service->recordCount('shift-1', 600.00, 'boss');

		$this->assertSame('closed', $result['shift']['status']);
		$this->assertSame(600.00, $result['count']['amount']);
		$this->assertSame(0.0, $result['diff']['diffAmount']);
		$this->assertSame('pending', $result['diff']['status']);
	}//end testRecordCountClosesShiftAndCreatesDiff()

	/**
	 * recordCount on a non-open shift is rejected.
	 *
	 * @return void
	 */
	public function testRecordCountRejectsClosedShift(): void {
		$this->asAdmin();
		$this->seedShift(100.00, 0.00);
		$this->objects->store['cashShift_schema']['shift-1']['status'] = 'closed';

		$this->expectException(OCSBadRequestException::class);
		$this->service->recordCount('shift-1', 100.00, 'boss');
	}//end testRecordCountRejectsClosedShift()

	/**
	 * approveDiff reconciles the shift and emits the confirmed CloudEvent.
	 *
	 * @return void
	 */
	public function testApproveDiffReconcilesAndEmitsEvent(): void {
		$this->asAdmin();
		$this->seedShift(100.00, 500.00);

		$result = $this->service->recordCount('shift-1', 615.00, 'boss');
		$diffId = $result['diff']['id'];

		$approved = $this->service->approveDiff($diffId, 'boss');

		$this->assertSame('approved', $approved['status']);
		$this->assertSame('boss', $approved['approvedBy']);
		$this->assertNotEmpty($approved['approvedAt']);

		$shift = $this->objects->store['cashShift_schema']['shift-1'];
		$this->assertSame('reconciled', $shift['status']);
		$this->assertSame('approved', $shift['reconciliationStatus']);

		$events = array_values(array_filter($this->webhooks->events,
			fn (array $e): bool => $e['eventName'] === CashShiftService::EVENT_CASH_DIFF_CONFIRMED
		));
		$this->assertCount(1, $events);
		$data = $events[0]['payload']['data'];
		$this->assertSame('shift-1', $data['shift_id']);
		$this->assertSame('kassa-01', $data['drawer']);
		$this->assertSame(15.00, $data['diff_amount']);
		$this->assertSame('boss', $data['approved_by']);
	}//end testApproveDiffReconcilesAndEmitsEvent()

	/**
	 * approveDiff fails closed for a non-manager.
	 *
	 * @return void
	 */
	public function testApproveDiffManagerGateFailsClosed(): void {
		// POS user but not admin/manager: open + count succeed, approve denied.
		// 'clerk' is in the POS group (so recordCount passes) but not a manager
		// (no manager group configured), so approveDiff must fail closed.
		$this->posGroupMembers[] = 'clerk';
		$this->seedShift(100.00, 500.00);
		$result = $this->service->recordCount('shift-1', 615.00, 'clerk');

		$this->expectException(OCSForbiddenException::class);
		$this->service->approveDiff($result['diff']['id'], 'clerk');
	}//end testApproveDiffManagerGateFailsClosed()

	/**
	 * rejectDiff reopens the shift, requires a reason, and creates a recount task.
	 *
	 * @return void
	 */
	public function testRejectDiffReopensShiftAndCreatesTask(): void {
		$this->asAdmin();
		$this->seedShift(100.00, 500.00);
		$result = $this->service->recordCount('shift-1', 700.00, 'boss');

		$rejected = $this->service->rejectDiff($result['diff']['id'], 'Hertelling vereist', 'boss');

		$this->assertSame('rejected', $rejected['status']);
		$this->assertSame('Hertelling vereist', $rejected['rejectionReason']);

		$shift = $this->objects->store['cashShift_schema']['shift-1'];
		$this->assertSame('open', $shift['status']);

		// A recount task was created for the operator.
		$tasks = $this->objects->store['task_schema'] ?? [];
		$this->assertCount(1, $tasks);
		$task = array_values($tasks)[0];
		$this->assertSame('clerk', $task['assigneeUserId']);
		// Dutch PROSE in a user-facing description, not an enum value — the value
		// pass rewrote this expectation and should not have. Translating the
		// message itself is l10n work, not a value migration.
		$this->assertStringContainsString('afgewezen', $task['description']);
	}//end testRejectDiffReopensShiftAndCreatesTask()

	/**
	 * rejectDiff requires a non-empty reason.
	 *
	 * @return void
	 */
	public function testRejectDiffRequiresReason(): void {
		$this->asAdmin();
		$this->seedShift(100.00, 500.00);
		$result = $this->service->recordCount('shift-1', 700.00, 'boss');

		$this->expectException(OCSBadRequestException::class);
		$this->service->rejectDiff($result['diff']['id'], '   ', 'boss');
	}//end testRejectDiffRequiresReason()

	/**
	 * A second count reuses the pending diff rather than duplicating it.
	 *
	 * @return void
	 */
	public function testRecordCountUpdatesPendingDiffInPlace(): void {
		$this->asAdmin();
		$this->seedShift(100.00, 500.00);

		$this->service->recordCount('shift-1', 600.00, 'boss');
		// Reopen and recount (simulates a rejected-then-recount flow).
		$this->objects->store['cashShift_schema']['shift-1']['status'] = 'open';
		$this->service->recordCount('shift-1', 590.00, 'boss');

		$diffs = $this->objects->store['cashDiff_schema'] ?? [];
		$this->assertCount(1, $diffs, 'pending diff should be updated in place, not duplicated');
		$diff = array_values($diffs)[0];
		$this->assertSame(590.00, $diff['actualAmount']);
	}//end testRecordCountUpdatesPendingDiffInPlace()
}//end class
