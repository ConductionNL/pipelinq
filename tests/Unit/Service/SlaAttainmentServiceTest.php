<?php

/**
 * Unit tests for SlaAttainmentService's grouped breakdown.
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
 * Met objects and breach events of one group must describe the same tracked
 * objects, whatever the grouping, and a group is shown by its name.
 */
class SlaAttainmentServiceTest extends TestCase {
	/**
	 * The service under test, over a fixed set of tickets, policies, clients and breach events.
	 *
	 * @var SlaAttainmentService
	 */
	private SlaAttainmentService $service;

	/**
	 * Set up the fixture: two policies, two met tickets and one breached ticket.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$met = static fn (string $policy, array $kinds): array => [
			'policyId' => $policy,
			'targets' => array_map(
				static fn (string $kind): array => ['kind' => $kind, 'status' => 'met', 'withAt' => '2026-10-01T09:00:00+00:00'],
				$kinds
			),
		];

		$tickets = [
			'request' => [
				['id' => 't1', 'client' => 'c1', 'slaStatus' => $met('p1', ['acknowledgement', 'resolution'])],
				['id' => 't2', 'client' => 'c2', 'slaStatus' => $met('p2', ['acknowledgement'])],
				[
					'id' => 't3',
					'client' => 'c2',
					'slaStatus' => [
						'policyId' => 'p2',
						'targets' => [['kind' => 'acknowledgement', 'status' => 'breached']],
					],
				],
			],
			'complaint' => [],
		];

		$objects = [
			'296' => [
				[
					'policyId' => 'p2',
					'targetKind' => 'acknowledgement',
					'targetObjectId' => 't3',
					'breachedAt' => '2026-10-01T08:00:00+00:00',
				],
			],
			'295' => [
				['id' => 'p1', 'name' => 'Standaard', 'customerTier' => '*'],
				['id' => 'p2', 'name' => 'Goud', 'customerTier' => 'gold'],
			],
			'242' => [
				['id' => 'c1', 'name' => 'Bakkerij De Jong'],
				['id' => 'c2', 'name' => 'Garage Smit'],
			],
		];

		$config = [
			'sla_register' => '26',
			'sla_breach_event_schema' => '296',
			'sla_policy_schema' => '295',
			'register' => '24',
			'client_schema' => '242',
		];

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $config[$key] ?? $default
		);

		$objectService = new class($objects) {
			/**
			 * Constructor.
			 *
			 * @param array $objects The objects per schema id.
			 */
			public function __construct(private array $objects) {
			}

			/**
			 * Return the objects of the requested schema.
			 *
			 * @param array $config The query config.
			 *
			 * @return array
			 */
			public function findAll(array $config): array {
				return $this->objects[(string)($config['filters']['schema'] ?? '')] ?? [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('findByType')->willReturnCallback(
			static fn (string $type): array => $tickets[$type] ?? []
		);

		$this->service = new SlaAttainmentService(
			$container,
			$appConfig,
			$ticketService,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The breakdown rows for a grouping, keyed by group name.
	 *
	 * @param string $groupBy The grouping.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function breakdown(string $groupBy): array {
		$result = $this->service->compute(['bucket' => 'day', 'date' => '2026-10-01', 'groupBy' => $groupBy]);
		$rows = [];
		foreach ($result['details']['byGroup'] as $row) {
			$rows[$row['groupName']] = $row;
		}

		return $rows;
	}//end breakdown()

	/**
	 * By policy: one row per policy, named after it.
	 *
	 * @return void
	 */
	public function testGroupsByPolicyUnderThePolicyName(): void {
		$groups = $this->breakdown('policy');

		$this->assertEqualsCanonicalizing(['Standaard', 'Goud'], array_keys($groups));
		$this->assertSame(1, $groups['Goud']['met']);
		$this->assertSame(1, $groups['Goud']['breached']);
		$this->assertSame(2, $groups['Goud']['total']);
	}//end testGroupsByPolicyUnderThePolicyName()

	/**
	 * By tier: met and breached objects land in the same tier rows, taken from
	 * the policy's tier where the ticket has none.
	 *
	 * @return void
	 */
	public function testGroupsMetAndBreachedObjectsByTheSameTier(): void {
		$groups = $this->breakdown('tier');

		$this->assertEqualsCanonicalizing(['gold', 'unspecified'], array_keys($groups));
		$this->assertSame(['met' => 1, 'breached' => 1], array_intersect_key($groups['gold'], ['met' => 0, 'breached' => 0]));
		$this->assertSame(['met' => 1, 'breached' => 0], array_intersect_key($groups['unspecified'], ['met' => 0, 'breached' => 0]));
	}//end testGroupsMetAndBreachedObjectsByTheSameTier()

	/**
	 * By customer: the ticket's client, under the client's name, for the breach too.
	 *
	 * @return void
	 */
	public function testGroupsByCustomerUnderTheClientName(): void {
		$groups = $this->breakdown('customer');

		$this->assertEqualsCanonicalizing(['Bakkerij De Jong', 'Garage Smit'], array_keys($groups));
		$this->assertSame(1, $groups['Garage Smit']['met']);
		$this->assertSame(1, $groups['Garage Smit']['breached']);
	}//end testGroupsByCustomerUnderTheClientName()

	/**
	 * By target: a met object counts once for every target it met.
	 *
	 * @return void
	 */
	public function testGroupsByTargetCountEachMetTarget(): void {
		$groups = $this->breakdown('target');

		$this->assertSame(2, $groups['acknowledgement']['met']);
		$this->assertSame(1, $groups['acknowledgement']['breached']);
		$this->assertSame(1, $groups['resolution']['met']);
	}//end testGroupsByTargetCountEachMetTarget()
}//end class
