<?php

/**
 * Golden test for AnalyticsService::getOverview().
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
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/dashboard/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\AnalyticsService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The overview KPIs and their previous-period block for every period, over
 * row sets that sit on both sides of each window, compared with
 * AnalyticsOverviewGolden.json. Row dates are offsets from now that stay at
 * least half a day away from every window edge. Set
 * ANALYTICS_OVERVIEW_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @covers \OCA\Pipelinq\Service\AnalyticsService
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @spec openspec/changes/dashboard/tasks.md#task-2.2
 */
class AnalyticsOverviewGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/AnalyticsOverviewGolden.json';

	private const OFFSETS = [
		-0.5, -2, -5, -8, -15, -29, -31, -45, -59, -61, -80, -89,
		-91, -120, -179, -181, -200, -364, -366, -500, -729, -731, -900, 2,
	];

	/**
	 * Run every world for every period and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->worlds() as $name => $rows) {
			$service = $this->service(rows: $rows);
			foreach (AnalyticsService::ALLOWED_OVERVIEW_PERIODS as $period) {
				$actual[$name . ' / ' . $period] = $service->getOverview(period: $period);
			}
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
		if (getenv('ANALYTICS_OVERVIEW_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * The survey score is the rounded mean of the numeric scores in the
	 * window; getOverview() passes no survey responses yet, so this reads the
	 * helper directly.
	 *
	 * @return void
	 */
	public function testSatisfactionScoreAveragesScoredResponsesInTheWindow(): void {
		$method = new \ReflectionMethod(AnalyticsService::class, 'satisfactionScore');
		$window = ['from' => 1000, 'to' => 2000];
		$responses = [
			['submittedAt' => '@1000', 'score' => 4],
			['submittedAt' => '@1999', 'score' => '3.5'],
			['createdAt' => '@1500', 'score' => 2],
			['submittedAt' => '@2000', 'score' => 1],
			['submittedAt' => '@999', 'score' => 1],
			['submittedAt' => '@1500', 'score' => 'high'],
			['submittedAt' => '@1500'],
			['score' => 5],
		];

		$this->assertSame(expected: 3.17, actual: $method->invoke($this->service(rows: []), $responses, $window));
		$this->assertNull(actual: $method->invoke($this->service(rows: []), [['submittedAt' => '@1500', 'score' => 'x']], $window));
	}//end testSatisfactionScoreAveragesScoredResponsesInTheWindow()

	/**
	 * A date the given number of days from now.
	 *
	 * @param float $days Offset in days.
	 *
	 * @return string
	 */
	private function at(float $days): string {
		return (new DateTimeImmutable())->modify(sprintf('%+d seconds', (int)round($days * 86400)))->format(DateTimeInterface::ATOM);
	}//end at()

	/**
	 * The row sets, keyed by collection.
	 *
	 * @return array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private function worlds(): array {
		$leads = [];
		$requests = [];
		$moments = [];
		$statuses = ['won', 'lost', 'open', 'WON', ''];
		foreach (self::OFFSETS as $i => $offset) {
			$leads[] = ['status' => $statuses[$i % 5], 'createdAt' => $this->at(days: $offset)];
			$requests[] = ['occurredAt' => $this->at(days: ($offset - (($i % 4) + 0.25))), 'resolvedAt' => $this->at(days: $offset)];
			$moments[] = ['occurredAt' => $this->at(days: $offset)];
		}

		return [
			'empty'           => [],
			'spread'          => ['lead_schema' => $leads, 'ticket_schema:request' => $requests, 'ticket_schema:interaction' => $moments],
			'lead date fields' => [
				'lead_schema' => [
					['status' => 'won', 'created' => $this->at(days: -3)],
					['status' => 'won', 'created' => $this->at(days: -3), 'expectedCloseDate' => $this->at(days: -200)],
					['status' => 'won', 'expectedCloseDate' => $this->at(days: -40)],
					['status' => 'lost', 'createdAt' => '', 'created' => $this->at(days: -4)],
					['status' => 'won', 'createdAt' => 'not a date', 'created' => $this->at(days: -100)],
					['status' => 'won', 'createdAt' => $this->at(days: -2), 'expectedCloseDate' => $this->at(days: -400)],
					['status' => 'won'],
					['createdAt' => $this->at(days: -6)],
					['status' => 'won', 'createdAt' => 'garbage'],
				],
			],
			'request edges'   => [
				'ticket_schema:request' => [
					['occurredAt' => $this->at(days: -3), 'completedAt' => $this->at(days: -1)],
					['occurredAt' => $this->at(days: -3), 'resolvedAt' => 'never', 'completedAt' => $this->at(days: -2)],
					['occurredAt' => $this->at(days: -1), 'resolvedAt' => $this->at(days: -2)],
					['occurredAt' => $this->at(days: -50), 'resolvedAt' => $this->at(days: -4)],
					['resolvedAt' => $this->at(days: -2)],
					['occurredAt' => $this->at(days: -2)],
					['occurredAt' => $this->at(days: -40), 'resolvedAt' => $this->at(days: -35)],
					['occurredAt' => $this->at(days: -9), 'resolvedAt' => $this->at(days: 3)],
					['occurredAt' => $this->at(days: -1), 'resolvedAt' => $this->at(days: -0.75)],
				],
			],
			'moment fields'   => [
				'ticket_schema:interaction' => [
					['createdAt' => $this->at(days: -3)],
					['occurredAt' => '', 'createdAt' => $this->at(days: -20)],
					['occurredAt' => 'bad', 'createdAt' => $this->at(days: -50)],
					['occurredAt' => $this->at(days: -1), 'createdAt' => $this->at(days: -300)],
					[],
					['occurredAt' => $this->at(days: 5)],
				],
				'ticket_schema:request'     => [['occurredAt' => $this->at(days: -1), 'resolvedAt' => $this->at(days: -0.5)]],
				'lead_schema'               => [['status' => 'lost', 'createdAt' => $this->at(days: -1)]],
			],
		];
	}//end worlds()

	/**
	 * A service over the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per collection.
	 *
	 * @return AnalyticsService
	 */
	private function service(array $rows): AnalyticsService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'register-1'][$key] ?? $key)
		);

		$tickets = $this->createMock(originalClassName: TicketService::class);
		$tickets->method('findByType')->willReturnCallback(
			static fn (string $ticketType): array => ($rows['ticket_schema:' . $ticketType] ?? [])
		);

		$objects = new class($rows) extends ObjectService {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per collection.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param array<string, mixed> $config        The query configuration.
			 * @param bool                 $_rbac         Whether to enforce RBAC checks.
			 * @param bool                 $_multitenancy Whether to enforce tenant scoping.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->rows[(string)($config['filters']['schema'] ?? '')] ?? []);
			}
		};

		return new AnalyticsService(
			appConfig: $appConfig,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			ticketService: $tickets,
			objectService: $objects,
		);
	}//end service()
}//end class
