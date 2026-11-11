<?php

/**
 * Pins the leads and pipeline-value trend series against a reference bucketing.
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

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\AnalyticsService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every period and both lead metrics, with leads placed relative to the run.
 */
class AnalyticsTimeSeriesGoldenTest extends TestCase {

	/**
	 * Period, metric, days and bucket size.
	 *
	 * @return array<string, array{string, string, int, int}>
	 */
	public static function cases(): array {
		return [
			'leads by week'           => ['week', 'leads', 7, 86400],
			'leads by month'          => ['month', 'leads', 30, 86400],
			'leads by quarter'        => ['quarter', 'leads', 90, 86400 * 7],
			'value by quarter'        => ['quarter', 'pipeline-value', 90, 86400 * 7],
			'value by year'           => ['year', 'pipeline-value', 365, 86400 * 30],
		];
	}//end cases()

	/**
	 * The series equals the reference bucketing, dated from the window start.
	 *
	 * @param string $period The period.
	 * @param string $metric The metric.
	 * @param int    $days   The window in days.
	 * @param int    $size   The bucket size in seconds.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testSeries(string $period, string $metric, int $days, int $size): void {
		$now   = time();
		$at    = static fn (float $daysAgo): string => date(DATE_ATOM, (int)($now - $daysAgo * 86400));
		$leads = [
			['createdAt' => $at(0.5), 'value' => '100.555'],
			['created' => $at(1.5), 'value' => 50],
			['expectedCloseDate' => $at(2.5), 'value' => 7],
			['createdAt' => '', 'created' => $at(3.5)],
			['createdAt' => 'not a date', 'value' => 9],
			['createdAt' => $at(20.5), 'value' => 1000],
			['createdAt' => $at(60.5), 'value' => 2000],
			['createdAt' => $at(200.5), 'value' => 3000],
			['createdAt' => $at(400), 'value' => 99999],
			['createdAt' => $at(-2), 'value' => 99999],
			['createdAt' => $at(0.6), 'value' => 0.445],
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? 'register-1' : $key
		);
		$objects = new class($leads) extends ObjectService {
			/**
			 * @param array<int, array<string, mixed>> $leads The lead rows.
			 */
			public function __construct(private array $leads) {
			}

			/**
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Tenancy.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return (($config['filters']['schema'] ?? '') === 'lead_schema') ? $this->leads : [];
			}
		};
		$service = new AnalyticsService(
			appConfig: $config,
			logger: $this->createMock(LoggerInterface::class),
			ticketService: $this->createMock(TicketService::class),
			objectService: $objects,
		);

		$result  = $service->getTrends(metric: $metric, period: $period);
		$startTs = (new \DateTimeImmutable())->setTimestamp($now)->modify(sprintf('-%d days', $days))->getTimestamp();
		$buckets = [];
		foreach ($leads as $lead) {
			$raw = ($lead['createdAt'] ?? '') !== '' ? $lead['createdAt'] : (($lead['created'] ?? '') !== '' ? $lead['created'] : ($lead['expectedCloseDate'] ?? ''));
			$ts  = strtotime((string)$raw);
			if ($ts === false || $ts < $startTs || $ts > $now) {
				continue;
			}

			$key = date('Y-m-d', $startTs + ((int)floor(($ts - $startTs) / $size)) * $size);
			$buckets[$key] = ($buckets[$key] ?? 0) + ($metric === 'leads' ? 1 : (float)($lead['value'] ?? 0));
		}

		ksort($buckets);
		$series = [];
		foreach ($buckets as $date => $value) {
			$series[] = ['date' => $date, 'value' => $metric === 'leads' ? (int)$value : round((float)$value, 2)];
		}

		$this->assertSame(['metric' => $metric, 'period' => $period, 'series' => $series], $result);
		$this->assertNotSame([], $series);
	}//end testSeries()

	/**
	 * The revenue series from won leads only is dated and rounded like the reference.
	 *
	 * @return void
	 */
	public function testRevenueSeriesFromWonLeads(): void {
		$now   = time();
		$at    = static fn (float $daysAgo): string => date(DATE_ATOM, (int)($now - $daysAgo * 86400));
		$leads = [
			['status' => 'won', 'stageEnteredAt' => $at(1.5), 'value' => '10.005'],
			['status' => 'won', 'expectedCloseDate' => $at(1.6), 'value' => 5],
			['status' => 'open', 'stageEnteredAt' => $at(1.5), 'value' => 999],
			['status' => 'won', 'created' => $at(10.5), 'value' => 2.5],
			['status' => 'won', 'stageEnteredAt' => $at(45), 'value' => 999],
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? 'register-1' : $key
		);
		$objects = new class($leads) extends ObjectService {
			/**
			 * @param array<int, array<string, mixed>> $leads The lead rows.
			 */
			public function __construct(private array $leads) {
			}

			/**
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Tenancy.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return (($config['filters']['schema'] ?? '') === 'lead_schema') ? $this->leads : [];
			}
		};
		$service = new AnalyticsService(
			appConfig: $config,
			logger: $this->createMock(LoggerInterface::class),
			ticketService: $this->createMock(TicketService::class),
			objectService: $objects,
		);

		$result  = $service->getTrends(metric: 'revenue', period: 'month');
		$startTs = (new \DateTimeImmutable())->setTimestamp($now)->modify('-30 days')->getTimestamp();
		$day     = static fn (float $daysAgo): string => date('Y-m-d', $startTs + ((int)floor(((int)($now - $daysAgo * 86400) - $startTs) / 86400)) * 86400);
		$this->assertSame(
			['metric' => 'revenue', 'period' => 'month', 'series' => [['date' => $day(10.5), 'value' => 2.5], ['date' => $day(1.5), 'value' => 15.01]]],
			$result
		);
	}//end testRevenueSeriesFromWonLeads()
}//end class
