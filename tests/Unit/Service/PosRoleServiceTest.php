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
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\PosRoleService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosRoleService::countActiveStaffForRole.
 *
 * @spec openspec/changes/pipelinq-query-pushdown-batch-1/tasks.md#task-3
 */
class PosRoleServiceTest extends TestCase {

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
	 * @return ObjectServiceInterface The fake ObjectService.
	 */
	private function fakeObjectService(array $staff): ObjectServiceInterface {
		// Extends the ObjectService stub so it satisfies the contract type-hint
		// PosRoleService now declares (ADR-084); a bare anonymous class is a
		// different type wearing the same method names.
		return new class($staff) extends ObjectService {
			/**
			 * @param array<int, array<string, mixed>> $staff Staff rows.
			 */
			public function __construct(
				private array $staff,
			) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $config Config with `filters`.
			 * @param boolean $_rbac Unused.
			 * @param boolean $_multitenancy Unused.
			 *
			 * @return array<int, array<string, mixed>> Matching rows.
			 *
			 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parent signature.
			 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Parent signature.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
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
	 * @param ObjectServiceInterface $objectService The fake ObjectService.
	 *
	 * @return PosRoleService
	 */
	private function buildService(ObjectServiceInterface $objectService): PosRoleService {
		return new PosRoleService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $objectService,
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
