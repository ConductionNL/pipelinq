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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\PosRoleService;
use OCA\Pipelinq\Service\PosStaffService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * In-memory object service stub for the staff + role tests.
 *
 * Extends the ObjectService stub so it satisfies the `ObjectServiceInterface`
 * type-hint PosStaffService / PosRoleService now declare (ADR-084). Every
 * override carries the parent's exact signature — PHP checks compatibility at
 * class-load time, so drift is a fatal before test 1.
 */
class StaffFakeObjectService extends ObjectService {

	/**
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Read one row from the schema table.
	 *
	 * @param integer|string $id The object UUID.
	 * @param array<string, mixed>|null $_extend Unused.
	 * @param boolean $files Unused.
	 * @param string|int|null $register Unused — single-register fake.
	 * @param string|int|null $schema The schema key.
	 * @param boolean $_rbac Unused.
	 * @param boolean $_multitenancy Unused.
	 * @param boolean $_render Unused.
	 * @param boolean $_audit Unused.
	 *
	 * @return ObjectEntityInterface|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parent signature.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Parent signature.
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_render = true,
		bool $_audit = true,
	): ?ObjectEntityInterface {
		$row = ($this->store[(string)$schema][(string)$id] ?? null);
		if ($row === null) {
			return null;
		}

		return self::entity(uuid: (string)$id, row: $row);
	}//end find()

	/**
	 * Mirror the real OR ObjectService::findAll(array $config) signature and
	 * resolve the schema from the metadata filters, then apply any remaining
	 * field filters (e.g. posRole) in-memory.
	 *
	 * @param array<string, mixed> $config Config with `filters`, `limit`.
	 * @param boolean $_rbac Unused.
	 * @param boolean $_multitenancy Unused.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parent signature.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Parent signature.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
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
	 * Upsert a row into a schema table.
	 *
	 * @param array<string, mixed> $object The payload.
	 * @param array<string, mixed>|null $extend Unused.
	 * @param string|int|null $register Unused.
	 * @param string|int|null $schema The schema key.
	 * @param string|null $uuid The row UUID.
	 * @param boolean $_rbac Unused.
	 * @param boolean $_multitenancy Unused.
	 * @param boolean $silent Unused.
	 * @param boolean $_validation Unused.
	 * @param array<string, mixed>|null $uploadedFiles Unused.
	 * @param IUser|null $currentUser Unused.
	 * @param boolean $failIfExists Unused.
	 *
	 * @return ObjectEntityInterface
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parent signature.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Parent signature.
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Parent signature.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false,
	): ObjectEntityInterface {
		$key = (string)$uuid;
		$object['id'] = $key;
		$this->store[(string)$schema][$key] = $object;

		return self::entity(uuid: $key, row: $object);
	}//end saveObject()

	/**
	 * Remove a row from a schema table.
	 *
	 * @param string $uuid The object UUID.
	 * @param string|int|null $register Unused.
	 * @param string|int|null $schema The schema key.
	 * @param boolean $_rbac Unused.
	 * @param boolean $_multitenancy Unused.
	 * @param boolean $_retentionSweep Unused.
	 * @param IUser|null $currentUser Unused.
	 * @param boolean $permanent Unused — an in-memory table has no soft-delete.
	 *
	 * @return boolean
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parent signature.
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Parent signature.
	 */
	public function deleteObject(
		string $uuid,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_retentionSweep = false,
		?IUser $currentUser = null,
		bool $permanent = false,
	): bool {
		unset($this->store[(string)$schema][$uuid]);

		return true;
	}//end deleteObject()

	/**
	 * Wrap a stored row in the entity the contract now returns.
	 *
	 * @param string $uuid The object UUID.
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return ObjectEntityInterface
	 */
	private static function entity(string $uuid, array $row): ObjectEntityInterface {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($row);

		return $entity;
	}//end entity()
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

		$logger = $this->createMock(LoggerInterface::class);

		// Both services read the SAME in-memory store: the staff service looks
		// its role up through the role service, so handing either of them an
		// unprogrammed mock makes every lookup return null ("POS-rol niet
		// gevonden") rather than exercising the behaviour under test.
		$this->roleService = new PosRoleService(
			appConfig: $this->appConfig,
			logger: $logger,
			objectService: $this->os,
		);

		$this->service = new PosStaffService(
			appConfig: $this->appConfig,
			posRoleService: $this->roleService,
			logger: $logger,
			objectService: $this->os,
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
		$this->assertNotEmpty($stored['lockedUntil'] ?? '',
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

	/**
	 * A permitted delete actually REMOVES the row.
	 *
	 * Regression cover for an ADR-084 drift no existing test could see: both
	 * services called `deleteObject(id: ...)`, but the contract's first
	 * parameter is `$uuid`. PHP raises "Unknown named parameter $id", the
	 * service's `catch (\Throwable)` swallows it, and the caller is handed a
	 * plausible-looking "kon niet worden verwijderd" 404 — so delete was
	 * inert in production while nothing in the suite disagreed. Asserting the
	 * row is GONE, rather than that no exception was thrown, is what makes the
	 * difference visible.
	 *
	 * @return void
	 */
	public function testDeleteRemovesTheRowForBothStaffAndRole(): void {
		$saved = $this->service->saveStaff(
			data: [
				'displayName' => 'Anna',
				'posRole' => $this->roleId,
				'pin' => '1234',
			]
		);
		$staffId = (string)$saved['id'];
		$this->assertArrayHasKey($staffId, $this->os->store['sch-posStaff']);

		$this->service->deleteStaff(id: $staffId);
		$this->assertArrayNotHasKey($staffId, $this->os->store['sch-posStaff']);

		// With no staff left referencing it, the role deletes too.
		$this->roleService->deleteRole(id: $this->roleId);
		$this->assertArrayNotHasKey($this->roleId, $this->os->store['sch-posRole']);
	}//end testDeleteRemovesTheRowForBothStaffAndRole()

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
