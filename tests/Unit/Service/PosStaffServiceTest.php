<?php

/**
 * Unit tests for PosStaffService.
 *
 * Covers PIN validation format, bcrypt hashing on save, the 5-strike
 * 15-minute lockout, pinHash stripping on all reads, and permission matrix
 * resolution from posRole (REQ-PSP-002 / REQ-PSP-003).
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\PosRoleService;
use OCA\Pipelinq\Service\PosStaffService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory object service stub for the staff + role tests.
 *
 * Implements the OR ObjectService API surface PosStaff/RoleService depends on
 * (find / findAll / saveObject / deleteObject) with positional + named-arg
 * compatibility.
 */
class StaffFakeObjectService {

	/**
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	public function find(string $id, string $register, string $schema): ?array {
		return $this->store[$schema][$id] ?? null;
	}//end find()

	/**
	 * Mirror the real OR ObjectService::findAll(array $config) signature and
	 * resolve the schema from the metadata filters, then apply any remaining
	 * field filters (e.g. posRole) in-memory.
	 *
	 * @param array<string, mixed> $config Config with `filters`, `limit`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		$filters = ($config['filters'] ?? []);
		$schema = (string)($filters['schema'] ?? '');
		unset($filters['register'], $filters['schema']);

		$rows = array_values($this->store[$schema] ?? []);
		if ($filters === []) {
			return $rows;
		}

		$out = [];
		foreach ($rows as $row) {
			foreach ($filters as $k => $v) {
				if (($row[$k] ?? null) !== $v) {
					continue 2;
				}
			}

			$out[] = $row;
		}

		return $out;
	}//end findAll()

	/**
	 * Count objects matching the config filters (real OR signature).
	 *
	 * @param array<string, mixed> $config Config with `filters`.
	 *
	 * @return int
	 */
	public function count(array $config = []): int {
		return count($this->findAll(config: $config));
	}//end count()

	/**
	 * @param array<string, mixed> $object
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array {
		$object['id'] = $uuid;
		$this->store[$schema][$uuid] = $object;
		return $object;
	}//end saveObject()

	public function deleteObject(string $id, string $register, string $schema): void {
		unset($this->store[$schema][$id]);
	}//end deleteObject()
}//end class

/**
 * Tests for PosStaffService.
 */
class PosStaffServiceTest extends TestCase {

	private StaffFakeObjectService $os;

	private IAppConfig $appConfig;

	private PosRoleService $roleService;

	private PosStaffService $service;

	private string $roleId = 'role-1';

