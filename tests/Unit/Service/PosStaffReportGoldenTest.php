<?php

/**
 * Golden test for PosStaffReportService::staffSalesReport.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\Service\PosStaffReportService;
use OCA\Pipelinq\Service\PosStaffService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/FakeAggregationRunner.php';

/**
 * Pins the exact report — row order, names, rounding — and the five grouped
 * aggregations it asks for (in order, with their filters), the PHP fallback
 * when aggregation fails, and the name lookup falling back to the id.
 */
class PosStaffReportGoldenTest extends TestCase {
	/**
	 * Transactions over two staff members, a draft, an empty staff id and a null one.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const ROWS = [
		['staffMemberId' => 's2', 'status' => 'confirmed', 'total' => 200.004, 'totalTax' => 42.001],
		['staffMemberId' => 's1', 'status' => 'confirmed', 'total' => 100.00, 'totalTax' => 21.00],
		['staffMemberId' => 's1', 'status' => 'settled', 'total' => 50.00, 'totalTax' => 10.50],
		['staffMemberId' => 's1', 'status' => 'refunded', 'total' => 30.00, 'totalTax' => 6.30],
		['staffMemberId' => 's3', 'status' => 'refunded', 'total' => 12.50, 'totalTax' => 2.10],
		['staffMemberId' => 's2', 'status' => 'draft', 'total' => 9.00, 'totalTax' => 1.00],
		['staffMemberId' => '', 'status' => 'confirmed', 'total' => 5.00, 'totalTax' => 1.00],
		['staffMemberId' => null, 'status' => 'confirmed', 'total' => 7.00, 'totalTax' => 1.00],
	];

	/**
	 * The report through the aggregation path, and the queries it ran.
	 *
	 * @return void
	 */
	public function testAggregatedReport(): void {
		$runner = new class(self::ROWS) extends FakeAggregationRunner {
			/**
			 * The queries run.
			 *
			 * @var array<int, array<int, mixed>>
			 */
			public array $queries = [];

			/**
			 * @param string           $registerRef The register ref.
			 * @param string           $schemaRef   The schema ref.
			 * @param AggregationQuery $query       The query.
			 * @param bool             $bypassRbac  Unused.
			 *
			 * @return array<string, mixed>
			 */
			public function runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query, bool $bypassRbac=false): array {
				$this->queries[] = [$registerRef, $schemaRef, $query->metric, $query->field, $query->filter, $query->groupBy];
				return parent::runAdhocByRef($registerRef, $schemaRef, $query, $bypassRbac);
			}
		};

		$report = $this->service($runner, null)->staffSalesReport();

		$this->assertSame(
			[
				['reg', 'posTransaction_schema', 'count', null, ['status' => ['in' => ['confirmed', 'settled', 'refunded']]], ['field' => 'staffMemberId']],
				['reg', 'posTransaction_schema', 'sum', 'total', ['status' => ['in' => ['confirmed', 'settled']]], ['field' => 'staffMemberId']],
				['reg', 'posTransaction_schema', 'sum', 'total', ['status' => 'refunded'], ['field' => 'staffMemberId']],
				['reg', 'posTransaction_schema', 'sum', 'totalTax', ['status' => ['in' => ['confirmed', 'settled']]], ['field' => 'staffMemberId']],
				['reg', 'posTransaction_schema', 'sum', 'totalTax', ['status' => 'refunded'], ['field' => 'staffMemberId']],
			],
			$runner->queries
		);
		$this->assertSame($this->expectedReport(), $report);
	}//end testAggregatedReport()

	/**
	 * When aggregation fails the PHP reduce over the fetched transactions gives the same report.
	 *
	 * @return void
	 */
	public function testFallbackReport(): void {
		$runner = new class([]) extends FakeAggregationRunner {
			/**
			 * @param string           $registerRef The register ref.
			 * @param string           $schemaRef   The schema ref.
			 * @param AggregationQuery $query       The query.
			 * @param bool             $bypassRbac  Unused.
			 *
			 * @return array<string, mixed>
			 */
			public function runAdhocByRef(string $registerRef, string $schemaRef, AggregationQuery $query, bool $bypassRbac=false): array {
				throw new \RuntimeException('no aggregation');
			}
		};
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('findAll')->with(['filters' => ['register' => 'reg', 'schema' => 'posTransaction_schema'], 'limit' => 5000])->willReturn(self::ROWS);

		$report = $this->service($runner, $objects)->staffSalesReport();

		$this->assertSame(
			[
				['staffMemberId' => 's2', 'displayName' => 'Name-s2', 'transactionCount' => 1, 'total' => 200.0, 'totalTax' => 42.0],
				['staffMemberId' => 's1', 'displayName' => 'Name-s1', 'transactionCount' => 3, 'total' => 120.0, 'totalTax' => 25.2],
				['staffMemberId' => 's3', 'displayName' => 's3', 'transactionCount' => 1, 'total' => -12.5, 'totalTax' => -2.1],
			],
			$report
		);
	}//end testFallbackReport()

	/**
	 * The report rows of the fixture, in the order the aggregation yields them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function expectedReport(): array {
		return [
			['staffMemberId' => 's2', 'displayName' => 'Name-s2', 'transactionCount' => 1, 'total' => 200.0, 'totalTax' => 42.0],
			['staffMemberId' => 's1', 'displayName' => 'Name-s1', 'transactionCount' => 3, 'total' => 120.0, 'totalTax' => 25.2],
			['staffMemberId' => 's3', 'displayName' => 's3', 'transactionCount' => 1, 'total' => -12.5, 'totalTax' => -2.1],
		];
	}//end expectedReport()

	/**
	 * A service on the given runner and object service; s3's name lookup fails.
	 *
	 * @param FakeAggregationRunner       $runner  The aggregation runner.
	 * @param ObjectServiceInterface|null $objects The object service.
	 *
	 * @return PosStaffReportService
	 */
	private function service(FakeAggregationRunner $runner, ?ObjectServiceInterface $objects): PosStaffReportService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register' ? 'reg' : $key)
		);
		$staff = $this->createMock(PosStaffService::class);
		$staff->method('getStaff')->willReturnCallback(
			static function (string $id): array {
				if ($id === 's3') {
					throw new \RuntimeException('gone');
				}

				return ['displayName' => 'Name-' . $id];
			}
		);

		return new PosStaffReportService(
			appConfig: $appConfig,
			posStaffService: $staff,
			logger: $this->createMock(LoggerInterface::class),
			objectService: ($objects ?? $this->createMock(ObjectServiceInterface::class)),
			aggregationRunner: $runner,
		);
	}//end service()
}//end class
