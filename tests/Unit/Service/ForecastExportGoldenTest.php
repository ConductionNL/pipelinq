<?php

/**
 * Golden test for ForecastExportService::exportSnapshots().
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
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\ForecastExportService;
use OCA\Pipelinq\Service\ForecastService;
use OCA\Pipelinq\Service\ReportingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The export payload, every findAll()/count() configuration and every
 * roll-up lookup, for each level, owner filter, page window and failure,
 * compared with ForecastExportGolden.json. Set FORECAST_EXPORT_GOLDEN_RECORD=1
 * to rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\ForecastExportService
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010-01
 */
class ForecastExportGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/ForecastExportGolden.json';

	/**
	 * Calls made in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $calls = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$cases = [
			'company'            => ['args' => ['p1', 'company', null, 10, 0]],
			'division, owner'    => ['args' => ['p1', 'division', 'd1', 10, 0]],
			'team, empty owner'  => ['args' => ['p1', 'team', '', 2, 1]],
			'rep'                => ['args' => ['p1', 'rep', null, 50, 0]],
			'other period'       => ['args' => ['p2', 'company', null, 10, 0]],
			'no register'        => ['args' => ['p1', 'company', null, 10, 0], 'config' => ['register' => '']],
			'no schema'          => ['args' => ['p1', 'company', null, 10, 0], 'config' => ['forecastSnapshot_schema' => '']],
			'findAll fails'      => ['args' => ['p1', 'company', null, 10, 0], 'fail' => 'findAll'],
			'count fails'        => ['args' => ['p1', 'team', null, 10, 0], 'fail' => 'count'],
		];

		$actual = [];
		foreach ($cases as $name => $case) {
			self::$calls = [];
			[$periodId, $level, $ownerId, $limit, $offset] = $case['args'];
			$result = $this->service(case: $case)->exportSnapshots($periodId, $level, $ownerId, $limit, $offset);
			$actual[$name] = ['result' => $result, 'calls' => self::$calls];
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (getenv('FORECAST_EXPORT_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$got = json_encode(($actual[$name] ?? null), $flags);
			$this->assertSame(expected: json_encode($want, $flags), actual: $got, message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * A service over a fixed snapshot set for one case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return ForecastExportService
	 */
	private function service(array $case): ForecastExportService {
		$rows = [
			['@self' => ['uuid' => 'u1', 'id' => 9], 'period_id' => 'p1', 'level' => 'company', 'owner_id' => 'acme', 'as_of_date' => '2026-03-02',
				'commit_amount' => 100, 'best_case_amount' => '150.5', 'pipeline_amount' => 300, 'closed_won_amount' => 20, 'quota_amount' => 500,
				'currency' => 'USD', 'partial' => 1, 'exchange_rates' => ['USD' => ['rate' => 1.1], 'GBP' => 'bad'],
			],
			['@self' => ['id' => 7], 'period_id' => 'p1', 'level' => 'company', 'owner_id' => 'acme', 'as_of_date' => '2026-03-01',
				'exchange_rates' => 'none',
			],
			['@self' => 'broken', 'period_id' => 'p1', 'level' => 'division', 'owner_id' => 'd1', 'as_of_date' => '2026-03-01', 'commit_amount' => 50],
			['period_id' => 'p1', 'level' => 'division', 'owner_id' => 'd2', 'as_of_date' => '2026-03-01'],
			['period_id' => 'p1', 'level' => 'division', 'owner_id' => '', 'as_of_date' => '2026-03-03'],
			['period_id' => 'p1', 'level' => 'division', 'owner_id' => 'd1', 'as_of_date' => '2026-03-04'],
			['period_id' => 'p1', 'level' => 'team', 'owner_id' => 't1', 'as_of_date' => '2026-03-01'],
			['period_id' => 'p1', 'level' => 'team', 'owner_id' => 't2', 'as_of_date' => '2026-03-02'],
			['period_id' => 'p1', 'level' => 'team', 'owner_id' => 't3', 'as_of_date' => '2026-02-28'],
			['period_id' => 'p1', 'level' => 'rep', 'owner_id' => 'r1', 'as_of_date' => '2026-03-01'],
			['period_id' => 'p2', 'level' => 'company', 'owner_id' => 'acme', 'as_of_date' => '2026-06-01'],
		];

		$config = ['register' => 'pipelinq', 'forecastSnapshot_schema' => 'forecastSnapshot', ...($case['config'] ?? [])];
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$forecast = $this->createMock(originalClassName: ForecastService::class);
		$forecast->method('computeRollUp')->willReturnCallback(
			static function (string $ownerId, string $periodId, string $level): array {
				ForecastExportGoldenTest::$calls[] = ['computeRollUp' => [$ownerId, $periodId, $level]];
				$override = null;
				if (str_ends_with($ownerId, '1') === true) {
					$override = 'ov-' . $ownerId;
				}

				return ['commit' => strlen($ownerId) * 10, 'commit_override_id' => $override, 'commit_override_reason' => 'reason ' . $ownerId];
			}
		);

		$store = new class($rows, ($case['fail'] ?? '')) extends ObjectService {
			/**
			 * @param array<int, array<string, mixed>> $rows Snapshot rows.
			 * @param string                           $fail The call that throws.
			 */
			public function __construct(private array $rows, private string $fail) {
			}

			/**
			 * @param array<string, mixed> $config        The query configuration.
			 * @param bool                 $_rbac         Whether to enforce RBAC checks.
			 * @param bool                 $_multitenancy Whether to enforce tenant scoping.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				ForecastExportGoldenTest::$calls[] = ['findAll' => $config];
				if ($this->fail === 'findAll') {
					throw new RuntimeException('down');
				}

				$out = $this->matching(filters: ($config['filters'] ?? []));
				if (isset($config['sort']) === true) {
					usort($out, static fn (array $a, array $b): int => strcmp($a['as_of_date'], $b['as_of_date']));
				}

				return array_slice($out, (int)($config['offset'] ?? 0), ($config['limit'] ?? null));
			}

			/**
			 * @param array<string, mixed> $config The query configuration.
			 *
			 * @return int
			 */
			public function count(array $config = []): int {
				ForecastExportGoldenTest::$calls[] = ['count' => $config];
				if ($this->fail === 'count') {
					throw new RuntimeException('down');
				}

				return count($this->matching(filters: ($config['filters'] ?? [])));
			}

			/**
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			private function matching(array $filters): array {
				unset($filters['register'], $filters['schema']);
				return array_values(
					array_filter(
						$this->rows,
						static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters
					)
				);
			}
		};

		return new ForecastExportService(
			appConfig: $appConfig,
			forecastService: $forecast,
			reportingService: $this->createMock(originalClassName: ReportingService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			objectService: $store,
		);
	}//end service()
}//end class
