<?php

/**
 * Pins the override-applied forecast roll-up for every snapshot and override shape.
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
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-03
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ExchangeRateService;
use OCA\Pipelinq\Service\ForecastRollupService;
use OCA\Pipelinq\Service\ForecastService;
use OCA\Pipelinq\Service\QuotaService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The full roll-up row and the queries behind it, in order.
 */
class ForecastRollUpGoldenTest extends TestCase {

	/**
	 * Snapshot rows and override rows per category, and the expected row changes against the base.
	 *
	 * @return array<string, array{array<int, array<string, mixed>>, array<string, array<int, array<string, mixed>>>, array<string, mixed>}>
	 */
	public static function cases(): array {
		$snapshot = [
			'commit_amount'     => '1200.5',
			'best_case_amount'  => 2000,
			'pipeline_amount'   => 5000,
			'closed_won_amount' => 300,
			'deal_snapshot_ids' => ['d-1', 'd-2'],
			'partial'           => 1,
		];
		return [
			'no snapshot'          => [[], [], ['commit' => 0.0, 'original_commit' => 0.0, 'best_case' => 0.0, 'original_best_case' => 0.0, 'pipeline' => 0.0, 'closed_won' => 0.0, 'deal_snapshot_ids' => [], 'partial' => false]],
			'snapshot only'        => [[$snapshot], [], []],
			'commit override'      => [
				[$snapshot],
				['commit' => [['@self' => ['uuid' => 'o-1'], 'override_amount' => '1500', 'reason' => 'Big deal']]],
				['commit' => 1500.0, 'commit_override_id' => 'o-1', 'commit_override_reason' => 'Big deal'],
			],
			'best override no amount' => [
				[$snapshot],
				['best_case' => [['uuid' => 'o-2']]],
				['best_case' => 2000.0, 'best_case_override_id' => 'o-2', 'best_case_override_reason' => ''],
			],
			'both overrides'       => [
				[$snapshot],
				[
					'commit'    => [['@self' => ['id' => 'o-3'], 'override_amount' => 0, 'reason' => 'Lost']],
					'best_case' => [['id' => 7, 'override_amount' => 2500.25, 'reason' => 'Upside']],
				],
				[
					'commit'                    => 0.0,
					'commit_override_id'        => 'o-3',
					'commit_override_reason'    => 'Lost',
					'best_case'                 => 2500.25,
					'best_case_override_id'     => null,
					'best_case_override_reason' => 'Upside',
				],
			],
			'override no snapshot' => [
				[],
				['commit' => [['uuid' => 'o-4', 'override_amount' => 10]]],
				['commit' => 10.0, 'original_commit' => 0.0, 'commit_override_id' => 'o-4', 'commit_override_reason' => '', 'best_case' => 0.0, 'original_best_case' => 0.0, 'pipeline' => 0.0, 'closed_won' => 0.0, 'deal_snapshot_ids' => [], 'partial' => false],
			],
		];
	}//end cases()

	/**
	 * Each shape rolls up to exactly the pinned row, after the same queries.
	 *
	 * @param array<int, array<string, mixed>>                $snapshots The snapshot rows.
	 * @param array<string, array<int, array<string, mixed>>> $overrides Override rows per category.
	 * @param array<string, mixed>                            $changes   Expected changes against the base row.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testRollUp(array $snapshots, array $overrides, array $changes): void {
		$calls = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $a, string $k, string $d = ''): string => 'v-'.$k
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$calls, $snapshots, $overrides): array {
				$calls[] = $config;
				if ($config['filters']['schema'] === 'v-forecastSnapshot_schema') {
					return $snapshots;
				}

				return $overrides[$config['filters']['category']] ?? [];
			}
		);
		$quota = $this->createMock(QuotaService::class);
		$quota->method('getQuotaAmount')->willReturnCallback(
			function (string $ownerId, string $periodId, string $level) use (&$calls): float {
				$calls[] = ['quota', $ownerId, $periodId, $level];
				return 9000.0;
			}
		);
		$service = new ForecastService(
			appConfig: $appConfig,
			quotaService: $quota,
			rollup: new ForecastRollupService(exchangeRate: new ExchangeRateService(appConfig: $appConfig)),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
		);

		$base = [
			'owner_id'                  => 'u-1',
			'period_id'                 => 'q3',
			'level'                     => 'team',
			'commit'                    => 1200.5,
			'original_commit'           => 1200.5,
			'commit_override_id'        => null,
			'commit_override_reason'    => null,
			'best_case'                 => 2000.0,
			'original_best_case'        => 2000.0,
			'best_case_override_id'     => null,
			'best_case_override_reason' => null,
			'pipeline'                  => 5000.0,
			'closed_won'                => 300.0,
			'quota'                     => 9000.0,
			'deal_snapshot_ids'         => ['d-1', 'd-2'],
			'partial'                   => true,
		];
		$this->assertSame(array_replace($base, $changes), $service->computeRollUp(ownerId: 'u-1', periodId: 'q3', level: 'team'));

		$override = static fn (string $category): array => [
			'filters' => ['register' => 'v-register', 'schema' => 'v-forecastOverride_schema', 'override_owner_id' => 'u-1', 'period_id' => 'q3', 'level' => 'team', 'category' => $category],
			'sort'    => ['created_at' => 'DESC'],
			'limit'   => 1,
		];
		$this->assertSame(
			[
				[
					'filters' => ['register' => 'v-register', 'schema' => 'v-forecastSnapshot_schema', 'owner_id' => 'u-1', 'period_id' => 'q3', 'level' => 'team'],
					'sort'    => ['as_of_date' => 'DESC'],
					'limit'   => 1,
				],
				['quota', 'u-1', 'q3', 'team'],
				$override('commit'),
				$override('best_case'),
			],
			$calls
		);
	}//end testRollUp()
}//end class
