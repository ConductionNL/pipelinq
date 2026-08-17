<?php

/**
 * Unit tests for Customer360SummaryService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/klantbeeld-360-activation/tasks.md#task-5.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ActivityTimelineService;
use OCA\Pipelinq\Service\Customer360SummaryService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the customer-360 consolidated summary aggregation.
 *
 * @spec openspec/specs/customer-360/spec.md#requirement-consolidated-customer-360-summary
 */
class Customer360SummaryServiceTest extends TestCase {
	/**
	 * The ticket resolver mock (unify-ticket-supertype).
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService $ticketService;

	/**
	 * The activity timeline mock (last-activity lookup).
	 *
	 * @var ActivityTimelineService&MockObject
	 */
	private ActivityTimelineService $activityTimeline;

	/**
	 * The mocked OpenRegister ObjectService (leads + queues).
	 *
	 * @var \OCA\OpenRegister\Service\ObjectServiceInterface&MockObject
	 */
	private \OCA\OpenRegister\Contract\ObjectServiceInterface $objectService;

	/**
	 * Leads returned by the mocked ObjectService for `findAll(lead)`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $leads = [];

	/**
	 * Queues returned by the mocked ObjectService for `findAll(queue)`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queues = [];

	/**
	 * Set up fixtures with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->ticketService = $this->createMock(TicketService::class);
		$this->activityTimeline = $this->createMock(ActivityTimelineService::class);
		$this->objectService = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService);

		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$schema = (string)($config['filters']['schema'] ?? '');
				if ($schema === 'lead') {
					return $this->leads;
				}
				if ($schema === 'queue') {
					return $this->queues;
				}
				return [];
			}
		);

		$registerResolver = $this->createMock(RegisterResolverService::class);
		$registerResolver->method('resolve')->willReturn('pipelinq');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'lead_schema' => 'lead',
					'queue_schema' => 'queue',
					default => $default,
				};
			}
		);

		$this->activityTimeline->method('getTimeline')->willReturn(['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1]);

		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new Customer360SummaryService($registerResolver,
			$appConfig,
			$this->ticketService,
			$this->activityTimeline,
			$logger,
			objectService: $this->objectService,
		);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @var Customer360SummaryService
	 */
	private Customer360SummaryService $service;

	/**
	 * Configure `TicketService::findByType()` to return a fixed set of open
	 * tickets per ticketType, keyed by TicketService::TYPE_*.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $byType Tickets per type.
	 *
	 * @return void
	 */
	private function stubTicketsByType(array $byType): void {
		$this->ticketService->method('findByType')->willReturnCallback(
			static function (string $ticketType) use ($byType): array {
				return $byType[$ticketType] ?? [];
			}
		);
	}//end stubTicketsByType()

	/**
	 * Open tickets are counted across all three ticketTypes, spanning statuses
	 * the equality-only declarative primitives cannot express as one filter.
	 *
	 * @return void
	 */
	public function testOpenTicketCountSpansAllTicketTypes(): void {
		$this->stubTicketsByType(
			[
				TicketService::TYPE_REQUEST => [['title' => 'R1']],
				TicketService::TYPE_COMPLAINT => [['title' => 'C1']],
				TicketService::TYPE_CONTACTMOMENT => [['title' => 'M1'], ['title' => 'M2']],
			]
		);

		$summary = $this->service->getSummary('client-1');

		$this->assertSame(4, $summary['openTicketCount']);
		$this->assertSame(
			['request' => 1, 'complaint' => 1, 'interaction' => 2],
			$summary['openTicketsByType']
		);
	}//end testOpenTicketCountSpansAllTicketTypes()

	/**
	 * A `slaDeadline` already in the past counts as breached.
	 *
	 * @return void
	 */
	public function testSlaBreachedForPastDeadline(): void {
		$past = (new DateTimeImmutable('-2 days'))->format('c');
		$this->stubTicketsByType(
			[
				TicketService::TYPE_COMPLAINT => [['title' => 'Overdue', 'slaDeadline' => $past]],
			]
		);

		$summary = $this->service->getSummary('client-1');

		$this->assertSame(1, $summary['sla']['breached']);
		$this->assertSame(0, $summary['sla']['atRisk']);
	}//end testSlaBreachedForPastDeadline()