	protected function setUp(): void {
		$this->os = new StaffFakeObjectService();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$map = [
					'register' => 'reg-pipelinq',
					'posRole_schema' => 'sch-posRole',
					'posStaff_schema' => 'sch-posStaff',
				];
				return $map[$key] ?? $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $this->os;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$this->roleService = new PosRoleService(
			appConfig: $this->appConfig,
			logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		$this->service = new PosStaffService(
			appConfig: $this->appConfig,
			posRoleService: $this->roleService,
			logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// Seed a role.
		$this->os->store['sch-posRole'][$this->roleId] = [
			'id' => $this->roleId,
			'name' => 'Supervisor',
			'description' => '',
			'canVoid' => false,
			'maxDiscountPercent' => 15,
			'canRefund' => true,
			'canNoSale' => true,
		];
	}//end setUp()

	public function testSaveStaffHashesPinAndStripsHashOnResponse(): void {
		$saved = $this->service->saveStaff(
			data: [
				'displayName' => 'Anna de Vries',
				'posRole' => $this->roleId,
				'pin' => '1234',
				'isActive' => true,
			]
		);

		$this->assertSame('Anna de Vries', $saved['displayName']);
		$this->assertArrayNotHasKey('pinHash', $saved, 'pinHash MUST be stripped from API responses');

		// The persisted object DOES have a bcrypt hash though.
		$stored = $this->os->store['sch-posStaff'];
		$this->assertCount(1, $stored);
		$row = array_values($stored)[0];
		$this->assertNotEmpty($row['pinHash']);
		$this->assertTrue(password_verify('1234', $row['pinHash']));
		$this->assertNotSame('1234', $row['pinHash']);
	}//end testSaveStaffHashesPinAndStripsHashOnResponse()

	public function testSaveStaffRejectsInvalidPinFormat(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->saveStaff(
			data: [
				'displayName' => 'Anna',
				'posRole' => $this->roleId,
				'pin' => '12',
			]
		);
	}//end testSaveStaffRejectsInvalidPinFormat()

	public function testValidatePinSucceedsWithCorrectPinAndReturnsPermissions(): void {
		$saved = $this->service->saveStaff(
			data: [
				'displayName' => 'Carla Peters',
				'posRole' => $this->roleId,
				'pin' => '5678',
			]
		);

		$session = $this->service->validatePin(staffId: $saved['id'], pin: '5678');
		$this->assertSame($saved['id'], $session['staffId']);
		$this->assertSame('Carla Peters', $session['displayName']);
		$this->assertTrue($session['permissions']['canRefund']);
		$this->assertSame(15, $session['permissions']['maxDiscountPercent']);
	}//end testValidatePinSucceedsWithCorrectPinAndReturnsPermissions()

	public function testValidatePinRejectsBadPin(): void {
		$saved = $this->service->saveStaff(
			data: [
				'displayName' => 'Bob',
				'posRole' => $this->roleId,
				'pin' => '5678',
			]
		);

		$this->expectException(OCSForbiddenException::class);
		$this->service->validatePin(staffId: $saved['id'], pin: '0000');
	}//end testValidatePinRejectsBadPin()

	public function testFiveFailedAttemptsLocksAccount(): void {
		$saved = $this->service->saveStaff(
			data: [
				'displayName' => 'David',
				'posRole' => $this->roleId,
				'pin' => '5678',
			]
		);

		// 5 bad attempts.
		for ($i = 0; $i < PosStaffService::LOCKOUT_THRESHOLD; $i++) {
			try {
				$this->service->validatePin(staffId: $saved['id'], pin: '0000');
				$this->fail('Bad PIN should throw');
			} catch (OCSForbiddenException $e) {
				// expected
			}
		}

		$stored = $this->os->store['sch-posStaff'][$saved['id']];
		$this->assertNotEmpty(
			$stored['lockedUntil'] ?? '',
			'Account must have lockedUntil set after 5 failed attempts'
		);

		// 6th attempt — even with the right PIN — must be blocked.
		$this->expectException(OCSForbiddenException::class);
		$this->service->validatePin(staffId: $saved['id'], pin: '5678');
	}//end testFiveFailedAttemptsLocksAccount()

	public function testInactiveAccountIsRejected(): void {
		$saved = $this->service->saveStaff(
			data: [
				'displayName' => 'Emma',
				'posRole' => $this->roleId,
				'pin' => '5678',
				'isActive' => false,
			]
		);

		$this->expectException(OCSForbiddenException::class);
		$this->service->validatePin(staffId: $saved['id'], pin: '5678');
	}//end testInactiveAccountIsRejected()

	public function testEditPreservesPinWhenBlank(): void {
		$saved = $this->service->saveStaff(
			data: [
				'displayName' => 'Bob',
				'posRole' => $this->roleId,
				'pin' => '5678',
			]
		);

		$originalHash = $this->os->store['sch-posStaff'][$saved['id']]['pinHash'];

		// Update only the displayName — the PIN field stays blank.
		$updated = $this->service->saveStaff(
			data: [
				'displayName' => 'Bob Janssen',
				'posRole' => $this->roleId,
				'pin' => '',
			],
			id: $saved['id']
		);

		$this->assertSame('Bob Janssen', $updated['displayName']);
		$this->assertSame($originalHash, $this->os->store['sch-posStaff'][$saved['id']]['pinHash']);
	}//end testEditPreservesPinWhenBlank()

	public function testListStaffStripsHashFromEveryRow(): void {
		$this->service->saveStaff(
			data: [
				'displayName' => 'Anna',
				'posRole' => $this->roleId,
				'pin' => '1234',
			]
		);
		$this->service->saveStaff(
			data: [
				'displayName' => 'Bob',
				'posRole' => $this->roleId,
				'pin' => '5678',
			]
		);

		$rows = $this->service->listStaff();
		$this->assertCount(2, $rows);
		foreach ($rows as $row) {
			$this->assertArrayNotHasKey('pinHash', $row);
		}
	}//end testListStaffStripsHashFromEveryRow()

	public function testDeleteRoleBlockedWhenStaffStillAssigned(): void {
		$this->service->saveStaff(
			data: [
				'displayName' => 'Anna',
				'posRole' => $this->roleId,
				'pin' => '1234',
			]
		);

		$this->expectException(OCSForbiddenException::class);
		$this->roleService->deleteRole(id: $this->roleId);
	}//end testDeleteRoleBlockedWhenStaffStillAssigned()

	public function testMaxDiscountPercentBoundsAreEnforced(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->roleService->saveRole(
			data: [
				'name' => 'Bad role',
				'maxDiscountPercent' => 150,
			]
		);
	}//end testMaxDiscountPercentBoundsAreEnforced()
}//end class
