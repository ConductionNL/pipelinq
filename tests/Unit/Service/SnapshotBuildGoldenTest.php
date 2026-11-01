<?php

/**
 * Golden test for SnapshotGenerationService::buildSnapshots.
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
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-005-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ExchangeRateService;
use OCA\Pipelinq\Service\FiscalPeriodService;
use OCA\Pipelinq\Service\ForecastRollupService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\QuotaService;
use OCA\Pipelinq\Service\SnapshotGenerationService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Every snapshot buildSnapshots() returns, and every quota question it asks
 * (by parameter name), compared with SnapshotBuildGolden.json. Set
 * SNAPSHOT_BUILD_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-005-01
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SnapshotBuildGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/SnapshotBuildGolden.json';

	/**
	 * Quota calls made during the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario);
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (getenv('SNAPSHOT_BUILD_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, json_decode((string)json_encode(($actual[$name] ?? null), $flags), true), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The scenarios: the arguments and the quotas per owner.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$deals = [
			'rep.a' => [
				['uuid' => 'd-1', 'forecast_category' => 'commit', 'value' => 200000],
				['id' => 'd-2', 'forecast_category' => 'best_case', 'value' => 300000],
				['forecast_category' => 'closed_won', 'value' => 50000],
			],
			'rep.b' => [
				['uuid' => 'd-3', 'forecast_category' => 'commit', 'value' => 150000],
				['uuid' => 'd-4', 'forecast_category' => 'pipeline', 'value' => 350000],
			],
			'rep.c' => [['uuid' => 'd-5', 'forecast_category' => 'closed_won', 'value' => 1000, 'currency' => 'USD']],
		];
		$teams = ['team-east' => ['rep.a', 'rep.b'], 'team-west' => ['rep.c']];
		$quotas = ['rep.a' => 900000.0, 'rep.b' => 100000.0, 'team-east' => 0.0, 'team-west' => 5000.0, 'division-north' => 2000000.0, 'company' => 10.0];
		return [
			'teams only, open period'              => ['args' => ['Q2-2026', '2026-05-20', ['teams' => $teams, 'divisions' => []], $deals], 'quotas' => $quotas],
			'divisions, a team missing'            => ['args' => ['Q2-2026', '2026-06-29', ['teams' => $teams, 'divisions' => ['division-north' => ['team-east', 'team-gone'], 'division-south' => ['team-west']]], $deals], 'quotas' => $quotas],
			'closed period, no quotas'             => ['args' => ['Q2-2026', '2026-07-15', ['teams' => $teams, 'divisions' => ['division-north' => ['team-east', 'team-west']]], $deals], 'quotas' => []],
			'a rep without deals, numeric team id' => ['args' => ['Q3-2026', '2026-07-01', ['teams' => [7 => ['rep.a', 'rep.z']], 'divisions' => [9 => [7]]], ['rep.a' => $deals['rep.a']]], 'quotas' => ['rep.z' => 1.0, '7' => 1.0]],
			'nobody'                               => ['args' => ['Q2-2026', '2026-05-20', [], []], 'quotas' => $quotas],
			'a team in no division'                => ['args' => ['Q2-2026', '2026-05-20', ['teams' => $teams, 'divisions' => ['division-north' => ['team-west']]], $deals], 'quotas' => $quotas],
			'foreign rep first, then local reps'   => ['args' => ['Q2-2026', '2026-05-20', ['teams' => ['t' => ['rep.c', 'rep.a', 'rep.b']]], $deals], 'quotas' => []],
			'reporting currency is USD'            => ['args' => ['Q2-2026', '2026-05-20', ['teams' => ['t' => ['rep.a', 'rep.c']]], $deals], 'quotas' => [], 'currency' => 'USD'],
			'unknown period'                       => ['args' => ['nonsense', '2026-05-20', ['teams' => ['t' => ['rep.b']]], $deals], 'quotas' => $quotas],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh service.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->calls = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$currency = ($scenario['currency'] ?? 'EUR');
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($currency): string {
				if ($key === ExchangeRateService::REPORTING_CURRENCY_KEY) {
					return $currency;
				}

				return $default;
			}
		);
		$exchange = new ExchangeRateService(appConfig: $appConfig);

		$quotas = $scenario['quotas'];
		$quota = $this->createMock(QuotaService::class);
		$quota->method('getQuotaAmount')->willReturnCallback(
			$this->recorder(method: 'getQuotaAmount', answer: static fn (array $a): ?float => ($quotas[$a['ownerId']] ?? null))
		);
		$quota->method('projectedAttainment')->willReturnCallback(
			$this->recorder(method: 'projectedAttainment', answer: static fn (array $a): float => ($a['closedWon'] + $a['commit'] + ($a['bestCase'] / 2)))
		);
		$quota->method('isAtRisk')->willReturnCallback(
			$this->recorder(method: 'isAtRisk', answer: static fn (array $a): bool => ($a['projected'] < $a['quota'] && $a['daysRemaining'] < 40))
		);

		$service = new SnapshotGenerationService(
			appConfig: $appConfig,
			groupManager: $this->createMock(IGroupManager::class),
			rollup: new ForecastRollupService(exchangeRate: $exchange),
			exchangeRate: $exchange,
			period: new FiscalPeriodService(),
			quotaService: $quota,
			notifier: $this->createMock(NotificationService::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		return ['snapshots' => $service->buildSnapshots(...$scenario['args']), 'quotaCalls' => $this->calls];
	}//end runScenario()

	/**
	 * A callback that records a QuotaService call by parameter name and answers.
	 *
	 * @param string   $method The QuotaService method.
	 * @param callable $answer Computes the answer from the named arguments.
	 *
	 * @return callable
	 */
	private function recorder(string $method, callable $answer): callable {
		$params = (new ReflectionMethod(QuotaService::class, $method))->getParameters();
		return function (...$args) use ($method, $answer, $params): mixed {
			$named = [];
			foreach ($params as $i => $param) {
				if (array_key_exists($i, $args) === true) {
					$named[$param->getName()] = $args[$i];
				}
			}

			$this->calls[] = ['method' => $method, 'args' => $named];
			return $answer($named);
		};
	}//end recorder()
}//end class
