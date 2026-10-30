<?php

/**
 * Unit tests for ClientRiskService: every signal, every cap, every level
 * boundary, and the write that happens only when something a reader sees
 * changed.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\ClientRiskScore;
use OCA\Pipelinq\Service\ClientRiskService;
use OCA\Pipelinq\Service\Customer360SummaryService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the churn risk score.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ClientRiskServiceTest extends TestCase {

	/**
	 * The fixed evaluation instant.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * The weights override in app config, as JSON.
	 *
	 * @var string
	 */
	private string $weightsJson = '';

	/**
	 * Rows findAll() serves, keyed by schema id.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The client find() serves.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $client = null;

	/**
	 * Every patchObject() call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $patched = [];

	/**
	 * The 360 summary the summary service mock returns.
	 *
	 * @var array<string, mixed>
	 */
	private array $summary = [];

	/**
	 * Whether runAsSystem() was entered.
	 *
	 * @var bool
	 */
	private bool $systemScope = false;

	/**
	 * Set the clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->now = new DateTimeImmutable('2026-10-30T12:00:00+00:00');
	}//end setUp()

	/**
	 * Build the service over doubles.
	 *
	 * @return ClientRiskService
	 */
	private function service(): ClientRiskService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'reg',
				'client_schema' => 'sch-client',
				'contract_schema' => 'sch-contract',
				'surveyResponse_schema' => 'sch-response',
				ClientRiskService::WEIGHTS_KEY => $this->weightsJson,
				default => $default,
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('runAsSystem')->willReturnCallback(
			function (callable $operation) {
				$this->systemScope = true;
				return $operation();
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) {
				$filters = $config['filters'];
				$rows = $this->rows[$filters['schema']] ?? [];
				unset($filters['register'], $filters['schema']);

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$objectService->method('find')->willReturnCallback(
			function () {
				if ($this->client === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('client-1');
				$entity->setObject($this->client);
				return $entity;
			}
		);
		$objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data) {
				$this->patched[] = ['id' => $objectId, 'data' => $data];
				return new ObjectEntity();
			}
		);

		$summaryService = $this->createMock(Customer360SummaryService::class);
		$summaryService->method('getSummary')->willReturnCallback(fn (): array => $this->summary);

		return new ClientRiskService(
			$objectService,
			$summaryService,
			new ClientRiskScore(),
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Facts with nothing wrong: recent contact, nothing open.
	 *
	 * @param array<string, mixed> $overrides Facts to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function facts(array $overrides = []): array {
		return array_merge(
			[
				'contracts' => [],
				'detractors' => [],
				'openComplaints' => 0,
				'slaBreached' => 0,
				'lastActivityAt' => '2026-10-23T09:00:00+00:00',
				'createdAt' => '2025-01-01T00:00:00+00:00',
			],
			$overrides
		);
	}//end facts()

	/**
	 * The signals of an assessment.
	 *
	 * @param array<string, mixed> $assessed The assessment.
	 *
	 * @return list<string>
	 */
	private static function signals(array $assessed): array {
		return array_column($assessed['reasons'], 'signal');
	}//end signals()

	/**
	 * A quiet, happy client is low with no reasons.
	 *
	 * @return void
	 */
	public function testQuietClientIsLow(): void {
		$assessed = $this->service()->evaluate($this->facts(), $this->now);

		$this->assertSame('low', $assessed['level']);
		$this->assertSame(0, $assessed['score']);
		$this->assertSame([], $assessed['reasons']);
		$this->assertSame('2026-10-30T12:00:00+00:00', $assessed['computedAt']);
	}//end testQuietClientIsLow()

	/**
	 * An expiring contract adds 30 and names the contract: medium.
	 *
	 * @return void
	 */
	public function testExpiringContractIsMediumAndNamesTheContract(): void {
		$assessed = $this->service()->evaluate(
			$this->facts(['contracts' => [['id' => 'c-1', 'status' => 'expiring', 'endDate' => '2026-12-31']]]),
			$this->now
		);

		$this->assertSame(30, $assessed['score']);
		$this->assertSame('medium', $assessed['level']);
		$this->assertSame(['signal' => 'contract-expiring', 'points' => 30, 'objectType' => 'salesContract', 'objectId' => 'c-1', 'since' => '2026-12-31'], $assessed['reasons'][0]);
	}//end testExpiringContractIsMediumAndNamesTheContract()

	/**
	 * A contract churned inside 90 days adds 40; one churned earlier adds nothing.
	 *
	 * @return void
	 */
	public function testChurnedContractCountsOnlyInsideTheWindow(): void {
		$recent = $this->service()->evaluate(
			$this->facts(['contracts' => [['id' => 'c-1', 'status' => 'churned', 'endDate' => '2026-10-29']]]),
			$this->now
		);
		$old = $this->service()->evaluate(
			$this->facts(['contracts' => [['id' => 'c-2', 'status' => 'churned', 'endDate' => '2026-07-01']]]),
			$this->now
		);

		$this->assertSame(40, $recent['score']);
		$this->assertSame(['contract-churned'], self::signals($recent));
		$this->assertSame('c-1', $recent['reasons'][0]['objectId']);
		$this->assertSame(0, $old['score']);
	}//end testChurnedContractCountsOnlyInsideTheWindow()

	/**
	 * An active or renewed contract is no signal.
	 *
	 * @return void
	 */
	public function testActiveContractIsNoSignal(): void {
		$assessed = $this->service()->evaluate(
			$this->facts(['contracts' => [['id' => 'c-1', 'status' => 'active'], ['id' => 'c-2', 'status' => 'renewed']]]),
			$this->now
		);

		$this->assertSame(0, $assessed['score']);
	}//end testActiveContractIsNoSignal()

	/**
	 * Detractor answers add 25 each inside 180 days, capped at 50, newest first.
	 *
	 * @return void
	 */
	public function testDetractorsAreCappedAndWindowed(): void {
		$assessed = $this->service()->evaluate(
			$this->facts(
				[
					'detractors' => [
						['id' => 'r-1', 'submittedAt' => '2026-09-01T10:00:00+00:00'],
						['id' => 'r-2', 'submittedAt' => '2026-10-20T10:00:00+00:00'],
						['id' => 'r-3', 'submittedAt' => '2026-08-01T10:00:00+00:00'],
						['id' => 'r-old', 'submittedAt' => '2026-01-01T10:00:00+00:00'],
					],
				]
			),
			$this->now
		);

		$this->assertSame(50, $assessed['score']);
		$this->assertSame(['r-2', 'r-1'], array_column($assessed['reasons'], 'objectId'));
	}//end testDetractorsAreCappedAndWindowed()

	/**
	 * Open complaints add 10 each, capped at 30, with their count.
	 *
	 * @return void
	 */
	public function testComplaintsAreCapped(): void {
		$two = $this->service()->evaluate($this->facts(['openComplaints' => 2]), $this->now);
		$five = $this->service()->evaluate($this->facts(['openComplaints' => 5]), $this->now);

		$this->assertSame(20, $two['score']);
		$this->assertSame(2, $two['reasons'][0]['count']);
		$this->assertSame(30, $five['score']);
		$this->assertSame(5, $five['reasons'][0]['count']);
	}//end testComplaintsAreCapped()

	/**
	 * A breached deadline adds 15 once, however many there are.
	 *
	 * @return void
	 */
	public function testBreachedSlaAddsOnce(): void {
		$assessed = $this->service()->evaluate($this->facts(['slaBreached' => 3]), $this->now);

		$this->assertSame(15, $assessed['score']);
		$this->assertSame(['sla-breached'], self::signals($assessed));
	}//end testBreachedSlaAddsOnce()

	/**
	 * No activity for more than 90 days adds 20; a new client without
	 * activity is measured from when it was created.
	 *
	 * @return void
	 */
	public function testSilenceUsesLastActivityThenCreation(): void {
		$silent = $this->service()->evaluate($this->facts(['lastActivityAt' => '2026-06-01T00:00:00+00:00']), $this->now);
		$newClient = $this->service()->evaluate($this->facts(['lastActivityAt' => null, 'createdAt' => '2026-10-01T00:00:00+00:00']), $this->now);
		$oldNeverContacted = $this->service()->evaluate($this->facts(['lastActivityAt' => null, 'createdAt' => '2025-01-01T00:00:00+00:00']), $this->now);

		$this->assertSame(['silence'], self::signals($silent));
		$this->assertSame('2026-06-01', $silent['reasons'][0]['since']);
		$this->assertSame(20, $silent['score']);
		$this->assertSame([], $newClient['reasons']);
		$this->assertSame(['silence'], self::signals($oldNeverContacted));
	}//end testSilenceUsesLastActivityThenCreation()

	/**
	 * The level boundaries: 29 low, 30 medium, 59 medium, 60 high.
	 *
	 * @return void
	 */
	public function testLevelBoundaries(): void {
		$service = $this->service();
		$this->weightsJson = json_encode(['complaint' => 1, 'complaintCap' => 100]);

		$this->assertSame('low', $service->evaluate($this->facts(['openComplaints' => 29]), $this->now)['level']);
		$this->assertSame('medium', $service->evaluate($this->facts(['openComplaints' => 30]), $this->now)['level']);
		$this->assertSame('medium', $service->evaluate($this->facts(['openComplaints' => 59]), $this->now)['level']);
		$this->assertSame('high', $service->evaluate($this->facts(['openComplaints' => 60]), $this->now)['level']);
	}//end testLevelBoundaries()

	/**
	 * Weights come from app config; unknown keys and negative values are ignored.
	 *
	 * @return void
	 */
	public function testWeightsOverrideOnlyKnownNonNegativeKeys(): void {
		$this->weightsJson = json_encode(['slaBreached' => 70, 'contractChurned' => -5, 'made-up' => 9]);
		$weights = $this->service()->weights();

		$this->assertSame(70, $weights['slaBreached']);
		$this->assertSame(40, $weights['contractChurned']);
		$this->assertArrayNotHasKey('made-up', $weights);
		$this->assertSame('high', $this->service()->evaluate($this->facts(['slaBreached' => 1]), $this->now)['level']);
	}//end testWeightsOverrideOnlyKnownNonNegativeKeys()

	/**
	 * changed() looks at the level and which reasons there are, not at score or time.
	 *
	 * @return void
	 */
	public function testChangedIgnoresScoreAndTime(): void {
		$stored = ['level' => 'medium', 'score' => 30, 'reasons' => [['signal' => 'contract-expiring', 'objectId' => 'c-1', 'points' => 30]], 'computedAt' => 'yesterday'];
		$same = ['level' => 'medium', 'score' => 31, 'reasons' => [['signal' => 'contract-expiring', 'objectId' => 'c-1', 'points' => 31]], 'computedAt' => 'today'];
		$otherReason = ['level' => 'medium', 'score' => 30, 'reasons' => [['signal' => 'contract-expiring', 'objectId' => 'c-2', 'points' => 30]], 'computedAt' => 'today'];
		$otherLevel = ['level' => 'high', 'score' => 70, 'reasons' => $stored['reasons'], 'computedAt' => 'today'];

		$this->assertFalse((new ClientRiskScore())->changed($stored, $same));
		$this->assertTrue((new ClientRiskScore())->changed($stored, $otherReason));
		$this->assertTrue((new ClientRiskScore())->changed($stored, $otherLevel));
		$this->assertTrue((new ClientRiskScore())->changed(null, $same));
	}//end testChangedIgnoresScoreAndTime()

	/**
	 * Spec scenario: a client whose contract turned churned yesterday is
	 * written as high with the churned contract as a reason.
	 *
	 * @return void
	 */
	public function testRecomputeWritesAChurnedClientAsHigh(): void {
		$this->client = ['name' => 'Bakkerij Jansen', '@self' => ['created' => '2025-01-01T00:00:00+00:00']];
		$this->rows['sch-contract'] = [['id' => 'c-9', 'clientRef' => 'client-1', 'status' => 'churned', 'endDate' => '2026-10-29']];
		$this->rows['sch-response'] = [['id' => 'r-1', 'clientRef' => 'client-1', 'classification' => 'detractor', 'submittedAt' => '2026-10-15T00:00:00+00:00']];
		$this->summary = ['openTicketsByType' => ['complaint' => 0], 'sla' => ['breached' => 0], 'lastActivityAt' => '2026-10-28T00:00:00+00:00'];

		$written = $this->service()->recompute('client-1', $this->now);

		$this->assertTrue($written);
		$this->assertTrue($this->systemScope, 'The recompute runs in the system scope');
		$this->assertCount(1, $this->patched);
		$risk = $this->patched[0]['data']['churnRisk'];
		$this->assertSame('client-1', $this->patched[0]['id']);
		$this->assertSame('high', $risk['level']);
		$this->assertSame(65, $risk['score']);
		$this->assertContains('c-9', array_column($risk['reasons'], 'objectId'));
	}//end testRecomputeWritesAChurnedClientAsHigh()

	/**
	 * Spec scenario: a quiet, happy client that is already low is not rewritten.
	 *
	 * @return void
	 */
	public function testRecomputeLeavesAnUnchangedClientAlone(): void {
		$this->client = ['churnRisk' => ['level' => 'low', 'score' => 0, 'reasons' => [], 'computedAt' => '2026-10-29T12:00:00+00:00']];
		$this->rows['sch-contract'] = [['id' => 'c-1', 'clientRef' => 'client-1', 'status' => 'active']];
		$this->summary = ['openTicketsByType' => ['complaint' => 0], 'sla' => ['breached' => 0], 'lastActivityAt' => '2026-10-23T00:00:00+00:00'];

		$this->assertFalse($this->service()->recompute('client-1', $this->now));
		$this->assertSame([], $this->patched);
	}//end testRecomputeLeavesAnUnchangedClientAlone()

	/**
	 * A client that cannot be read is not written.
	 *
	 * @return void
	 */
	public function testRecomputeOfMissingClientWritesNothing(): void {
		$this->assertFalse($this->service()->recompute('client-1', $this->now));
		$this->assertSame([], $this->patched);
	}//end testRecomputeOfMissingClientWritesNothing()

	/**
	 * recomputeAll() writes only the clients whose risk changed and counts them.
	 *
	 * @return void
	 */
	public function testRecomputeAllCountsOnlyChangedClients(): void {
		$this->rows['sch-client'] = [
			['id' => 'client-1', 'churnRisk' => ['level' => 'low', 'reasons' => []]],
			['id' => 'client-2'],
		];
		$this->summary = ['openTicketsByType' => ['complaint' => 0], 'sla' => ['breached' => 0], 'lastActivityAt' => '2026-10-23T00:00:00+00:00'];

		$this->assertSame(1, $this->service()->recomputeAll($this->now));
		$this->assertSame(['client-2'], array_column($this->patched, 'id'));
	}//end testRecomputeAllCountsOnlyChangedClients()
}//end class
