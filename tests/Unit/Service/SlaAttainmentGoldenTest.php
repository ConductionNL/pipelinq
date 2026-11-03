<?php

/**
 * Golden test for SlaAttainmentService::compute().
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SlaAttainmentService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The whole attainment payload for every grouping, with and without a policy
 * filter, over tracked objects and breach events that sit inside and outside
 * the range, compared with SlaAttainmentGolden.json. Set
 * SLA_ATTAINMENT_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @covers \OCA\Pipelinq\Service\SlaAttainmentService
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */
class SlaAttainmentGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/SlaAttainmentGolden.json';

	private const IN = '2026-10-01T09:00:00+00:00';

	private const OUT = '2026-09-30T23:59:59+00:00';

	/**
	 * Run every grouping and filter and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$service = $this->service();
		$actual = [];
		foreach (SlaAttainmentService::VALID_GROUPS as $groupBy) {
			foreach (['', 'p1', 'p2', 'p9'] as $policy) {
				$params = ['bucket' => 'day', 'date' => '2026-10-01', 'groupBy' => $groupBy, 'policy' => $policy];
				$actual[$groupBy . ' / ' . $policy] = $service->compute(params: $params);
			}
		}

		$actual['month'] = $service->compute(params: ['bucket' => 'month', 'month' => '2026-09', 'groupBy' => 'team']);

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		if (getenv('SLA_ATTAINMENT_GOLDEN_RECORD') !== false) {
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
	 * An SLA status whose targets have the given kind => [status, withAt].
	 *
	 * @param string $policy The policy id.
	 * @param array<int, array{0: string, 1: string, 2: string}> $targets Kind, status and withAt per target.
	 *
	 * @return array<string, mixed>
	 */
	private static function slaStatus(string $policy, array $targets): array {
		$rows = [];
		foreach ($targets as [$kind, $state, $withAt]) {
			$rows[] = array_filter(['kind' => $kind, 'status' => $state, 'withAt' => $withAt], static fn ($value): bool => $value !== '');
		}

		return ['policyId' => $policy, 'targets' => $rows];
	}//end slaStatus()

	/**
	 * The service over a fixed world of tickets, callbacks, policies, clients and breach events.
	 *
	 * @return SlaAttainmentService
	 */
	private function service(): SlaAttainmentService {
		$tickets = [
			TicketService::TYPE_REQUEST => [
				['id' => 't1', 'client' => 'c1', 'team' => 'north',
					'slaStatus' => self::slaStatus(policy: 'p1', targets: [['acknowledgement', 'met', self::IN], ['resolution', 'met', self::IN]]),
				],
				['id' => 't2', 'client' => 'c2', 'team' => 'south', 'slaTier' => 'silver', 'customerTier' => 'bronze',
					'slaStatus' => self::slaStatus(policy: 'p2', targets: [['acknowledgement', 'met', self::IN]]),
				],
				['id' => 't3', 'client' => 'c2', 'customerTier' => 'bronze',
					'slaStatus' => self::slaStatus(policy: 'p2', targets: [['acknowledgement', 'breached', '']]),
				],
				['id' => 't4', 'client' => 'c3', 'team' => 'north',
					'slaStatus' => self::slaStatus(policy: 'p1', targets: [['', 'met', self::IN], ['response', 'met', self::OUT]]),
				],
				['id' => 't5', 'slaStatus' => self::slaStatus(policy: 'p1', targets: [['resolution', 'met', self::OUT]])],
				['id' => 't6', 'team' => '',
					'slaStatus' => self::slaStatus(policy: 'p2', targets: [['resolution', 'met', 'not a date'], ['acknowledgement', 'met', self::IN]]),
				],
				['id' => 't7', 'slaStatus' => 'none'],
				['id' => 't8', 'client' => 'c1', 'slaStatus' => self::slaStatus(policy: 'p1', targets: [['resolution', 'met', '']])],
			],
			TicketService::TYPE_COMPLAINT => [
				['id' => 'k1', 'client' => 'c3', 'team' => 'east',
					'slaStatus' => self::slaStatus(policy: 'p3', targets: [['resolution', 'met', '2026-10-01T23:59:59+00:00']]),
				],
				['id' => 'k2', 'client' => 'c1',
					'slaStatus' => self::slaStatus(policy: 'p1', targets: [['resolution', 'atRisk', self::IN], ['response', 'met', self::IN]]),
				],
			],
		];
		$objects = [
			'callbacks' => [
				['id' => 'cb1', 'client' => 'c2', 'team' => 'south', 'slaTier' => '', 'customerTier' => 'gold',
					'slaStatus' => self::slaStatus(policy: 'p2', targets: [['callback', 'met', '2026-10-01T00:00:00+00:00']]),
				],
			],
			'events' => [
				['policyId' => 'p2', 'targetKind' => 'acknowledgement', 'targetObjectId' => 't3', 'breachedAt' => self::IN],
				['policyId' => 'p1', 'targetKind' => 'resolution', 'targetObjectId' => 't1',
					'breachedAt' => self::IN, 'resolvedAt' => self::IN, 'team' => 'west', 'organisationId' => 'c8',
				],
				['policyId' => 'p1', 'targetObjectId' => 'gone',
					'breachedAt' => self::IN, 'resolvedAt' => '', 'organisationId' => 'c9', 'customerTier' => 'platinum',
				],
				['policyId' => 'p2', 'targetKind' => 'response', 'targetObjectId' => 't2', 'breachedAt' => self::OUT],
				['policyId' => 'p2', 'targetKind' => 'response', 'breachedAt' => ''],
				['targetKind' => 'response', 'targetObjectId' => 'cb1', 'breachedAt' => '2026-10-01T12:00:00+00:00'],
				['policyId' => 'p3', 'targetKind' => 'resolution', 'targetObjectId' => 'k1', 'breachedAt' => 'garbage'],
				['policyId' => 'p1', 'targetKind' => 'resolution', 'targetObjectId' => 't4', 'breachedAt' => '2026-09-15T10:00:00+00:00'],
			],
			'policies' => [
				['id' => 'p1', 'name' => 'Standaard', 'customerTier' => '*'],
				['id' => 'p2', 'name' => 'Goud', 'customerTier' => 'gold'],
				['id' => 'p3', 'name' => '', 'customerTier' => 'silver'],
			],
			'clients' => [
				['id' => 'c1', 'name' => 'Bakkerij De Jong'],
				['id' => 'c2', 'name' => 'Garage Smit'],
				['id' => 'c3', 'name' => ''],
			],
		];
		$config = [
			'sla_register' => 'sla',
			'sla_breach_event_schema' => 'events',
			'sla_policy_schema' => 'policies',
			'register' => 'crm',
			'client_schema' => 'clients',
			'callback_schema' => 'callbacks',
		];

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$objectService = new class($objects) {
			/**
			 * @param array<string, array<int, array<string, mixed>>> $objects Rows per schema id.
			 */
			public function __construct(private array $objects) {
			}

			/**
			 * @param array<string, mixed> $config The query config.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config): array {
				return ($this->objects[(string)($config['filters']['schema'] ?? '')] ?? []);
			}
		};

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$ticketService = $this->createMock(originalClassName: TicketService::class);
		$ticketService->method('findByType')->willReturnCallback(
			static fn (string $type): array => ($tickets[$type] ?? [])
		);

		return new SlaAttainmentService(
			container: $container,
			appConfig: $appConfig,
			ticketService: $ticketService,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()
}//end class