	/**
	 * A `slaDeadline` within the 24h lookahead window (but not yet past)
	 * counts as at-risk, not breached.
	 *
	 * @return void
	 */
	public function testSlaAtRiskForNearFutureDeadline(): void {
		$soon = (new DateTimeImmutable('+2 hours'))->format('c');
		$this->stubTicketsByType(
			[
				TicketService::TYPE_REQUEST => [['title' => 'Imminent', 'slaDeadline' => $soon]],
			]
		);

		$summary = $this->service->getSummary('client-1');

		$this->assertSame(0, $summary['sla']['breached']);
		$this->assertSame(1, $summary['sla']['atRisk']);
	}//end testSlaAtRiskForNearFutureDeadline()

	/**
	 * A `slaDeadline` well beyond the at-risk window is neither breached nor
	 * at-risk.
	 *
	 * @return void
	 */
	public function testSlaOnTrackForFarFutureDeadline(): void {
		$far = (new DateTimeImmutable('+10 days'))->format('c');
		$this->stubTicketsByType(
			[
				TicketService::TYPE_REQUEST => [['title' => 'Plenty of time', 'slaDeadline' => $far]],
			]
		);

		$summary = $this->service->getSummary('client-1');

		$this->assertSame(0, $summary['sla']['breached']);
		$this->assertSame(0, $summary['sla']['atRisk']);
	}//end testSlaOnTrackForFarFutureDeadline()

	/**
	 * The distinct set of queues on open tickets is reduced (not a count of
	 * tickets), and resolved to `{id, name}` pairs via the queue schema.
	 *
	 * @return void
	 */
	public function testDistinctQueuesAreReduced(): void {
		$this->stubTicketsByType(
			[
				TicketService::TYPE_REQUEST => [
					['title' => 'R1', 'queue' => 'queue-a'],
					['title' => 'R2', 'queue' => 'queue-a'],
					['title' => 'R3', 'queue' => 'queue-b'],
				],
			]
		);
		$this->queues = [
			['@self' => ['id' => 'queue-a'], 'title' => 'Vergunningen'],
			['@self' => ['id' => 'queue-b'], 'title' => 'Algemene Zaken'],
		];

		$summary = $this->service->getSummary('client-1');

		$this->assertSame(2, $summary['queueCount']);
		$names = array_column($summary['queues'], 'name');
		sort($names);
		$this->assertSame(['Algemene Zaken', 'Vergunningen'], $names);
	}//end testDistinctQueuesAreReduced()

	/**
	 * Open-lead count and summed value are read via the shared ObjectService,
	 * scoped to the client + status=open.
	 *
	 * @return void
	 */
	public function testOpenLeadCountAndValue(): void {
		$this->stubTicketsByType([]);
		$this->leads = [
			['title' => 'Lead 1', 'value' => 1000],
			['title' => 'Lead 2', 'value' => 2500],
		];

		$summary = $this->service->getSummary('client-1');

		$this->assertSame(2, $summary['openLeadCount']);
		$this->assertSame(3500.0, $summary['openLeadValue']);
	}//end testOpenLeadCountAndValue()

	/**
	 * RBAC scoping: an object the caller may not read never reaches this
	 * service (OpenRegister's ObjectService/TicketService already filter it
	 * out), so the summary only ever reflects what was actually returned —
	 * simulated here by a "hidden" ticket simply being absent from the mocked
	 * findByType() result set.
	 *
	 * @return void
	 */
	public function testRbacHiddenTicketsDoNotContribute(): void {
		// Only ONE of two tickets is "visible" — the hidden one is never
		// returned by findByType(), mirroring RBAC-filtered OR reads.
		$this->stubTicketsByType(
			[
				TicketService::TYPE_REQUEST => [['title' => 'Visible to caller']],
			]
		);
		// A hidden lead is likewise simply absent from findAll()'s result.
		$this->leads = [['title' => 'Visible lead', 'value' => 500]];

		$summary = $this->service->getSummary('client-1');

		$this->assertSame(1, $summary['openTicketCount']);
		$this->assertSame(1, $summary['openLeadCount']);
		$this->assertSame(500.0, $summary['openLeadValue']);
	}//end testRbacHiddenTicketsDoNotContribute()

	/**
	 * A missing/unparsable `slaDeadline` is silently skipped, never thrown.
	 *
	 * @return void
	 */
	public function testMissingSlaDeadlineIsSkippedNotThrown(): void {
		$this->stubTicketsByType(
			[
				TicketService::TYPE_CONTACTMOMENT => [['title' => 'No deadline'], ['title' => 'Bad', 'slaDeadline' => 'not-a-date']],
			]
		);

		$summary = $this->service->getSummary('client-1');

		$this->assertSame(2, $summary['openTicketCount']);
		$this->assertSame(0, $summary['sla']['breached']);
		$this->assertSame(0, $summary['sla']['atRisk']);
	}//end testMissingSlaDeadlineIsSkippedNotThrown()
}//end class
