<?php

/**
 * Golden test for SlaEngineService::resolvePolicyForObject().
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BusinessHoursCalculator;
use OCA\Pipelinq\Service\HolidayCalendarService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The chosen policy and every log line, for object types, customer metadata
 * and policy sets that exercise every match rule and every tie-break,
 * compared with SlaPolicyResolveGolden.json. Set
 * SLA_POLICY_RESOLVE_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @covers \OCA\Pipelinq\Service\SlaEngineService
 * @covers \OCA\Pipelinq\Service\Sla\SlaPolicyQuery
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
class SlaPolicyResolveGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/SlaPolicyResolveGolden.json';

	/**
	 * Log lines of the current case.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logs = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$all = array_keys($this->policies());
		$metas = [
			'none'         => [],
			'gold'         => ['tier' => 'gold'],
			'bronze'       => ['tier' => 'bronze'],
			'org o1'       => ['organisationId' => 'o1'],
			'org o2'       => ['organisationId' => 'o2'],
			'contract c1'  => ['contractId' => 'c1'],
			'o1 c1 gold'   => ['tier' => 'gold', 'organisationId' => 'o1', 'contractId' => 'c1'],
			'o2 c1'        => ['organisationId' => 'o2', 'contractId' => 'c1'],
			'numeric tier' => ['tier' => 7, 'organisationId' => 12],
		];
		$sets = [
			'all'                => $all,
			'wildcards'          => ['wild-a', 'wild-b', 'wild-prio', 'ticket-any'],
			'validFrom order'    => ['wild-a', 'wild-b'],
			'validFrom reversed' => ['wild-b', 'wild-a'],
			'no priority'        => ['wild-a', 'wild-noprio'],
			'no priority first'  => ['wild-noprio', 'wild-a'],
			'not started'        => ['late-start', 'wild-a'],
			'tier vs scope'      => ['tier-gold', 'scope-late'],
			'org vs contract'    => ['scope-org', 'scope-contract'],
			'integer org'        => ['scope-int-org', 'wild-a'],
			'tiers'              => ['tier-gold', 'tier-bronze', 'wild-a'],
			'scopes'             => ['scope-org', 'scope-contract', 'scope-both', 'scope-empty', 'tier-gold'],
			'org vs empty scope' => ['scope-empty', 'scope-org'],
			'windows'            => ['expired', 'future', 'bad-date', 'open-window'],
			'excluded only'      => ['expired', 'future', 'archived', 'inactive', 'lead'],
			'slug identity'      => ['slug-only'],
			'none'               => [],
		];

		$actual = [];
		foreach (['ticket', 'lead', 'request'] as $type) {
			foreach ($sets as $setName => $keys) {
				foreach ($metas as $metaName => $meta) {
					$this->logs = [];
					$chosen = $this->engine(keys: $keys)->resolvePolicyForObject($type, 'obj-1', $meta);
					$actual[$type . ' / ' . $setName . ' / ' . $metaName] = ['chosen' => ($chosen['name'] ?? null), 'logs' => $this->logs];
				}
			}
		}

		foreach (['no register' => 'noRegister', 'container fails' => 'noContainer', 'findAll fails' => 'findAllFails'] as $name => $failure) {
			$this->logs = [];
			$chosen = $this->engine(keys: $all, failure: $failure)->resolvePolicyForObject('ticket', 'obj-1', []);
			$actual[$name] = ['chosen' => ($chosen['name'] ?? null), 'logs' => $this->logs];
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (getenv('SLA_POLICY_RESOLVE_GOLDEN_RECORD') !== false) {
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
	 * The policy rows by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function policies(): array {
		$open = ['validFrom' => '2000-01-01T00:00:00Z', 'validUntil' => '2999-01-01T00:00:00Z'];
		return [
			'wild-a'         => ['id' => 'wild-a', 'name' => 'wild-a', 'priority' => 50, 'validFrom' => '2020-01-01T00:00:00Z'],
			'wild-b'         => ['id' => 'wild-b', 'name' => 'wild-b', 'priority' => 50, 'validFrom' => '2021-01-01T00:00:00Z'],
			'wild-prio'      => ['id' => 'wild-prio', 'name' => 'wild-prio', 'priority' => 5, 'appliesTo' => '*'],
			'wild-noprio'    => ['id' => 'wild-noprio', 'name' => 'wild-noprio', 'validFrom' => '2019-01-01T00:00:00Z', 'validUntil' => '2999-01-01'],
			'late-start'     => ['id' => 'late-start', 'name' => 'late-start', 'validFrom' => '2030-01-01T00:00:00Z', 'priority' => 1],
			'ticket-any'     => ['id' => 'ticket-any', 'name' => 'ticket-any', 'appliesTo' => 'ticket', 'customerTier' => '*', 'priority' => 50],
			'lead'           => ['id' => 'lead', 'name' => 'lead', 'appliesTo' => 'lead', 'priority' => 1],
			'tier-gold'      => ['id' => 'tier-gold', 'name' => 'tier-gold', 'customerTier' => 'gold', 'priority' => 90],
			'tier-bronze'    => ['id' => 'tier-bronze', 'name' => 'tier-bronze', 'customerTier' => 'bronze', 'priority' => 90],
			'scope-org'      => ['id' => 'scope-org', 'name' => 'scope-org', 'customerScope' => ['organisationIds' => ['o1']], 'priority' => 99],
			'scope-contract' => ['id' => 'scope-contract', 'name' => 'scope-contract', 'customerScope' => ['contractIds' => ['c1']], 'priority' => 99],
			'scope-both'     => [
				'id'            => 'scope-both',
				'name'          => 'scope-both',
				'customerScope' => ['organisationIds' => ['o1'], 'contractIds' => ['c1']],
				'priority'      => 98,
			],
			'scope-empty'    => [
				'id'            => 'scope-empty',
				'name'          => 'scope-empty',
				'customerScope' => ['organisationIds' => [], 'contractIds' => []],
				'priority'      => 1,
			],
			'scope-int-org'  => ['id' => 'scope-int-org', 'name' => 'scope-int-org', 'customerScope' => ['organisationIds' => [12]], 'priority' => 1],
			'scope-late'     => ['id' => 'scope-late', 'name' => 'scope-late', 'customerScope' => [], 'priority' => 95],
			'expired'        => ['id' => 'expired', 'name' => 'expired', 'validUntil' => '2001-01-01T00:00:00Z'],
			'future'         => ['id' => 'future', 'name' => 'future', 'validFrom' => '2999-01-01T00:00:00Z'],
			'bad-date'       => ['id' => 'bad-date', 'name' => 'bad-date', 'validFrom' => 'garbage', 'priority' => 70],
			'open-window'    => ['id' => 'open-window', 'name' => 'open-window', 'priority' => 80, ...$open],
			'archived'       => ['id' => 'archived', 'name' => 'archived', 'status' => 'archived'],
			'inactive'       => ['id' => 'inactive', 'name' => 'inactive', 'active' => false],
			'slug-only'      => ['@self' => ['slug' => 'by-slug'], 'name' => 'slug-only', 'customerTier' => 'gold'],
		];
	}//end policies()

	/**
	 * An engine whose policy register holds the given policies.
	 *
	 * @param array<int, string> $keys    The policy keys, in register order.
	 * @param string             $failure The failure to simulate, or ''.
	 *
	 * @return SlaEngineService
	 */
	private function engine(array $keys, string $failure=''): SlaEngineService {
		$rows = array_map(fn (string $key): array => $this->policies()[$key], $keys);

		$config = ['sla_register' => 'sla', 'sla_policy_schema' => 'policy'];
		if ($failure === 'noRegister') {
			$config = [];
		}

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$objects = new class ($rows, $failure) {
			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $rows    The rows.
			 * @param string                           $failure The failure to simulate.
			 */
			public function __construct(private array $rows, private string $failure) {
			}

			/**
			 * The rows.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config): array {
				if ($this->failure === 'findAllFails') {
					throw new RuntimeException(message: 'findAll down');
				}

				return $this->rows;
			}
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $failure): object {
				if ($failure === 'noContainer') {
					throw new RuntimeException(message: 'no ' . $id);
				}

				return $objects;
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['debug', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$holidays = $this->createMock(originalClassName: HolidayCalendarService::class);
		$hours = $this->createMock(originalClassName: BusinessHoursCalculator::class);
		$notifications = $this->createMock(originalClassName: INotificationManager::class);

		return new SlaEngineService(
			holidays: $holidays,
			businessHours: $hours,
			container: $container,
			appConfig: $appConfig,
			notifications: $notifications,
			logger: $logger
		);
	}//end engine()
}//end class
