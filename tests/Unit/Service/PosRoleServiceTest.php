<?php

/**
 * Unit tests for PosRoleService query pushdown.
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
use OCA\Pipelinq\Service\PosRoleService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosRoleService::countActiveStaffForRole.
 *
 * @spec openspec/changes/pipelinq-query-pushdown-batch-1/tasks.md#task-3
 */
class PosRoleServiceTest extends TestCase {

	/**
	 * The DI container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				return match ($key) {
					'register' => 'pipelinq',
					'posStaff_schema' => 'posStaff',
					'posRole_schema' => 'posRole',
					default => $default,
				};
			}
		);
	}//end setUp()

	/**
	 * Build a fake OR ObjectService whose findAll mirrors the real
	 * single-$config signature and filters the in-memory staff rows by the
	 * supplied field filters (register/schema metadata keys excluded).
	 *
	 * @param array<int, array<string, mixed>> $staff Staff rows.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $staff): object {
		return new class($staff) {
			/**
			 * @param array<int, array<string, mixed>> $staff Staff rows.
			 */
			public function __construct(
				private array $staff,
			) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $config Config with `filters`.
			 *
			 * @return array<int, array<string, mixed>> Matching rows.
			 */
			public function findAll(array $config = []): array {
				$filters = ($config['filters'] ?? []);
				unset($filters['register'], $filters['schema']);
				$out = [];
				foreach ($this->staff as $row) {
					foreach ($filters as $k => $v) {
						if (($row[$k] ?? null) !== $v) {
							continue 2;
						}
					}

					$out[] = $row;
				}

				return $out;
			}//end findAll()
		};
	}//end fakeObjectService()

	/**
	 * Build the service with the given fake ObjectService.
	 *
	 * @param object $objectService The fake ObjectService.
	 *
	 * @return PosRoleService
	 */
	private function buildService(object $objectService): PosRoleService {
		$this->container->method('get')->willReturn($objectService);
		return new PosRoleService($this->container, $this->appConfig, $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end buildService()

	/**
	 * The role-match filter is pushed down to OR; active staff are counted,
	 * including rows with a missing isActive field (treated as active), and
	 * rows belonging to other roles are excluded server-side.
	 *
	 * @return void
	 */
	public function testCountActiveStaffForRoleCountsActiveAndMissingFlag(): void {
		$fake = $this->fakeObjectService(
			[
				['id' => 's1', 'posRole' => 'role-1', 'isActive' => true],
				['id' => 's2', 'posRole' => 'role-1', 'isActive' => false],
				['id' => 's3', 'posRole' => 'role-1'],
				['id' => 's4', 'posRole' => 'role-2', 'isActive' => true],
			]
		);

		$count = $this->buildService($fake)->countActiveStaffForRole(roleId: 'role-1');

		// s1 (active) + s3 (missing flag => active). s2 inactive, s4 other role.
		$this->assertSame(2, $count);
	}//end testCountActiveStaffForRoleCountsActiveAndMissingFlag()

	/**
	 * Returns zero when no staff reference the role.
	 *
	 * @return void
	 */
	public function testCountActiveStaffForRoleReturnsZeroWhenNone(): void {
		$fake = $this->fakeObjectService([['id' => 's1', 'posRole' => 'role-x', 'isActive' => true]]);
		$count = $this->buildService($fake)->countActiveStaffForRole(roleId: 'role-1');

		$this->assertSame(0, $count);
	}//end testCountActiveStaffForRoleReturnsZeroWhenNone()
}//end class
